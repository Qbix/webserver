package com.qbix.server.transport

import android.bluetooth.*
import android.bluetooth.le.*
import android.content.Context
import android.net.nsd.NsdManager
import android.net.nsd.NsdServiceInfo
import android.os.ParcelUuid
import org.json.JSONObject
import java.io.BufferedReader
import java.io.InputStreamReader
import java.net.HttpURLConnection
import java.net.InetAddress
import java.net.ServerSocket
import java.net.URL
import java.util.UUID
import java.util.concurrent.ConcurrentHashMap
import java.util.concurrent.Executors

// ═══════════════════════════════════════════════════════════════════
// TransportManager
//
// Unified transport layer for Qbix Server on Android. Discovers
// nearby peers over all available channels and picks the best:
//
//   Priority: LAN/TCP → BLE GATT
//
// (Android doesn't have MultipeerConnectivity. Wi-Fi Direct exists
// but requires user confirmation for each connection, so we skip it
// in favor of BLE + LAN which are fully automatic.)
//
// When a peer is discovered, the manager:
//   1. Registers it with the PHP server (/Q/api/transport/event)
//   2. Performs the mesh handshake (/Q/sync/handshake)
//   3. Routes requests through the best available transport
//
// The PHP server sees only HTTP on localhost.
// ═══════════════════════════════════════════════════════════════════

class TransportManager(private val context: Context, private val serverPort: Int) {

    companion object {
        // BLE UUIDs — must match iOS TransportManager
        val SERVICE_UUID: UUID = UUID.fromString("0000FB10-0000-1000-8000-00805F9B34FB")
        val REQUEST_UUID: UUID = UUID.fromString("0000FB11-0000-1000-8000-00805F9B34FB")
        val RESPONSE_UUID: UUID = UUID.fromString("0000FB12-0000-1000-8000-00805F9B34FB")
        val IDENTITY_UUID: UUID = UUID.fromString("0000FB13-0000-1000-8000-00805F9B34FB")
        val CCCD_UUID: UUID = UUID.fromString("00002902-0000-1000-8000-00805F9B34FB")

        // Chunking flags — same protocol as MeshBLE.php and iOS
        const val CHUNK_FIRST: Byte = 0x01
        const val CHUNK_MIDDLE: Byte = 0x02
        const val CHUNK_LAST: Byte = 0x03
        const val MAX_BLE_MESSAGE = 65536

        // NSD service type for LAN discovery
        const val NSD_SERVICE_TYPE = "_qbix-server._tcp"
    }

    // ── State ───────────────────────────────────────────

    private var meshId: String = ""
    private val deviceName: String = android.os.Build.MODEL
    private val executor = Executors.newFixedThreadPool(4)

    /** Known peers by peer_id. May be reachable over multiple transports. */
    private val peers = ConcurrentHashMap<String, PeerInfo>()

    // BLE
    private var bluetoothManager: BluetoothManager? = null
    private var gattServer: BluetoothGattServer? = null
    private var advertiser: BluetoothLeAdvertiser? = null
    private var bleScanner: BluetoothLeScanner? = null
    private var responseChar: BluetoothGattCharacteristic? = null
    private var identityChar: BluetoothGattCharacteristic? = null
    private val requestBuffers = ConcurrentHashMap<String, ByteArray>()
    private val connectedDevices = ConcurrentHashMap<String, BluetoothDevice>()

    // LAN (NSD)
    private var nsdManager: NsdManager? = null
    private var registrationListener: NsdManager.RegistrationListener? = null
    private var discoveryListener: NsdManager.DiscoveryListener? = null

    // ── Lifecycle ───────────────────────────────────────

    /** Start all transports. Call after the PHP server is listening. */
    fun start() {
        // Fetch mesh identity from PHP
        executor.submit {
            meshId = fetchMeshIdentity() ?: ""
            if (meshId.isNotEmpty()) {
                println("[Transport] Mesh ID: ${meshId.take(12)}…")
                updateBLEIdentity()
            }
        }

        startBLEPeripheral()
        startBLEScanner()
        startLANDiscovery()

        println("[Transport] Started on port $serverPort, name '$deviceName'")
    }

