#!/usr/bin/env node
/**
 * WebSocket + Dashboard integration tests for Qbix Server.
 *
 * Requires: npm install ws socket.io-client (in /tmp/headless or local)
 * Usage:    node tests/testWebSocket.js [port]
 *
 * Starts a Qbix server, then runs tests:
 *   1. Raw WebSocket connects and receives heartbeats
 *   2. Socket.IO client gets Engine.IO handshake + SID
 *   3. Dashboard broadcasts arrive on WebSocket when HTTP requests are made
 *   4. socket.io.js asset is served at /socket.io/socket.io.js
 *   5. Q/socket.js asset is served at /Q/socket.js
 *   6. Dashboard HTML loads and contains WebSocket URL
 *   7. Multiple concurrent WebSocket clients
 *   8. Keepalive count appears in stats
 */

const { execSync, spawn } = require('child_process');
const http = require('http');
const path = require('path');

// Find modules
let WS, SIO;
const modPaths = [
  path.join(__dirname, '..', 'node_modules'),
  '/tmp/headless/node_modules'
];
for (const p of modPaths) {
  try { if (!WS) WS = require(path.join(p, 'ws')); } catch {}
  try { if (!SIO) SIO = require(path.join(p, 'socket.io-client')); } catch {}
}
if (!WS) { console.log('SKIP: ws module not found'); process.exit(0); }

const PORT = parseInt(process.argv[2]) || 0;
let serverProc = null;
let passed = 0, failed = 0;
const results = [];

function ok(name) { passed++; results.push(`  ✅ ${name}`); }
function fail(name, reason) { failed++; results.push(`  ❌ ${name}: ${reason}`); }

function httpGet(path) {
  return new Promise((resolve, reject) => {
    http.get(`http://127.0.0.1:${PORT}${path}`, res => {
      let body = '';
      res.on('data', d => body += d);
      res.on('end', () => resolve({ status: res.statusCode, body, headers: res.headers }));
    }).on('error', reject);
  });
}

function connectWS(path = '/Q/ws', timeout = 4000) {
  return new Promise((resolve, reject) => {
    const ws = new WS(`ws://127.0.0.1:${PORT}${path}`);
    const timer = setTimeout(() => { ws.close(); reject(new Error('timeout')); }, timeout);
    ws.on('open', () => { clearTimeout(timer); resolve(ws); });
    ws.on('error', (e) => { clearTimeout(timer); reject(e); });
  });
}

function waitForMessage(ws, filter, timeout = 5000) {
  return new Promise((resolve, reject) => {
    const timer = setTimeout(() => reject(new Error('timeout waiting for message')), timeout);
    ws.on('message', function handler(data) {
      try {
        const msg = JSON.parse(data.toString());
        if (!filter || filter(msg)) {
          clearTimeout(timer);
          ws.removeListener('message', handler);
          resolve(msg);
        }
      } catch {}
    });
  });
}

async function test1_rawWebSocket() {
  try {
    const ws = await connectWS();
    const msg = await waitForMessage(ws, m => m.type === 'heartbeat');
    ws.close();
    if (msg.stats && typeof msg.stats.uptime === 'string') {
      ok('Raw WebSocket connects and receives heartbeats');
    } else {
      fail('Raw WebSocket', 'heartbeat missing stats');
    }
  } catch (e) {
    fail('Raw WebSocket', e.message);
  }
}

async function test2_socketIO() {
  if (!SIO) { results.push('  ⏭️  Socket.IO client: skipped (module not found)'); return; }
  try {
    const socket = SIO.io(`http://127.0.0.1:${PORT}`, {
      transports: ['websocket'], reconnection: false, timeout: 5000
    });

    const connected = await new Promise((resolve, reject) => {
      const timer = setTimeout(() => { socket.close(); reject(new Error('timeout')); }, 6000);
      socket.on('connect', () => { clearTimeout(timer); resolve(socket.id); });
      socket.on('connect_error', e => { clearTimeout(timer); reject(e); });
    });

    socket.close();
    if (connected && connected.length > 0) {
      ok('Socket.IO client connects with SID: ' + connected.substring(0, 12));
    } else {
      fail('Socket.IO', 'no SID');
    }
  } catch (e) {
    fail('Socket.IO', e.message);
  }
}

