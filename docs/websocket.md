## 🔌 WebSocket — Process Per Connection

Each WebSocket connection gets **one PHP process**. It stays alive for the entire connection. Static variables persist across messages. When the client disconnects, the process dies — all state wiped.

### How it works

```
Browser: connects to ws://localhost/ws
  → Parent forks a child process for this connection
  → Every message the client sends goes to this child
  → Child runs Q::event('chat/message', ...) for each message
  → Static variables persist between messages (same process!)
  → Client disconnects → child process exits
  → OS reclaims all memory
```

### A simple counter

```php
<?php
// handlers/counter/increment.php function counter_increment(&$params, &$result) {
    static $count = 0;  // persists across messages from THIS client
    $count++;
    $result = ['count' => $count];
}
```

```javascript
// Client — standard socket.io-client import { io } from 'socket.io-client'; const socket = io('http://localhost', {transports: ['websocket']});

socket.emit('counter/increment', {}, (res) => {
    console.log(res.count); // 1
}); socket.emit('counter/increment', {}, (res) => {
    console.log(res.count); // 2 — same process, same static var
});
```

### Authentication

The per-connection process is the natural place for auth. Validate once, store in a static variable, use for every subsequent message:

```php
<?php
// handlers/auth/login.php function auth_login(&$params, &$result) {
    static $user = null;

    if ($user) {
        $result = ['error' => 'already authenticated'];
        return;
    }

    $user = MyApp\Auth::validate($params['data']['token']);
    if (!$user) {
        $result = ['error' => 'invalid token'];
        return;
    }

    $result = ['userId' => $user['id'], 'name' => $user['name']];
}
```

### Joining rooms

A per-connection handler decides when to join a room. This is your access control — the client can't join a room directly, only ask:

```php
<?php
// handlers/chat/join.php function chat_join(&$params, &$result) {
    static $user = null;  // set by auth/login handler (shared static scope)
    $socket = $params['socket']; // Q_Socket instance

    $room = $params['data']['room'] ?? '';
    if (!$room) return;

    // Your access control logic
    if (!MyApp\Rooms::canAccess($user, $room)) {
        $result = ['error' => 'forbidden'];
        return;
    }

    // Pass user info to the room — the room's join handler gets this in $params['data']
    $socket->join("chat/$room", [
        'userId' => $user['id'],
        'name'   => $user['name'],
    ]);
    $result = ['joined' => $room];
}
```

The third argument to `$socket->join()` is forwarded to the room's `join` handler as `$params['data']`. This is how the per-connection handler (which did auth) passes identity to the room process (which doesn't know who anyone is).

Leaving works the same way — call `$socket->leave()` from a handler, or it happens automatically on disconnect:

### Config

Map WebSocket event names to handler files:

```json
{
    "Q": {
        "webserver": {
            "sockets": {
                "events": {
                    "_connect":    "auth/login",
                    "_disconnect": "chat/leave",
                    "chat/join":   "chat/join",
                    "chat/message":"chat/message",
                    "chat/typing": "chat/typing"
                }
            }
        }
    }
}
```

If no mapping is configured, the event name is used directly as the handler path. `_connect` and `_disconnect` are lifecycle events fired automatically.

### The client

```javascript
import { io } from 'socket.io-client'; const socket = io('http://localhost', {transports: ['websocket']});

socket.on('connect', () => {
    socket.emit('auth/login', {token: myToken}, (res) => {
        if (res.userId) {
            socket.emit('chat/join', {room: 'general'});
        }
    });
});

socket.on('chat/message', (data) => {
    console.log(data.user + ': ' + data.text);
});

socket.emit('chat/message', {text: 'hello'}, (res) => {
    console.log('Saved as message #' + res.id);
});
```
### Context objects

Every handler receives context objects in `$params`. Use `extract($params)` to get clean variables:

```php
function chat_message(&$params, &$result) {
    extract($params); // $socket, $event, $data
    $socket->reply(['received' => true]);
}
```

**Per-connection handlers** get `$socket` — a `Q_Socket` instance:

