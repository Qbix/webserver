#!/usr/bin/env node
/**
 * WebSocket, Socket.IO, and Rooms integration tests.
 *
 * Tests:
 *  1. Raw WebSocket connects, receives heartbeats
 *  2. Socket.IO handshake (Engine.IO OPEN + SIO CONNECT)
 *  3. Socket.IO event with ack response
 *  4. Engine.IO ping/pong
 *  5. socket.io.js + Q/socket.js assets served
 *  6. Dashboard HTML + WebSocket URL
 *  7. Dashboard live broadcast on HTTP request
 *  8. Multiple concurrent WebSocket clients
 *  9. keepAlive in /Q/health stats
 * 10. Room: join + broadcast to members
 * 11. Room: message broadcast to all members
 * 12. favicon.ico served
 */

const { spawn } = require('child_process');
const http = require('http');
const path = require('path');

let WS, SIO;
for (const p of [path.join(__dirname,'..','node_modules'),'/tmp/headless/node_modules']) {
  try { if (!WS) WS = require(path.join(p, 'ws')); } catch {}
  try { if (!SIO) SIO = require(path.join(p, 'socket.io-client')); } catch {}
}
if (!WS) { console.log('SKIP: ws module not found'); process.exit(0); }

const PORT = parseInt(process.argv[2]) || 7790 + Math.floor(Math.random()*100);
let passed = 0, failed = 0;
const results = [];
function ok(name) { passed++; results.push(`  ✅ ${name}`); }
function fail(name, reason) { failed++; results.push(`  ❌ ${name}: ${reason}`); }

function httpGet(p) {
  return new Promise((res, rej) => {
    http.get(`http://127.0.0.1:${PORT}${p}`, r => {
      let b=''; r.on('data',d=>b+=d); r.on('end',()=>res({status:r.statusCode,body:b,headers:r.headers}));
    }).on('error', rej);
  });
}

function connectWS(urlPath='/Q/ws', timeout=4000) {
  return new Promise((res, rej) => {
    const ws = new WS(`ws://127.0.0.1:${PORT}${urlPath}`);
    const t = setTimeout(()=>{ws.close();rej(new Error('timeout'))}, timeout);
    ws.on('open', ()=>{clearTimeout(t);res(ws)});
    ws.on('error', e=>{clearTimeout(t);rej(e)});
  });
}

function waitMsg(ws, filter, timeout=5000) {
  return new Promise((res, rej) => {
    const t = setTimeout(()=>rej(new Error('msg timeout')), timeout);
    function handler(data) {
      try { const m = JSON.parse(data.toString());
        if (!filter || filter(m)) { clearTimeout(t); ws.removeListener('message',handler); res(m); }
      } catch {}
    }
    ws.on('message', handler);
  });
}