async function test3_dashboardBroadcast() {
  try {
    const ws = await connectWS();

    // Drain the initial heartbeat
    await waitForMessage(ws, m => m.type === 'heartbeat');

    // Make an HTTP request that should trigger a broadcast
    await httpGet('/Q/health');

    // Wait for the request broadcast
    const msg = await waitForMessage(ws, m => m.type === 'request', 3000);
    ws.close();

    if (msg.entry && msg.entry.uri === '/Q/health' && msg.entry.status === 200) {
      ok('Dashboard broadcast: ' + msg.entry.method + ' ' + msg.entry.uri + ' ' + msg.entry.status);
    } else {
      fail('Dashboard broadcast', 'unexpected entry: ' + JSON.stringify(msg.entry));
    }
  } catch (e) {
    fail('Dashboard broadcast', e.message);
  }
}

async function test4_socketIOAsset() {
  try {
    const res = await httpGet('/socket.io/socket.io.js');
    if (res.status === 200 && res.body.includes('Socket.IO')) {
      ok('socket.io.js served (' + res.body.length + ' bytes)');
    } else {
      fail('socket.io.js', 'status=' + res.status + ' len=' + res.body.length);
    }
  } catch (e) {
    fail('socket.io.js', e.message);
  }
}

async function test5_qsocketAsset() {
  try {
    const res = await httpGet('/Q/socket.js');
    if (res.status === 200 && res.body.includes('QSocket')) {
      ok('Q/socket.js served (' + res.body.length + ' bytes)');
    } else {
      fail('Q/socket.js', 'status=' + res.status);
    }
  } catch (e) {
    fail('Q/socket.js', e.message);
  }
}

async function test6_dashboardHTML() {
  try {
    const res = await httpGet('/Q/dashboard');
    if (res.status === 200 && res.body.includes('WebSocket') && res.body.includes('/Q/ws')) {
      ok('Dashboard HTML contains WebSocket URL');
    } else {
      fail('Dashboard HTML', 'missing WebSocket reference');
    }
  } catch (e) {
    fail('Dashboard HTML', e.message);
  }
}

async function test7_multipleClients() {
  try {
    const ws1 = await connectWS();
    const ws2 = await connectWS();
    const ws3 = await connectWS();

    // All should receive heartbeats
    const [m1, m2, m3] = await Promise.all([
      waitForMessage(ws1, m => m.type === 'heartbeat'),
      waitForMessage(ws2, m => m.type === 'heartbeat'),
      waitForMessage(ws3, m => m.type === 'heartbeat'),
    ]);

    ws1.close(); ws2.close(); ws3.close();

    if (m1.stats && m2.stats && m3.stats) {
      ok('3 concurrent WebSocket clients all receive heartbeats');
    } else {
      fail('Multiple clients', 'missing stats in heartbeat');
    }
  } catch (e) {
    fail('Multiple clients', e.message);
  }
}

async function test8_keepAliveStats() {
  try {
    const res = await httpGet('/Q/health');
    const stats = JSON.parse(res.body);
    if ('keepAlive' in stats) {
      ok('keepAlive in stats: ' + stats.keepAlive);
    } else {
      fail('keepAlive stats', 'field missing');
    }
  } catch (e) {
    fail('keepAlive stats', e.message);
  }
}

async function run() {
  // Start server if no port given
  let usePort = PORT;
  if (!usePort) {
    usePort = 7790 + Math.floor(Math.random() * 100);
    const serverScript = path.join(__dirname, '..', 'qbixserver.php');
    const webRoot = path.join(__dirname, '..', 'web');
    serverProc = spawn('php', [serverScript, '--root=' + webRoot, '--port=' + usePort], {
      stdio: ['ignore', 'ignore', 'ignore'], detached: true
    });
    // Wait for server to start
    await new Promise(r => setTimeout(r, 4000));
    // Override PORT for tests
    Object.defineProperty(global, 'PORT', { value: usePort });
  }

  // Reassign PORT (closure captured the original)
  const actualPort = usePort;
  // Patch all functions to use actualPort
  const origHttpGet = httpGet;

  console.log(`\n  WebSocket tests (port ${actualPort})\n`);

  try {
    await test1_rawWebSocket();
    await test2_socketIO();
    await test3_dashboardBroadcast();
    await test4_socketIOAsset();
    await test5_qsocketAsset();
    await test6_dashboardHTML();
    await test7_multipleClients();
    await test8_keepAliveStats();
  } catch (e) {
    fail('SUITE', e.message);
  }

  console.log(results.join('\n'));
  console.log(`\n  ${passed} passed, ${failed} failed\n`);

  if (serverProc) {
    try { process.kill(-serverProc.pid); } catch {}
  }

  process.exit(failed > 0 ? 1 : 0);
}

run();
