import Foundation
import MultipeerConnectivity
import CoreBluetooth
import Network

// ═══════════════════════════════════════════════════════════════════
// MARK: - TransportManager
//
// Unified transport layer for Qbix Server on iOS. Discovers nearby
// peers over all available channels and picks the best transport:
//
//   Priority: LAN/TCP → MultipeerConnectivity → BLE GATT
//
// When a peer is discovered, the manager:
//   1. Registers it with the PHP server (/Q/api/transport/event)
//   2. Performs the mesh handshake (/Q/sync/handshake)
//   3. Routes requests through the best available transport
//
// The PHP server sees only HTTP on localhost. Transport details are
// invisible to app code — a Qbix app calling handleUsingRemote gets
// BLE mesh, P2P Wi-Fi, or TCP transparently.
// ═══════════════════════════════════════════════════════════════════

class TransportManager: NSObject {
    static let shared = TransportManager()

    // ── Configuration ───────────────────────────────────

    /// Bonjour/mDNS service type for LAN discovery (max 15 chars)
    static let serviceType = "qbix-server"

    /// BLE service UUID — must match Android's TransportManager
    static let bleServiceUUID = CBUUID(string: "0000FB10-0000-1000-8000-00805F9B34FB")
    static let bleRequestUUID = CBUUID(string: "0000FB11-0000-1000-8000-00805F9B34FB")
    static let bleResponseUUID = CBUUID(string: "0000FB12-0000-1000-8000-00805F9B34FB")
    static let bleIdentityUUID = CBUUID(string: "0000FB13-0000-1000-8000-00805F9B34FB")

    /// BLE chunking flags — same protocol as MeshBLE.php
    static let chunkFirst: UInt8 = 0x01
    static let chunkMiddle: UInt8 = 0x02
    static let chunkLast: UInt8 = 0x03
    static let maxBLEMessage = 65536

    // ── State ───────────────────────────────────────────

    private var serverPort: Int = 8080
    private var meshId: String = ""           // Our peer_id from PHP
    private var deviceName: String = ""

    /// Known peers indexed by peer_id. Each peer may have multiple
    /// transports; we pick the best one when sending.
    private var peers: [String: PeerInfo] = [:]

    // MultipeerConnectivity
    private var mcPeerID: MCPeerID!
    private var mcSession: MCSession!
    private var mcAdvertiser: MCNearbyServiceAdvertiser?
    private var mcBrowser: MCNearbyServiceBrowser?

    // BLE
    private var blePeripheralManager: CBPeripheralManager?
    private var bleCentralManager: CBCentralManager?
    private var bleRequestChar: CBMutableCharacteristic?
    private var bleResponseChar: CBMutableCharacteristic?
    private var bleIdentityChar: CBMutableCharacteristic?
    private var bleRequestBuffers: [String: Data] = [:]  // central UUID → accumulated data
    private var discoveredPeripherals: [UUID: CBPeripheral] = [:]

    // LAN (Bonjour/NWBrowser)
    private var nwBrowser: NWBrowser?
    private var nwListener: NWListener?

    // ── Lifecycle ───────────────────────────────────────

    /// Start all transports. Call after the PHP server is listening.
    ///
    /// - Parameters:
    ///   - port: The localhost port the PHP server listens on.
    ///   - name: Human-readable device name (shown to other peers).
    func start(port: Int, name: String? = nil) {
        serverPort = port
        deviceName = name ?? UIDevice.current.name

        // Fetch our mesh identity from the PHP server
        fetchMeshIdentity { [weak self] meshId in
            guard let self = self, let meshId = meshId else {
                print("[Transport] Failed to fetch mesh identity — continuing without mesh")
                return
            }
            self.meshId = meshId
            print("[Transport] Mesh ID: \(meshId.prefix(12))…")

            // Update the BLE identity characteristic
            self.updateBLEIdentity()
        }

        startMultipeerConnectivity()
        startBLEPeripheral()
        startBLECentral()
        startLANDiscovery()

        print("[Transport] Started on port \(port), name '\(deviceName)'")
    }