// Socket.IO helper: connect and return {ws, sid, send, waitAck, waitEvent, close}
function sioConnect(timeout=5000) {
  return new Promise((res, rej) => {
    const ws = new WS(`ws://127.0.0.1:${PORT}/socket.io/?EIO=4&transport=websocket`);
    const t = setTimeout(()=>{ws.close();rej(new Error('sio timeout'))}, timeout);
    let ackId = 0;
    const ackCallbacks = {};
    const eventQueue = [];
    const eventWaiters = [];

    ws.on('message', d => {
      const s = d.toString();
      if (s[0]==='0') ws.send('40'); // EIO open → SIO connect
      else if (s[0]==='2') ws.send('3'); // ping→pong
      else if (s.startsWith('40{')) {
        const h = JSON.parse(s.substring(2));
        clearTimeout(t);
        res({
          ws, sid: h.sid,
          emit(event, data) { ws.send(`42["${event}",${JSON.stringify(data)}]`); },
          emitAck(event, data) {
            return new Promise((resolve, reject) => {
              const id = ++ackId;
              ackCallbacks[id] = resolve;
              ws.send(`42${id}["${event}",${JSON.stringify(data)}]`);
              setTimeout(()=>{delete ackCallbacks[id]; reject(new Error('ack timeout'))}, 4000);
            });
          },
          waitEvent(name, ms=4000) {
            // Check queue first
            const idx = eventQueue.findIndex(e => !name || e.name === name);
            if (idx >= 0) return Promise.resolve(eventQueue.splice(idx, 1)[0]);
            return new Promise((resolve, reject) => {
              const timer = setTimeout(()=>reject(new Error('event timeout: '+name)), ms);
              eventWaiters.push({name, resolve, reject, timer});
            });
          },
          close() { ws.close(); }
        });
      } else if (s[0]==='4' && s[1]==='3') {
        const m = s.substring(2).match(/^(\d+)(.*)/);
        if (m && ackCallbacks[m[1]]) {
          ackCallbacks[m[1]](JSON.parse(m[2]));
          delete ackCallbacks[m[1]];
        }
      } else if (s[0]==='4' && s[1]==='2') {
        try {
          const arr = JSON.parse(s.substring(2));
          const evt = {name: arr[0], data: arr[1]};
          const wi = eventWaiters.findIndex(w => !w.name || w.name === evt.name);
          if (wi >= 0) {
            clearTimeout(eventWaiters[wi].timer);
            eventWaiters[wi].resolve(evt);
            eventWaiters.splice(wi, 1);
          } else {
            eventQueue.push(evt);
          }
        } catch(e) {}
      }
    });
    ws.on('error', e=>{clearTimeout(t);rej(e)});
  });
}

async function test1() {
  try {
    const ws = await connectWS();
    const msg = await waitMsg(ws, m=>m.type==='heartbeat');
    ws.close();
    msg.stats && typeof msg.stats.uptime==='string'
      ? ok('Raw WebSocket: heartbeat received')
      : fail('Raw WebSocket', 'bad heartbeat');
  } catch(e) { fail('Raw WebSocket', e.message); }
}

async function test2() {
  try {
    const c = await sioConnect();
    c.sid ? ok('Socket.IO handshake: sid=' + c.sid) : fail('SIO handshake', 'no sid');
    c.close();
  } catch(e) { fail('SIO handshake', e.message); }
}

async function test3() {
  try {
    const c = await sioConnect();
    const [ack1, ack2] = await Promise.all([
      c.emitAck('echo', {text: 'hello'}),
      c.emitAck('ping', {}),
    ]);
    c.close();
    if (ack1[0]?.echo === 'hello' && ack2[0]?.pong === true)
      ok('Socket.IO events with ack: echo + ping');
    else fail('SIO ack', JSON.stringify({ack1,ack2}));
  } catch(e) { fail('SIO ack', e.message); }
}

async function test4() {
  try {
    const ws = await connectWS('/socket.io/?EIO=4&transport=websocket');
    // Send EIO ping
    await new Promise(r => { ws.on('message', d => { if(d.toString()[0]==='0') r(); }); });
    ws.send('2'); // ping
    const pong = await new Promise((res,rej) => {
      const t=setTimeout(()=>rej(new Error('no pong')),2000);
      ws.on('message', d => { if(d.toString()==='3'){clearTimeout(t);res(true)} });
    });
    ws.close();
    pong ? ok('Engine.IO ping/pong') : fail('EIO ping', 'no pong');
  } catch(e) { fail('EIO ping', e.message); }
}

async function test5() {
  try {
    const [r1,r2] = await Promise.all([httpGet('/socket.io/socket.io.js'), httpGet('/Q/socket.js')]);
    r1.status===200 && r1.body.includes('Socket.IO') && r2.status===200 && r2.body.includes('QSocket')
      ? ok('Assets: socket.io.js (' + r1.body.length + 'b) + Q/socket.js (' + r2.body.length + 'b)')
      : fail('Assets', `io=${r1.status} qs=${r2.status}`);
  } catch(e) { fail('Assets', e.message); }
}

async function test6() {
  try {
    const r = await httpGet('/Q/dashboard');
    r.status===200 && r.body.includes('/Q/ws')
      ? ok('Dashboard HTML with WebSocket URL')
      : fail('Dashboard', 'status='+r.status);
  } catch(e) { fail('Dashboard', e.message); }
}

