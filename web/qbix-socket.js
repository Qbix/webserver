/**
 * QSocket — tiny WebSocket client for Qbix Server.
 *
 * Usage:
 *   var qs = new QSocket('ws://localhost:8080/ws/chat');
 *
 *   qs.on('chat/message', function(data) {
 *       console.log(data.from + ': ' + data.text);
 *   });
 *
 *   qs.emit('chat/message', {text: 'hello'}, function(ack) {
 *       console.log('Server confirmed:', ack);
 *   });
 *
 *   qs.emit('chat/join', {room: 'lobby'});
 *
 * Protocol (JSON over WebSocket):
 *   Client → Server:  {"event": "...", "data": {...}, "ack": N}
 *   Server → Client:  {"event": "...", "data": {...}}           (broadcast)
 *   Server → Client:  {"ack": N, "data": {...}}                 (callback)
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
                // Flush queued messages
                while (self._queue.length) {
                    self.ws.send(self._queue.shift());
                }
                if (self._handlers['connect']) {
                    self._handlers['connect'].forEach(function (fn) { fn(); });
                }
            };

            self.ws.onmessage = function (e) {
                var msg;
                try { msg = JSON.parse(e.data); } catch (err) { return; }

                // Ack response (callback from server)
                if (msg.ack !== undefined && self._acks[msg.ack]) {
                    self._acks[msg.ack](msg.data);
                    delete self._acks[msg.ack];
                    return;
                }

                // Event broadcast
                if (msg.event && self._handlers[msg.event]) {
                    self._handlers[msg.event].forEach(function (fn) {
                        fn(msg.data);
                    });
                }
            };

            self.ws.onclose = function () {
                if (self._handlers['disconnect']) {
                    self._handlers['disconnect'].forEach(function (fn) { fn(); });
                }
                if (self._reconnect) {
                    self._delay = Math.min(self._delay * 1.5, self._maxDelay);
                    setTimeout(self._connect, self._delay);
                }
            };

            self.ws.onerror = function () {
                self.ws.close();
            };
        };

        self._delay = self._reconnectDelay;
        self._connect();
    }

    /**
     * Listen for an event from the server.
     * Special events: 'connect', 'disconnect'
     */
    QSocket.prototype.on = function (event, fn) {
        if (!this._handlers[event]) this._handlers[event] = [];
        this._handlers[event].push(fn);
        return this;
    };

    /**
     * Remove a listener.
     */
    QSocket.prototype.off = function (event, fn) {
        if (!this._handlers[event]) return this;
        if (!fn) { delete this._handlers[event]; return this; }
        this._handlers[event] = this._handlers[event].filter(function (f) { return f !== fn; });
        return this;
    };

    /**
     * Send an event to the server.
     * Optional callback is invoked when the server acks.
     */
    QSocket.prototype.emit = function (event, data, callback) {
        var msg = { event: event, data: data || {} };
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
     * Close the connection (disables auto-reconnect).
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