    /// Stop all transports and disconnect all peers.
    func stop() {
        mcAdvertiser?.stopAdvertisingPeer()
        mcBrowser?.stopBrowsingForPeers()
        mcSession?.disconnect()

        blePeripheralManager?.stopAdvertising()
        blePeripheralManager?.removeAllServices()
        bleCentralManager?.stopScan()

        nwBrowser?.cancel()
        nwListener?.cancel()

        // Notify PHP that all peers are offline
        for (peerId, _) in peers {
            notifyPHP(event: "offline", peerId: peerId, data: ["reason": "shutdown"])
        }
        peers.removeAll()

        print("[Transport] Stopped")
    }

    // ── Peer Management ─────────────────────────────────

    /// Get all connected peers for the panel.
    func peerList() -> [[String: Any]] {
        return peers.values.map { peer in
            [
                "peer_id": peer.peerId,
                "name": peer.name,
                "transport": peer.bestTransport.rawValue,
                "transports": peer.transports.map { $0.rawValue },
                "hops": 0,
                "direct": true
            ] as [String: Any]
        }
    }

    /// Register a newly discovered peer. Deduplicates by peer_id.
    /// If a peer is found over multiple transports, we keep track of all
    /// and use the best one.
    private func addPeer(peerId: String, name: String, transport: Transport,
                         address: String? = nil, mcPeer: MCPeerID? = nil,
                         blePeripheral: CBPeripheral? = nil) {
        if peerId == meshId { return }  // Don't register ourselves

        var peer = peers[peerId] ?? PeerInfo(peerId: peerId, name: name)
        peer.name = name
        if !peer.transports.contains(transport) {
            peer.transports.append(transport)
        }
        if let addr = address { peer.tcpAddress = addr }
        if let mc = mcPeer { peer.mcPeerID = mc }
        if let ble = blePeripheral { peer.blePeripheral = ble }

        let isNew = peers[peerId] == nil
        peers[peerId] = peer

        if isNew {
            print("[Transport] New peer: \(name) (\(peerId.prefix(12))…) via \(transport)")
            notifyPHP(event: "online", peerId: peerId, data: [
                "name": name,
                "transport": transport.rawValue,
                "address": address ?? "",
                "hops": 0,
                "direct": true
            ])
            // Start mesh handshake in background
            performMeshHandshake(peerId: peerId)
        } else {
            print("[Transport] Peer \(name) now also reachable via \(transport)")
        }
    }

    /// Remove a peer (or remove one of its transports).
    private func removePeer(peerId: String, transport: Transport) {
        guard var peer = peers[peerId] else { return }
        peer.transports.removeAll { $0 == transport }

        if peer.transports.isEmpty {
            peers.removeValue(forKey: peerId)
            print("[Transport] Peer \(peer.name) offline (last transport \(transport) gone)")
            notifyPHP(event: "offline", peerId: peerId, data: ["reason": "transport_lost"])
        } else {
            peers[peerId] = peer
            print("[Transport] Peer \(peer.name) lost \(transport), still reachable via \(peer.bestTransport)")
        }
    }

    // ── PHP Server Communication ────────────────────────

    /// Call a PHP endpoint on our local server.
    private func phpRequest(_ method: String, _ path: String,
                            body: [String: Any]? = nil,
                            completion: ((Data?, URLResponse?, Error?) -> Void)? = nil) {
        guard let url = URL(string: "http://127.0.0.1:\(serverPort)\(path)") else { return }
        var req = URLRequest(url: url)
        req.httpMethod = method
        req.timeoutInterval = 10
        if let body = body {
            req.httpBody = try? JSONSerialization.data(withJSONObject: body)
            req.setValue("application/json", forHTTPHeaderField: "Content-Type")
        }
        URLSession.shared.dataTask(with: req) { data, resp, err in
            completion?(data, resp, err)
        }.resume()
    }

    /// Notify PHP of a transport event (peer online/offline/message).
    private func notifyPHP(event: String, peerId: String, data: [String: Any] = [:]) {
        var body: [String: Any] = ["type": event, "peer_id": peerId]
        for (k, v) in data { body[k] = v }
        phpRequest("POST", "/Q/api/transport/event", body: body)
    }

