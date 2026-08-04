/* u_tls.h — TLS support for U runtime via OpenSSL 3.x
 *
 * Server-side: ctx_new → accept → read/write → close
 * Client-side: ctx_client → connect → read/write → close
 * Cert inspection, ALPN negotiation, hot reload, SNI.
 */

#ifndef U_TLS_H
#define U_TLS_H

#include <openssl/ssl.h>
#include <openssl/err.h>
#include <openssl/x509.h>
#include <openssl/x509v3.h>
#include <openssl/pem.h>
#include <string.h>
#include <time.h>

typedef struct {
    SSL_CTX* ctx;
    char     cert_path[512];
    char     key_path[512];
    int      min_version;
} UTlsCtx;

typedef struct {
    SSL*    ssl;
    int     fd;
    int     handshake_done;
} UTlsConn;

typedef struct {
    char subject[256];
    char issuer[256];
    char not_before[64];
    char not_after[64];
    int  days_remaining;
    int  is_expired;
    char serial[128];
    char san[512];
} UTlsCertInfo;

static __thread char u_tls_errbuf[256];

static const char* u_tls_last_error(void) {
    unsigned long e = ERR_get_error();
    if (e) ERR_error_string_n(e, u_tls_errbuf, sizeof(u_tls_errbuf));
    return u_tls_errbuf;
}

/* ── Init (call once) ───────────────────────────────────────────── */

static int u_tls_initialized = 0;
static void u_tls_init(void) {
    if (u_tls_initialized) return;
    OPENSSL_init_ssl(OPENSSL_INIT_LOAD_SSL_STRINGS | OPENSSL_INIT_LOAD_CRYPTO_STRINGS, NULL);
    u_tls_initialized = 1;
}

/* ── Server context ─────────────────────────────────────────────── */

static UTlsCtx* u_tls_ctx_new(const char* cert, const char* key, const char* min_ver) {
    u_tls_init();
    SSL_CTX* ctx = SSL_CTX_new(TLS_server_method());
    if (!ctx) return NULL;

    SSL_CTX_set_min_proto_version(ctx,
        (min_ver && strcmp(min_ver, "1.3") == 0) ? TLS1_3_VERSION : TLS1_2_VERSION);

    if (SSL_CTX_use_certificate_chain_file(ctx, cert) <= 0 ||
        SSL_CTX_use_PrivateKey_file(ctx, key, SSL_FILETYPE_PEM) <= 0 ||
        !SSL_CTX_check_private_key(ctx)) {
        SSL_CTX_free(ctx); return NULL;
    }

    SSL_CTX_set_session_cache_mode(ctx, SSL_SESS_CACHE_SERVER);
    SSL_CTX_sess_set_cache_size(ctx, 1024);

    /* ALPN: prefer h2, fall back to http/1.1 */
    static const unsigned char alpn[] = { 2,'h','2', 8,'h','t','t','p','/','1','.','1' };
    SSL_CTX_set_alpn_protos(ctx, alpn, sizeof(alpn));

    UTlsCtx* t = (UTlsCtx*)malloc(sizeof(UTlsCtx));
    t->ctx = ctx;
    t->min_version = SSL_CTX_get_min_proto_version(ctx);
    strncpy(t->cert_path, cert, 511); t->cert_path[511] = 0;
    strncpy(t->key_path, key, 511);   t->key_path[511] = 0;
    return t;
}

/* ── Client context ─────────────────────────────────────────────── */

static UTlsCtx* u_tls_ctx_client(const char* ca_path) {
    u_tls_init();
    SSL_CTX* ctx = SSL_CTX_new(TLS_client_method());
    if (!ctx) return NULL;
    SSL_CTX_set_min_proto_version(ctx, TLS1_2_VERSION);
    if (ca_path && ca_path[0])
        SSL_CTX_load_verify_locations(ctx, ca_path, NULL);
    else
        SSL_CTX_set_default_verify_paths(ctx);
    SSL_CTX_set_verify(ctx, SSL_VERIFY_PEER, NULL);
    UTlsCtx* t = (UTlsCtx*)malloc(sizeof(UTlsCtx));
    t->ctx = ctx; t->min_version = TLS1_2_VERSION;
    t->cert_path[0] = 0; t->key_path[0] = 0;
    return t;
}

/* ── Accept (server) ────────────────────────────────────────────── */

static UTlsConn* u_tls_accept(UTlsCtx* t, int fd) {
    SSL* ssl = SSL_new(t->ctx);
    if (!ssl) return NULL;
    SSL_set_fd(ssl, fd);
    if (SSL_accept(ssl) <= 0) { SSL_free(ssl); return NULL; }
    UTlsConn* c = (UTlsConn*)malloc(sizeof(UTlsConn));
    c->ssl = ssl; c->fd = fd; c->handshake_done = 1;
    return c;
}

/* ── Connect (client) ───────────────────────────────────────────── */

static UTlsConn* u_tls_connect(UTlsCtx* t, int fd, const char* hostname) {
    SSL* ssl = SSL_new(t->ctx);
    if (!ssl) return NULL;
    SSL_set_fd(ssl, fd);
    if (hostname && hostname[0]) SSL_set_tlsext_host_name(ssl, hostname);
    if (SSL_connect(ssl) <= 0) { SSL_free(ssl); return NULL; }
    if (hostname && hostname[0]) {
        X509* cert = SSL_get_peer_certificate(ssl);
        if (!cert) { SSL_free(ssl); return NULL; }
        int ok = X509_check_host(cert, hostname, strlen(hostname), 0, NULL);
        X509_free(cert);
        if (ok != 1) { SSL_free(ssl); return NULL; }
    }
    UTlsConn* c = (UTlsConn*)malloc(sizeof(UTlsConn));
    c->ssl = ssl; c->fd = fd; c->handshake_done = 1;
    return c;
}

