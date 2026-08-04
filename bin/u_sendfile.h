/* u_sendfile.h — zero-copy file-to-socket transfer
 *
 * Linux:   sendfile(2) + TCP_CORK for header batching
 * macOS:   sendfile(2) with hdtr (headers/trailers in one call)
 * FreeBSD: same as macOS
 * Windows: TransmitFile via Winsock2
 * Fallback: read() + write() in 64KB chunks
 *
 * For TLS connections, always falls back to read + tls_write
 * because sendfile bypasses user-space encryption.
 */

#ifndef U_SENDFILE_H
#define U_SENDFILE_H

#include <sys/types.h>
#include <sys/stat.h>
#include <fcntl.h>
#include <unistd.h>
#include <errno.h>

/* ── TCP_CORK: batch headers + sendfile into one TCP segment ──── */

#ifdef __linux__
#include <netinet/tcp.h>
static void u_tcp_cork(int fd, int on) {
    setsockopt(fd, IPPROTO_TCP, TCP_CORK, &on, sizeof(on));
}
#elif defined(__APPLE__) || defined(__FreeBSD__)
#include <netinet/tcp.h>
static void u_tcp_cork(int fd, int on) {
    setsockopt(fd, IPPROTO_TCP, TCP_NOPUSH, &on, sizeof(on));
}
#else
static void u_tcp_cork(int fd, int on) { (void)fd; (void)on; }
#endif

/* ── sendfile: platform-specific ────────────────────────────────── */

#ifdef __linux__
#include <sys/sendfile.h>

/*  Linux sendfile: out_fd must be a socket, in_fd must support mmap.
 *  Returns bytes sent, -1 on error. Handles partial sends. */
static ssize_t u_sendfile_raw(int sock_fd, int file_fd, off_t offset, size_t count) {
    ssize_t total = 0;
    off_t off = offset;
    while ((size_t)total < count) {
        ssize_t n = sendfile(sock_fd, file_fd, &off, count - total);
        if (n <= 0) {
            if (n == -1 && errno == EAGAIN) continue;
            break;
        }
        total += n;
    }
    return total > 0 ? total : -1;
}

#elif defined(__APPLE__) || defined(__FreeBSD__)
#include <sys/socket.h>
#include <sys/uio.h>

/* BSD sendfile: note reversed fd order from Linux.
 * Supports hdtr for sending headers+file+trailers in one call. */
static ssize_t u_sendfile_raw(int sock_fd, int file_fd, off_t offset, size_t count) {
    off_t len = (off_t)count;
    int r = sendfile(file_fd, sock_fd, offset, &len, NULL, 0);
    return (r == 0 || (r == -1 && errno == EAGAIN)) ? (ssize_t)len : -1;
}

/* BSD sendfile with headers — sends headers + file body in one syscall */
static ssize_t u_sendfile_with_headers(int sock_fd, int file_fd,
                                        off_t offset, size_t count,
                                        const void* headers, size_t hdr_len) {
    struct iovec hdr_iov = { .iov_base = (void*)headers, .iov_len = hdr_len };
    struct sf_hdtr hdtr = { .headers = &hdr_iov, .hdr_cnt = 1,
                            .trailers = NULL, .trl_cnt = 0 };
    off_t len = (off_t)(hdr_len + count);
    int r = sendfile(file_fd, sock_fd, offset, &len, &hdtr, 0);
    return (r == 0 || (r == -1 && errno == EAGAIN)) ? (ssize_t)len : -1;
}

#else
/* Fallback: read + write in chunks */
static ssize_t u_sendfile_raw(int sock_fd, int file_fd, off_t offset, size_t count) {
    char buf[65536];
    if (offset > 0) lseek(file_fd, offset, SEEK_SET);
    ssize_t total = 0;
    while ((size_t)total < count) {
        size_t chunk = count - total;
        if (chunk > sizeof(buf)) chunk = sizeof(buf);
        ssize_t n = read(file_fd, buf, chunk);
        if (n <= 0) break;
        ssize_t w = write(sock_fd, buf, n);
        if (w <= 0) break;
        total += w;
    }
    return total > 0 ? total : -1;
}
#endif