async function test7() {
  try {
    const ws = await connectWS();
    // Small delay to let initial heartbeat pass
    await new Promise(r => setTimeout(r, 500));
    await httpGet('/Q/health');
    // Wait for a 'request' type message (ignore heartbeats)
    const msg = await waitMsg(ws, m=>m.type==='request', 5000);
    ws.close();
    msg.entry?.uri==='/Q/health' && msg.entry?.status===200
      ? ok('Dashboard broadcast: ' + msg.entry.method + ' ' + msg.entry.uri)
      : fail('Broadcast', JSON.stringify(msg.entry));
  } catch(e) { fail('Broadcast', e.message); }
}

async function test8() {
  try {
    const [ws1,ws2,ws3] = await Promise.all([connectWS(),connectWS(),connectWS()]);
    const [m1,m2,m3] = await Promise.all([
      waitMsg(ws1,m=>m.type==='heartbeat'), waitMsg(ws2,m=>m.type==='heartbeat'),
      waitMsg(ws3,m=>m.type==='heartbeat')]);
    ws1.close(); ws2.close(); ws3.close();
    m1.stats && m2.stats && m3.stats
      ? ok('3 concurrent WebSocket clients')
      : fail('Multi WS', 'missing stats');
  } catch(e) { fail('Multi WS', e.message); }
}

async function test9() {
  try {
    const r = await httpGet('/Q/health');
    const s = JSON.parse(r.body);
    'keepAlive' in s ? ok('keepAlive in /Q/health: ' + s.keepAlive)
      : fail('keepAlive', 'field missing');
  } catch(e) { fail('keepAlive', e.message); }
}

async function test10() {
  try {
    const alice = await sioConnect();
    const ack = await alice.emitAck('chat/join', {room:'general',name:'Alice'});
    if (!ack[0]?.joined) { fail('Room join', 'no ack: '+JSON.stringify(ack)); alice.close(); return; }
    // Should also get a broadcast event
    const evt = await alice.waitEvent('chat/joined', 3000);
    alice.close();
    evt.data?.name==='Alice' && evt.data?.members===1
      ? ok('Room join: Alice joined, members=' + evt.data.members)
      : fail('Room join', JSON.stringify(evt));
  } catch(e) { fail('Room join', e.message); }
}

async function test11() {
  try {
    const alice = await sioConnect();
    await alice.emitAck('chat/join', {room:'test',name:'Alice'});
    await alice.waitEvent('chat/joined'); // drain join broadcast

    const bob = await sioConnect();
    await bob.emitAck('chat/join', {room:'test',name:'Bob'});
    // Both get join broadcast
    await alice.waitEvent('chat/joined');
    await bob.waitEvent('chat/joined');

    // Alice sends message
    const ack = await alice.emitAck('chat/message', {text:'Hi Bob!'});
    // Both should get the broadcast
    const [m1, m2] = await Promise.all([
      alice.waitEvent('chat/message', 3000),
      bob.waitEvent('chat/message', 3000)
    ]);
    alice.close(); bob.close();
    m1.data?.text==='Hi Bob!' && m2.data?.text==='Hi Bob!'
      ? ok('Room broadcast: both members received message')
      : fail('Room broadcast', JSON.stringify({m1:m1.data,m2:m2.data}));
  } catch(e) { fail('Room broadcast', e.message); }
}

async function test12() {
  try {
    const r = await httpGet('/favicon.ico');
    r.status===200 ? ok('favicon.ico served (' + r.body.length + 'b)')
      : fail('favicon', 'status=' + r.status);
  } catch(e) { fail('favicon', e.message); }
}

async function run() {
  console.log(`\n  WebSocket + Room tests (port ${PORT})\n`);
  await test1(); await test2(); await test3(); await test4();
  await test5(); await test6(); await test7(); await test8();
  await test9(); await test10(); await test11(); await test12();
  console.log(results.join('\n'));
  console.log(`\n  ${passed} passed, ${failed} failed\n`);
  process.exit(failed > 0 ? 1 : 0);
}

run();