    /** Stop all transports. */
    fun stop() {
        advertiser?.stopAdvertising(advertiseCallback)
        gattServer?.close()
        bleScanner?.stopScan(scanCallback)
        nsdManager?.apply {
            registrationListener?.let { unregisterService(it) }
            discoveryListener?.let { stopServiceDiscovery(it) }
        }
        for ((peerId, _) in peers) {
            notifyPHP("offline", peerId, mapOf("reason" to "shutdown"))
        }
        peers.clear()
        executor.shutdown()
        println("[Transport] Stopped")
    }

    /** Peer list for the panel. */
    fun peerList(): List<Map<String, Any>> {
        return peers.values.map { peer ->
            mapOf(
                "peer_id" to peer.peerId,
                "name" to peer.name,
                "transport" to peer.bestTransport().name.lowercase(),
                "hops" to 0,
                "direct" to true
            )
        }
    }

    // ── Peer Management ─────────────────────────────────

    private fun addPeer(peerId: String, name: String, transport: TransportType,
                        address: String? = null, device: BluetoothDevice? = null) {
        if (peerId == meshId) return  // Don't register ourselves

        val isNew = !peers.containsKey(peerId)
        val peer = peers.getOrPut(peerId) { PeerInfo(peerId, name) }
        peer.name = name
        if (!peer.transports.contains(transport)) peer.transports.add(transport)
        address?.let { peer.tcpAddress = it }
        device?.let { peer.bleDevice = it }

        if (isNew) {
            println("[Transport] New peer: $name (${peerId.take(12)}…) via $transport")
            notifyPHP("online", peerId, mapOf(
                "name" to name,
                "transport" to transport.name.lowercase(),
                "address" to (address ?: ""),
                "hops" to 0,
                "direct" to true
            ))
            // Mesh handshake for TCP peers
            if (address != null) performMeshHandshake(address)
        }
    }

    private fun removePeer(peerId: String, transport: TransportType) {
        val peer = peers[peerId] ?: return
        peer.transports.remove(transport)
        if (peer.transports.isEmpty()) {
            peers.remove(peerId)
            println("[Transport] Peer ${peer.name} offline")
            notifyPHP("offline", peerId, mapOf("reason" to "transport_lost"))
        }
    }

    // ── PHP Communication ───────────────────────────────

    private fun notifyPHP(event: String, peerId: String,
                          data: Map<String, Any> = emptyMap()) {
        executor.submit {
            try {
                val body = JSONObject(data.toMutableMap().apply {
                    put("type", event)
                    put("peer_id", peerId)
                })
                httpPost("http://127.0.0.1:$serverPort/Q/api/transport/event",
                         body.toString())
            } catch (e: Exception) {
                println("[Transport] PHP notify error: ${e.message}")
            }
        }
    }

    private fun fetchMeshIdentity(): String? {
        return try {
            val json = httpGet("http://127.0.0.1:$serverPort/Q/sync/identity")
            JSONObject(json).optString("peer_id", "")
                .takeIf { it.isNotEmpty() }
        } catch (e: Exception) { null }
    }

    private fun performMeshHandshake(address: String) {
        executor.submit {
            try {
                val body = JSONObject(mapOf("address" to address))
                httpPost("http://127.0.0.1:$serverPort/Q/api/transport/connect",
                         body.toString())
            } catch (e: Exception) {
                println("[Transport] Handshake error: ${e.message}")
            }
        }
    }

    // ═══════════════════════════════════════════════════
    // BLE Peripheral (GATT server — others connect to us)
    //
    // Same GATT layout and chunking protocol as iOS.
    // ═══════════════════════════════════════════════════

    private fun startBLEPeripheral() {
        bluetoothManager = context.getSystemService(Context.BLUETOOTH_SERVICE)
            as? BluetoothManager ?: return

        setupGattServer()
        startAdvertising()
    }