/* ── High-level: send headers + file with optimal syscall strategy ── */

/*  Sends HTTP headers followed by file body using the best available
 *  method. On BSD, uses hdtr for one-syscall send. On Linux, uses
 *  TCP_CORK to batch. On fallback, uses write + write. */
static ssize_t u_send_file_response(int sock_fd, int file_fd,
                                     off_t offset, size_t file_size,
                                     const char* headers, size_t hdr_len) {
#if defined(__APPLE__) || defined(__FreeBSD__)
    /* BSD: one syscall for headers + file */
    return u_sendfile_with_headers(sock_fd, file_fd, offset, file_size,
                                   headers, hdr_len);
#else
    /* Linux / fallback: cork, write headers, sendfile body, uncork */
    u_tcp_cork(sock_fd, 1);
    ssize_t hw = write(sock_fd, headers, hdr_len);
    if (hw <= 0) { u_tcp_cork(sock_fd, 0); return -1; }
    ssize_t bw = u_sendfile_raw(sock_fd, file_fd, offset, file_size);
    u_tcp_cork(sock_fd, 0);
    return (bw > 0) ? hw + bw : hw;
#endif
}

/* ── Stat + open + send convenience ─────────────────────────────── */

typedef struct {
    ssize_t bytes_sent;
    int     status;         /* HTTP status: 200, 304, 404 */
    int     error;          /* errno on failure */
} USendFileResult;

/*  Full file response: stat, ETag check, open, send headers + body.
 *  Handles 304 Not Modified if etag matches If-None-Match. */
static USendFileResult u_serve_file(int sock_fd, const char* path,
                                     const char* if_none_match,
                                     const char* content_type,
                                     int keep_alive) {
    USendFileResult result = { 0, 500, 0 };
    struct stat st;
    if (stat(path, &st) != 0) { result.status = 404; result.error = errno; return result; }
    if (!S_ISREG(st.st_mode)) { result.status = 403; return result; }

    /* ETag from mtime + size */
    char etag[64];
    snprintf(etag, sizeof(etag), "\"%lx-%lx\"",
             (unsigned long)st.st_mtime, (unsigned long)st.st_size);

    /* 304 Not Modified */
    if (if_none_match && strcmp(if_none_match, etag) == 0) {
        char resp[512];
        int rlen = snprintf(resp, sizeof(resp),
            "HTTP/1.1 304 Not Modified\r\n"
            "ETag: %s\r\n"
            "Connection: %s\r\n\r\n",
            etag, keep_alive ? "keep-alive" : "close");
        write(sock_fd, resp, rlen);
        result.bytes_sent = rlen;
        result.status = 304;
        return result;
    }

    /* Build response headers */
    char headers[1024];
    int hlen = snprintf(headers, sizeof(headers),
        "HTTP/1.1 200 OK\r\n"
        "Content-Type: %s\r\n"
        "Content-Length: %ld\r\n"
        "ETag: %s\r\n"
        "Cache-Control: public, max-age=3600\r\n"
        "Connection: %s\r\n\r\n",
        content_type, (long)st.st_size, etag,
        keep_alive ? "keep-alive" : "close");

    int fd = open(path, O_RDONLY);
    if (fd < 0) { result.status = 500; result.error = errno; return result; }

    #ifdef __linux__
    /* Advise kernel we'll read sequentially */
    posix_fadvise(fd, 0, st.st_size, POSIX_FADV_SEQUENTIAL);
    #endif

    result.bytes_sent = u_send_file_response(sock_fd, fd, 0, st.st_size,
                                              headers, hlen);
    close(fd);
    result.status = 200;
    return result;
}

#endif /* U_SENDFILE_H */
