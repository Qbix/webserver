/**
 * QSocket — minimal WebSocket client for Qbix Server.
 * ~80 lines. No dependencies. Plain JSON over bare WebSocket.
 *
 *   var socket = new QSocket('/ws');
 *   socket.on('chat/message', function(data) { ... });
 *   socket.emit('chat/message', {text: 'hi'}, function(res) { ... });
 */
(function(root) {
  'use strict';
  function QSocket(path, opts) {
    opts = opts || {};
    var self = this;
    self._l = {};
    self._h = {};
    self._a = {};
    self._n = 0;
    self._q = [];
    self._rc = opts.reconnect !== false;
    self._d = opts.delay || 1000;
    self._md = opts.maxDelay || 30000;
    self._cd = self._d;

    var loc = root.location || {};
    var proto = (loc.protocol === 'https:') ? 'wss://' : 'ws://';
    self._url = (path.indexOf('ws') === 0) ? path : proto + loc.host + path;

    self._open = function() {
      var ws = self.ws = new WebSocket(self._url);
      ws.onopen = function() {
        self._cd = self._d;
        while (self._q.length) ws.send(self._q.shift());
        self._emit('connect');
      };
      ws.onmessage = function(e) {
        var m; try { m = JSON.parse(e.data); } catch(x) { return; }
        // Ack response
        if (m.ack != null && !m.event && self._a[m.ack]) {
          self._a[m.ack](m.data); delete self._a[m.ack]; return;
        }
        // Server RPC call (event + ack, no pending callback)
        if (m.event && m.ack != null && !self._a[m.ack]) {
          var h = self._h[m.event], r;
          try { r = h ? h(m.data) : null; } catch(x) { r = null; }
          if (r && typeof r.then === 'function') {
            r.then(function(v) { self._ack(m.ack, v); })
             ['catch'](function() { self._ack(m.ack, null); });
          } else {
            self._ack(m.ack, r);
          }
          return;
        }
        if (m.event) self._emit(m.event, m.data);
      };
      ws.onclose = function() {
        self._emit('disconnect');
        if (self._rc) {
          self._cd = Math.min(self._cd * 1.5, self._md);
          setTimeout(self._open, self._cd);
        }
      };
      ws.onerror = function() { ws.close(); };
    };

    self._emit = function(ev, d) {
      var ls = self._l[ev]; if (!ls) return;
      for (var i = 0; i < ls.length; i++) ls[i](d);
    };
    self._ack = function(id, d) {
      self._send(JSON.stringify({ack: id, data: d}));
    };
    self._send = function(s) {
      if (self.ws && self.ws.readyState === 1) self.ws.send(s);
      else self._q.push(s);
    };
    self._open();
  }

  QSocket.prototype.on = function(ev, fn) {
    if (!this._l[ev]) this._l[ev] = [];
    this._l[ev].push(fn); return this;
  };
  QSocket.prototype.off = function(ev, fn) {
    if (!fn) { delete this._l[ev]; return this; }
    if (this._l[ev]) this._l[ev] = this._l[ev].filter(function(f) { return f !== fn; });
    return this;
  };
  QSocket.prototype.emit = function(ev, data, cb) {
    var m = {event: ev, data: data != null ? data : null};
    if (typeof cb === 'function') { m.ack = ++this._n; this._a[m.ack] = cb; }
    this._send(JSON.stringify(m)); return this;
  };
  QSocket.prototype.handle = function(method, fn) {
    this._h[method] = fn; return this;
  };
  QSocket.prototype.close = function() {
    this._rc = false; if (this.ws) this.ws.close();
  };

  if (typeof module !== 'undefined' && module.exports) module.exports = QSocket;
  else root.QSocket = QSocket;
})(typeof window !== 'undefined' ? window : this);