    private fun setupGattServer() {
        val requestCharacteristic = BluetoothGattCharacteristic(
            REQUEST_UUID,
            BluetoothGattCharacteristic.PROPERTY_WRITE or
                BluetoothGattCharacteristic.PROPERTY_WRITE_NO_RESPONSE,
            BluetoothGattCharacteristic.PERMISSION_WRITE
        )

        val responseCharacteristic = BluetoothGattCharacteristic(
            RESPONSE_UUID,
            BluetoothGattCharacteristic.PROPERTY_READ or
                BluetoothGattCharacteristic.PROPERTY_NOTIFY,
            BluetoothGattCharacteristic.PERMISSION_READ
        )
        responseCharacteristic.addDescriptor(BluetoothGattDescriptor(
            CCCD_UUID, BluetoothGattDescriptor.PERMISSION_WRITE
        ))
        responseChar = responseCharacteristic

        val identityJSON = """{"peer_id":"$meshId","name":"$deviceName","port":$serverPort}"""
        val identityCharacteristic = BluetoothGattCharacteristic(
            IDENTITY_UUID,
            BluetoothGattCharacteristic.PROPERTY_READ,
            BluetoothGattCharacteristic.PERMISSION_READ
        )
        identityCharacteristic.value = identityJSON.toByteArray()
        identityChar = identityCharacteristic

        val service = BluetoothGattService(
            SERVICE_UUID, BluetoothGattService.SERVICE_TYPE_PRIMARY
        )
        service.addCharacteristic(requestCharacteristic)
        service.addCharacteristic(responseCharacteristic)
        service.addCharacteristic(identityCharacteristic)

        gattServer = bluetoothManager?.openGattServer(context, gattCallback)
        gattServer?.addService(service)
    }

    private fun startAdvertising() {
        val adapter = bluetoothManager?.adapter ?: return
        advertiser = adapter.bluetoothLeAdvertiser ?: return

        val settings = AdvertiseSettings.Builder()
            .setAdvertiseMode(AdvertiseSettings.ADVERTISE_MODE_LOW_LATENCY)
            .setConnectable(true)
            .setTimeout(0)
            .build()

        val data = AdvertiseData.Builder()
            .addServiceUuid(ParcelUuid(SERVICE_UUID))
            .setIncludeDeviceName(true)
            .build()

        advertiser?.startAdvertising(settings, data, advertiseCallback)
    }

    private fun updateBLEIdentity() {
        identityChar?.value =
            """{"peer_id":"$meshId","name":"$deviceName","port":$serverPort}""".toByteArray()
    }

    // ── BLE Chunking (same protocol as MeshBLE.php) ─────

    /** Reassemble chunks. Returns complete message when last chunk arrives. */
    private fun accumulateChunk(deviceAddr: String, data: ByteArray): ByteArray? {
        if (data.isEmpty()) return null
        val flag = data[0]
        val payload = data.sliceArray(1 until data.size)

        return when (flag) {
            CHUNK_FIRST -> {
                // [0x01][4-byte length][payload]
                if (payload.size < 4) return null
                requestBuffers[deviceAddr] = payload.sliceArray(4 until payload.size)
                null
            }
            CHUNK_MIDDLE -> {
                val buf = requestBuffers[deviceAddr] ?: return null
                requestBuffers[deviceAddr] = buf + payload
                null
            }
            CHUNK_LAST -> {
                val buf = requestBuffers.remove(deviceAddr)
                if (buf != null) {
                    buf + payload  // Multi-chunk complete
                } else if (payload.size >= 4) {
                    payload.sliceArray(4 until payload.size)  // Single chunk
                } else {
                    payload
                }
            }
            else -> null
        }
    }

