package com.qbix.server

import android.app.*
import android.content.Intent
import android.os.IBinder
import androidx.core.app.NotificationCompat
import com.qbix.server.transport.TransportBridge

/**
 * Foreground Service that keeps the PHP server and BLE transport alive
 * when the app is in the background.
 *
 * Android requires a persistent notification for foreground services.
 * The notification shows the server status and port.
 *
 * Unlike iOS, Android foreground services run indefinitely — the server
 * stays accessible to other devices on the network and via Bluetooth
 * even when the user switches to another app.
 */
class QbixServerService : Service() {

    companion object {
        const val CHANNEL_ID = "qbix_server_channel"
        const val NOTIFICATION_ID = 1
        const val EXTRA_PORT = "port"
    }

    private var transportBridge: TransportBridge? = null

    override fun onCreate() {
        super.onCreate()
        createNotificationChannel()
    }

    override fun onStartCommand(intent: Intent?, flags: Int, startId: Int): Int {
        val port = intent?.getIntExtra(EXTRA_PORT, 8080) ?: 8080

        val notification = NotificationCompat.Builder(this, CHANNEL_ID)
            .setContentTitle("Qbix Server")
            .setContentText("Running on port $port")
            .setSmallIcon(android.R.drawable.ic_dialog_info)
            .setOngoing(true)
            .setPriority(NotificationCompat.PRIORITY_LOW)
            .setCategory(NotificationCompat.CATEGORY_SERVICE)
            .build()

        startForeground(NOTIFICATION_ID, notification)

        // Start BLE transport bridge
        transportBridge = TransportBridge(this, port)
        transportBridge?.start()

        // START_STICKY: restart the service if the OS kills it
        return START_STICKY
    }

    override fun onDestroy() {
        transportBridge?.stop()
        super.onDestroy()
    }

    override fun onBind(intent: Intent?): IBinder? = null

    private fun createNotificationChannel() {
        val channel = NotificationChannel(
            CHANNEL_ID,
            "Qbix Server",
            NotificationManager.IMPORTANCE_LOW
        ).apply {
            description = "Keeps the PHP server running in the background"
            setShowBadge(false)
        }
        val notificationManager = getSystemService(NotificationManager::class.java)
        notificationManager?.createNotificationChannel(channel)
    }
}
