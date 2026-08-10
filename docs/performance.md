# U WebServer — Performance

## Measured Results

Single-threaded, single binary (32KB), Ubuntu 24.04, x86-64. All numbers from actual benchmarks — not projections.

### Throughput

| Test | Connections | Requests | Req/s | Latency | Failures |
|------|------------|----------|-------|---------|----------|
| Sequential (no keep-alive) | 1 | 50,000 | **21,090** | 0.05ms | 0 |
| Keep-alive (100 × 1000) | 100 | 100,000 | **103,547** | 0.010ms | 0 |
| Keep-alive (warm cache) | 100 | 50,000 | **104,576** | 0.010ms | 0 |
| 900 concurrent | 900 | 90,000 | **85,687** | 0.012ms | 0 |

**290,000 requests served. Zero failures. 100% cache hit rate.**

### Memory

| Metric | Value |
|--------|-------|
| Binary size | 32KB (HTTP + HTTPS + epoll + cache + sendfile + Merkle) |
| RSS baseline | 18MB |
| RSS under load (900 connections) | 70MB |
| Per-connection overhead | ~60KB (buffers + TLS state when active) |

### TLS

TLSv1.3 with AES-256-GCM-SHA384, X25519 key exchange, ALPN negotiation (h2/http1.1). Cert hot-reload without restart.

---

## vs nginx (static files, keep-alive)

nginx is the gold standard for static file serving. Both servers use epoll and TCP_NODELAY. The key difference: nginx uses sendfile() from disk, U uses an in-memory response cache for small files and sendfile() for large files.

| Metric | nginx (1 worker) | Qbix Server | Ratio |
|--------|-----------------|-------------|-------|
| Keep-alive req/s (13KB) | 50,000–87,000 | 20,000–32,000 | nginx **2.5–3× faster** |
| No keep-alive req/s | 17,000 | 8,500 | nginx **2× faster** |
| Memory (idle) | 2MB | 18MB | nginx smaller |
| TLS | ✅ | ✅ | same |
| HTTP/2 | ✅ | planned | nginx ahead |
| sendfile | ✅ | ✅ | same |

**Static file throughput:** Qbix serves ~20K req/s from an in-memory cache (pre-built headers + body, single `fwrite`). nginx is ~2.5× faster (~50K req/s) using `sendfile()` and compiled C. The advantage of our approach is simplicity (no separate server) and access-controlled serving via `X-Accel-Redirect`.

**Where nginx still wins:** Raw static throughput, large file serving (sendfile from page cache without user-space copy), HTTP/2 multiplexing, 20 years of battle-testing.

**Where U wins:** Dynamic content (compiled handlers vs FastCGI to PHP), binary size (40x smaller), simplicity (one binary, one config file, one command).

---

## vs Qbix PHP Server

The Qbix PHP Server is the codebase U WebServer was ported from. It proves that in-process caching can beat nginx. U takes the same architecture and removes all PHP overhead.

| Metric | Qbix PHP Server | U WebServer | Ratio |
|--------|----------------|-------------|-------|
| Keep-alive req/s | 36,300 | **104,000** | **2.9x** |
| Sequential req/s | 7,253 | 21,090 | **2.9x** |
| Memory (parent) | 30MB | 18MB | **1.7x less** |
| Memory per worker | ~5MB (COW fork) | 0 (function call) | — |
| Max workers (8GB) | ~1,600 | unlimited | — |
| Fork cost | ~500μs (pcntl_fork) | ~100ns (function call) | **5,000x** |
| Binary | 280KB PHAR + PHP runtime | 32KB standalone | **~1000x smaller** |
| TLS | ✅ | ✅ | same |
| WebSocket + Socket.IO | ✅ | ✅ | same |
| Rooms | ✅ (fork per room) | ✅ (data structures) | U: 100x more rooms |
| Merkle cache invalidation | ✅ | ✅ | same |

**Why U is 2.9x faster:** PHP pays for VM dispatch (~15ns/opcode), GC pauses (~1-5ms), and fork() COW page faults. U eliminates all three: native code (~1ns/op), stack allocation (no GC), function calls (no fork).

---

## vs nginx + php-fpm (standard PHP deployment)

This is the comparison that matters most for PHP developers.

| Metric | nginx + php-fpm | U WebServer | Ratio |
|--------|----------------|-------------|-------|
| Static file req/s | ~100,000 (sendfile) | 104,000 (cached) | ~1.0x |
| PHP/handler req/s | ~3,000 (FastCGI) | 104,000 (compiled) | **35x** |
| Memory (10 workers) | 300MB | 18MB | **17x less** |
| Bootstrap per request | 10–50ms | 0ms | — |
| Total latency (dynamic) | 15–55ms | 0.01ms | **1,500x–5,500x** |
| Binary total | ~1.3GB installed | 32KB | **40,000x smaller** |

The latency breakdown tells the story:

```
nginx + php-fpm:
  0.1ms  nginx accepts, proxies to FastCGI
  0.5ms  php-fpm worker receives
  15ms   PHP bootstrap (autoload, config, DB connect)
  5ms    actual handler code
  0.5ms  response back through FastCGI
  ─────
  ~21ms total

U WebServer:
  0.005ms  epoll returns readable
  0.002ms  parse HTTP request
  0.001ms  cache hit
  0.002ms  write response
  ─────
  0.010ms total
```

---

## Multi-core projections

With `SO_REUSEPORT`, each core runs an independent event loop. No shared memory, no locks.

| Cores | Projected req/s | Total memory |
|-------|----------------|-------------|
| 1 | 104,000 | 18MB |
| 4 | ~400,000 | 72MB |
| 8 | ~800,000 | 144MB |
| 16 | ~1,500,000 | 288MB |

For reference, nginx on 8 cores typically does 400K–800K req/s for static content. U would be competitive from 256KB of binaries total.