| Method / Property | What it does |
|---|---|
| `$socket->id` | This socket's numeric ID |
| `$socket->reply($data)` | Send to this client (fire and forget) |
| `$socket->send($socketId, $data)` | Send to a specific client |
| `$socket->broadcast($room, $data)` | Send to all clients in a room |
| `$socket->broadcastAll($data)` | Send to ALL connected clients |
| `$socket->join($room, $data)` | Join a room, forwarding `$data` to the room's join handler |
| `$socket->leave($room, $data)` | Leave a room, forwarding `$data` to the room's leave handler |
| `$socket->disconnect()` | Close this connection |
| `$socket->anyMethod($data)` | **RPC** — calls a method on the client, blocks until response (5s timeout) |

**Room handlers** get `$room` — a `Q_Room` instance:

| Method / Property | What it does |
|---|---|
| `$room->name` | Room name (e.g. `'chat/general'`) |
| `$room->socketId` | Current sender's socket ID |
| `$room->params` | Pattern params (e.g. `['room' => 'general']`) |
| `$room->broadcast($data)` | Send to all members (fire and forget) |
| `$room->reply($data)` | Send to the member who sent the current message |
| `$room->send($socketId, $data)` | Send to a specific member |

All send methods (`reply`, `broadcast`, `send`, `broadcastAll`) are **fire and forget** — they queue the message and return immediately. Only `__call` (RPC) blocks.

### Protocol

Two wire formats, auto-detected by path:

**Socket.IO** (connect to `/socket.io/`) — full Socket.IO v5 wire protocol. The server bundles the client JS — no npm needed:

```html
<script src="/socket.io/socket.io.js"></script>
<script>
var socket = io('http://localhost', {transports: ['websocket']}); socket.emit('chat/message', {text: 'hello'}); socket.on('chat/message', function(data) { console.log(data); });
</script>
```

Or use the npm package:

```javascript
import { io } from 'socket.io-client'; const socket = io('http://localhost', {transports: ['websocket']});
```

Acks work both directions. Server→client RPC uses native ack callbacks:

```javascript
socket.emit('game/score', {id: 42}, (response) => console.log(response.rank)); socket.on('getLocation', (data, callback) => callback({lat: 40.7, lng: -74.0}));
```

Supported: events, acks (both directions), namespaces, ping/pong. Not supported: HTTP long-polling, binary attachments.

**Bare WebSocket** (connect to any other path) — plain JSON, no framing. Works with any language's WebSocket library.

The server serves a minimal client at `/Q/socket.js` (~100 lines, no dependencies). Drop it in a `<script>` tag:

```html
<script src="/Q/socket.js"></script>
<script>
var socket = new QSocket('/ws');

socket.on('chat/message', function(data) {
    console.log(data.text);
}); socket.emit('chat/message', {text: 'hello'}, function(res) {
    console.log('sent, id=' + res.id);
});

// Server→client RPC socket.handle('getLocation', function() {
    return {lat: 40.7, lng: -74.0};
});
</script>
```

Same API as `socket.io-client` — `on()`, `emit()`, `handle()`. Auto-reconnect with backoff. Or use raw `WebSocket` directly:

```javascript
const ws = new WebSocket('ws://localhost/ws'); ws.send(JSON.stringify({event: 'chat/message', data: {text: 'hello'}})); ws.send(JSON.stringify({event: 'chat/message', data: {text: 'hi'}, ack: 1}));
```

```python
# Any language — just JSON over WebSocket
import websocket, json ws = websocket.WebSocket() ws.connect("ws://localhost/ws") ws.send(json.dumps({"event": "chat/message", "data": {"text": "hello"}}))
```

Handlers don't know which protocol the client is using — the server translates at the wire level. Same handlers, same rooms, same everything.

### Namespaces

Socket.IO namespaces map to handler path prefixes. The default namespace `/` maps to the root `handlers/` directory:

```
Namespace    Client emit              Handler path            Room "general" ─────────   ────────────             ────────────            ────────────── /           emit('message', ...)     message                 general /chat       emit('message', ...)     chat/message            chat/general /admin      emit('auth', ...)        admin/auth              admin/general
```