    /// Fetch our mesh identity from the PHP server.
    private func fetchMeshIdentity(completion: @escaping (String?) -> Void) {
        phpRequest("GET", "/Q/sync/identity") { data, _, _ in
            guard let data = data,
                  let json = try? JSONSerialization.jsonObject(with: data) as? [String: Any],
                  let peerId = json["peer_id"] as? String else {
                completion(nil)
                return
            }
            completion(peerId)
        }
    }

    /// Perform the mesh handshake with a newly discovered peer.
    /// For TCP peers, we call the PHP server's transport/connect endpoint.
    /// For BLE peers, we relay the handshake over BLE.
    private func performMeshHandshake(peerId: String) {
        guard let peer = peers[peerId] else { return }

        // For TCP/LAN peers, PHP can handshake directly
        if let addr = peer.tcpAddress, !addr.isEmpty {
            phpRequest("POST", "/Q/api/transport/connect",
                       body: ["address": addr]) { data, _, _ in
                if let data = data,
                   let json = try? JSONSerialization.jsonObject(with: data) as? [String: Any],
                   let connected = json["connected"] as? Bool, connected {
                    print("[Transport] Mesh handshake with \(peer.name) complete (TCP)")
                }
            }
        }
        // BLE handshake: relay /Q/sync/* requests over BLE (future)
    }

    // ═══════════════════════════════════════════════════
    // MARK: - MultipeerConnectivity
    //
    // Apple-only P2P. Automatically negotiates Bluetooth Classic
    // or P2P Wi-Fi (25+ Mbps). Best transport for Apple↔Apple.
    // ═══════════════════════════════════════════════════

    private func startMultipeerConnectivity() {
        mcPeerID = MCPeerID(displayName: deviceName)
        mcSession = MCSession(peer: mcPeerID, securityIdentity: nil,
                              encryptionPreference: .required)
        mcSession.delegate = self

        // Advertise ourselves. Discovery info carries our mesh_id
        // so peers can match us before the full handshake.
        let info: [String: String] = [
            "port": "\(serverPort)",
            "mesh": String(meshId.prefix(16))  // first 16 chars for matching
        ]
        mcAdvertiser = MCNearbyServiceAdvertiser(
            peer: mcPeerID, discoveryInfo: info,
            serviceType: Self.serviceType)
        mcAdvertiser?.delegate = self
        mcAdvertiser?.startAdvertisingPeer()

        mcBrowser = MCNearbyServiceBrowser(
            peer: mcPeerID, serviceType: Self.serviceType)
        mcBrowser?.delegate = self
        mcBrowser?.startBrowsingForPeers()
    }

    // ═══════════════════════════════════════════════════
    // MARK: - BLE Peripheral (others connect to us)
    //
    // Exposes our PHP server as a BLE GATT service. Cross-platform:
    // Android, iOS, anything that speaks BLE.
    //
    // GATT service layout:
    //   FB10 — Service UUID
    //   FB11 — Request characteristic (Write): client sends HTTP request
    //   FB12 — Response characteristic (Notify): we send HTTP response
    //   FB13 — Identity characteristic (Read): our mesh peer_id + name
    //
    // Chunking protocol (same as MeshBLE.php):
    //   0x01 [4-byte length] [payload] — first chunk
    //   0x02 [payload]                 — middle chunk
    //   0x03 [payload]                 — last chunk
    // ═══════════════════════════════════════════════════

    private func startBLEPeripheral() {
        blePeripheralManager = CBPeripheralManager(delegate: self, queue: nil)
    }