    /** Chunk data for BLE transmission. */
    private fun chunkForBLE(data: ByteArray, mtu: Int = 247): List<ByteArray> {
        val payloadMTU = mtu - 1
        if (data.size <= payloadMTU - 4) {
            // Single chunk
            val len = byteArrayOf(
                (data.size shr 24).toByte(), (data.size shr 16).toByte(),
                (data.size shr 8).toByte(), data.size.toByte()
            )
            return listOf(byteArrayOf(CHUNK_LAST) + len + data)
        }

        val chunks = mutableListOf<ByteArray>()
        val firstPayload = payloadMTU - 4
        val len = byteArrayOf(
            (data.size shr 24).toByte(), (data.size shr 16).toByte(),
            (data.size shr 8).toByte(), data.size.toByte()
        )
        chunks.add(byteArrayOf(CHUNK_FIRST) + len +
            data.sliceArray(0 until minOf(firstPayload, data.size)))
        var offset = firstPayload

        while (offset < data.size) {
            val remaining = data.size - offset
            if (remaining <= payloadMTU) {
                chunks.add(byteArrayOf(CHUNK_LAST) +
                    data.sliceArray(offset until data.size))
                break
            } else {
                chunks.add(byteArrayOf(CHUNK_MIDDLE) +
                    data.sliceArray(offset until offset + payloadMTU))
                offset += payloadMTU
            }
        }
        return chunks
    }

    // ── BLE Request Processing ──────────────────────────

    private fun handleBLERequest(requestData: ByteArray, device: BluetoothDevice) {
        executor.submit {
            try {
                val requestStr = String(requestData)
                val lines = requestStr.split("\r\n")
                val tokens = lines.firstOrNull()?.split(" ") ?: return@submit
                if (tokens.size < 2) return@submit

                val method = tokens[0]
                val path = tokens[1]
                val url = URL("http://127.0.0.1:$serverPort$path")
                val conn = url.openConnection() as HttpURLConnection
                conn.requestMethod = method
                conn.connectTimeout = 15000
                conn.readTimeout = 15000
                conn.setRequestProperty("X-Peer-Id", device.address)
                conn.setRequestProperty("X-Transport", "ble")

                for (line in lines.drop(1)) {
                    if (line.isEmpty()) break
                    val ci = line.indexOf(':')
                    if (ci > 0) conn.setRequestProperty(
                        line.substring(0, ci).trim(),
                        line.substring(ci + 1).trim()
                    )
                }

                val code = conn.responseCode
                val body = (if (code in 200..299) conn.inputStream
                    else conn.errorStream)?.bufferedReader()?.readText() ?: ""

                val response = StringBuilder("HTTP/1.1 $code OK\r\n")
                conn.headerFields?.forEach { (key, values) ->
                    if (key != null) response.append("$key: ${values.joinToString(", ")}\r\n")
                }
                response.append("\r\n$body")

                val responseBytes = response.toString().toByteArray()
                val chunks = chunkForBLE(responseBytes)
                for (chunk in chunks) {
                    responseChar?.value = chunk
                    gattServer?.notifyCharacteristicChanged(device, responseChar, false)
                }
            } catch (e: Exception) {
                val err = "HTTP/1.1 502 Bad Gateway\r\nContent-Length: 0\r\n\r\n".toByteArray()
                responseChar?.value = err
                gattServer?.notifyCharacteristicChanged(device, responseChar, false)
            }
        }
    }

    // ═══════════════════════════════════════════════════
    // BLE Scanner (we discover other Qbix Servers)
    // ═══════════════════════════════════════════════════

    private fun startBLEScanner() {
        val adapter = bluetoothManager?.adapter ?: return
        bleScanner = adapter.bluetoothLeScanner ?: return

        val filter = ScanFilter.Builder()
            .setServiceUuid(ParcelUuid(SERVICE_UUID))
            .build()
        val settings = ScanSettings.Builder()
            .setScanMode(ScanSettings.SCAN_MODE_LOW_LATENCY)
            .build()

        bleScanner?.startScan(listOf(filter), settings, scanCallback)
        println("[BLE] Scanner started")
    }

    private val scanCallback = object : ScanCallback() {
        override fun onScanResult(callbackType: Int, result: ScanResult) {
            val device = result.device
            val name = result.scanRecord?.deviceName ?: device.name ?: "Unknown"
            println("[BLE] Found: $name RSSI: ${result.rssi}")

            // Connect to read their identity characteristic
            if (!connectedDevices.containsKey(device.address)) {
                connectedDevices[device.address] = device
                device.connectGatt(context, false, clientGattCallback)
            }
        }
    }