```javascript
// Client connects to namespaces const main = io('http://localhost')
;          // default / const chat = io('http://localhost/chat')
;     // /chat const admin = io('http://localhost/admin')
;   // /admin

chat.emit('message', {text: 'hello'});   // → handlers/chat/message.php admin.emit('auth', {token: '...'})
;      // → handlers/admin/auth.php
```

Namespace connect/disconnect handlers are optional. If you define one, it runs as access control. If you don't, the namespace auto-accepts:

```php
<?php
// handlers/admin/connect.php — optional, runs on namespace connect function MyApp_admin_connect(&$params, &$result) {
    extract($params); // $socket, $data
    if (!MyApp\Auth::isAdmin($data['token'] ?? '')) {
        $result = ['error' => 'forbidden'];
        return false; // reject namespace connection
    }
}
```

### Server→Client RPC

PHP handlers can call methods on the client using `$socket->methodName()`. The call blocks until the client responds (5s timeout):

```php
<?php
// handlers/location/check.php function MyApp_location_check(&$params, &$result) {
    extract($params); // $socket, $event, $data

    $location = $socket->getLocation();
    $prefs = $socket->getPreferences(['keys' => ['theme', 'lang']]);

    $result = [
        'lat' => $location['lat'],
        'theme' => $prefs['theme'],
    ];
}
```

Any method name that isn't `reply`, `send`, `broadcast`, `broadcastAll`, `join`, or `leave` goes through `__call` → IPC → WebSocket → client → response.

**With `socket.io-client`** — server→client RPC uses native ack callbacks:

```javascript
const socket = io('http://localhost', {transports: ['websocket']});

socket.on('getLocation', (data, callback) => {
    callback({lat: 40.7, lng: -74.0});
});

socket.on('getPreferences', (data, callback) => {
    callback({theme: 'dark', lang: data.keys});
});
```

**With `/Q/socket.js`** — use `handle()`:

```javascript
var socket = new QSocket('/ws');

socket.handle('getLocation', function() {
    return {lat: 40.7, lng: -74.0};
});

// Async handlers work too socket.handle('getPosition', async function() {
    var pos = await new Promise(function(resolve) {
        navigator.geolocation.getCurrentPosition(resolve);
    });
    return {lat: pos.coords.latitude, lng: pos.coords.longitude};
});
```

**With bare WebSocket** — the client receives `{"event":"getLocation","data":{},"ack":7}` and responds with `{"ack":7,"data":{"lat":40.7}}`.

### App namespacing

When building an app, prefix your handler functions with your app name to avoid collisions. Set the app name in config:

```json
{
    "Q": {
        "app": "Chess"
    }
}
```

```
handlers/game/move.php    →  function Chess_game_move(&$params, &$result) handlers/chat/message.php →  function Chess_chat_message(&$params, &$result) handlers/connect.php      →  function Chess_connect(&$params, &$result)
```

Handler file paths stay the same — the app prefix is only on the function name. Read it at runtime with `Q::app()`. Same for classes — use PHP namespaces:

```php
<?php
// classes/Chess/Game.php namespace Chess; class Game { /* ... */ }
```

If `Q.app` is not set, functions use no prefix: `game_move`, `chat_message`. Small standalone projects don't need it.

### Autoloading

Classes in `classes/` are autoloaded by default — `Chess\Game` or `Chess_Game` both resolve to `classes/Chess/Game.php`. No config needed.

For PSR-4 compliant layouts, configure the namespace mapping:

```json
{
    "Q": {
        "autoload": {
            "psr-4": {
                "App\\": "src/",
                "App\\Models\\": "src/Models/"
            }
        }
    }
}
```

`App\Http\Controller` → `src/Http/Controller.php`. Underscores are literal (PSR-4 compliant). Paths are relative to the project root.

If you use Composer, its autoloader is loaded automatically — `vendor/autoload.php` is included at startup if it exists. Composer's own PSR-4, classmap, and files entries all work. The `Q.autoload` config and Composer coexist: Q's autoloader runs first, Composer catches anything it misses.

