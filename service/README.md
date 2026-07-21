# Running Qbix Server as a Service

## Linux (systemd)

```bash
# Edit paths in the service file
sudo cp service/qbixserver.service /etc/systemd/system/
sudo systemctl daemon-reload

# Start, stop, reload
sudo systemctl start qbixserver
sudo systemctl stop qbixserver
sudo systemctl reload qbixserver    # re-reads config, restarts workers

# Auto-start on boot
sudo systemctl enable qbixserver

# View logs
journalctl -u qbixserver -f
```

## macOS (launchd)

```bash
# Edit paths in the plist file
cp service/com.qbix.server.plist ~/Library/LaunchAgents/

# Start, stop
launchctl load ~/Library/LaunchAgents/com.qbix.server.plist
launchctl unload ~/Library/LaunchAgents/com.qbix.server.plist

# View logs
tail -f /var/log/qbixserver.log
```

For system-wide (not per-user), use `/Library/LaunchDaemons/` instead.

## Manual (any platform)

```bash
# Start in background
./qbixserver.php --root=./web --port=8080 --pid=./qbixserver.pid &

# Stop
./qbixserver.php --stop --pid=./qbixserver.pid

# Reload (graceful restart)
./qbixserver.php --reload --pid=./qbixserver.pid

# Test config
./qbixserver.php -t
```