    private func setupBLEService() {
        guard let pm = blePeripheralManager else { return }

        bleRequestChar = CBMutableCharacteristic(
            type: Self.bleRequestUUID,
            properties: [.write, .writeWithoutResponse],
            value: nil, permissions: [.writeable])

        bleResponseChar = CBMutableCharacteristic(
            type: Self.bleResponseUUID,
            properties: [.notify, .read],
            value: nil, permissions: [.readable])

        let identityJSON = """
        {"peer_id":"\(meshId)","name":"\(deviceName)","port":\(serverPort)}
        """.data(using: .utf8)
        bleIdentityChar = CBMutableCharacteristic(
            type: Self.bleIdentityUUID,
            properties: [.read],
            value: identityJSON, permissions: [.readable])

        let service = CBMutableService(type: Self.bleServiceUUID, primary: true)
        service.characteristics = [bleRequestChar!, bleResponseChar!, bleIdentityChar!]
        pm.add(service)
    }

    private func updateBLEIdentity() {
        let json = """
        {"peer_id":"\(meshId)","name":"\(deviceName)","port":\(serverPort)}
        """.data(using: .utf8)
        bleIdentityChar?.value = json
    }

    /// Reassemble chunks received from a BLE central using the chunking protocol.
    /// Returns the complete message when the last chunk arrives, nil otherwise.
    private func accumulateBLEChunk(centralUUID: String, data: Data) -> Data? {
        guard !data.isEmpty else { return nil }
        let flag = data[0]

        switch flag {
        case Self.chunkFirst:
            // [0x01][4-byte length][payload]
            guard data.count >= 5 else { return nil }
            let length = UInt32(data[1]) << 24 | UInt32(data[2]) << 16
                       | UInt32(data[3]) << 8  | UInt32(data[4])
            var buffer = Data()
            buffer.append(data.subdata(in: 5..<data.count))
            bleRequestBuffers[centralUUID] = buffer
            return nil

        case Self.chunkMiddle:
            bleRequestBuffers[centralUUID]?.append(data.subdata(in: 1..<data.count))
            return nil

        case Self.chunkLast:
            if var buffer = bleRequestBuffers[centralUUID] {
                // Multi-chunk: append final payload
                buffer.append(data.subdata(in: 1..<data.count))
                bleRequestBuffers.removeValue(forKey: centralUUID)
                return buffer
            } else {
                // Single-chunk message: [0x03][4-byte length][payload]
                guard data.count >= 5 else { return data.subdata(in: 1..<data.count) }
                return data.subdata(in: 5..<data.count)
            }

        default:
            return nil
        }
    }

    /// Chunk a message for BLE transmission using the protocol from MeshBLE.php.
    private func chunkForBLE(_ data: Data, mtu: Int = 247) -> [Data] {
        let payloadMTU = mtu - 1  // 1 byte for flag
        if data.count <= payloadMTU - 4 {
            // Single chunk: [0x03][4-byte length][payload]
            var chunk = Data([Self.chunkLast])
            var len = UInt32(data.count).bigEndian
            chunk.append(Data(bytes: &len, count: 4))
            chunk.append(data)
            return [chunk]
        }

        var chunks: [Data] = []
        var offset = 0

        // First chunk: [0x01][4-byte length][payload]
        let firstPayload = payloadMTU - 4
        var first = Data([Self.chunkFirst])
        var len = UInt32(data.count).bigEndian
        first.append(Data(bytes: &len, count: 4))
        first.append(data.subdata(in: 0..<min(firstPayload, data.count)))
        chunks.append(first)
        offset = firstPayload

        while offset < data.count {
            let remaining = data.count - offset
            if remaining <= payloadMTU {
                var last = Data([Self.chunkLast])
                last.append(data.subdata(in: offset..<data.count))
                chunks.append(last)
                break
            } else {
                var mid = Data([Self.chunkMiddle])
                mid.append(data.subdata(in: offset..<offset + payloadMTU))
                chunks.append(mid)
                offset += payloadMTU
            }
        }
        return chunks
    }