The resolution order:

1. `Q.autoload.psr-4` — config-driven PSR-4 mappings 2. `Q.autoload.psr-0` — config-driven PSR-0 mappings (underscores = separators) 3. Internal Q classes — `src/Q/*.php` 4. Project `classes/` directory — the Qbix convention (both `\` and `_` as separators) 5. Composer — `vendor/autoload.php` (if present)

### When to use per-connection

Use per-connection processes for **user-specific state**: authentication, preferences, per-user rate limiting, message history, notification subscriptions. Each user's data lives in their own process and can never leak to another user.

---

## 🔄 Server-Sent Events (SSE)

Scripts that set `Content-Type: text/event-stream` automatically stream their output to the client incrementally instead of buffering the entire response:

```php
<?php
Q_Response::header('Content-Type: text/event-stream');
Q_Response::header('Cache-Control: no-cache');

for ($i = 1;
$i <= 100;
$i++) {
    echo "data: " . json_encode(['count' => $i, 'time' => date('H:i:s')]) . "\n\n";
    @ob_flush();
    flush();
    sleep(1);
}
```

Three triggers activate streaming mode (any one is sufficient):

| Trigger | How |
|---|---|
| `Content-Type: text/event-stream` | Auto-detected from response headers |
| `X-Accel-Buffering: no` | Nginx convention, also auto-detected |
| `Q_Response::setStreaming(true)` | Explicit API call |

The server sends HTTP headers immediately with `Transfer-Encoding: chunked`, then writes each `flush()` output as a chunked frame directly to the client socket. Non-streaming responses are completely unaffected — the detection adds zero overhead (the callback only fires on explicit `ob_flush()`, not per byte).

This is useful for AI token streaming (proxying LLM APIs to the browser), real-time logs, progress indicators, and any long-running response where the client should see partial results before the script finishes.

---

## 🏠 Rooms — Process Per Room

For use cases where multiple connections need **shared in-memory state** — chat messages, game positions, cursor aggregation, live vote tallies — use room processes.

One process per active room. All members' messages go to the same process. State is shared across all of them. When the last member leaves, the process dies.

### The lifecycle

```
1. Client A's handler calls $socket->join('chat/general', ['userId'=>1, 'name'=>'Alice']) 2. Parent sees 'chat/general' matches pattern 'chat/$room' 3. Parent forks a room process → init handler fires 4. Parent sends _join to room → join handler fires (with socketId + data) 5. Client B joins the same room → join fires again (no new fork) 6. Both clients' messages are forwarded to the room process 7. Client A disconnects → leave fires (data is empty — unplanned disconnect) 8. Client B disconnects → leave fires → room is empty 9. destroy fires → room process exits
```

The client never talks to the room process directly. Per-connection handlers call `$socket->join()` — that's the gateway. Access control lives there. User identity flows through the third argument.

### Config

```json
{
    "Q": {
        "webserver": {
            "sockets": {
                "rooms": {
                    "chat/$room":  {"handler": "chat/room"},
                    "game/$id":    {"handler": "game/room", "tick": 100},
                    "collab/$doc": {"handler": "collab/room", "tick": 50}
                }
            }
        }
    }
}
```

The pattern uses `$name` placeholders — `chat/$room` matches `chat/general`, `chat/dev`, etc. The `tick` option (in ms) fires `tick` events on a timer, even when no messages arrive.

The `handler` value is a path prefix. Each event dispatches to its own handler file under that prefix — just like HTTP handlers:

```
"chat/$room": {"handler": "chat/room"}

handlers/chat/room/ ├── init.php          ← room created (first user joins) ├── join.php          ← user enters ├── leave.php         ← user exits or disconnects ├── tick.php          ← timer fired (if configured) ├── destroy.php       ← room shutting down (last user left) ├── message.php       ← "message" event from a member └── typing.php        ← "typing" event from a member
```

Same pattern as HTTP: one file per event, function name matches the path.

### Room events

| Event | Handler file | `$params` has |
|---|---|---|
| `_init` | `handler/init.php` | `room`, `event`, `data` |
| `_join` | `handler/join.php` | `room`, `event`, `data` (from `$socket->join()`) |
| `_leave` | `handler/leave.php` | `room`, `event`, `data` (from `$socket->leave()`, or empty on disconnect) |
| `_tick` | `handler/tick.php` | `room`, `event`, `data` |
| `_destroy` | `handler/destroy.php` | `room`, `event`, `data` |
| *user event* | `handler/eventname.php` | `room`, `event`, `data` |

### Example: chat room handlers

```php
<?php
// handlers/chat/room/join.php function chat_room_join(&$params, &$result) {
    $room   = $params['room']; // Q_Room instance
    $sid    = $room->socketId;
    $userId = $params['data']['userId'] ?? null;
    $name   = $params['data']['name'] ?? 'anon';

    ChatRoom::$names[$sid] = $name;

    // Track multiple sockets per user (tabs, devices)
    $isNew = true;
    if ($userId) {
        if (!isset(ChatRoom::$users[$userId])) ChatRoom::$users[$userId] = [];
        $isNew = empty(ChatRoom::$users[$userId]);
        ChatRoom::$users[$userId][$sid] = true;
    }

    // Send history to the new socket
    $room->reply([
        'event' => 'chat/history',
        'data'  => ['messages' => ChatRoom::$history],
    ]);

    if ($isNew) {
        $room->broadcast([
            'event' => 'chat/joined',
            'data'  => ['name' => $name],
        ]);
    }
}
```

```php
<?php
// handlers/chat/room/message.php function chat_room_message(&$params, &$result) {
    $room = $params['room'];
    $name = ChatRoom::$names[$room->socketId] ?? 'anon';
    $text = $params['data']['text'] ?? '';
    if (!$text) return;

    $msg = ['name' => $name, 'text' => $text, 'time' => date('c')];
    ChatRoom::$history[] = $msg;
    if (count(ChatRoom::$history) > 50) array_shift(ChatRoom::$history);

    $room->broadcast([
        'event' => 'chat/message',
        'data'  => $msg,
    ]);
    $result = ['sent' => true];
}
```

```php
<?php
// handlers/chat/room/leave.php function chat_room_leave(&$params, &$result) {
    $room = $params['room'];
    $sid  = $room->socketId;
    $name = ChatRoom::$names[$sid] ?? 'anon';
    unset(ChatRoom::$names[$sid]);

    $reallyGone = true;
    foreach (ChatRoom::$users as $uid => &$sockets) {
        if (isset($sockets[$sid])) {
            unset($sockets[$sid]);
            if (!empty($sockets)) $reallyGone = false;
            else unset(ChatRoom::$users[$uid]);
            break;
        }
    }
    if ($reallyGone) {
        $room->broadcast([
            'event' => 'chat/left',
            'data'  => ['name' => $name],
        ]);
    }
}
```

```php
<?php
// classes/ChatRoom.php — static properties for room state
// Preloaded into the parent process, shared via COW.
// Each room process gets its own copy-on-write fork —
// static properties start fresh and accumulate room-specific state.
// When the room process dies, everything is reclaimed. No cleanup needed.
class ChatRoom {
    static $users = [];    // userId => [socketId => true, ...]
    static $names = [];    // socketId => name
    static $history = [];  // recent messages
}
```

Why class statics instead of `static` variables inside functions? Because each handler is now a separate file. A `static $users` in `join.php` wouldn't be visible in `leave.php`. Class statics (or globals) are shared across all handlers in the same room process.

Copy-on-write handles the rest: the parent's `ChatRoom::$users` starts as `[]`. When a room process forks and writes to it, only that room's pages are copied. When the room dies, the OS reclaims everything. No `unset()`, no destructors, no cleanup.

### Example: game with tick timer

```php
<?php
// handlers/game/room/join.php function game_room_join(&$params, &$result) {
    $room = $params['room'];
    GameRoom::$players[$room->socketId] = [
        'x' => 0, 'y' => 0, 'hp' => 100,
    ];
    $room->reply([
        'event' => 'game/state',
        'data'  => ['players' => GameRoom::$players],
    ]);
}
```

```php
<?php
// handlers/game/room/move.php — client sends "move" event function game_room_move(&$params, &$result) {
    $room = $params['room'];
    GameRoom::$players[$room->socketId]['x'] = $params['data']['x'];
    GameRoom::$players[$room->socketId]['y'] = $params['data']['y'];
    $result = ['ok' => true];
}
```

```php
<?php
// handlers/game/room/tick.php — called every 100ms function game_room_tick(&$params, &$result) {
    $room = $params['room'];
    GameRoom::$tick++;
    $room->broadcast([
        'event' => 'game/state',
        'data'  => ['players' => GameRoom::$players, 'tick' => GameRoom::$tick],
    ]);
}
```

```php
<?php
// handlers/game/room/leave.php function game_room_leave(&$params, &$result) {
    unset(GameRoom::$players[$params['room']->socketId]);
}
```

```php
<?php
// classes/GameRoom.php class GameRoom {
    static $players = [];
    static $tick = 0;
}
```

### Per-connection vs rooms

Both use the same handler pattern, same `Q_Socket` API, same directory structure.

| Use case | Model | Why |
|---|---|---|
| Auth, user prefs | Per-connection | Private to each user |
| Chat messages | Room | All members see all messages |
| Game state | Room + tick | Shared positions, periodic broadcast |
| Typing indicators | Either | Stateless — just relay |
| Notifications | Per-connection | User-specific subscriptions |
| Collaborative editing | Room + tick | Shared document state |
| Live voting/polling | Room | Shared tally, instant broadcast |

---

## 📖 Complete Example: Chat App With Rooms

All three models in one project. HTTP handles pages and login. Per-connection WebSocket handles auth and room joining. Room processes handle the actual chat.

### Project structure

```
chat/ ├── qbixserver.php ├── config/ │   └── server.json ├── web/ │   ├── index.html              ← static: the chat UI │   └── api/ │   └── api/ │       ├── messages.php        ← HTTP: GET recent messages from DB │       └── login.php           ← HTTP: POST authenticate, return token ├── classes/ │   ├── Chat/ │   │   ├── Auth.php            ← shared: token validation │   │   └── Messages.php        ← shared: DB read/write │   └── ChatRoom.php            ← room state: static properties └── handlers/
    ├── auth/
    │   └── login.php           ← per-connection: authenticate
    ├── chat/
    │   ├── join.php            ← per-connection: access control + join room
    │   └── room/
    │       ├── join.php        ← room: new member arrived
    │       ├── message.php     ← room: broadcast a message
    │       ├── typing.php      ← room: relay typing indicator
    │       └── leave.php       ← room: member left
    └── user/
        └── disconnect.php      ← per-connection: cleanup