    /** GATT client callback — reads identity from discovered peripherals. */
    private val clientGattCallback = object : BluetoothGattCallback() {
        override fun onConnectionStateChange(gatt: BluetoothGatt, status: Int, newState: Int) {
            if (newState == BluetoothProfile.STATE_CONNECTED) {
                gatt.discoverServices()
            } else if (newState == BluetoothProfile.STATE_DISCONNECTED) {
                connectedDevices.remove(gatt.device.address)
                gatt.close()
            }
        }

        override fun onServicesDiscovered(gatt: BluetoothGatt, status: Int) {
            val service = gatt.getService(SERVICE_UUID) ?: return
            val idChar = service.getCharacteristic(IDENTITY_UUID) ?: return
            gatt.readCharacteristic(idChar)
        }

        override fun onCharacteristicRead(gatt: BluetoothGatt,
            characteristic: BluetoothGattCharacteristic, status: Int) {
            if (characteristic.uuid == IDENTITY_UUID && status == BluetoothGatt.GATT_SUCCESS) {
                try {
                    val json = JSONObject(String(characteristic.value))
                    val peerId = json.optString("peer_id", "")
                    val name = json.optString("name", "BLE Device")
                    if (peerId.isNotEmpty()) {
                        addPeer(peerId, name, TransportType.BLE, device = gatt.device)
                    }
                } catch (e: Exception) {
                    println("[BLE] Identity parse error: ${e.message}")
                }
            }
            gatt.disconnect()
        }
    }

    // ═══════════════════════════════════════════════════
    // LAN Discovery (Android NSD / mDNS)
    //
    // Discovers Qbix Servers on the local Wi-Fi network.
    // TCP is vastly faster than BLE, so we always prefer it.
    // ═══════════════════════════════════════════════════

    private fun startLANDiscovery() {
        nsdManager = context.getSystemService(Context.NSD_SERVICE) as? NsdManager ?: return

        // Register our service
        val serviceInfo = NsdServiceInfo().apply {
            serviceName = "QbixServer-${meshId.take(8)}"
            serviceType = NSD_SERVICE_TYPE
            port = serverPort
        }

        registrationListener = object : NsdManager.RegistrationListener {
            override fun onServiceRegistered(info: NsdServiceInfo) {
                println("[LAN] Registered: ${info.serviceName}")
            }
            override fun onRegistrationFailed(info: NsdServiceInfo, code: Int) {
                println("[LAN] Registration failed: $code")
            }
            override fun onServiceUnregistered(info: NsdServiceInfo) {}
            override fun onUnregistrationFailed(info: NsdServiceInfo, code: Int) {}
        }
        nsdManager?.registerService(serviceInfo, NsdManager.PROTOCOL_DNS_SD, registrationListener)

        // Discover other services
        discoveryListener = object : NsdManager.DiscoveryListener {
            override fun onServiceFound(info: NsdServiceInfo) {
                if (info.serviceName.startsWith("QbixServer-") &&
                    !info.serviceName.endsWith(meshId.take(8))) {
                    nsdManager?.resolveService(info, resolveListener)
                }
            }
            override fun onServiceLost(info: NsdServiceInfo) {
                println("[LAN] Lost: ${info.serviceName}")
            }
            override fun onDiscoveryStarted(type: String) {
                println("[LAN] Discovery started")
            }
            override fun onDiscoveryStopped(type: String) {}
            override fun onStartDiscoveryFailed(type: String, code: Int) {
                println("[LAN] Discovery failed: $code")
            }
            override fun onStopDiscoveryFailed(type: String, code: Int) {}
        }
        nsdManager?.discoverServices(NSD_SERVICE_TYPE, NsdManager.PROTOCOL_DNS_SD,
            discoveryListener)
    }