    /// Forward a BLE request to localhost PHP and send the response back.
    private func handleBLERequest(_ requestData: Data, central: CBCentral) {
        guard let parsed = HTTPRequestParser.parse(requestData) else {
            sendBLEResponse("HTTP/1.1 400 Bad Request\r\nContent-Length: 0\r\n\r\n"
                .data(using: .utf8)!, to: central)
            return
        }

        var urlReq = URLRequest(
            url: URL(string: "http://127.0.0.1:\(serverPort)\(parsed.path)")!)
        urlReq.httpMethod = parsed.method
        urlReq.allHTTPHeaderFields = parsed.headers
        urlReq.allHTTPHeaderFields?["X-Peer-Id"] = central.identifier.uuidString
        urlReq.allHTTPHeaderFields?["X-Transport"] = "ble"
        urlReq.httpBody = parsed.body
        urlReq.timeoutInterval = 15

        URLSession.shared.dataTask(with: urlReq) { [weak self] data, response, error in
            guard let self = self else { return }
            var raw: Data
            if let http = response as? HTTPURLResponse, let body = data {
                var header = "HTTP/1.1 \(http.statusCode) OK\r\n"
                for (k, v) in http.allHeaderFields { header += "\(k): \(v)\r\n" }
                header += "\r\n"
                raw = header.data(using: .utf8)!
                raw.append(body)
            } else {
                raw = "HTTP/1.1 502 Bad Gateway\r\nContent-Length: 0\r\n\r\n".data(using: .utf8)!
            }
            self.sendBLEResponse(raw, to: central)
        }.resume()
    }

    /// Send a response to a BLE central, chunked per the protocol.
    private func sendBLEResponse(_ data: Data, to central: CBCentral) {
        guard let char = bleResponseChar, let pm = blePeripheralManager else { return }
        let chunks = chunkForBLE(data, mtu: central.maximumUpdateValueLength)
        for chunk in chunks {
            pm.updateValue(chunk, for: char, onSubscribedCentrals: [central])
        }
    }

    // ═══════════════════════════════════════════════════
    // MARK: - BLE Central (we connect to others)
    //
    // Scans for other Qbix Server peripherals (Android or iOS).
    // Reads their identity characteristic to get their peer_id,
    // then registers them as a peer.
    // ═══════════════════════════════════════════════════

    private func startBLECentral() {
        bleCentralManager = CBCentralManager(delegate: self, queue: nil)
    }

    // ═══════════════════════════════════════════════════
    // MARK: - LAN Discovery (Bonjour / NWBrowser)
    //
    // Discovers Qbix Servers on the local Wi-Fi network.
    // If both devices are on the same Wi-Fi, TCP is vastly faster
    // than BLE (100 Mbps vs 2 Mbps). We always prefer LAN when
    // available, falling back to MultipeerConnectivity then BLE.
    // ═══════════════════════════════════════════════════

    private func startLANDiscovery() {
        // Advertise via Bonjour
        let params = NWParameters.tcp
        params.includePeerToPeer = true
        nwListener = try? NWListener(using: params)
        nwListener?.service = NWListener.Service(
            name: deviceName, type: "_\(Self.serviceType)._tcp")
        nwListener?.serviceRegistrationUpdateHandler = { change in
            switch change {
            case .add(let endpoint):
                print("[LAN] Advertising: \(endpoint)")
            default: break
            }
        }
        nwListener?.stateUpdateHandler = { state in
            if case .ready = state {
                print("[LAN] Listener ready")
            }
        }
        nwListener?.start(queue: .main)

        // Browse for others
        nwBrowser = NWBrowser(for: .bonjour(type: "_\(Self.serviceType)._tcp", domain: nil),
                              using: params)
        nwBrowser?.browseResultsChangedHandler = { [weak self] results, changes in
            for change in changes {
                switch change {
                case .added(let result):
                    self?.handleLANDiscovery(result)
                case .removed(let result):
                    if case .service(let name, _, _, _) = result.endpoint {
                        print("[LAN] Lost: \(name)")
                    }
                default: break
                }
            }
        }
        nwBrowser?.start(queue: .main)
    }

