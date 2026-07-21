/**
 * QSocket — WebSocket client for Qbix Server.
 *
 * Usage:
 *   var qs = new QSocket('ws://localhost:8080/ws/chat');
 *
 *   // Listen for events from the server
 *   qs.on('chat/message', function(data) {
 *       console.log(data.user + ': ' + data.text);
 *   });
 *
 *   // Send event with callback (server acks with structured data)
 *   qs.emit('chat/message', {text: 'hello'}, function(response) {
 *       // response is whatever the PHP handler set as $result
 *       // arrays, objects, nested structures — all preserved via JSON
 *       console.log('Message #' + response.count);
 *   });
 *
 *   // Send without callback
 *   qs.emit('chat/typing', {user: 'Alice'});
 *
 * Protocol (JSON over WebSocket):
 *   Client → Server:  {"event": "...", "data": {...}, "ack": N}
 *   Server → Client:  {"event": "...", "data": {...}}           (broadcast)
 *   Server → Client:  {"ack": N, "data": {...}}                 (callback)
 *
 * Data is serialized as JSON in both directions. PHP arrays and nested
 * objects map to JS objects/arrays. Callbacks receive the full structured
 * response — strings, numbers, booleans, arrays, nested objects.
 */
(function (root) {
    'use strict';

    function QSocket(url, options) {
        var self = this;
        options = options || {};
        self._handlers = {};
        self._ackId = 0;
        self._acks = {};
        self._queue = [];
        self._reconnect = options.reconnect !== false;
        self._reconnectDelay = options.reconnectDelay || 1000;
        self._maxDelay = options.maxReconnectDelay || 30000;
        self._url = url;

        self._connect = function () {
            self.ws = new WebSocket(url);

            self.ws.onopen = function () {
                self._delay = self._reconnectDelay;
                while (self._queue.length) {
                    self.ws.send(self._queue.shift());
                }
                self._fire('connect');
            };

            self.ws.onmessage = function (e) {
                var msg;
                try { msg = JSON.parse(e.data); } catch (err) { return; }

                // Ack response — invoke the stored callback with full data
                if (msg.ack !== undefined && self._acks[msg.ack]) {
                    self._acks[msg.ack](msg.data);
                    delete self._acks[msg.ack];
                    return;
                }

                // Event broadcast — pass full data to all listeners
                if (msg.event) {
                    self._fire(msg.event, msg.data);
                }
            };

            self.ws.onclose = function () {
                self._fire('disconnect');
                if (self._reconnect) {
                    self._delay = Math.min(self._delay * 1.5, self._maxDelay);
                    setTimeout(self._connect, self._delay);
                }
            };

            self.ws.onerror = function () {
                self.ws.close();
            };
        };

        self._fire = function (event, data) {
            var handlers = self._handlers[event];
            if (!handlers) return;
            for (var i = 0; i < handlers.length; i++) {
                handlers[i](data);
            }
        };

        self._delay = self._reconnectDelay;
        self._connect();
    }

    /**
     * Listen for an event from the server.
     * Callback receives the data object (arrays, nested objects preserved).
     * Special events: 'connect' (no data), 'disconnect' (no data)
     */
    QSocket.prototype.on = function (event, fn) {
        if (!this._handlers[event]) this._handlers[event] = [];
        this._handlers[event].push(fn);
        return this;
    };

    /**
     * Remove listener(s). No fn = remove all for that event.
     */
    QSocket.prototype.off = function (event, fn) {
        if (!this._handlers[event]) return this;
        if (!fn) { delete this._handlers[event]; return this; }
        this._handlers[event] = this._handlers[event].filter(function (f) {
            return f !== fn;
        });
        return this;
    };

    /**
     * Send an event to the server.
     *
     * @param {string} event - Event name (maps to PHP handler)
     * @param {*} data - Any JSON-serializable value: object, array, string, number, boolean, null
     * @param {function} [callback] - Called with the server's response (the PHP handler's $result)
     *
     * Examples:
     *   qs.emit('chat/message', {text: 'hi', tags: ['urgent']}, function(res) { ... });
     *   qs.emit('game/move', {x: 10, y: 20});
     *   qs.emit('ping', null, function(res) { console.log(res.time); });
     */
    QSocket.prototype.emit = function (event, data, callback) {
        var msg = { event: event, data: (data !== undefined ? data : null) };
        if (typeof callback === 'function') {
            msg.ack = ++this._ackId;
            this._acks[msg.ack] = callback;
        }
        var json = JSON.stringify(msg);
        if (this.ws && this.ws.readyState === WebSocket.OPEN) {
            this.ws.send(json);
        } else {
            this._queue.push(json);
        }
        return this;
    };

    /**
     * Close the connection. Disables auto-reconnect.
     */
    QSocket.prototype.close = function () {
        this._reconnect = false;
        if (this.ws) this.ws.close();
    };

    // Export
    if (typeof module !== 'undefined' && module.exports) {
        module.exports = QSocket;
    } else {
        root.QSocket = QSocket;
    }
})(typeof window !== 'undefined' ? window : this);
