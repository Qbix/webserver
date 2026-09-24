import AVFoundation

/// Keeps the app alive in the background using a silent AVAudioEngine session.
///
/// iOS suspends apps shortly after backgrounding. This starts a silent audio
/// buffer loop with MixWithOthers — the OS sees an active audio session and
/// keeps the process running. The user hears nothing; any music already
/// playing continues undisturbed.
///
/// Requires UIBackgroundModes: [audio] in Info.plist.
///
/// Used by PocketServer, Mob framework, Dala, and other App Store-approved apps.
class BackgroundKeepAlive {
    static let shared = BackgroundKeepAlive()

    private var engine: AVAudioEngine?
    private var player: AVAudioPlayerNode?
    private var isRunning = false

    /// Start the silent audio session. Idempotent — safe to call multiple times.
    func start() {
        guard !isRunning else { return }

        let engine = AVAudioEngine()
        let player = AVAudioPlayerNode()
        engine.attach(player)

        let format = AVAudioFormat(
            standardFormatWithSampleRate: 44100, channels: 1)!
        let buffer = AVAudioPCMBuffer(
            pcmFormat: format, frameCapacity: 4410)!  // 0.1s of silence
        buffer.frameLength = buffer.frameCapacity
        // Buffer is already zero-filled — silence

        engine.connect(player, to: engine.mainMixerNode, format: format)

        let session = AVAudioSession.sharedInstance()
        do {
            try session.setCategory(.playback, options: .mixWithOthers)
            try session.setActive(true)
            try engine.start()
            player.play()
            player.scheduleBuffer(buffer, at: nil, options: .loops)
        } catch {
            print("[KeepAlive] Failed to start: \(error)")
            return
        }

        self.engine = engine
        self.player = player
        self.isRunning = true

        // Handle audio session interruptions (phone calls, other audio apps)
        NotificationCenter.default.addObserver(
            self,
            selector: #selector(handleInterruption),
            name: AVAudioSession.interruptionNotification,
            object: session)

        print("[KeepAlive] Started silent audio session")
    }

    /// Stop the silent audio session. The OS may suspend the app.
    func stop() {
        guard isRunning else { return }
        player?.stop()
        engine?.stop()
        try? AVAudioSession.sharedInstance().setActive(false)
        engine = nil
        player = nil
        isRunning = false
        NotificationCenter.default.removeObserver(self)
        print("[KeepAlive] Stopped")
    }

    @objc private func handleInterruption(_ notification: Notification) {
        guard let info = notification.userInfo,
              let typeValue = info[AVAudioSessionInterruptionTypeKey] as? UInt,
              let type = AVAudioSession.InterruptionType(rawValue: typeValue)
        else { return }

        switch type {
        case .began:
            // Another audio source took over (phone call, Siri, etc.)
            // The engine stops automatically. The OS keeps us alive because
            // the interrupting audio holds the session.
            print("[KeepAlive] Interrupted (paused)")

        case .ended:
            // Interruption ended — restart the silent session
            let options = info[AVAudioSessionInterruptionOptionKey] as? UInt ?? 0
            if AVAudioSession.InterruptionOptions(rawValue: options)
                .contains(.shouldResume) {
                try? engine?.start()
                player?.play()
                print("[KeepAlive] Resumed after interruption")
            }

        @unknown default:
            break
        }
    }
}