    private func handleLANDiscovery(_ result: NWBrowser.Result) {
        guard case .service(let name, _, _, _) = result.endpoint else { return }
        if name == deviceName { return }  // Don't discover ourselves

        // Resolve the endpoint to get the IP address
        let connection = NWConnection(to: result.endpoint, using: .tcp)
        connection.stateUpdateHandler = { [weak self] state in
            if case .ready = state {
                if let path = connection.currentPath,
                   let endpoint = path.remoteEndpoint,
                   case .hostPort(let host, let port) = endpoint {
                    let addr = "http://\(host):\(port)"
                    print("[LAN] Resolved \(name) at \(addr)")

                    // Fetch their mesh identity over TCP
                    self?.fetchRemoteIdentity(address: addr, name: name, transport: .tcp)
                }
                connection.cancel()
            }
        }
        connection.start(queue: .global())
    }

    /// Fetch a remote peer's identity over TCP and register them.
    private func fetchRemoteIdentity(address: String, name: String, transport: Transport) {
        guard let url = URL(string: "\(address)/Q/sync/identity") else { return }
        var req = URLRequest(url: url)
        req.timeoutInterval = 5
        URLSession.shared.dataTask(with: req) { [weak self] data, _, _ in
            guard let data = data,
                  let json = try? JSONSerialization.jsonObject(with: data) as? [String: Any],
                  let peerId = json["peer_id"] as? String else { return }
            let peerName = json["name"] as? String ?? name
            self?.addPeer(peerId: peerId, name: peerName, transport: transport,
                         address: address)
        }.resume()
    }
}

// ═══════════════════════════════════════════════════════════════════
// MARK: - Data Types
// ═══════════════════════════════════════════════════════════════════

/// Transport types, ordered by priority (best first).
enum Transport: String, Comparable {
    case tcp = "tcp"                 // LAN / internet — highest bandwidth
    case multipeer = "multipeer"     // Apple P2P Wi-Fi — 25 Mbps
    case ble = "ble"                 // Bluetooth LE — 2 Mbps

    static func < (lhs: Transport, rhs: Transport) -> Bool {
        let order: [Transport] = [.tcp, .multipeer, .ble]
        return (order.firstIndex(of: lhs) ?? 99) < (order.firstIndex(of: rhs) ?? 99)
    }
}

/// Info about a discovered peer. May be reachable over multiple transports.
struct PeerInfo {
    let peerId: String
    var name: String
    var transports: [Transport] = []
    var tcpAddress: String?
    var mcPeerID: MCPeerID?
    var blePeripheral: CBPeripheral?

    /// The best available transport (lowest enum value = highest priority).
    var bestTransport: Transport {
        return transports.sorted().first ?? .ble
    }
}

// ═══════════════════════════════════════════════════════════════════
// MARK: - MCSessionDelegate
// ═══════════════════════════════════════════════════════════════════

extension TransportManager: MCSessionDelegate {
    func session(_ session: MCSession, peer peerID: MCPeerID,
                 didChange state: MCSessionState) {
        switch state {
        case .connected:
            // Peer connected via MultipeerConnectivity. We need their mesh_id
            // which we get from the discoveryInfo or by sending an identity request.
            addPeer(peerId: peerID.displayName, name: peerID.displayName,
                    transport: .multipeer, mcPeer: peerID)

        case .notConnected:
            // Find the peer by MCPeerID and remove the multipeer transport
            for (id, peer) in peers {
                if peer.mcPeerID == peerID {
                    removePeer(peerId: id, transport: .multipeer)
                    break
                }
            }

        default: break
        }
    }

    func session(_ session: MCSession, didReceive data: Data,
                 fromPeer peerID: MCPeerID) {
        // Incoming HTTP request from a peer — forward to localhost
        handleBLERequest(data, central: CBCentral())  // Reuse the handler
        // (In practice, MC requests go through forwardToLocalServer)
        phpRequest("POST", "/Q/api/transport/event", body: [
            "type": "message",
            "peer_id": peerID.displayName,
            "message": String(data: data, encoding: .utf8) ?? ""
        ])
    }

    func session(_ s: MCSession, didReceive stream: InputStream,
                 withName n: String, fromPeer p: MCPeerID) {}
    func session(_ s: MCSession, didStartReceivingResourceWithName n: String,
                 fromPeer p: MCPeerID, with progress: Progress) {}
    func session(_ s: MCSession, didFinishReceivingResourceWithName n: String,
                 fromPeer p: MCPeerID, at url: URL?, withError e: Error?) {}
}