/* ── Read / Write ───────────────────────────────────────────────── */

static int u_tls_read(UTlsConn* c, void* buf, int len) {
    int n = SSL_read(c->ssl, buf, len);
    if (n <= 0) {
        int err = SSL_get_error(c->ssl, n);
        return (err == SSL_ERROR_WANT_READ || err == SSL_ERROR_WANT_WRITE) ? 0 : -1;
    }
    return n;
}

static int u_tls_write(UTlsConn* c, const void* buf, int len) {
    int n = SSL_write(c->ssl, buf, len);
    if (n <= 0) {
        int err = SSL_get_error(c->ssl, n);
        return (err == SSL_ERROR_WANT_READ || err == SSL_ERROR_WANT_WRITE) ? 0 : -1;
    }
    return n;
}

/* ── Close / Free ───────────────────────────────────────────────── */

static void u_tls_close(UTlsConn* c) {
    if (!c) return;
    SSL_shutdown(c->ssl);
    SSL_free(c->ssl);
    free(c);
}

static void u_tls_ctx_free(UTlsCtx* t) {
    if (!t) return;
    SSL_CTX_free(t->ctx);
    free(t);
}

/* ── Hot reload certs ───────────────────────────────────────────── */

static int u_tls_ctx_reload(UTlsCtx* t) {
    if (SSL_CTX_use_certificate_chain_file(t->ctx, t->cert_path) <= 0) return -1;
    if (SSL_CTX_use_PrivateKey_file(t->ctx, t->key_path, SSL_FILETYPE_PEM) <= 0) return -1;
    return SSL_CTX_check_private_key(t->ctx) ? 0 : -1;
}

/* ── Certificate inspection ─────────────────────────────────────── */

static UTlsCertInfo u_tls_cert_info(const char* path) {
    UTlsCertInfo info = {0};
    FILE* fp = fopen(path, "r");
    if (!fp) return info;
    X509* cert = PEM_read_X509(fp, NULL, NULL, NULL);
    fclose(fp);
    if (!cert) return info;

    X509_NAME_oneline(X509_get_subject_name(cert), info.subject, sizeof(info.subject));
    X509_NAME_oneline(X509_get_issuer_name(cert), info.issuer, sizeof(info.issuer));

    BIO* bio = BIO_new(BIO_s_mem());
    if (bio) {
        ASN1_TIME_print(bio, X509_get0_notBefore(cert));
        int n = BIO_read(bio, info.not_before, 63); if (n > 0) info.not_before[n] = 0;
        BIO_reset(bio);
        ASN1_TIME_print(bio, X509_get0_notAfter(cert));
        n = BIO_read(bio, info.not_after, 63); if (n > 0) info.not_after[n] = 0;
        BIO_free(bio);
    }

    int day, sec;
    if (ASN1_TIME_diff(&day, &sec, NULL, X509_get0_notAfter(cert))) {
        info.days_remaining = day;
        info.is_expired = (day < 0);
    }

    /* Serial number */
    ASN1_INTEGER* serial = X509_get_serialNumber(cert);
    if (serial) {
        BIGNUM* bn = ASN1_INTEGER_to_BN(serial, NULL);
        if (bn) {
            char* hex = BN_bn2hex(bn);
            if (hex) { strncpy(info.serial, hex, 127); OPENSSL_free(hex); }
            BN_free(bn);
        }
    }

    /* SAN (Subject Alternative Names) */
    GENERAL_NAMES* sans = X509_get_ext_d2i(cert, NID_subject_alt_name, NULL, NULL);
    if (sans) {
        int pos = 0;
        for (int i = 0; i < sk_GENERAL_NAME_num(sans) && pos < 500; i++) {
            GENERAL_NAME* gen = sk_GENERAL_NAME_value(sans, i);
            if (gen->type == GEN_DNS) {
                const char* dns = (const char*)ASN1_STRING_get0_data(gen->d.dNSName);
                if (pos > 0) { info.san[pos++] = ','; info.san[pos++] = ' '; }
                int len = strlen(dns);
                if (pos + len < 510) { memcpy(info.san + pos, dns, len); pos += len; }
            }
        }
        info.san[pos] = 0;
        GENERAL_NAMES_free(sans);
    }

    X509_free(cert);
    return info;
}

/* ── ALPN / version / cipher queries ────────────────────────────── */

static const char* u_tls_alpn_selected(UTlsConn* c) {
    const unsigned char* proto = NULL; unsigned int len = 0;
    SSL_get0_alpn_selected(c->ssl, &proto, &len);
    if (proto && len > 0) {
        static __thread char buf[32];
        int n = len < 31 ? len : 31;
        memcpy(buf, proto, n); buf[n] = 0;
        return buf;
    }
    return "http/1.1";
}

static const char* u_tls_version(UTlsConn* c) { return SSL_get_version(c->ssl); }
static const char* u_tls_cipher(UTlsConn* c) { return SSL_get_cipher_name(c->ssl); }

#endif /* U_TLS_H */
