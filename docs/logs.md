# Log rotation

The server rotates `access.log` and `error.log` itself. Every other log it writes (the autohost log, the watchdog log, and any file you add) is handled by `Q_WebServer_LogRotate`, which runs about 45 seconds after startup and then every hour:

- **rotate** when the file is bigger than `maxSize`, or when `rotateDays` have passed since the last rotation. The file is renamed to `<name>.<date>.log` (with the time added if that name exists) and a new one starts. A note of when each file was last rotated is kept in `.rotated.json` beside it.
- **archive**: rotated files older than `archiveAfterDays` are compressed to `.gz`.
- **delete**: rotated files and archives older than `deleteAfterDays` are removed.

Only files this class made (`<name>.<YYYY-MM-DD>[-HHMMSS].log[.gz]`) are ever compressed or deleted. The live file is never deleted.

## Config (`Q/webserver/logs`)

| Key | Default | Meaning |
|---|---|---|
| `maxSize` | 20971520 | Bytes before a rotation |
| `rotateDays` | 1 | Days between rotations; 0 for size only |
| `archiveAfterDays` | 2 | Compress rotated files after this many days |
| `deleteAfterDays` | 30 | Delete after this many days; 0 keeps archives |
| `files` | none | More files to manage, `{"name": "/path/to/file.log"}` |
| `managed` | on | Set to `false` to turn all of this off |

## Control panel API

`GET /Q/api/logs/managed` lists the managed files with their sizes, rotated copies and the policy in force. `POST /Q/api/logs/rotate` runs the rotation now instead of waiting for the hour.