// ═══════════════════════════════════════════════════════════════════
// MARK: - MCNearbyServiceAdvertiserDelegate
// ═══════════════════════════════════════════════════════════════════

extension TransportManager: MCNearbyServiceAdvertiserDelegate {
    func advertiser(_ advertiser: MCNearbyServiceAdvertiser,
                    didReceiveInvitationFromPeer peerID: MCPeerID,
                    withContext context: Data?,
                    invitationHandler: @escaping (Bool, MCSession?) -> Void) {
        invitationHandler(true, mcSession)
        print("[MC] Accepted invite from \(peerID.displayName)")
    }

    func advertiser(_ a: MCNearbyServiceAdvertiser,
                    didNotStartAdvertisingPeer error: Error) {
        print("[MC] Advertiser error: \(error.localizedDescription)")
    }
}

// ═══════════════════════════════════════════════════════════════════
// MARK: - MCNearbyServiceBrowserDelegate
// ═══════════════════════════════════════════════════════════════════

extension TransportManager: MCNearbyServiceBrowserDelegate {
    func browser(_ browser: MCNearbyServiceBrowser,
                 foundPeer peerID: MCPeerID,
                 withDiscoveryInfo info: [String: String]?) {
        print("[MC] Found: \(peerID.displayName) info: \(info ?? [:])")
        browser.invitePeer(peerID, to: mcSession, withContext: nil, timeout: 10)
    }

    func browser(_ browser: MCNearbyServiceBrowser,
                 lostPeer peerID: MCPeerID) {
        print("[MC] Lost: \(peerID.displayName)")
    }

    func browser(_ b: MCNearbyServiceBrowser,
                 didNotStartBrowsingForPeers error: Error) {
        print("[MC] Browser error: \(error.localizedDescription)")
    }
}

// ═══════════════════════════════════════════════════════════════════
// MARK: - CBPeripheralManagerDelegate (BLE server)
// ═══════════════════════════════════════════════════════════════════

extension TransportManager: CBPeripheralManagerDelegate {
    func peripheralManagerDidUpdateState(_ peripheral: CBPeripheralManager) {
        if peripheral.state == .poweredOn {
            setupBLEService()
            peripheral.startAdvertising([
                CBAdvertisementDataServiceUUIDsKey: [Self.bleServiceUUID],
                CBAdvertisementDataLocalNameKey: "QS:\(meshId.prefix(8))"
            ])
            print("[BLE] Peripheral advertising")
        }
    }

    func peripheralManager(_ peripheral: CBPeripheralManager,
                          didReceiveWrite requests: [CBATTRequest]) {
        for request in requests {
            guard request.characteristic.uuid == Self.bleRequestUUID,
                  let data = request.value else { continue }

            let key = request.central.identifier.uuidString
            if let complete = accumulateBLEChunk(centralUUID: key, data: data) {
                handleBLERequest(complete, central: request.central)
            }
            peripheral.respond(to: request, withResult: .success)
        }
    }

    func peripheralManager(_ peripheral: CBPeripheralManager,
                          didReceiveRead request: CBATTRequest) {
        if request.characteristic.uuid == Self.bleIdentityUUID ||
           request.characteristic.uuid == Self.bleResponseUUID {
            request.value = request.characteristic.value
            peripheral.respond(to: request, withResult: .success)
        }
    }
}

// ═══════════════════════════════════════════════════════════════════
// MARK: - CBCentralManagerDelegate (BLE scanner)
// ═══════════════════════════════════════════════════════════════════

extension TransportManager: CBCentralManagerDelegate {
    func centralManagerDidUpdateState(_ central: CBCentralManager) {
        if central.state == .poweredOn {
            central.scanForPeripherals(
                withServices: [Self.bleServiceUUID],
                options: [CBCentralManagerScanOptionAllowDuplicatesKey: false])
            print("[BLE] Central scanning")
        }
    }