```

### Config

```json
{
    "Q": {
        "webserver": {
            "sockets": {
                "events": {
                    "_connect":    "auth/login",
                    "_disconnect": "user/disconnect",
                    "chat/join":   "chat/join"
                },
                "rooms": {
                    "chat/$room": {"handler": "chat/room"}
                }
            }
        }
    }
}
```

Note: `message`, `typing` are NOT in the events map. Once a user joins a room, their messages are forwarded directly to the room process and dispatched as `chat/room/message`, `chat/room/typing`, etc.

### Per-connection handlers

```php
<?php
// handlers/auth/login.php — authenticate on connect function auth_login(&$params, &$result) {
    $token = $params['data']['token'] ?? '';
    $user = Chat\Auth::validateToken($token);
    if (!$user) {
        $result = ['error' => 'invalid token'];
        return;
    }
    // Store for later use by chat/join (same process, shared globals)
    $GLOBALS['user'] = $user;
    $result = ['userId' => $user['id'], 'name' => $user['name']];
}
```

```php
<?php
// handlers/chat/join.php — access control, then join room function chat_join(&$params, &$result) {
    $socket = $params['socket']; // Q_Socket instance
    $user = $GLOBALS['user'] ?? null;
    if (!$user) {
        $result = ['error' => 'not authenticated'];
        return;
    }
    $room = $params['data']['room'] ?? 'general';

    // Pass user identity to the room process
    $socket->join("chat/$room", [
        'userId' => $user['id'],
        'name'   => $user['name'],
    ]);
    $result = ['joined' => $room];
}
```

### Room handlers

```php
<?php
// handlers/chat/room/join.php function chat_room_join(&$params, &$result) {
    $room   = $params['room']; // Q_Room instance
    $sid    = $room->socketId;
    $userId = $params['data']['userId'] ?? null;
    $name   = $params['data']['name'] ?? 'anon';
    ChatRoom::$names[$sid] = $name;

    $isNew = true;
    if ($userId) {
        if (!isset(ChatRoom::$users[$userId])) ChatRoom::$users[$userId] = [];
        $isNew = empty(ChatRoom::$users[$userId]);
        ChatRoom::$users[$userId][$sid] = true;
    }

    $room->reply([
        'event' => 'chat/history',
        'data'  => ['messages' => ChatRoom::$history],
    ]);
    if ($isNew) {
        $room->broadcast([
            'event' => 'chat/joined',
            'data'  => ['name' => $name],
        ]);
    }
}
```

```php
<?php
// handlers/chat/room/message.php function chat_room_message(&$params, &$result) {
    $room = $params['room'];
    $name = ChatRoom::$names[$room->socketId] ?? 'anon';
    $text = $params['data']['text'] ?? '';
    if (!$text) return;

    $msg = ['name' => $name, 'text' => $text, 'time' => date('c')];
    ChatRoom::$history[] = $msg;
    if (count(ChatRoom::$history) > 50) array_shift(ChatRoom::$history);

    Chat\Messages::save($name, $text, $room->name);

    $room->broadcast([
        'event' => 'chat/message',
        'data'  => $msg,
    ]);
    $result = ['sent' => true];
}
```

```php
<?php
// handlers/chat/room/leave.php function chat_room_leave(&$params, &$result) {
    $room = $params['room'];
    $sid  = $room->socketId;
    $name = ChatRoom::$names[$sid] ?? 'anon';
    unset(ChatRoom::$names[$sid]);

    $reallyGone = true;
    foreach (ChatRoom::$users as $uid => &$sockets) {
        if (isset($sockets[$sid])) {
            unset($sockets[$sid]);
            if (!empty($sockets)) $reallyGone = false;
            else unset(ChatRoom::$users[$uid]);
            break;
        }
    }
    if ($reallyGone) {
        $room->broadcast([
            'event' => 'chat/left',
            'data'  => ['name' => $name],
        ]);
    }
}
```

### The client

```javascript
import { io } from 'socket.io-client'; const socket = io('http://localhost', {transports: ['websocket']});

socket.on('connect', () => {
    socket.emit('auth/login', {token: myToken}, (res) => {
        if (res.userId) socket.emit('chat/join', {room: 'general'});
    });
});

socket.on('chat/history', (data) => {
    data.messages.forEach(renderMessage);
}); socket.on('chat/message', (data) => {
    renderMessage(data);
}); socket.on('chat/joined', (data) => {
    showNotice(data.name + ' joined');
}); socket.on('chat/left', (data) => {
    showNotice(data.name + ' left');
});

document.getElementById('send').onclick = () => {
    socket.emit('message', {text: input.value});
};
```

### The three models in action

```
HTTP:           GET /api/messages   → fork → query DB → respond → die Per-connection: auth/login          → validate token → store in $GLOBALS
                chat/join           → check access → $socket->join() with user data
Room:           chat/room/join      → ChatRoom::$users, $names, $history
                chat/room/message   → broadcast to all, persist to DB
                chat/room/leave     → multi-tab aware departure
```

### Run it

```bash
php qbixserver.php
```

One command. Static files, REST API, authentication, access-controlled rooms, multi-tab awareness, and shared real-time chat — all from one PHP server.

---

---
[← Back to README](../README.md)