    private val resolveListener = object : NsdManager.ResolveListener {
        override fun onResolveFailed(info: NsdServiceInfo, code: Int) {
            println("[LAN] Resolve failed: ${info.serviceName} code=$code")
        }
        override fun onServiceResolved(info: NsdServiceInfo) {
            val host = info.host?.hostAddress ?: return
            val port = info.port
            val address = "http://$host:$port"
            println("[LAN] Resolved: ${info.serviceName} at $address")

            // Fetch their mesh identity over TCP
            executor.submit {
                try {
                    val json = httpGet("$address/Q/sync/identity")
                    val obj = JSONObject(json)
                    val peerId = obj.optString("peer_id", "")
                    val name = obj.optString("name", info.serviceName)
                    if (peerId.isNotEmpty()) {
                        addPeer(peerId, name, TransportType.TCP, address = address)
                    }
                } catch (e: Exception) {
                    println("[LAN] Identity fetch error: ${e.message}")
                }
            }
        }
    }

    // ── GATT Server Callback ────────────────────────────

    private val gattCallback = object : BluetoothGattServerCallback() {
        override fun onConnectionStateChange(device: BluetoothDevice, status: Int, newState: Int) {
            if (newState == BluetoothProfile.STATE_CONNECTED) {
                connectedDevices[device.address] = device
            } else {
                connectedDevices.remove(device.address)
                requestBuffers.remove(device.address)
            }
        }

        override fun onCharacteristicWriteRequest(
            device: BluetoothDevice, requestId: Int,
            characteristic: BluetoothGattCharacteristic,
            preparedWrite: Boolean, responseNeeded: Boolean,
            offset: Int, value: ByteArray
        ) {
            if (characteristic.uuid == REQUEST_UUID) {
                val complete = accumulateChunk(device.address, value)
                if (complete != null) {
                    handleBLERequest(complete, device)
                }
                if (responseNeeded) {
                    gattServer?.sendResponse(
                        device, requestId, BluetoothGatt.GATT_SUCCESS, 0, null)
                }
            }
        }

        override fun onCharacteristicReadRequest(
            device: BluetoothDevice, requestId: Int, offset: Int,
            characteristic: BluetoothGattCharacteristic
        ) {
            gattServer?.sendResponse(
                device, requestId, BluetoothGatt.GATT_SUCCESS,
                offset, characteristic.value)
        }

        override fun onDescriptorWriteRequest(
            device: BluetoothDevice, requestId: Int,
            descriptor: BluetoothGattDescriptor,
            preparedWrite: Boolean, responseNeeded: Boolean,
            offset: Int, value: ByteArray
        ) {
            if (responseNeeded) {
                gattServer?.sendResponse(
                    device, requestId, BluetoothGatt.GATT_SUCCESS, 0, null)
            }
        }
    }

    private val advertiseCallback = object : AdvertiseCallback() {
        override fun onStartSuccess(settings: AdvertiseSettings) {
            println("[BLE] Advertising on port $serverPort")
        }
        override fun onStartFailure(code: Int) {
            println("[BLE] Advertise failed: $code")
        }
    }

    // ── HTTP Helpers ────────────────────────────────────

    private fun httpGet(urlStr: String): String {
        val conn = URL(urlStr).openConnection() as HttpURLConnection
        conn.connectTimeout = 5000
        conn.readTimeout = 5000
        return conn.inputStream.bufferedReader().readText()
    }

    private fun httpPost(urlStr: String, body: String): String {
        val conn = URL(urlStr).openConnection() as HttpURLConnection
        conn.requestMethod = "POST"
        conn.doOutput = true
        conn.connectTimeout = 10000
        conn.readTimeout = 10000
        conn.setRequestProperty("Content-Type", "application/json")
        conn.outputStream.write(body.toByteArray())
        return conn.inputStream.bufferedReader().readText()
    }
}

// ═══════════════════════════════════════════════════════════════════
// Data Types
// ═══════════════════════════════════════════════════════════════════

/** Transport types ordered by priority (best first). */
enum class TransportType {
    TCP,   // LAN / internet — highest bandwidth
    BLE    // Bluetooth LE — 2 Mbps
}

/** Info about a discovered peer. May be reachable over multiple transports. */
data class PeerInfo(
    val peerId: String,
    var name: String,
    val transports: MutableList<TransportType> = mutableListOf(),
    var tcpAddress: String? = null,
    var bleDevice: BluetoothDevice? = null
) {
    fun bestTransport(): TransportType = transports.minByOrNull { it.ordinal } ?: TransportType.BLE
}