    func centralManager(_ central: CBCentralManager,
                        didDiscover peripheral: CBPeripheral,
                        advertisementData: [String: Any],
                        rssi RSSI: NSNumber) {
        let name = advertisementData[CBAdvertisementDataLocalNameKey] as? String
            ?? peripheral.name ?? "Unknown"
        print("[BLE] Discovered: \(name) RSSI: \(RSSI)")

        // Connect to read their identity characteristic
        if discoveredPeripherals[peripheral.identifier] == nil {
            discoveredPeripherals[peripheral.identifier] = peripheral
            peripheral.delegate = self
            central.connect(peripheral)
        }
    }

    func centralManager(_ central: CBCentralManager,
                        didConnect peripheral: CBPeripheral) {
        // Discover the Qbix service to read the identity characteristic
        peripheral.discoverServices([Self.bleServiceUUID])
    }

    func centralManager(_ central: CBCentralManager,
                        didDisconnectPeripheral peripheral: CBPeripheral,
                        error: Error?) {
        discoveredPeripherals.removeValue(forKey: peripheral.identifier)
        // Find and remove the peer associated with this peripheral
        for (id, peer) in peers {
            if peer.blePeripheral?.identifier == peripheral.identifier {
                removePeer(peerId: id, transport: .ble)
                break
            }
        }
    }
}

// ═══════════════════════════════════════════════════════════════════
// MARK: - CBPeripheralDelegate (reading identity from remote)
// ═══════════════════════════════════════════════════════════════════

extension TransportManager: CBPeripheralDelegate {
    func peripheral(_ peripheral: CBPeripheral,
                    didDiscoverServices error: Error?) {
        guard let service = peripheral.services?.first(
            where: { $0.uuid == Self.bleServiceUUID }) else { return }
        peripheral.discoverCharacteristics(
            [Self.bleIdentityUUID], for: service)
    }

    func peripheral(_ peripheral: CBPeripheral,
                    didDiscoverCharacteristicsFor service: CBService,
                    error: Error?) {
        guard let identityChar = service.characteristics?.first(
            where: { $0.uuid == Self.bleIdentityUUID }) else { return }
        peripheral.readValue(for: identityChar)
    }

    func peripheral(_ peripheral: CBPeripheral,
                    didUpdateValueFor characteristic: CBCharacteristic,
                    error: Error?) {
        guard characteristic.uuid == Self.bleIdentityUUID,
              let data = characteristic.value,
              let json = try? JSONSerialization.jsonObject(with: data) as? [String: Any],
              let peerId = json["peer_id"] as? String else { return }

        let name = json["name"] as? String ?? "BLE Device"
        addPeer(peerId: peerId, name: name, transport: .ble,
                blePeripheral: peripheral)
    }
}

// ═══════════════════════════════════════════════════════════════════
// MARK: - HTTP Request Parser (reused from original)
// ═══════════════════════════════════════════════════════════════════

struct HTTPRequestParser {
    struct ParsedRequest {
        let method: String
        let path: String
        let headers: [String: String]
        let body: Data?
    }

    static func parse(_ data: Data) -> ParsedRequest? {
        guard let str = String(data: data, encoding: .utf8) else { return nil }
        let parts = str.components(separatedBy: "\r\n\r\n")
        guard let headerSection = parts.first else { return nil }
        let lines = headerSection.components(separatedBy: "\r\n")
        guard let requestLine = lines.first else { return nil }
        let tokens = requestLine.components(separatedBy: " ")
        guard tokens.count >= 2 else { return nil }

        var headers: [String: String] = [:]
        for line in lines.dropFirst() {
            if let idx = line.firstIndex(of: ":") {
                let key = String(line[..<idx]).trimmingCharacters(in: .whitespaces)
                let val = String(line[line.index(after: idx)...]).trimmingCharacters(in: .whitespaces)
                headers[key] = val
            }
        }

        var body: Data? = nil
        if parts.count > 1 {
            let bodyStr = parts.dropFirst().joined(separator: "\r\n\r\n")
            if !bodyStr.isEmpty { body = bodyStr.data(using: .utf8) }
        }

        return ParsedRequest(method: tokens[0], path: tokens[1],
                            headers: headers, body: body)
    }
}
