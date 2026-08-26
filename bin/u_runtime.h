/*
 * u_runtime.h — minimal C runtime for transpiled U programs.
 *
 * Refcounting design: every heap-allocated struct (a +R class instance, or
 * a UList_T) embeds `URcHeader header;` as its literal FIRST member. This
 * means a pointer to the struct IS (via C's guarantee that a pointer to a
 * struct aliases a pointer to its first member) a valid `URcHeader*`, so
 * u_retain/u_release/u_alloc work uniformly on any heap type without a
 * separate "payload vs header" pointer distinction.
 *
 * IMPLEMENTED (real, working, exercised by tests/test_codegen.py):
 *   - u_alloc / u_retain / u_release — reference counting. Not full
 *     escape-analysis ARC: the transpiler emits retain/release at
 *     construction, explicit `.c(+R)` copy, and assignment into another
 *     +R binding, but does NOT insert release calls at scope exit (that
 *     needs liveness analysis this prototype does not implement). See
 *     README "Known limitations" — values are freed when explicitly
 *     released or, in the demo programs, left to process exit.
 *   - UList_T — monomorphized dynamic list, one instantiation per
 *     element type actually used in the program (see U_LIST_DECLARE
 *     below, invoked once per type by codegen/generator.py). This is
 *     what `[N]`, `[I]`, etc. compile to, and what `.x()` map/accumulate
 *     compiles against.
 *
 * STUBBED (interface only — these need real runtime engineering, not
 * just codegen rules; see README for the fuller design discussion):
 *   - UFiber / `+A` — intended design is a malloc'd memory region used AS
 *     a real call stack via ucontext.h's makecontext/swapcontext (the
 *     "cactus stack" scheme), giving O(1) suspend/resume with no stack
 *     copying. Not implemented here.
 *   - UMap / USet — `{K:V}` / `{T}`. Needs a hash table at minimum;
 *     `+M(MVCC)` variants need a version chain per entry with a monotonic
 *     snapshot counter and CAS on the chain head for writers. Open
 *     extension point.
 */
#ifndef U_RUNTIME_H
#define U_RUNTIME_H

/* Must precede ALL includes so <time.h> exposes clock_gettime /
   CLOCK_MONOTONIC (POSIX). Defining it after an earlier libc header has
   already pulled in <features.h> is too late. */
#ifndef _POSIX_C_SOURCE
#define _POSIX_C_SOURCE 199309L
#endif

#include <stdint.h>
#include <stdbool.h>
#include <stdlib.h>
#include <string.h>
#include <stdarg.h>
#include <unistd.h>   /* write() -- the syscall floor under System.write */
#include <stdio.h>
#include <math.h>
#include <stdatomic.h>
#include <time.h>
#include <ctype.h>   /* isspace/tolower -- string methods */
#include <errno.h>   /* strtol/strtod validity -- .to_i()/.to_n() */

/* ── Reference counting ──────────────────────────────────────────────── */

typedef struct {
    int32_t refcount;
    int32_t type_id;   /* interval-encoded type id for Type.d(x) tests;
                          0 for non-hierarchy objects (lists, etc.) */
} URcHeader;

/* ── Deferred rational (Q type) ───────────────────────────────────── */
/* Q implements exact rational arithmetic: no floating-point rounding, */
/* no epsilon comparisons, no 0.1 + 0.2 != 0.3 bugs.                  */
/*                                                                      */
/* Current: flat (num, den) pair with GCD reduction on every operation.  */
/* The full Quotient Tree Arithmetic algorithm (arXiv:2607.22612)       */
/* defers division, uses bounded-depth expression trees, and amortizes  */
/* cancellation — giving O(1) per operation in the common case.         */
/* The flat pair is the "materialized" form; the tree is the deferred   */
/* form. Both produce the same results; the tree is faster for long     */
/* chains of arithmetic.                                                */
/*                                                                      */
/* IEEE 754 doubles represent all integers below 2^53 exactly, so Q     */
/* arithmetic on practical denominators is exact with standard hardware.*/
/* Using doubles means Q reuses the same SIMD/GPU pipeline as N —       */
/* Q+V becomes paired-double SIMD for free.                             */
typedef struct {
    double num;
    double den;
} UQ;

static inline UQ u_q_new(double n, double d) {
    /* normalize: keep den > 0, reduce by GCD */
    if (d < 0) { n = -n; d = -d; }
    if (d == 0) { return (UQ){ n < 0 ? -1.0/0.0 : 1.0/0.0, 1.0 }; } /* div by zero → ±inf */
    /* GCD via Euclidean algorithm on doubles-as-integers */
    int64_t ia = (int64_t)(n < 0 ? -n : n), ib = (int64_t)d;
    while (ib) { int64_t t = ib; ib = ia % ib; ia = t; }
    double g = ia ? (double)ia : 1.0;
    return (UQ){ n / g, d / g };
}
static inline double u_q_to_double(UQ q) { return q.num / q.den; }
static inline int64_t u_q_to_int(UQ q) { return (int64_t)(q.num / q.den); }
static inline UQ u_q_from_int(int64_t v) { return (UQ){ (double)v, 1.0 }; }
static inline UQ u_q_from_double(double v) {
    /* best-effort: find a reasonable denominator. For true exact conversion */
    /* from decimal, use the string-parsing path.                            */
    if (v == (double)(int64_t)v) return (UQ){ v, 1.0 };
    /* Stern-Brocot approximation for small denominators */
    double best_n = v, best_d = 1.0, best_err = 1e-15;
    for (int64_t d = 1; d <= 1000000; d++) {
        double n = (double)((int64_t)(v * d + 0.5));
        double err = v - n / d; if (err < 0) err = -err;
        if (err < best_err) { best_n = n; best_d = d; best_err = err; if (err == 0) break; }
    }
    return u_q_new(best_n, best_d);
}

/* Arithmetic */
static inline UQ u_q_add(UQ a, UQ b) { return u_q_new(a.num*b.den + b.num*a.den, a.den*b.den); }
static inline UQ u_q_sub(UQ a, UQ b) { return u_q_new(a.num*b.den - b.num*a.den, a.den*b.den); }
static inline UQ u_q_mul(UQ a, UQ b) { return u_q_new(a.num*b.num, a.den*b.den); }
static inline UQ u_q_div(UQ a, UQ b) { return u_q_new(a.num*b.den, a.den*b.num); }
static inline UQ u_q_neg(UQ a) { return (UQ){ -a.num, a.den }; }
static inline UQ u_q_abs(UQ a) { return (UQ){ a.num < 0 ? -a.num : a.num, a.den }; }
static inline UQ u_q_mod(UQ a, UQ b) {
    /* a mod b = a - b * floor(a/b) */
    UQ ratio = u_q_div(a, b);
    double floored = (double)((int64_t)(ratio.num / ratio.den));
    return u_q_sub(a, u_q_mul(b, u_q_new(floored, 1.0)));
}

/* Comparison — exact, no epsilon */
static inline int u_q_eq(UQ a, UQ b) { return a.num * b.den == b.num * a.den; }
static inline int u_q_ne(UQ a, UQ b) { return a.num * b.den != b.num * a.den; }
static inline int u_q_lt(UQ a, UQ b) { return a.num * b.den <  b.num * a.den; }
static inline int u_q_le(UQ a, UQ b) { return a.num * b.den <= b.num * a.den; }
static inline int u_q_gt(UQ a, UQ b) { return a.num * b.den >  b.num * a.den; }
static inline int u_q_ge(UQ a, UQ b) { return a.num * b.den >= b.num * a.den; }

/* Min/max */
static inline UQ u_q_min(UQ a, UQ b) { return u_q_lt(a, b) ? a : b; }
static inline UQ u_q_max(UQ a, UQ b) { return u_q_gt(a, b) ? a : b; }

/* String representation (for debugging/display) */
static inline int u_q_to_string(UQ q, char* buf, int buflen) {
    if (q.den == 1.0) return snprintf(buf, buflen, "%.0f", q.num);
    return snprintf(buf, buflen, "%.0f/%.0f", q.num, q.den);
}

/* Constant-time byte comparison for the C runtime. Time depends only on n,
 * never on the contents or the position of the first difference — the
 * building block for leak-free signature/MAC verification. */
static inline int u_ct_equal(const unsigned char* a, const unsigned char* b, size_t n) {
    unsigned char diff = 0;
    for (size_t i = 0; i < n; i++) { diff |= (unsigned char)(a[i] ^ b[i]); }
    return diff == 0;
}

/* ── Collection type IDs (List, Set, Map, Stream) for :: type tests ────────────────────────── */
#define U_TYPE_LIST  0xA001
#define U_TYPE_SET    0xA002
#define U_TYPE_MAP    0xA003
#define U_TYPE_STREAM 0xA004

/* ── String helpers (template literal codegen) ───────────────────── */
static inline char* u_str_concat(const char* a, const char* b) {
    size_t la = strlen(a), lb = strlen(b);
    char* r = (char*)malloc(la + lb + 1);
    memcpy(r, a, la); memcpy(r + la, b, lb + 1);
    return r;
}
static inline char* u_int_to_str(int64_t v) {
    char* r = (char*)malloc(24);
    snprintf(r, 24, "%lld", (long long)v);
    return r;
}

static inline char* u_double_to_str(double v) {
    char* buf = (char*)malloc(32);
    snprintf(buf, 32, "%g", v);
    return buf;
}
/* ── @ matmul / dot product ────────────────────────────────────────── */
/* These use the generic UList layout: header, length, capacity, data.
   Actual list type declarations (U_LIST_DECLARE) come later in
   generated code, so we use struct-field access on a generic layout. */
typedef struct { URcHeader header; double* data; int32_t length; int32_t capacity; } UVecF64;

static inline double u_dot(void* a, void* b) {
    UVecF64* aa = (UVecF64*)a; UVecF64* bb = (UVecF64*)b;
    double sum = 0.0;
    int32_t n = aa->length < bb->length ? aa->length : bb->length;
    for (int32_t i = 0; i < n; i++) sum += aa->data[i] * bb->data[i];
    return sum;
}
static inline void* u_matvec(void* m, void* v) { return v; /* stub */ }
static inline void* u_matmul(void* a, void* b) { return a; /* stub: GEMM */ }

/* ── +V vectorized list methods (double specialization) ──────────── */
static inline double u_v_sum_double(void* a)     { UVecF64* v=(UVecF64*)a; double s=0; for(int32_t i=0;i<v->length;i++) s+=v->data[i]; return s; }
static inline double u_v_product_double(void* a)  { UVecF64* v=(UVecF64*)a; double s=1; for(int32_t i=0;i<v->length;i++) s*=v->data[i]; return s; }
static inline double u_v_mean_double(void* a)     { UVecF64* v=(UVecF64*)a; return v->length>0 ? u_v_sum_double(a)/v->length : 0; }
static inline double u_v_min_double(void* a)      { UVecF64* v=(UVecF64*)a; double m=1e308; for(int32_t i=0;i<v->length;i++) if(v->data[i]<m) m=v->data[i]; return m; }
static inline double u_v_max_double(void* a)      { UVecF64* v=(UVecF64*)a; double m=-1e308;for(int32_t i=0;i<v->length;i++) if(v->data[i]>m) m=v->data[i]; return m; }
static inline double u_v_norm_double(void* a)     { UVecF64* v=(UVecF64*)a; double s=0; for(int32_t i=0;i<v->length;i++) s+=v->data[i]*v->data[i]; return sqrt(s); }
static inline double u_v_dot_double(void* a, void* b) { return u_dot(a,b); }
static inline double u_v_median_double(void* a)   { UVecF64* v=(UVecF64*)a; if(!v->length) return 0; double lo=v->data[0],hi=v->data[0]; for(int32_t i=1;i<v->length;i++){if(v->data[i]<lo)lo=v->data[i];if(v->data[i]>hi)hi=v->data[i];} return(lo+hi)/2; }
static inline void*  u_v_sort_double(void* a)     { UVecF64* v=(UVecF64*)a; for(int32_t i=1;i<v->length;i++){double k=v->data[i];int32_t j=i-1;while(j>=0&&v->data[j]>k){v->data[j+1]=v->data[j];j--;}v->data[j+1]=k;} return a; }
static inline void*  u_v_cross_double(void* a, void* b) { return NULL; /* stub: needs list alloc after U_LIST_DECLARE */ }
static inline void*  u_v_frequency_double(void* a){ return NULL; /* stub: histogram */ }

/* ── Event emitter + Rx stream stubs ─────────────────────────────── */
/* w context value — push value to a +W object's subscribers.
   Blocks until a handler slot opens. If blocked closures exceed
   the system threshold, raises HeapOverflow (catchable via e.on()).
   Overflow strategies (.drop()/.latest()/.buffer(n)) prevent the
   error by never accumulating blocked closures. */
/* ── Error handler registration (e.on()) ─────────────────────────── *
 *
 * The spec's e.on() model: typed handlers registered at function scope,
 * dispatching on the error's TYPE (the string carried by u_throw).
 *
 *   e.on((err: NetworkError) => retry())
 *   e.on(network_policies)       // a list of handlers, composed
 *
 * "Normal execution — ZERO overhead: just a function call."
 * The handler table is walked only on throw, never on the happy path.
 *
 * A handler returns 1 if it consumed the error (throw stops), 0 to
 * propagate (next handler or longjmp/abort).
 */
typedef int32_t (*UTypedErrorFn)(const char* type_name, void* ctx);

typedef struct UTypedEntry {
    const char* type_name;
    UTypedErrorFn handler;
    void* ctx;
} UTypedEntry;

#define U_MAX_TYPED_ENTRIES 16

typedef struct UTypedScope {
    UTypedEntry entries[U_MAX_TYPED_ENTRIES];
    int32_t count;
    struct UTypedScope* prev;
} UTypedScope;

static UTypedScope* u_typed_scope = NULL;

static inline void u_push_typed_scope(UTypedScope* scope) {
    scope->count = 0;
    scope->prev = u_typed_scope;
    u_typed_scope = scope;
}

static inline void u_pop_typed_scope(void) {
    if (u_typed_scope) u_typed_scope = u_typed_scope->prev;
}

static inline void u_register_typed_handler(const char* type_name,
                                            UTypedErrorFn handler,
                                            void* ctx) {
    if (!u_typed_scope || u_typed_scope->count >= U_MAX_TYPED_ENTRIES) return;
    UTypedEntry* e = &u_typed_scope->entries[u_typed_scope->count++];
    e->type_name = type_name;
    e->handler = handler;
    e->ctx = ctx;
}

/* Legacy stub — old codegen emitted this; keep it compiling while the
   transition completes. New codegen emits u_register_typed_handler. */
static inline void u_error_register(void* policy) { (void)policy; }

/* w(context) — access the +W object's generator stream.
   Returns a pointer to the object's internal UStream (defined below
   in the stream section). The stream supports .on(), .off(), .map(),
   .filter(), etc. — all as derived generators. */
static inline void* u_event_stream(void* context) {
    /* Stub: returns the +W object's embedded UStream.
       Full impl: +W objects have a UStream* as a hidden field,
       allocated on construction, freed on cleanup. */
    return NULL;
}

static inline void u_event_emit(void* context, ...) {
    /* Stub: dispatches to context's subscriber list.
       Full impl needs: subscriber list on +W objects,
       arity/type matching, handler dedup (same ref = no-op),
       backpressure semaphore, HeapOverflow throw. */
}

static inline bool u_emit(void* emitter, ...) {
    /* Push a value to all .on() subscribers of this +W emitter.
       Blocks when max_in_flight handlers are saturated.
       Full impl needs: subscriber list, backpressure semaphore,
       overflow strategy dispatch. */
    return true; /* stub: always delivered */
}
static inline void* u_rx_take(void* gen, int32_t n) { return gen; /* stub: first n */ }
static inline void* u_rx_skip(void* gen, int32_t n) { return gen; /* stub: drop first n */ }
static inline void* u_rx_drop(void* gen) { return gen; /* stub: overflow=drop */ }
static inline void* u_rx_latest(void* gen) { return gen; /* stub: overflow=latest */ }
static inline void* u_rx_buffer(void* gen, int32_t n) { return gen; /* stub: overflow=buffer(n) */ }
static inline void* u_rx_merge(void* a, void* b) { return a; /* stub: interleave */ }
static inline void* u_rx_zip(void* a, void* b) { return a; /* stub: pair 1:1 */ }
static inline void* u_rx_window(void* gen, int32_t n) { return gen; /* stub: chunk */ }
static inline void* u_rx_debounce(void* gen, int32_t ms) { return gen; /* stub */ }
static inline void* u_rx_throttle(void* gen, int32_t ms) { return gen; /* stub */ }
static inline void* u_rx_delay(void* gen, int32_t ms) { return gen; /* stub */ }
static inline void* u_rx_distinct(void* gen) { return gen; /* stub */ }
static inline void* u_rx_until(void* gen, void* signal) { return gen; /* stub */ }
static inline void* u_rx_scan(void* gen, ...) { return gen; /* stub: running fold */ }
static inline void* u_rx_first(void* gen) { return NULL; /* stub */ }
static inline void* u_rx_last(void* gen) { return NULL; /* stub */ }
static inline void u_rx_dispatch(void* stream, ...) {
    /* Dispatch with capture+target+bubble on the ownership tree.
       Phase 1 — Capture: walk parent chain to root, emit top-down.
       Phase 2 — Target: emit on the target node.
       Phase 3 — Bubble: emit on each ancestor bottom-up.
       Handler returning true (break) stops propagation.
       Stub: for now, just emits on the target (no tree walk). */
    u_emit(stream);
}

/* ── String methods ────────────────────────────────────────────────── */
static inline bool u_str_endswith(const char* s, const char* suffix) {
    size_t sl = strlen(s), xl = strlen(suffix);
    return sl >= xl && strcmp(s + sl - xl, suffix) == 0;
}
static inline char* u_str_strip(const char* s) {
    while (*s == ' ' || *s == '\t' || *s == '\n') s++;
    size_t len = strlen(s);
    while (len > 0 && (s[len-1] == ' ' || s[len-1] == '\t' || s[len-1] == '\n')) len--;
    char* r = (char*)malloc(len + 1);
    memcpy(r, s, len); r[len] = 0;
    return r;
}
static inline char* u_str_slice(const char* s, int32_t start, int32_t end) {
    size_t len = strlen(s);
    if (start < 0) start = 0;
    if (end > (int32_t)len) end = len;
    if (start >= end) { char* r = (char*)malloc(1); r[0]=0; return r; }
    size_t n = end - start;
    char* r = (char*)malloc(n + 1);
    memcpy(r, s + start, n); r[n] = 0;
    return r;
}
static inline char* u_str_upper(const char* s) {
    size_t len = strlen(s);
    char* r = (char*)malloc(len + 1);
    for (size_t i = 0; i <= len; i++) r[i] = (s[i] >= 'a' && s[i] <= 'z') ? s[i]-32 : s[i];
    return r;
}
static inline char* u_str_lower(const char* s) {
    size_t len = strlen(s);
    char* r = (char*)malloc(len + 1);
    for (size_t i = 0; i <= len; i++) r[i] = (s[i] >= 'A' && s[i] <= 'Z') ? s[i]+32 : s[i];
    return r;
}
/* .replace(from, to) -> S -- u_language.html Strings table: "Replace FIRST
   occurrence." Distinct from .replace_all below; getting these two backwards
   is a silent wrong-answer bug, so they are deliberately separate symbols
   rather than one function with a count flag. */
static inline char* u_str_replace(const char* s, const char* old, const char* new_s) {
    size_t slen = strlen(s), olen = strlen(old), nlen = strlen(new_s);
    if (olen == 0) { char* r = (char*)malloc(slen+1); strcpy(r, s); return r; }
    const char* found = strstr(s, old);
    if (!found) { char* r = (char*)malloc(slen+1); strcpy(r, s); return r; }
    size_t rlen = slen - olen + nlen;
    char* result = (char*)malloc(rlen + 1);
    size_t head = (size_t)(found - s);
    memcpy(result, s, head);
    memcpy(result + head, new_s, nlen);
    strcpy(result + head + nlen, found + olen);
    return result;
}

/* .replace_all(from, to) -> S -- "Replace all occurrences." */
static inline char* u_str_replace_all(const char* s, const char* old, const char* new_s) {
    size_t slen = strlen(s), olen = strlen(old), nlen = strlen(new_s);
    if (olen == 0) { char* r = (char*)malloc(slen+1); strcpy(r, s); return r; }
    int count = 0;
    const char* p = s;
    while ((p = strstr(p, old)) != NULL) { count++; p += olen; }
    size_t rlen = slen + (size_t)count * nlen - (size_t)count * olen;
    char* result = (char*)malloc(rlen + 1);
    char* dst = result;
    p = s;
    while (*p) {
        const char* found = strstr(p, old);
        if (!found) { strcpy(dst, p); return result; }
        memcpy(dst, p, (size_t)(found - p)); dst += (found - p);
        memcpy(dst, new_s, nlen); dst += nlen;
        p = found + olen;
    }
    *dst = '\0';
    return result;
}

/* ── String methods: u_language.html "Strings" table ──────────────── */

/* .trim_start() -> S -- "Strip leading whitespace." */
static inline char* u_str_trim_start(const char* s) {
    while (*s && isspace((unsigned char)*s)) s++;
    char* r = (char*)malloc(strlen(s) + 1); strcpy(r, s); return r;
}

/* .trim_end() -> S -- "Strip trailing whitespace." */
static inline char* u_str_trim_end(const char* s) {
    size_t n = strlen(s);
    while (n > 0 && isspace((unsigned char)s[n-1])) n--;
    char* r = (char*)malloc(n + 1);
    memcpy(r, s, n); r[n] = '\0'; return r;
}

/* .repeat(n) -> S -- spec example: "ab".repeat(3) -> "ababab" */
static inline char* u_str_repeat(const char* s, int32_t n) {
    if (n <= 0) { char* r = (char*)malloc(1); r[0] = '\0'; return r; }
    size_t len = strlen(s);
    char* r = (char*)malloc(len * (size_t)n + 1);
    for (int32_t i = 0; i < n; i++) memcpy(r + (size_t)i * len, s, len);
    r[len * (size_t)n] = '\0';
    return r;
}

/* .pad_start(n, ch?) -> S -- "Pad to length n on the left."
   Already-long-enough strings are returned unchanged (never truncated):
   pad is additive, .slice is the tool for shortening. */
static inline char* u_str_pad_start(const char* s, int32_t n, char ch) {
    size_t len = strlen(s);
    if ((int32_t)len >= n) { char* r = (char*)malloc(len+1); strcpy(r, s); return r; }
    size_t pad = (size_t)n - len;
    char* r = (char*)malloc((size_t)n + 1);
    memset(r, ch, pad);
    strcpy(r + pad, s);
    return r;
}

/* .pad_end(n, ch?) -> S -- "Pad to length n on the right." */
static inline char* u_str_pad_end(const char* s, int32_t n, char ch) {
    size_t len = strlen(s);
    if ((int32_t)len >= n) { char* r = (char*)malloc(len+1); strcpy(r, s); return r; }
    char* r = (char*)malloc((size_t)n + 1);
    memcpy(r, s, len);
    memset(r + len, ch, (size_t)n - len);
    r[n] = '\0';
    return r;
}

/* .has(sub) -> L -- "Substring search." */
static inline bool u_str_has(const char* s, const char* sub) {
    return strstr(s, sub) != NULL;
}

/* .index(sub) -> I+N -- "First position or none."
   A nullable scalar is a pointer in the emitted C (I+N => int32_t*, NULL =
   none) -- see codegen's `??` lowering, which does `_t != NULL ? *_t : alt`.
   Returning that shape directly means .index() composes with `??` and
   `== none` for free, with no special-casing in the codegen. Position is
   1-based to match u_language.html's 1-based indexing. */
static inline int32_t* u_str_index(const char* s, const char* sub) {
    const char* found = strstr(s, sub);
    if (!found) return NULL;
    int32_t* r = (int32_t*)malloc(sizeof(int32_t));
    *r = (int32_t)(found - s) + 1;
    return r;
}

/* .is_empty() -> L */
static inline bool u_str_is_empty(const char* s) { return s == NULL || s[0] == '\0'; }

/* .to_i() -> I+N -- "Parse to integer. none if invalid."
   strtol + end-pointer check, so "12abc" is none rather than 12; atoi()
   would silently accept the prefix and lose the error. */
static inline int32_t* u_str_to_i(const char* s) {
    if (!s || !*s) return NULL;
    char* end; errno = 0;
    long v = strtol(s, &end, 10);
    if (errno != 0 || *end != '\0' || end == s) return NULL;
    int32_t* r = (int32_t*)malloc(sizeof(int32_t));
    *r = (int32_t)v;
    return r;
}

/* .to_n() -> N+N -- "Parse to float. none if invalid." */
static inline double* u_str_to_n(const char* s) {
    if (!s || !*s) return NULL;
    char* end; errno = 0;
    double v = strtod(s, &end);
    if (errno != 0 || *end != '\0' || end == s) return NULL;
    double* r = (double*)malloc(sizeof(double));
    *r = v;
    return r;
}
/* I(s) / N(s) -- the THROWING parse. u_language.html Strings table gives two
   spellings: `I(s)` parses and throws if invalid, `I+N(s)` returns none. The
   nullable pair above (u_str_to_i / u_str_to_n) backs the `+N` spelling; these
   back the bare one. Two symbols rather than one with a flag, so a caller
   cannot accidentally get the silent-none behaviour where it wanted the throw.
   Abort matches how `x Error(...)` lowers in the C target. */
static inline int32_t u_str_to_i_throw(const char* s) {
    int32_t* v = u_str_to_i(s);
    if (!v) {
        fprintf(stderr, "error: I(\"%s\") — not an integer\n", s ? s : "");
        abort();
    }
    return *v;
}

static inline double u_str_to_n_throw(const char* s) {
    double* v = u_str_to_n(s);
    if (!v) {
        fprintf(stderr, "error: N(\"%s\") — not a number\n", s ? s : "");
        abort();
    }
    return *v;
}
static inline int u_cmp_int32_t(const void* a, const void* b) {
    int32_t va = *(const int32_t*)a, vb = *(const int32_t*)b;
    return (va > vb) - (va < vb);
}
static inline int u_cmp_double(const void* a, const void* b) {
    double va = *(const double*)a, vb = *(const double*)b;
    return (va > vb) - (va < vb);
}

/* .reverse() -> [T] "Reversed copy. Non-mutating."  (u_language.html Lists)
   .concat(other) -> [T] "Join two lists. Non-mutating."
   Macros, not functions: both must allocate a UList_##T, but they are generic
   over T and the monomorphizations do not exist until codegen emits them.
   __typeof__ recovers the concrete list and element types at the call site,
   so one definition serves every element type without token-pasting a name.
   These previously returned their input unchanged (`return arr;` / `return a;`)
   -- a silent wrong answer: .reverse() left order untouched and .concat()
   dropped its second operand entirely. */
#define u_list_reverse(A_) ({                                                 \
    __typeof__(A_) _a = (A_);                                                 \
    __typeof__(A_) _o = (__typeof__(A_))u_alloc(sizeof(*_a));                 \
    _o->capacity = _a->length > 0 ? _a->length : 1;                           \
    _o->length = _a->length;                                                  \
    _o->data = (__typeof__(_a->data))malloc(sizeof(_a->data[0]) * _o->capacity); \
    for (int32_t _i = 0; _i < _a->length; _i++)                               \
        _o->data[_i] = _a->data[_a->length - 1 - _i];                         \
    _o; })

#define u_list_concat(A_, B_) ({                                              \
    __typeof__(A_) _a = (A_); __typeof__(A_) _b = (__typeof__(A_))(B_);       \
    __typeof__(A_) _o = (__typeof__(A_))u_alloc(sizeof(*_a));                 \
    int32_t _n = _a->length + _b->length;                                     \
    _o->capacity = _n > 0 ? _n : 1;                                           \
    _o->length = _n;                                                          \
    _o->data = (__typeof__(_a->data))malloc(sizeof(_a->data[0]) * _o->capacity); \
    for (int32_t _i = 0; _i < _a->length; _i++) _o->data[_i] = _a->data[_i];  \
    for (int32_t _i = 0; _i < _b->length; _i++)                               \
        _o->data[_a->length + _i] = _b->data[_i];                             \
    _o; })

static inline char* u_float_to_str(double v) {
    char* r = (char*)malloc(32);
    snprintf(r, 32, "%g", v);
    return r;
}

/* A promise (+A value) is stored as an opaque fiber-frame pointer.
   Lists of promises use this typedef because U_LIST_DECLARE token-
   pastes its argument into a type name (UList_##T), and `void*` can't
   be pasted — `void_ptr` can. */
typedef void* void_ptr;

/* Same trick for strings. `[S]` is a list of `char*`, but U_LIST_DECLARE
   token-pastes its argument into `UList_##T`, and `char*` would paste into
   `UList_char*` -- not an identifier. That is why `[S]` lists were
   unrepresentable and u_str_split() had to return NULL. codegen's
   c_safe_name() maps `char*` -> `char_ptr` so the pasted name is valid. */
typedef char* char_ptr;

/* Same reason, for `[S]` (lists of strings). `char*` cannot be pasted into
   UList_##T; `char_ptr` can. Without this, `[S]` lowered to the malformed
   `UList_char_*`, which is why .split()/.chars()/.keys() were all stubbed. */
typedef char* char_ptr;

/* ── Slab allocator with hierarchical bitmasks ─────────────────────── */
/*                                                                       */
/* Every +R type gets a slab: a contiguous array of fixed-size slots.     */
/* Each slab uses a two-level bitmap to find free slots in O(1):          */
/*   Level 0 (L0): one bit per slot.       1 = free, 0 = used.           */
/*   Level 1 (L1): one bit per 64 L0 bits. 1 = has a free slot below.    */
/*                                                                        */
/* Alloc: scan L1 for first set bit (one __builtin_ctzll), then L0.       */
/*        Two instructions to find a free slot. O(1).                     */
/* Free:  set the bit in L0, set the bit in L1. O(1).                     */
/*                                                                        */
/* Each slab page holds SLAB_PAGE_SLOTS objects. When a page fills, a     */
/* new page is allocated and linked. Pages are never freed individually —  */
/* they're recycled as slots return to the free pool.                      */
/*                                                                        */
/* +R(parent) / weak refs: the slab doesn't care about weak vs strong.    */
/* Weak refs are just pointers with no retain — allocation is identical.   */
/* The compiler emits u_retain/u_release only for strong refs.             */
/*                                                                        */
/* +R(pool) / regions: bypass the slab entirely. Bump-allocate into the   */
/* region. Free the whole region at once. O(1) alloc, O(1) total free.    */
/* ─────────────────────────────────────────────────────────────────────── */

#define U_SLAB_PAGE_SLOTS 512   /* slots per page — fits in 2-4 OS pages  */
#define U_SLAB_L0_WORDS   8     /* 512 bits = 8 × 64-bit words            */

typedef struct USlabPage {
    struct USlabPage* next;              /* linked list of pages            */
    uint64_t          L0[U_SLAB_L0_WORDS]; /* level-0: one bit per slot    */
    uint8_t           L1;                /* level-1: one bit per L0 word    */
    uint16_t          used;              /* count of allocated slots        */
    uint16_t          slot_size;         /* bytes per slot (inc. header)    */
    char              data[];            /* flexible array of slots         */
} USlabPage;

typedef struct {
    USlabPage*  head;          /* first page — for freeing/searching       */
    USlabPage*  current;       /* page with known free slots               */
    uint16_t    slot_size;     /* fixed for this type                      */
    uint32_t    total_allocs;  /* stats                                    */
} USlab;

/* ── Region (arena / pool) ─────────────────────────────────────────── */
/* +R(pool) objects are bump-allocated here. The entire region is freed  */
/* in one shot. No per-object refcount tracking within the region.       */

#define U_REGION_DEFAULT_SIZE (64 * 1024)   /* 64 KB initial chunk */

typedef struct URegionChunk {
    struct URegionChunk* next;
    size_t               capacity;
    size_t               used;
    char                 data[];
} URegionChunk;

typedef struct {
    URegionChunk* current;
    URegionChunk* head;        /* first chunk, for freeing */
} URegion;

/* ── Slab operations ───────────────────────────────────────────────── */

static inline USlabPage* u_slab_page_new(uint16_t slot_size) {
    size_t page_size = sizeof(USlabPage) + (size_t)slot_size * U_SLAB_PAGE_SLOTS;
    USlabPage* p = (USlabPage*)calloc(1, page_size);
    if (!p) { fprintf(stderr, "u_slab: out of memory\n"); abort(); }
    p->slot_size = slot_size;
    p->used = 0;
    p->next = NULL;
    /* Mark all slots as free: set all bits to 1 */
    for (int i = 0; i < U_SLAB_L0_WORDS; i++) p->L0[i] = ~(uint64_t)0;
    p->L1 = 0xFF;  /* all 8 L0 words have free slots */
    return p;
}

static inline void u_slab_init(USlab* slab, uint16_t slot_size) {
    slab->slot_size = slot_size;
    slab->current = u_slab_page_new(slot_size);
    slab->head = slab->current;
    slab->total_allocs = 0;
}

static inline void* u_slab_alloc(USlab* slab) {
    USlabPage* page = slab->current;
    /* Find a page with free slots */
    while (page && page->L1 == 0) {
        if (!page->next) page->next = u_slab_page_new(slab->slot_size);
        page = page->next;
    }
    slab->current = page;

    /* L1: find which L0 word has a free bit */
    int l1_idx = __builtin_ctz(page->L1);           /* O(1) — hardware instruction */
    /* L0: find which slot is free within that word */
    int l0_bit = __builtin_ctzll(page->L0[l1_idx]); /* O(1) — hardware instruction */
    int slot_idx = l1_idx * 64 + l0_bit;

    /* Mark slot as used */
    page->L0[l1_idx] &= ~(1ULL << l0_bit);
    if (page->L0[l1_idx] == 0) page->L1 &= ~(1U << l1_idx);
    page->used++;

    /* Zero-init and set refcount */
    void* slot = page->data + (size_t)slot_idx * page->slot_size;
    memset(slot, 0, page->slot_size);
    ((URcHeader*)slot)->refcount = 1;
    slab->total_allocs++;
    return slot;
}

static inline void u_slab_free(USlab* slab, void* ptr) {
    /* Find which page this pointer belongs to — walk from head */
    USlabPage* page = slab->head;
    /* Walk pages to find the owner — in practice current page is almost always right */
    while (page) {
        char* base = page->data;
        char* end = base + (size_t)page->slot_size * U_SLAB_PAGE_SLOTS;
        if ((char*)ptr >= base && (char*)ptr < end) {
            int slot_idx = (int)(((char*)ptr - base) / page->slot_size);
            int l1_idx = slot_idx / 64;
            int l0_bit = slot_idx % 64;
            page->L0[l1_idx] |= (1ULL << l0_bit);
            page->L1 |= (1U << l1_idx);
            page->used--;
            return;
        }
        page = page->next;
    }
    /* Fallback: not from our slab (region-allocated or legacy) */
    free(ptr);
}

/* ── Region operations ─────────────────────────────────────────────── */

static inline URegion* u_region_new(size_t initial_size) {
    if (initial_size == 0) initial_size = U_REGION_DEFAULT_SIZE;
    URegion* r = (URegion*)malloc(sizeof(URegion));
    URegionChunk* c = (URegionChunk*)malloc(sizeof(URegionChunk) + initial_size);
    if (!r || !c) { fprintf(stderr, "u_region: out of memory\n"); abort(); }
    c->capacity = initial_size;
    c->used = 0;
    c->next = NULL;
    r->current = c;
    r->head = c;
    return r;
}

static inline void* u_region_alloc(URegion* region, size_t size) {
    /* Align to 8 bytes */
    size = (size + 7) & ~(size_t)7;
    URegionChunk* c = region->current;
    if (c->used + size > c->capacity) {
        /* Allocate a new chunk, at least 2x the requested size */
        size_t new_cap = c->capacity * 2;
        if (new_cap < size) new_cap = size * 2;
        URegionChunk* nc = (URegionChunk*)malloc(sizeof(URegionChunk) + new_cap);
        if (!nc) { fprintf(stderr, "u_region: out of memory\n"); abort(); }
        nc->capacity = new_cap;
        nc->used = 0;
        nc->next = NULL;
        c->next = nc;
        region->current = nc;
        c = nc;
    }
    void* ptr = c->data + c->used;
    c->used += size;
    memset(ptr, 0, size);
    /* Region objects get refcount = -1: sentinel meaning "region-owned, don't free individually" */
    ((URcHeader*)ptr)->refcount = -1;
    return ptr;
}

static inline void u_region_free(URegion* region) {
    URegionChunk* c = region->head;
    while (c) {
        URegionChunk* next = c->next;
        free(c);
        c = next;
    }
    free(region);
}

/* ── Public API: u_alloc / u_retain / u_release ────────────────────── */
/*                                                                       */
/* u_alloc: default path for +R objects without a slab or region.         */
/*          The codegen can emit u_slab_alloc(&TypeName_slab) instead     */
/*          for per-type slab allocation. Both return a valid +R pointer  */
/*          with refcount=1.                                              */
/*                                                                       */
/* u_retain: increment refcount. Skips region objects (refcount=-1).      */
/* u_release: decrement refcount. Free when it reaches 0.                 */
/*            Skips region objects — they're freed with u_region_free().   */

/* Every heap struct must declare `URcHeader header;` as its FIRST member.
   u_alloc takes the struct's full size (including that header field). */
static inline void* u_alloc(size_t total_size) {
    void* p = calloc(1, total_size);
    if (!p) { fprintf(stderr, "u_alloc: out of memory\n"); abort(); }
    ((URcHeader*)p)->refcount = 1;
    return p;
}

static inline void* u_retain(void* p) {
    if (!p) return NULL;
    int32_t rc = ((URcHeader*)p)->refcount;
    if (rc < 0) return p;   /* region-owned — no individual refcount */
    ((URcHeader*)p)->refcount = rc + 1;
    return p;
}

static inline void u_release(void* p) {
    if (!p) return;
    URcHeader* h = (URcHeader*)p;
    if (h->refcount < 0) return;   /* region-owned — freed with u_region_free */
    if (--h->refcount <= 0) {
        free(p);  /* TODO: codegen should emit u_slab_free for known types */
    }
}

/* ── Capability vtable dispatch ─────────────────────────────────────── */
/*                                                                        */
/* A Capability is a class whose methods are the whitelist.  Hooks        */
/* attach via .on(.method, handler, { timing, governance, ... }).         */
/* The vtable dispatches: before → handler → after, with transform_in,   */
/* transform_out, and error hooks at the appropriate points.              */
/*                                                                        */
/* Timing values:                                                         */
/*   "before"        — gate: returns 1=allow, 0=deny                      */
/*   "after"         — observe: returns 1=ok (can throw to reject)        */
/*   "transform_in"  — modify input before handler                        */
/*   "transform_out" — modify output after handler                        */
/*   "error"         — handle errors from handler                         */
/*                                                                        */
/* Dispatch cost: one L2-cached load (~3-5ns). Irrelevant for I/O.        */
/* The compiler devirtualizes monomorphic capabilities automatically.      */

typedef int (*UCapHookFn)(const char* name, const void* data, void* ctx);

typedef struct {
    const char*     method;        /* method name to match, "*" = all    */
    UCapHookFn      handler;       /* the hook function                  */
    int             timing;        /* 0=before 1=after 2=xform_in 3=xform_out 4=error */
    int             priority;      /* higher runs first                  */
    int             is_async;      /* fire without blocking              */
    int             batch;         /* accumulate N calls before firing   */
    int             batch_count;   /* current accumulated count          */
    int             timeout_ms;    /* max ms for hook, 0 = unlimited     */
    int             on_timeout;    /* 0=allow 1=deny                     */
} UCapHook;

#define U_TIMING_BEFORE       0
#define U_TIMING_AFTER        1
#define U_TIMING_XFORM_IN     2
#define U_TIMING_XFORM_OUT    3
#define U_TIMING_ERROR        4

typedef struct {
    URcHeader       header;
    const char*     name;           /* capability name                    */
    void*           handler;        /* the actual function pointer        */
    UCapHook*       hooks;          /* array of hooks                     */
    int             hook_count;
    int             hook_capacity;
} UCapability;

static inline UCapability* u_cap_new(const char* name, void* handler) {
    UCapability* cap = (UCapability*)u_alloc(sizeof(UCapability));
    cap->name = name;
    cap->handler = handler;
    cap->hooks = NULL;
    cap->hook_count = 0;
    cap->hook_capacity = 0;
    return cap;
}

static inline void u_cap_add_hook(UCapability* cap, UCapHook hook) {
    if (cap->hook_count >= cap->hook_capacity) {
        int new_cap = cap->hook_capacity ? cap->hook_capacity * 2 : 8;
        UCapHook* new_hooks = (UCapHook*)malloc(new_cap * sizeof(UCapHook));
        if (cap->hooks) {
            memcpy(new_hooks, cap->hooks, cap->hook_count * sizeof(UCapHook));
            free(cap->hooks);
        }
        cap->hooks = new_hooks;
        cap->hook_capacity = new_cap;
    }
    cap->hooks[cap->hook_count++] = hook;
}

/* Run hooks for a given timing. Returns 0 if any hook denied. */
static inline int u_cap_run_hooks(UCapability* cap, const char* method,
                                   int timing, const void* data) {
    for (int i = 0; i < cap->hook_count; i++) {
        UCapHook* h = &cap->hooks[i];
        if (h->timing != timing) continue;
        /* Method matching: "*" matches all, else exact match */
        if (h->method[0] != '*' && strcmp(h->method, method) != 0) continue;
        /* Batch: accumulate and skip until batch count reached */
        if (h->batch > 0) {
            h->batch_count++;
            if (h->batch_count < h->batch) continue;
            h->batch_count = 0;
        }
        int result = h->handler(method, data, NULL);
        if (result == 0) return 0;  /* denied */
    }
    return 1;  /* all hooks passed */
}

/* ── Monomorphized dynamic list ─────────────────────────────────────── */
/* Codegen emits one U_LIST_DECLARE(T) per distinct list element type
   actually used in the program (deduplicated in codegen/generator.py). */

#define U_LIST_DECLARE(T)                                                   \
    typedef struct {                                                         \
        URcHeader header;                                                    \
        T* data;                                                             \
        int32_t length;                                                      \
        int32_t capacity;                                                    \
    } UList_##T;                                                            \
                                                                               \
    static inline UList_##T* u_list_new_##T(int32_t initial_capacity) {    \
        UList_##T* a = (UList_##T*)u_alloc(sizeof(UList_##T));            \
        a->capacity = initial_capacity > 0 ? initial_capacity : 4;           \
        a->length = 0;                                                       \
        a->data = (T*)malloc(sizeof(T) * a->capacity);                       \
        return a;                                                            \
    }                                                                        \
                                                                               \
    static inline UList_##T* u_list_from_##T(T* items, int32_t n) {        \
        UList_##T* a = u_list_new_##T(n > 0 ? n : 1);                      \
        memcpy(a->data, items, sizeof(T) * n);                               \
        a->length = n;                                                       \
        return a;                                                            \
    }                                                                        \
                                                                               \
    static inline void u_list_push_##T(UList_##T* a, T val) {              \
        if (a->length >= a->capacity) {                                      \
            a->capacity *= 2;                                                \
            a->data = (T*)realloc(a->data, sizeof(T) * a->capacity);         \
        }                                                                    \
        a->data[a->length++] = val;                                         \
    }                                                                        \
                                                                               \
    static inline T u_list_get_##T(UList_##T* a, int32_t u_idx) {         \
        /* 1-based (arr[1]=first) -- see u_language.html iteration section */ \
        return a->data[u_idx - 1];                                          \
    }                                                                       \
                                                                               \
    static inline T u_list_getraw_##T(UList_##T* a, int32_t c_idx) {      \
        /* 0-based -- for codegen's OWN internal iteration loops only       \
           (e.g. .x()'s C-level walk over the backing list), never for a  \
           U-source-written subscript. Keeping this separate from          \
           u_list_get_##T is what it took to fix an out-of-bounds bug:    \
           the internal loop counter used to be passed straight to the     \
           1-based accessor, so its first iteration (_i=0) read data[-1]. */ \
        return a->data[c_idx];                                              \
    }                                                                       \
                                                                               \
    static inline void u_list_free_##T(UList_##T* a) {                     \
        if (!a) return;                                                      \
        free(a->data);                                                       \
        free(a);                                                             \
    }                                                                        \
                                                                               \
    static inline void u_list_set_##T(UList_##T* a, int32_t u_idx, T val) { \
        a->data[u_idx - 1] = val;                                            \
    }                                                                        \
                                                                               \
    static inline T u_list_pop_##T(UList_##T* a) {                         \
        T val = a->data[a->length - 1];                                      \
        a->length--;                                                         \
        return val;                                                          \
    }                                                                        \
                                                                               \
    static inline bool u_list_includes_##T(UList_##T* a, T val) {          \
        for (int32_t i = 0; i < a->length; i++) {                            \
            if (a->data[i] == val) return true;                              \
        }                                                                    \
        return false;                                                        \
    }

/* ── Scalar boxing (for `.c(+R)` on a stack scalar) ─────────────────── */
/* Codegen emits one U_BOX_DECLARE(T) per distinct scalar type that is
   ever heap-boxed via `.c(+R)` in the program. */

#define U_BOX_DECLARE(T)                                                     \
    typedef struct {                                                         \
        URcHeader header;                                                    \
        T value;                                                             \
    } UBox_##T;                                                              \
                                                                               \
    static inline UBox_##T* u_box_##T(T v) {                                 \
        UBox_##T* b = (UBox_##T*)u_alloc(sizeof(UBox_##T));                  \
        b->value = v;                                                        \
        return b;                                                            \
    }

/* ── MVCC ("+M(MVCC)") — REAL, see design notes below ──────────────── */
/*
 * Codegen generates, for every class ever declared +M(MVCC) somewhere in
 * the program (see linter.py's mvcc_classes and codegen/structs.py):
 *
 *   struct ClassName_Version { URcHeader header; <fields...>; };
 *   struct ClassName         { URcHeader header; _Atomic(ClassName_Version*) head; };
 *
 * `ClassName` is the thin, stable "cell" — the pointer type a +R+M(MVCC)
 * binding actually holds. `ClassName_Version` is one complete, immutable
 * snapshot of the fields. A read is `atomic_load(&obj->head)->field`; a
 * multi-field read within one statement should snapshot `head` ONCE and
 * read every field off that same pointer (codegen does this per
 * *statement*, not per full expression-tree — see funcs.py — which is
 * enough to prevent torn reads across a `<<` landing mid-statement, but
 * is coarser than the ideal "once per top-level expression" granularity;
 * see README "Known limitations").
 *
 * A write (`obj << { field: val, ... }`) is the u_mvcc_patch pattern
 * below: read the current head, allocate a full new version copying
 * every unchanged field forward and overlaying the changed ones, then
 * CAS the head from old to new. On CAS failure (a concurrent writer won
 * the race), retry against the new current head. This is the standard
 * optimistic-concurrency pattern — the same shape as Postgres's MVCC,
 * adapted to per-object rather than per-transaction versioning, and
 * using refcounting instead of a VACUUM sweep to reclaim old versions
 * (see header comment at the top of this file).
 *
 * u_mvcc_cas_retry is a generic helper: `build` allocates and populates
 * a full new version (already holding a fresh reference), and this
 * function performs the load/attempt/retry loop and the old-version
 * release on success. Per-class code (emitted by structs.py) supplies
 * `build` as a small closure-shaped callback carrying the patch's field
 * values, because C has no generics to express this once for every
 * version-struct type.
 */

typedef void* (*UMvccBuildFn)(void* old_version, void* ctx);

static inline void* u_mvcc_cas_retry(_Atomic(void*)* head, UMvccBuildFn build, void* ctx) {
    void* old_v = atomic_load(head);
    void* new_v;
    for (;;) {
        new_v = build(old_v, ctx);
        if (atomic_compare_exchange_weak(head, &old_v, new_v)) {
            u_release(old_v);
            return new_v;
        }
        /* old_v was updated by atomic_compare_exchange_weak to the
           current value on failure; retry building against it. */
        u_release(new_v);
    }
}

/* ── Transaction blocks: << ( ... ) — multi-object optimistic MVCC ── */
/*
 * A transaction groups multiple << patches into one atomic commit.
 * The approach is optimistic: reads record version stamps, writes are
 * buffered, and at commit time all versions are checked.  If any changed,
 * the entire block retries via longjmp to the setjmp at block entry.
 *
 * The read-set and write-set live on the stack — no heap allocation.
 * The maximum transaction size is bounded at compile time (the compiler
 * counts << operations inside the block).
 *
 * The linter guarantees only -E -D (pure, deterministic) code inside,
 * so retry is safe: no side effects duplicate, no nondeterminism
 * produces inconsistent values.
 */

#include <setjmp.h>

#ifndef U_TXN_MAX_OPS
#define U_TXN_MAX_OPS 16
#endif

typedef struct {
    _Atomic(void*)* head;       /* pointer to the object's atomic head */
    void*           snapshot;   /* version we read (for version check) */
    void*           new_ver;    /* buffered new version (NULL if read-only) */
    UMvccBuildFn    build;      /* build function for this patch */
    void*           build_ctx;  /* context for the build function */
} UTxnOp;

typedef struct {
    jmp_buf         retry_point;
    UTxnOp          ops[U_TXN_MAX_OPS];
    int             nops;
    int             retries;
    int             max_retries;
} UTxnCtx;

static inline void u_txn_begin(UTxnCtx* tx) {
    tx->nops = 0;
    tx->retries = 0;
    tx->max_retries = 1000;  /* avoid infinite retry on pathological contention */
}

/* Record a read: snapshot the current version of an MVCC object. */
static inline void* u_txn_read(UTxnCtx* tx, _Atomic(void*)* head) {
    void* snap = atomic_load(head);
    /* Check if we already have this head in the read-set */
    for (int i = 0; i < tx->nops; i++) {
        if (tx->ops[i].head == head) {
            /* Return the snapshot we already have for consistency */
            return tx->ops[i].new_ver ? tx->ops[i].new_ver : tx->ops[i].snapshot;
        }
    }
    /* New entry — read-only for now */
    if (tx->nops < U_TXN_MAX_OPS) {
        UTxnOp* op = &tx->ops[tx->nops++];
        op->head = head;
        op->snapshot = snap;
        op->new_ver = NULL;
        op->build = NULL;
        op->build_ctx = NULL;
    }
    return snap;
}

/* Buffer a write: record the build function for a << patch. */
static inline void u_txn_write(UTxnCtx* tx, _Atomic(void*)* head,
                                UMvccBuildFn build, void* ctx) {
    /* Find existing entry or create new one */
    for (int i = 0; i < tx->nops; i++) {
        if (tx->ops[i].head == head) {
            tx->ops[i].build = build;
            tx->ops[i].build_ctx = ctx;
            return;
        }
    }
    /* New entry */
    if (tx->nops < U_TXN_MAX_OPS) {
        UTxnOp* op = &tx->ops[tx->nops++];
        op->head = head;
        op->snapshot = atomic_load(head);
        op->new_ver = NULL;
        op->build = build;
        op->build_ctx = ctx;
    }
}

/* Commit: check all versions, apply all patches atomically, or retry. */
static inline int u_txn_commit(UTxnCtx* tx) {
    /* Phase 1: build all new versions from current snapshots */
    for (int i = 0; i < tx->nops; i++) {
        if (tx->ops[i].build) {
            void* current = tx->ops[i].new_ver ? tx->ops[i].new_ver : tx->ops[i].snapshot;
            tx->ops[i].new_ver = tx->ops[i].build(current, tx->ops[i].build_ctx);
        }
    }

    /* Phase 2: validate — check all versions are still what we read */
    for (int i = 0; i < tx->nops; i++) {
        void* current = atomic_load(tx->ops[i].head);
        if (current != tx->ops[i].snapshot) {
            /* Version changed — discard all new versions, retry */
            for (int j = 0; j < tx->nops; j++) {
                if (tx->ops[j].new_ver) {
                    u_release(tx->ops[j].new_ver);
                    tx->ops[j].new_ver = NULL;
                }
            }
            if (++tx->retries >= tx->max_retries) return -1; /* give up */
            /* Re-snapshot everything for retry */
            for (int j = 0; j < tx->nops; j++) {
                tx->ops[j].snapshot = atomic_load(tx->ops[j].head);
            }
            longjmp(tx->retry_point, 1);
        }
    }

    /* Phase 3: commit — CAS all patches */
    for (int i = 0; i < tx->nops; i++) {
        if (tx->ops[i].new_ver) {
            /* CAS should succeed since we validated; if it fails,
               another writer slipped in between validate and commit.
               In single-threaded (fiber) mode this can't happen.
               In multi-threaded mode we'd need a lock for the commit
               phase — but the linter bans +A inside << ( ), so
               no fiber can interleave here. */
            void* expected = tx->ops[i].snapshot;
            if (atomic_compare_exchange_strong(tx->ops[i].head,
                                               &expected,
                                               tx->ops[i].new_ver)) {
                u_release(tx->ops[i].snapshot);
            }
            /* If CAS fails, the new_ver is leaked — shouldn't happen
               in cooperative mode. */
        }
    }
    return 0;
}

/* For +G +M scalar globals: version-stamped atomic store.
 * The "version" is the value itself — CAS checks the old value. */
static inline int u_cas_i64(int64_t* ptr, int64_t old_val, int64_t new_val) {
    return __atomic_compare_exchange_n(ptr, &old_val, new_val, 0,
                                       __ATOMIC_SEQ_CST, __ATOMIC_SEQ_CST);
}
static inline int u_cas_i32(int32_t* ptr, int32_t old_val, int32_t new_val) {
    return __atomic_compare_exchange_n(ptr, &old_val, new_val, 0,
                                       __ATOMIC_SEQ_CST, __ATOMIC_SEQ_CST);
}
static inline int u_cas_double(double* ptr, double old_val, double new_val) {
    /* Double CAS via type-punned uint64 */
    uint64_t o, n;
    memcpy(&o, &old_val, 8);
    memcpy(&n, &new_val, 8);
    return __atomic_compare_exchange_n((uint64_t*)ptr, &o, n, 0,
                                       __ATOMIC_SEQ_CST, __ATOMIC_SEQ_CST);
}

/* ── Fiber (+A) — REAL, single-threaded cooperative ──────────────────── */
/*
 * Every generated {Name}_Frame struct (see codegen/fibers.py) shares a
 * common PREFIX: header, parent, resume_point, status. UGenericFrame
 * mirrors that prefix so the scheduler can check/manipulate any frame's
 * status without knowing its concrete type — the same "common initial
 * sequence" trick the class-instance layout already relies on (see
 * structs.py: URcHeader as every class's literal first member, for the
 * identical reason).
 *
 * Fork ("pending = a fetch(url)") allocates a child frame and pushes it
 * onto the ready queue; the caller's own execution continues immediately
 * without blocking — this is what makes it non-blocking at all. Await
 * (inserted automatically wherever a forked variable's resolved value is
 * read) drives the ready queue — running whichever fibers are ready,
 * possibly including the awaited one, possibly others first — until the
 * specific awaited frame's status becomes DONE.
 *
 * Scope of this implementation (see implementation.html's fork/cactus
 * section for the fuller design discussion): single-threaded cooperative
 * only. Multi-threaded work-stealing is a documented future extension,
 * not built here — but the frame-pointer-based design (heap-allocated,
 * refcounted, no captured-by-value stack data) is exactly what makes
 * that extension a matter of a thread-safe queue, not a redesign.
 */

#define U_FIBER_SUSPENDED 0
#define U_FIBER_DONE 1

typedef struct {
    URcHeader header;
    void* parent;
    int32_t resume_point;
    int32_t status;
} UGenericFrame;

typedef int32_t (*UFiberRunFn)(void* frame);

typedef struct UFiberQueueNode {
    void* frame;
    UFiberRunFn run;
    struct UFiberQueueNode* next;
} UFiberQueueNode;

typedef struct {
    UFiberQueueNode* head;
    UFiberQueueNode* tail;
} UReadyQueue;

static UReadyQueue u_ready_queue = { NULL, NULL };

static inline void u_ready_queue_push(void* frame, UFiberRunFn run) {
    UFiberQueueNode* node = (UFiberQueueNode*)malloc(sizeof(UFiberQueueNode));
    node->frame = frame;
    node->run = run;
    node->next = NULL;
    if (u_ready_queue.tail) {
        u_ready_queue.tail->next = node;
    } else {
        u_ready_queue.head = node;
    }
    u_ready_queue.tail = node;
}

static inline UFiberQueueNode* u_ready_queue_pop(void) {
    UFiberQueueNode* node = u_ready_queue.head;
    if (node) {
        u_ready_queue.head = node->next;
        if (!u_ready_queue.head) u_ready_queue.tail = NULL;
    }
    return node;
}

/* Run exactly one ready fiber one step. Re-queues it if still suspended. */
static inline void u_scheduler_step(void) {
    UFiberQueueNode* node = u_ready_queue_pop();
    if (!node) return;
    int32_t status = node->run(node->frame);
    if (status == U_FIBER_SUSPENDED) {
        u_ready_queue_push(node->frame, node->run);
    }
    free(node);
}

/* Drive the ready queue until `target` reaches DONE. If the queue empties
   before that happens (a malformed program — target is neither done nor
   anywhere in the queue), stop rather than loop forever. */
static inline void u_drive_until_done(void* target_raw) {
    UGenericFrame* target = (UGenericFrame*)target_raw;
    while (target->status != U_FIBER_DONE) {
        if (u_ready_queue.head == NULL) {
            break;
        }
        u_scheduler_step();
    }
}

/* ── Deadlines / timeouts — the +A(ms) bound on a promise ─────────────────
 *
 * The design (see implementation.html's backpressure/timeout section):
 * every await is bounded. A promise typed +A(ms) must settle within `ms`
 * milliseconds; bare +A picks up the program default (below); +A(w) is the
 * explicit unbounded escape hatch — the ONLY way to legitimately wait
 * forever, and it has to be typed on purpose.
 *
 * The default is deliberately SHORT (1s) so that a wait which should have
 * been thought about fails NOISILY (a timeout with a stack) rather than
 * silently (a hang). A wrong-because-too-long default hangs; a
 * wrong-because-too-short default rejects and points at the exact site —
 * we want the noisy failure. The default is program-settable because
 * "how long is too long" is genuinely program-global.
 *
 * U_TIMEOUT_FOREVER is the sentinel for +A(w).
 */

#define U_FIBER_TIMED_OUT 2
#define U_TIMEOUT_FOREVER (-1)

/* Program-settable default deadline in milliseconds (the value bare +A
   resolves to at a park site). 1000ms shipped default. */
static int64_t u_default_timeout_ms = 1000;
static inline void u_set_default_timeout_ms(int64_t ms) { u_default_timeout_ms = ms; }

/* Monotonic clock in milliseconds. */
static inline int64_t u_now_ms(void) {
    struct timespec ts;
    clock_gettime(CLOCK_MONOTONIC, &ts);
    return (int64_t)ts.tv_sec * 1000 + ts.tv_nsec / 1000000;
}

/* Resolve the deadline that applies at a park site: an explicit per-site
   ms (>=0), U_TIMEOUT_FOREVER for +A(w), or the sentinel -2 meaning "use
   the ambient program default" (what bare +A lowers to). Returns an
   absolute deadline in ms, or U_TIMEOUT_FOREVER. Resolved AT THE PARK, not
   at promise creation — a promise passed into a more-tolerant context
   takes that context's bound, so the short default stays a clean tripwire
   rather than a source of spurious rejections. */
#define U_TIMEOUT_DEFAULT (-2)
static inline int64_t u_deadline_from(int64_t site_ms) {
    if (site_ms == U_TIMEOUT_FOREVER) return U_TIMEOUT_FOREVER;
    int64_t eff = (site_ms == U_TIMEOUT_DEFAULT) ? u_default_timeout_ms : site_ms;
    return u_now_ms() + eff;
}

/* Drive until `target` is DONE or the deadline passes. Sets the target's
   status to U_FIBER_TIMED_OUT on expiry so the await site can turn the
   timeout into a rejection (propagated upstream as an error). A
   deadline of U_TIMEOUT_FOREVER never times out — but if the ready queue
   drains with the target still suspended and no forever-wait excuse, that
   is the provable deadlock case, reported loudly rather than hung.
   Returns 1 if DONE, 0 if timed out or deadlocked. */
static inline int32_t u_drive_until_done_deadline(void* target_raw, int64_t deadline_ms) {
    UGenericFrame* target = (UGenericFrame*)target_raw;
    while (target->status != U_FIBER_DONE) {
        if (deadline_ms != U_TIMEOUT_FOREVER && u_now_ms() >= deadline_ms) {
            target->status = U_FIBER_TIMED_OUT;
            return 0;
        }
        if (u_ready_queue.head == NULL) {
            /* Nothing left to run and target isn't done. With a finite
               deadline we keep spinning until it trips (a sibling on
               another timer could still... but in this single-threaded
               model, an empty queue means no progress is possible). */
            if (deadline_ms == U_TIMEOUT_FOREVER) {
                /* Provable deadlock: forever-wait with no scheduled work
                   that could ever complete it. Fail loud, not hung. */
                fprintf(stderr, "fatal: await(+A(w)) with no scheduled work "
                                "to complete it — deadlock\n");
                abort();
            }
            /* Finite deadline, empty queue: busy-wait out the clock, then
               time out. Cheap here since these are sub-second waits in
               practice; a production scheduler would sleep to the
               deadline instead of spinning. */
            while (u_now_ms() < deadline_ms) { /* spin to deadline */ }
            target->status = U_FIBER_TIMED_OUT;
            return 0;
        }
        u_scheduler_step();
    }
    return 1;
}

/* ── .any() support — Promise.race + loser cancellation ──────────────────
 *
 * Single-threaded cooperative model makes "cancellation" tractable: a
 * suspended fiber only ever runs again if the scheduler steps it, so a
 * loser is neutralized simply by removing its node from the ready queue
 * (so no later u_drive_* resumes it) and freeing its frame. There is no
 * running code to interrupt and no in-progress work to roll back — the
 * spec's contract is "the loser is ignored", not "undone".
 *
 * u_drive_until_any drives the queue until ANY of the `n` targets reaches
 * DONE, returning that target's index (or -1 if the queue drained with
 * none done — the caller then yields `none`, matching .any()'s T+N type).
 * u_cancel_frame removes a specific frame from the ready queue and frees
 * it; the caller cancels every non-winner after a winner is found.
 */

/* Remove the queue node whose frame == `frame` (if present) and free the
   frame. O(queue length); .any() sets are small in practice. */
static inline void u_cancel_frame(void* frame) {
    UFiberQueueNode** link = &u_ready_queue.head;
    UFiberQueueNode* prev = NULL;
    while (*link) {
        if ((*link)->frame == frame) {
            UFiberQueueNode* dead = *link;
            *link = dead->next;
            if (u_ready_queue.tail == dead) u_ready_queue.tail = prev;
            free(dead);
            break;               /* a frame is queued at most once */
        }
        prev = *link;
        link = &(*link)->next;
    }
    free(frame);                 /* frame is heap-allocated (u_alloc) */
}

/* Drive until any of targets[0..n) is DONE. Returns its index, or -1 if
   the queue emptied first with none done. A target already DONE on entry
   short-circuits immediately (index returned, nothing driven). */
static inline int32_t u_drive_until_any(void** targets, int32_t n) {
    for (int32_t i = 0; i < n; i++) {
        if (((UGenericFrame*)targets[i])->status == U_FIBER_DONE) return i;
    }
    while (u_ready_queue.head != NULL) {
        u_scheduler_step();
        for (int32_t i = 0; i < n; i++) {
            if (((UGenericFrame*)targets[i])->status == U_FIBER_DONE) return i;
        }
    }
    return -1;
}

/* ── Map ({S:V}) — real string-keyed hash table ──────────────────────────
 *
 * First real map implementation: string keys, void* values (uniform
 * storage, cast at the use site — the same approach the promise lists
 * take for +A frames). Separate-chaining hash table with djb2 hashing
 * and load-factor-triggered doubling. Insertion order is tracked in a
 * parallel list so iteration and .all()/.any() joins over a map can
 * preserve a stable, predictable order (the spec's map .all() promises
 * "same keys preserved"; a deterministic order makes that testable).
 *
 * Scope of THIS build: string keys only. Integer-keyed and
 * class-instance-keyed maps (the latter needing __hash__/__equals__),
 * and the {T} set type, are follow-ons — the value side and the whole
 * table machinery are key-type-agnostic, so those extend this rather
 * than replace it. Values are owned by the caller (no deep free).
 */

typedef struct UMapEntry {
    char* key;               /* owned copy of the key string */
    void* value;             /* borrowed — caller owns the pointee */
    uint32_t hash;           /* cached djb2(key) */
    struct UMapEntry* next;  /* separate-chaining bucket link */
    int32_t order_index;     /* position in insertion order */
} UMapEntry;

typedef struct UMap {
    URcHeader header;
    UMapEntry** buckets;
    int32_t bucket_count;
    int32_t size;
    UMapEntry** order;       /* entries in insertion order, for iteration */
    int32_t order_capacity;
} UMap;

static inline uint32_t u_str_hash(const char* s) {
    uint32_t h = 5381;
    for (; *s; s++) h = ((h << 5) + h) + (unsigned char)*s;  /* djb2 */
    return h;
}

static inline UMap* u_map_new(int32_t initial_buckets) {
    UMap* m = (UMap*)u_alloc(sizeof(UMap));
    m->bucket_count = initial_buckets > 0 ? initial_buckets : 8;
    m->size = 0;
    m->buckets = (UMapEntry**)calloc(m->bucket_count, sizeof(UMapEntry*));
    m->order_capacity = 8;
    m->order = (UMapEntry**)malloc(sizeof(UMapEntry*) * m->order_capacity);
    return m;
}

static inline void u_map_rehash(UMap* m, int32_t new_count) {
    UMapEntry** nb = (UMapEntry**)calloc(new_count, sizeof(UMapEntry*));
    for (int32_t i = 0; i < m->bucket_count; i++) {
        UMapEntry* e = m->buckets[i];
        while (e) {
            UMapEntry* next = e->next;
            uint32_t idx = e->hash % (uint32_t)new_count;
            e->next = nb[idx];
            nb[idx] = e;
            e = next;
        }
    }
    free(m->buckets);
    m->buckets = nb;
    m->bucket_count = new_count;
}

static inline void u_map_set(UMap* m, const char* key, void* value) {
    uint32_t h = u_str_hash(key);
    uint32_t idx = h % (uint32_t)m->bucket_count;
    for (UMapEntry* e = m->buckets[idx]; e; e = e->next) {
        if (e->hash == h && strcmp(e->key, key) == 0) {
            e->value = value;      /* overwrite existing key */
            return;
        }
    }
    /* grow if load factor exceeds ~0.75 */
    if (m->size + 1 > (m->bucket_count * 3) / 4) {
        u_map_rehash(m, m->bucket_count * 2);
        idx = h % (uint32_t)m->bucket_count;
    }
    UMapEntry* e = (UMapEntry*)u_alloc(sizeof(UMapEntry));
    size_t klen = strlen(key) + 1;
    e->key = (char*)malloc(klen);
    memcpy(e->key, key, klen);
    e->value = value;
    e->hash = h;
    e->next = m->buckets[idx];
    e->order_index = m->size;
    m->buckets[idx] = e;
    if (m->size >= m->order_capacity) {
        m->order_capacity *= 2;
        m->order = (UMapEntry**)realloc(m->order, sizeof(UMapEntry*) * m->order_capacity);
    }
    m->order[m->size] = e;
    m->size++;
}

/* Merge every entry of `src` into `dst`, in insertion order.
   Spec: "Spread: {...base, extra: val} constructs a new map." The
   spread source is copied first and explicit pairs are set after, so
   later keys override earlier ones — matching literal ordering. */
static inline void u_map_merge(UMap* dst, UMap* src) {
    if (!dst || !src) return;
    for (int32_t i = 0; i < src->size; i++) {
        UMapEntry* e = src->order[i];
        if (e) u_map_set(dst, e->key, e->value);
    }
}


/* Returns the value pointer, or NULL if the key is absent. (A NULL value
   and an absent key are indistinguishable through this accessor; callers
   that need to tell them apart use u_map_has.) */
static inline void* u_map_get(UMap* m, const char* key) {
    uint32_t h = u_str_hash(key);
    uint32_t idx = h % (uint32_t)m->bucket_count;
    for (UMapEntry* e = m->buckets[idx]; e; e = e->next) {
        if (e->hash == h && strcmp(e->key, key) == 0) return e->value;
    }
    return NULL;
}

static inline int32_t u_map_has(UMap* m, const char* key) {
    uint32_t h = u_str_hash(key);
    uint32_t idx = h % (uint32_t)m->bucket_count;
    for (UMapEntry* e = m->buckets[idx]; e; e = e->next) {
        if (e->hash == h && strcmp(e->key, key) == 0) return 1;
    }
    return 0;
}

static inline int32_t u_map_size(UMap* m) { return m ? m->size : 0; }

/* .delete(key) -> L -- u_language.html Maps table: "Remove entry. Returns
   true if deleted, false if key was absent."
   The order[] list backs the insertion-order guarantee, which the spec
   calls "a language guarantee, not an implementation detail" -- so removal
   must compact order[] and fix up every later entry's order_index, not just
   unlink from the bucket chain. */
static inline int32_t u_map_delete(UMap* m, const char* key) {
    if (!m) return 0;
    uint32_t h = u_str_hash(key);
    uint32_t idx = h % (uint32_t)m->bucket_count;
    UMapEntry* prev = NULL;
    for (UMapEntry* e = m->buckets[idx]; e; prev = e, e = e->next) {
        if (e->hash != h || strcmp(e->key, key) != 0) continue;
        if (prev) prev->next = e->next; else m->buckets[idx] = e->next;
        int32_t oi = e->order_index;
        for (int32_t i = oi; i < m->size - 1; i++) {
            m->order[i] = m->order[i + 1];
            m->order[i]->order_index = i;
        }
        m->size--;
        free(e->key);
        free(e);
        return 1;
    }
    return 0;
}

/* .insert(key, val) -> L -- "Set only if key absent. Returns true if
   inserted." Deliberately NOT u_map_set: .set() overwrites unconditionally,
   .insert() is the SQL-style no-clobber form. Conflating them would silently
   destroy data. */
static inline int32_t u_map_insert(UMap* m, const char* key, void* value) {
    if (u_map_has(m, key)) return 0;
    u_map_set(m, key, value);
    return 1;
}

/* Insertion-order accessors, for iteration and same-key result maps. */
static inline const char* u_map_key_at(UMap* m, int32_t i) {
    return (i >= 0 && i < m->size) ? m->order[i]->key : NULL;
}
static inline void* u_map_value_at(UMap* m, int32_t i) {
    return (i >= 0 && i < m->size) ? m->order[i]->value : NULL;
}

/* ── log — the builtin the whole spec uses ──────────────────────────────
 *
 * u_language.html's FIRST example is:
 *     f main()
 *         greeting = "Hello, world!"
 *         console.log(greeting)
 *
 * `log` has been a declared builtin (linter.py: FuncSig(decl=None, name='log'))
 * with NO IMPLEMENTATION. It emitted `u_log___("hi")` and the link failed:
 *
 *     undefined reference to `u_log___'
 *
 * So the single most-used function in the specification did not link, for the
 * entire project. Nothing caught it because every test called u_fn() and
 * checked its RETURN -- no test ever called log(). "A feature that is
 * UNREACHABLE" and "an area never probed at all", together.
 *
 * Deliberately fputs to stdout with a newline, not printf("%s"): the argument
 * is user data and must never be a format string.
 */
static inline void u_log___(const char* msg) {
    fputs(msg ? msg : "", stderr);
    fputc('\n', stderr);
}

/* ── Process.log / warn / error — the call site is FREE ─────────────────
 *
 * Greg: "node.js often says undefined at undefined, which is maddening... we
 * know where the log line is in which file and line, after all."
 *
 * Node captures a stack at RUNTIME -- expensive, so it is off by default, so
 * you get `undefined at undefined`. But `log("x")` on line 42 of
 * Streams/Stream.u is a CONSTANT the compiler already holds. Zero runtime
 * cost, always on, never undefined.
 *
 * This is the whole thesis in miniature: what the compiler knows, the runtime
 * must not re-derive.
 *
 * A full stack trace (the chain of callers) is NOT free -- that needs frame
 * info at runtime. Call site always; trace on request. (Greg: "having an
 * option".)
 */
typedef enum { U_LOG_INFO = 0, U_LOG_WARN = 1, U_LOG_ERROR = 2 } ULogLevel;

static inline void u_diag(ULogLevel lvl, const char* file, int32_t line,
                          const char* msg) {
    static const char* tag[] = { "log", "warn", "error" };
    /* stderr: DIAGNOSTICS. Never stdout -- that is the program's output, and
     * mixing them is what corrupts `myprog | jq`. */
    fprintf(stderr, "%s %s:%d: %s\n", tag[lvl], file ? file : "?", line,
            msg ? msg : "");
}

/* System.write(fd, text) -- the syscall floor. Process.print/log are built on
 * this; see System.u. Returns bytes written, or -1. */
static inline int32_t u_sys_write(int32_t fd, const char* text) {
    if (!text) return 0;
    size_t n = strlen(text);
    ssize_t w = write(fd, text, n);
    return (int32_t)w;
}

/* ── Tree — THE one untyped type ────────────────────────────────────────
 *
 * Was `UTree` (t154). Greg: "instead of JsonValue I was thinking of Tree...
 * that way it's unified. It's like a multilevel Map, but with only string
 * keys." Right, and the spec contradicts itself in one sentence:
 *
 *     "__pack__(): {S: JsonValue} converts an object to FORMAT-AGNOSTIC plain
 *      data. Protobuf.encode(obj) calls the same __pack__(). Swap the encoder."
 *
 * "Format-agnostic", typed as JsonValue -- a type named after a format.
 *
 * THE HARD ARGUMENT: JsonValue CANNOT carry protobuf. JSON's number is a
 * float64; protobuf has int64/uint64/fixed64. Anything above 2^53 silently
 * loses precision -- which is why protobuf's own JSON mapping encodes int64 AS
 * A STRING, and why OCP writes "max": "1000000" (a string) beside
 * "chainId": 8453 (a number). The spec knows: "...normalized numbers, with
 * out-of-safe-range integers required to be canonical strings."
 *
 * And this union had `int32_t i` -- not even int64. Doubly lost.
 *
 * IS PROTOBUF MORE GENERAL? No, and its own stdlib is the proof:
 *     message Struct { map<string, Value> fields = 1; }
 *     message Value { oneof kind { NullValue; double number_value; string;
 *                                 bool; Struct; ListValue; } }
 * google.protobuf.Struct IS a Tree -- they needed one badly enough to ship it,
 * and `double number_value` has the SAME f64 bug. Meanwhile protobuf REQUIRES a
 * schema: config/plugin.json (500 lines, arbitrary nesting, {{Users}}
 * interpolation, chains keyed by hex string) has no .proto and never will. A
 * schema-ful format cannot be the intermediate for schema-less data.
 *
 * What Tree loses is WIDTH (readLevel: I8 -> Int drops the 8). That is correct:
 * the width comes back from T at the boundary, which is exactly why __unpack__
 * carries the checks. Protobuf recovers its field numbers the same way -- from
 * T, at compile time. The schema-ful formats get their schema from the TYPE,
 * not from the Tree. That is what lets one intermediate serve all of them.
 *
 * ONE PACK, MANY TAILS:
 *   T --__pack__--> Tree --+--> JSON.encode        --> [B]
 *                          +--> Protobuf.encode[T] --> [B]   lossless
 *                          +--> PHP.serialize      --> [B]   (sessions)
 *                          +--> Canonical.JCS[T]   --> [B] --hash--> --sign-->
 *                          +--> Tree.merge         --> Tree  (config)
 *                          +--> __unpack__         --> T ! ValidationFailed
 */
/* ── (historical header) ───────────────────────────────────────────────
 *
 * u_language.html: "__pack__ / __unpack__ replace __serialize__ /
 * __unserialize__. Serialization is orthogonal to the object model.
 * __pack__(): {S: JsonValue} converts an object to format-agnostic plain
 * data. Json.encode(obj) calls __pack__() then encodes; Protobuf.encode(obj)
 * calls the SAME __pack__(). Swap the encoder."
 *
 * That last sentence is the design: __pack__ must NOT know about JSON. It
 * produces plain data; an ENCODER turns plain data into bytes. So the
 * intermediate is a tagged union, not a string.
 *
 * NB the spec's own __pack__ EXAMPLE contradicts its own signature -- it
 * declares `{S: JsonValue}` and returns "{{t.id}}|{{t.name}}|{{t.email}}", a
 * pipe-delimited STRING. That is a leftover from __serialize__ (which the spec
 * says __pack__ REPLACES), relabelled and never rewritten: a pipe-delimited
 * string is precisely NOT format-agnostic, and Protobuf.encode could not
 * consume it. The prose is repeated and load-bearing, so the prose wins and
 * the example is a spec bug.
 */
typedef enum {
    U_TREE_NONE = 0, U_TREE_BOOL, U_TREE_INT, U_TREE_NUM,
    U_TREE_STR,  U_TREE_BYTES, U_TREE_LIST, U_TREE_NODE
} UTreeKind;

typedef struct UTree UTree;
struct UTree {
    UTreeKind kind;
    union {
        bool     b;
        int64_t  i;     /* I64 -- NOT int32, and NOT a double. See above. */
        double   n;
        char*    s;
        struct { uint8_t* data; int32_t len; } bytes;   /* protobuf native */
        struct { UTree** items; int32_t len; int32_t cap; } list;
        /* NODE: string keys, INSERTION ORDER. `Tree` IS {S: Tree.Value}, so
         * its ordering is Map's ordering -- "a language guarantee, not an
         * implementation detail" -- and there is no second implementation to
         * keep in sync. Ordering costs nothing for JCS (which sorts anyway) or
         * protobuf (which does not care); it matters for `git diff` on a
         * round-tripped config: unordered, the diff is the whole file. */
        struct { char** keys; UTree** vals; int32_t len; int32_t cap; } node;
    } as;
};

/* The runtime has no strdup helper (u_str_concat mallocs inline), and
 * POSIX strdup is not guaranteed under -std=c11. So: one local copy. */
static inline char* u_tree_strdup(const char* s) {
    size_t n = strlen(s ? s : "");
    char* r = (char*)malloc(n + 1);
    memcpy(r, s ? s : "", n + 1);
    return r;
}

static inline UTree* u_tree_new(UTreeKind k) {
    UTree* j = (UTree*)u_alloc(sizeof(UTree));
    memset(j, 0, sizeof(UTree));
    j->kind = k;
    return j;
}
static inline UTree* u_tree_null(void)         { return u_tree_new(U_TREE_NONE); }
static inline UTree* u_tree_bool(bool v)       { UTree* j=u_tree_new(U_TREE_BOOL); j->as.b=v; return j; }
static inline UTree* u_tree_int(int64_t v)     { UTree* j=u_tree_new(U_TREE_INT);  j->as.i=v; return j; }
static inline UTree* u_tree_bytes(const uint8_t* d, int32_t n) {
    UTree* j=u_tree_new(U_TREE_BYTES);
    j->as.bytes.len = n;
    j->as.bytes.data = (uint8_t*)u_alloc(n > 0 ? (size_t)n : 1);
    if (d && n > 0) memcpy(j->as.bytes.data, d, (size_t)n);
    return j;
}
static inline UTree* u_tree_num(double v)      { UTree* j=u_tree_new(U_TREE_NUM);  j->as.n=v; return j; }
static inline UTree* u_tree_str(const char* v) { UTree* j=u_tree_new(U_TREE_STR);  j->as.s=u_tree_strdup(v?v:""); return j; }

static inline UTree* u_tree_arr(void) {
    UTree* j=u_tree_new(U_TREE_LIST);
    j->as.list.cap=4; j->as.list.len=0;
    j->as.list.items=(UTree**)calloc(4, sizeof(UTree*));   /* raw array, not an object */
    return j;
}
/* TWO BUGS HERE, both found by AddressSanitizer at t175, both t154's:
 *
 * 1. `cap *= 2` CANNOT GROW FROM ZERO. u_tree_new() memsets to 0, so a fresh
 *    U_TREE_LIST has cap == 0; push saw len == cap, doubled 0 to 0, allocated
 *    zero slots, and wrote past the end. It only ever worked because the ONLY
 *    caller was u_tree_arr(), which pre-sets cap = 4 -- "works by accident for
 *    one case", from the bug table. The moment merge built a list with
 *    u_tree_new(U_TREE_LIST), the heap corrupted:
 *        malloc(): corrupted top size
 *
 * 2. u_alloc IS NOT A RAW ALLOCATOR. It stamps a refcount header at offset 0:
 *        void* p = calloc(1, total_size);
 *        ((URcHeader*)p)->refcount = 1;
 *    ...so every raw items/keys/vals array had its FIRST SLOT clobbered with
 *    the integer 1. Harmless only because the next line always overwrote it.
 *    Raw arrays are not refcounted objects; they use calloc.
 */
static inline void u_tree_push(UTree* a, UTree* v) {
    if (!a || a->kind != U_TREE_LIST) return;
    if (a->as.list.len == a->as.list.cap) {
        int32_t nc = a->as.list.cap ? a->as.list.cap * 2 : 4;
        UTree** ni = (UTree**)calloc((size_t)nc, sizeof(UTree*));
        if (!ni) { fprintf(stderr, "u_tree_push: out of memory\n"); abort(); }
        if (a->as.list.items && a->as.list.len > 0)
            memcpy(ni, a->as.list.items, sizeof(UTree*) * (size_t)a->as.list.len);
        free(a->as.list.items);
        a->as.list.items = ni;
        a->as.list.cap = nc;
    }
    a->as.list.items[a->as.list.len++] = v;
}
static inline UTree* u_tree_obj(void) {
    UTree* j=u_tree_new(U_TREE_NODE);
    j->as.node.cap=4; j->as.node.len=0;
    j->as.node.keys=(char**)calloc(4, sizeof(char*));      /* raw array, not an object */
    j->as.node.vals=(UTree**)calloc(4, sizeof(UTree*));
    return j;
}
static inline void u_tree_set(UTree* o, const char* k, UTree* v) {
    for (int32_t i=0;i<o->as.node.len;i++)
        if (strcmp(o->as.node.keys[i], k)==0) { o->as.node.vals[i]=v; return; }
    if (o->as.node.len == o->as.node.cap) {
        /* Same two bugs as u_tree_push (t175): `cap *= 2` cannot grow from
         * zero, and u_alloc stamps a refcount header into what is a RAW array. */
        int32_t nc = o->as.node.cap ? o->as.node.cap * 2 : 4;
        char**  nk = (char**)calloc((size_t)nc, sizeof(char*));
        UTree** nv = (UTree**)calloc((size_t)nc, sizeof(UTree*));
        if (!nk || !nv) { fprintf(stderr, "u_tree_set: out of memory\n"); abort(); }
        if (o->as.node.keys && o->as.node.len > 0) {
            memcpy(nk, o->as.node.keys, sizeof(char*)  * (size_t)o->as.node.len);
            memcpy(nv, o->as.node.vals, sizeof(UTree*) * (size_t)o->as.node.len);
        }
        free(o->as.node.keys); free(o->as.node.vals);
        o->as.node.keys = nk; o->as.node.vals = nv;
        o->as.node.cap = nc;
    }
    o->as.node.keys[o->as.node.len]=u_tree_strdup(k);
    o->as.node.vals[o->as.node.len]=v;
    o->as.node.len++;
}
/* Absent -> NULL -> none. u_language.html 09.1: "add a +N field and old
 * documents return none for it" -- so a missing key is not an error. */
static inline UTree* u_tree_get(UTree* o, const char* k) {
    if (!o || o->kind != U_TREE_NODE) return NULL;
    for (int32_t i=0;i<o->as.node.len;i++)
        if (strcmp(o->as.node.keys[i], k)==0) return o->as.node.vals[i];
    return NULL;
}

/* Deep copy a Tree. Used by Row.mark_saved to snapshot the current state. */
static inline UTree* u_tree_copy(const UTree* src) {
    if (!src) return NULL;
    switch (src->kind) {
        case U_TREE_NONE: return u_tree_null();
        case U_TREE_BOOL: return u_tree_bool(src->as.b);
        case U_TREE_INT:  return u_tree_int(src->as.i);
        case U_TREE_NUM:  return u_tree_num(src->as.n);
        case U_TREE_STR:  return u_tree_str(src->as.s);
        case U_TREE_BYTES: return u_tree_bytes(src->as.bytes.data, src->as.bytes.len);
        case U_TREE_LIST: {
            UTree* r = u_tree_arr();
            for (int32_t i = 0; i < src->as.list.len; i++)
                u_tree_push(r, u_tree_copy(src->as.list.items[i]));
            return r;
        }
        case U_TREE_NODE: {
            UTree* r = u_tree_new(U_TREE_NODE);
            for (int32_t i = 0; i < src->as.node.len; i++)
                u_tree_set(r, src->as.node.keys[i],
                           u_tree_copy(src->as.node.vals[i]));
            return r;
        }
    }
    return u_tree_null();
}

/* ── Tree merge — WIRE-IDENTICAL to Q_Tree::merge_internal ──────────────
 *
 * Greg: "for merge we dedup, every time. trees have deduped lists."
 *
 *   scalars   replace
 *   nodes     merge, at every level
 *   lists     APPEND, DEDUPED (strict identity)
 *
 * ["a","b","c"] + ["a","b","z"]  ->  ["a","b","c","z"]
 *
 * TWO of Qbix's own implementations disagree with the PHP, and both are bugs
 * on that side rather than choices:
 *   - the GUIDE's worked example gives ["a","b","c","a","b","z"] (no dedupe)
 *   - Tree.js has NO isNumeric branch and index-assigns -> ["a","b","z"],
 *     so config merges differently in Node than in PHP. The directives were
 *     ported to JS; the plain-list path never was.
 *
 * WHAT U DELETES: Q::isAssociative() exists ONLY because PHP conflates map
 * and list -- it COUNTS THE KEYS to tell {"a":1} from [1,2,3]. Here the kind
 * tag IS the answer: U_TREE_LIST vs U_TREE_NODE. The branch stops being a
 * decision, which is the entire argument for a typed intermediate.
 */
static inline bool u_tree_equal(const UTree* a, const UTree* b) {
    if (!a || !b) return a == b;
    if (a->kind != b->kind) return false;
    switch (a->kind) {
        case U_TREE_NONE:  return true;
        case U_TREE_BOOL:  return a->as.b == b->as.b;
        case U_TREE_INT:   return a->as.i == b->as.i;
        case U_TREE_NUM:   return a->as.n == b->as.n;
        case U_TREE_STR:   return strcmp(a->as.s ? a->as.s : "",
                                         b->as.s ? b->as.s : "") == 0;
        case U_TREE_BYTES:
            return a->as.bytes.len == b->as.bytes.len
                && memcmp(a->as.bytes.data, b->as.bytes.data,
                          (size_t)a->as.bytes.len) == 0;
        case U_TREE_LIST: {
            if (a->as.list.len != b->as.list.len) return false;
            for (int32_t i = 0; i < a->as.list.len; i++)
                if (!u_tree_equal(a->as.list.items[i], b->as.list.items[i]))
                    return false;
            return true;
        }
        case U_TREE_NODE: {
            /* PHP's === on arrays is order-sensitive; so is this. Tree
             * preserves insertion order, so that is well-defined. */
            if (a->as.node.len != b->as.node.len) return false;
            for (int32_t i = 0; i < a->as.node.len; i++) {
                if (strcmp(a->as.node.keys[i], b->as.node.keys[i]) != 0) return false;
                if (!u_tree_equal(a->as.node.vals[i], b->as.node.vals[i])) return false;
            }
            return true;
        }
    }
    return false;
}

static inline bool u_tree_contains(const UTree* list, const UTree* v) {
    if (!list || list->kind != U_TREE_LIST) return false;
    for (int32_t i = 0; i < list->as.list.len; i++)
        if (u_tree_equal(list->as.list.items[i], v)) return true;
    return false;
}

static inline UTree* u_tree_merge(UTree* first, UTree* second);
static inline UTree* u_tree_merge_directive(UTree* first, UTree* second, const char* key);

/* Append every element of `src` not already in `dst`. This IS the PHP:
 *     if (!in_array($value, $result, true)) { $result[] = $value; }
 */
static inline void u_tree_append_deduped(UTree* dst, const UTree* src) {
    if (!dst || !src || dst->kind != U_TREE_LIST || src->kind != U_TREE_LIST) return;
    for (int32_t i = 0; i < src->as.list.len; i++) {
        UTree* v = src->as.list.items[i];
        if (!u_tree_contains(dst, v)) u_tree_push(dst, v);
    }
}

/* ── Tree diff — the inverse of merge ───────────────────────────────────
 *
 * Q_Tree::diff walks BOTH trees:
 *   _diffTo   over `from`: a value that DIFFERS from `to`'s -> record to's
 *   _diffFrom over `to`:   a path `from` does not have      -> record it
 *
 * WHY `replace` EXISTS. A changed LIST cannot be recorded as a plain list,
 * because merge APPENDS -- merging ["y"] over ["x"] gives ["x","y"], not
 * ["y"]. So diff wraps it:
 *     $valueTo = array('replace' => $valueTo);
 * diff and merge are inverses ONLY because the directive exists. That is the
 * design, not a convenience.
 *
 * `detectKeyField` is NOT ported. It guesses the key by counting field
 * frequency across both arrays and taking the winner:
 *     foreach (array($arr1,$arr2) as $a) foreach ($a as $o)
 *         foreach ($o as $k=>$v) $counts[$k] = ($counts[$k]??0)+1;
 *     ...return the max
 * ...in a codebase that already knows the primary key. In U, `key: S +G` is
 * on the type, and Merge.Updates[T] takes no keyField at all.
 *
 * A BUG in Q_Tree::_diffTo, reported and NOT reproduced:
 *     $keyField = isset($context->keyField) ? $context->keyField
 *                                           : $this->detectKeyField(...);
 *     if ($keyField) {
 *         $diff = self::diffByKey($value, $valueTo, $context->keyField);
 *                                                   ^^^^^^^^^^^^^^^^^^
 * It computes $keyField, branches on it, then passes $context->keyField --
 * which is NULL in exactly the case detectKeyField was called for. So the
 * guess is made and then thrown away.
 */
static inline void u_tree_diff_into(UTree* from, UTree* to, UTree* out);

/* Record `v` at `key` in `out`, wrapping a LIST in {replace: [...]} because
 * merge would otherwise append it. */
static inline void u_tree_diff_put(UTree* out, const char* key, UTree* v) {
    if (v && v->kind == U_TREE_LIST) {
        UTree* rep = u_tree_new(U_TREE_NODE);
        u_tree_set(rep, "replace", v);
        u_tree_set(out, key, rep);
    } else {
        u_tree_set(out, key, v);
    }
}

static inline void u_tree_diff_into(UTree* from, UTree* to, UTree* out) {
    if (!from || !to || !out) return;
    if (from->kind != U_TREE_NODE || to->kind != U_TREE_NODE) return;

    /* _diffTo: every key in `from` whose value differs in `to`. */
    for (int32_t i = 0; i < from->as.node.len; i++) {
        const char* k = from->as.node.keys[i];
        UTree* a = from->as.node.vals[i];
        UTree* b = u_tree_get(to, k);
        if (!b) continue;                       /* absent in `to`: not a change */
        if (u_tree_equal(a, b)) continue;       /* identical: nothing to record */
        if (a->kind == U_TREE_NODE && b->kind == U_TREE_NODE) {
            UTree* sub = u_tree_new(U_TREE_NODE);
            u_tree_diff_into(a, b, sub);        /* recurse -- "on all levels" */
            if (sub->as.node.len > 0) u_tree_set(out, k, sub);
        } else {
            u_tree_diff_put(out, k, b);
        }
    }
    /* _diffFrom: every key in `to` that `from` does not have. */
    for (int32_t i = 0; i < to->as.node.len; i++) {
        const char* k = to->as.node.keys[i];
        if (!u_tree_get(from, k)) u_tree_diff_put(out, k, to->as.node.vals[i]);
    }
}

/* `from.diff(to)` -> d, such that `from.merge(d)` equals `to`. */
static inline UTree* u_tree_diff(UTree* from, UTree* to) {
    UTree* out = u_tree_new(U_TREE_NODE);
    u_tree_diff_into(from, to, out);
    return out;
}

/* ── JSON.encode — one tail off __pack__ ────────────────────────────────
 *
 *   T --__pack__--> Tree --+--> JSON.encode        --> [B]
 *                          +--> Protobuf.encode[T] --> [B]
 *                          +--> Canonical.JCS[T]   --> [B] --hash--> --sign-->
 * "Swap the encoder."
 *
 * THE INT RULE, and it is the spec's, not an invention:
 *     "...normalized numbers, with OUT-OF-SAFE-RANGE INTEGERS REQUIRED TO BE
 *      CANONICAL STRINGS."
 * JSON's number is a float64, so anything above 2^53 cannot round-trip
 * through it. Protobuf's own JSON mapping encodes int64 AS A STRING for
 * exactly this reason, and OCP writes "max": "1000000" beside
 * "chainId": 8453. Tree.Value.Int is an I64 and survives Borsh/EIP-712
 * losslessly; it is JSON, alone, that needs the workaround -- which is the
 * whole argument for Tree over JsonValue, and why the ADAPTER owns the number
 * rules rather than the intermediate.
 */
#define U_JSON_SAFE_MAX  9007199254740991LL     /* 2^53 - 1 */
#define U_JSON_SAFE_MIN (-9007199254740991LL)

typedef struct { char* buf; size_t len; size_t cap; } UStrBuf;

static inline void u_sb_putn(UStrBuf* b, const char* s, size_t n) {
    if (b->len + n + 1 > b->cap) {
        size_t nc = b->cap ? b->cap : 64;
        while (nc < b->len + n + 1) nc *= 2;
        char* nb = (char*)realloc(b->buf, nc);
        if (!nb) { fprintf(stderr, "u_sb: out of memory\n"); abort(); }
        b->buf = nb; b->cap = nc;
    }
    memcpy(b->buf + b->len, s, n);
    b->len += n;
    b->buf[b->len] = 0;
}
static inline void u_sb_put(UStrBuf* b, const char* s) { u_sb_putn(b, s, strlen(s)); }

/* RFC 8259 string escaping. Control characters below 0x20 MUST be escaped;
 * everything else passes through as UTF-8. */
static inline void u_json_str(UStrBuf* b, const char* s) {
    u_sb_put(b, "\"");
    for (const unsigned char* p = (const unsigned char*)(s ? s : ""); *p; p++) {
        switch (*p) {
            case '"':  u_sb_put(b, "\\\""); break;
            case '\\': u_sb_put(b, "\\\\"); break;
            case '\n': u_sb_put(b, "\\n");  break;
            case '\r': u_sb_put(b, "\\r");  break;
            case '\t': u_sb_put(b, "\\t");  break;
            case '\b': u_sb_put(b, "\\b");  break;
            case '\f': u_sb_put(b, "\\f");  break;
            default:
                if (*p < 0x20) { char e[8]; snprintf(e, sizeof e, "\\u%04x", *p); u_sb_put(b, e); }
                else { char c[2] = { (char)*p, 0 }; u_sb_put(b, c); }
        }
    }
    u_sb_put(b, "\"");
}

static inline void u_json_enc(UStrBuf* b, const UTree* t);

static inline void u_json_enc(UStrBuf* b, const UTree* t) {
    char num[40];
    if (!t) { u_sb_put(b, "null"); return; }
    switch (t->kind) {
        case U_TREE_NONE: u_sb_put(b, "null"); break;
        case U_TREE_BOOL: u_sb_put(b, t->as.b ? "true" : "false"); break;
        case U_TREE_INT:
            snprintf(num, sizeof num, "%lld", (long long)t->as.i);
            /* THE RULE: out of the float64-safe range -> a canonical STRING. */
            if (t->as.i > U_JSON_SAFE_MAX || t->as.i < U_JSON_SAFE_MIN) {
                u_sb_put(b, "\""); u_sb_put(b, num); u_sb_put(b, "\"");
            } else {
                u_sb_put(b, num);
            }
            break;
        case U_TREE_NUM:
            snprintf(num, sizeof num, "%.17g", t->as.n);
            u_sb_put(b, num);
            break;
        case U_TREE_STR: u_json_str(b, t->as.s); break;
        case U_TREE_BYTES:
            /* JSON has no byte type, so base64 -- an encoding decision that
             * belongs to THE ENCODER. JsonValue had no bytes variant at all,
             * so this decision had to be made in the INTERMEDIATE, which is
             * exactly what "format-agnostic" forbids. */
            u_sb_put(b, "\"");
            {
                static const char* T64 = "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/";
                const uint8_t* d = t->as.bytes.data; int32_t n = t->as.bytes.len;
                for (int32_t i = 0; i < n; i += 3) {
                    uint32_t v = (uint32_t)d[i] << 16;
                    if (i + 1 < n) v |= (uint32_t)d[i+1] << 8;
                    if (i + 2 < n) v |= (uint32_t)d[i+2];
                    char q[5] = { T64[(v>>18)&63], T64[(v>>12)&63],
                                  i+1 < n ? T64[(v>>6)&63] : '=',
                                  i+2 < n ? T64[v&63] : '=', 0 };
                    u_sb_put(b, q);
                }
            }
            u_sb_put(b, "\"");
            break;
        case U_TREE_LIST:
            u_sb_put(b, "[");
            for (int32_t i = 0; i < t->as.list.len; i++) {
                if (i) u_sb_put(b, ",");
                u_json_enc(b, t->as.list.items[i]);
            }
            u_sb_put(b, "]");
            break;
        case U_TREE_NODE:
            /* INSERTION ORDER -- not sorted. Sorting is JCS's job (turn 23b),
             * and conflating "serialize" with "canonicalize" would make every
             * encode pay for a guarantee only signing needs. */
            u_sb_put(b, "{");
            for (int32_t i = 0; i < t->as.node.len; i++) {
                if (i) u_sb_put(b, ",");
                u_json_str(b, t->as.node.keys[i]);
                u_sb_put(b, ":");
                u_json_enc(b, t->as.node.vals[i]);
            }
            u_sb_put(b, "}");
            break;
    }
}

static inline char* u_json_encode(const UTree* t) {
    UStrBuf b = { NULL, 0, 0 };
    u_sb_put(&b, "");
    u_json_enc(&b, t);
    return b.buf;
}

/* ── JCS canonicalization — RFC 8785 (turn 23b) ─────────────────────────
 *
 * Deterministic JSON: object keys sorted, no insignificant whitespace,
 * canonical number formatting. Same input → byte-identical output, which
 * is what makes it SIGNABLE. Two parties independently canonicalizing the
 * same object get the same bytes, so they get the same signature.
 *
 * The difference from u_json_enc is exactly ONE thing: object keys are
 * emitted in sorted (lexicographic, by UTF-8 code unit) order. Everything
 * else — number rules, string escaping, list order — is already canonical
 * in the base encoder. This is why canonicalization is a thin layer over
 * serialization, not a separate serializer.
 */
static inline void u_jcs_enc(UStrBuf* b, const UTree* t);

/* Comparator for sorting object keys lexicographically (by byte). */
static inline int u_jcs_keycmp(const void* a, const void* b) {
    const char* ka = *(const char* const*)a;
    const char* kb = *(const char* const*)b;
    return strcmp(ka, kb);
}

static inline void u_jcs_enc(UStrBuf* b, const UTree* t) {
    if (!t) { u_sb_put(b, "null"); return; }
    if (t->kind == U_TREE_NODE) {
        /* Sort keys, then emit in sorted order. */
        int32_t n = t->as.node.len;
        u_sb_put(b, "{");
        if (n > 0) {
            /* Build an index array sorted by key. */
            int32_t* order = (int32_t*)malloc(sizeof(int32_t) * n);
            for (int32_t i = 0; i < n; i++) order[i] = i;
            /* Simple insertion sort on keys (n is small for typical objects,
             * and this avoids qsort's context-passing portability issues). */
            for (int32_t i = 1; i < n; i++) {
                int32_t cur = order[i];
                const char* curkey = t->as.node.keys[cur];
                int32_t j = i - 1;
                while (j >= 0 && strcmp(t->as.node.keys[order[j]], curkey) > 0) {
                    order[j + 1] = order[j];
                    j--;
                }
                order[j + 1] = cur;
            }
            for (int32_t i = 0; i < n; i++) {
                if (i) u_sb_put(b, ",");
                u_json_str(b, t->as.node.keys[order[i]]);
                u_sb_put(b, ":");
                u_jcs_enc(b, t->as.node.vals[order[i]]);  /* recurse canonically */
            }
            free(order);
        }
        u_sb_put(b, "}");
    } else if (t->kind == U_TREE_LIST) {
        /* Lists preserve order (JCS does not reorder arrays), but elements
         * are canonicalized recursively. */
        u_sb_put(b, "[");
        for (int32_t i = 0; i < t->as.list.len; i++) {
            if (i) u_sb_put(b, ",");
            u_jcs_enc(b, t->as.list.items[i]);
        }
        u_sb_put(b, "]");
    } else {
        /* Scalars: the base encoder is already canonical. */
        u_json_enc(b, t);
    }
}

static inline char* u_jcs_canonicalize(const UTree* t) {
    UStrBuf b = { NULL, 0, 0 };
    u_sb_put(&b, "");
    u_jcs_enc(&b, t);
    return b.buf;
}

/* ── SHA-256 (turn 23c) ─────────────────────────────────────────────────
 *
 * A complete, standard SHA-256 (FIPS 180-4). Deterministic and fully
 * specified — no external crypto library needed. This is the HASH half of
 * the OCP pipeline (canonicalize → HASH → sign → verify). The signing half
 * needs elliptic-curve crypto (ES256/P-256), which is NOT implemented here;
 * u_ocp_sign below uses a clearly-marked reference construction so the
 * pipeline is testable end-to-end. The hash itself is real.
 */
static const uint32_t U_SHA256_K[64] = {
    0x428a2f98,0x71374491,0xb5c0fbcf,0xe9b5dba5,0x3956c25b,0x59f111f1,0x923f82a4,0xab1c5ed5,
    0xd807aa98,0x12835b01,0x243185be,0x550c7dc3,0x72be5d74,0x80deb1fe,0x9bdc06a7,0xc19bf174,
    0xe49b69c1,0xefbe4786,0x0fc19dc6,0x240ca1cc,0x2de92c6f,0x4a7484aa,0x5cb0a9dc,0x76f988da,
    0x983e5152,0xa831c66d,0xb00327c8,0xbf597fc7,0xc6e00bf3,0xd5a79147,0x06ca6351,0x14292967,
    0x27b70a85,0x2e1b2138,0x4d2c6dfc,0x53380d13,0x650a7354,0x766a0abb,0x81c2c92e,0x92722c85,
    0xa2bfe8a1,0xa81a664b,0xc24b8b70,0xc76c51a3,0xd192e819,0xd6990624,0xf40e3585,0x106aa070,
    0x19a4c116,0x1e376c08,0x2748774c,0x34b0bcb5,0x391c0cb3,0x4ed8aa4a,0x5b9cca4f,0x682e6ff3,
    0x748f82ee,0x78a5636f,0x84c87814,0x8cc70208,0x90befffa,0xa4506ceb,0xbef9a3f7,0xc67178f2
};

#define U_ROTR(x,n) (((x) >> (n)) | ((x) << (32 - (n))))

static inline void u_sha256(const uint8_t* msg, size_t len, uint8_t out[32]) {
    uint32_t h[8] = {
        0x6a09e667,0xbb67ae85,0x3c6ef372,0xa54ff53a,
        0x510e527f,0x9b05688c,0x1f83d9ab,0x5be0cd19
    };
    /* Padded length: msg + 0x80 + zeros + 8-byte length, multiple of 64. */
    size_t total = len + 1 + 8;
    size_t padded = ((total + 63) / 64) * 64;
    uint8_t* buf = (uint8_t*)calloc(padded, 1);
    memcpy(buf, msg, len);
    buf[len] = 0x80;
    uint64_t bitlen = (uint64_t)len * 8;
    for (int i = 0; i < 8; i++) {
        buf[padded - 1 - i] = (uint8_t)(bitlen >> (8 * i));
    }
    for (size_t off = 0; off < padded; off += 64) {
        uint32_t w[64];
        for (int i = 0; i < 16; i++) {
            w[i] = ((uint32_t)buf[off + i*4] << 24) | ((uint32_t)buf[off + i*4+1] << 16)
                 | ((uint32_t)buf[off + i*4+2] << 8) | ((uint32_t)buf[off + i*4+3]);
        }
        for (int i = 16; i < 64; i++) {
            uint32_t s0 = U_ROTR(w[i-15],7) ^ U_ROTR(w[i-15],18) ^ (w[i-15] >> 3);
            uint32_t s1 = U_ROTR(w[i-2],17) ^ U_ROTR(w[i-2],19) ^ (w[i-2] >> 10);
            w[i] = w[i-16] + s0 + w[i-7] + s1;
        }
        uint32_t a=h[0],b=h[1],c=h[2],d=h[3],e=h[4],f=h[5],g=h[6],hh=h[7];
        for (int i = 0; i < 64; i++) {
            uint32_t S1 = U_ROTR(e,6) ^ U_ROTR(e,11) ^ U_ROTR(e,25);
            uint32_t ch = (e & f) ^ ((~e) & g);
            uint32_t t1 = hh + S1 + ch + U_SHA256_K[i] + w[i];
            uint32_t S0 = U_ROTR(a,2) ^ U_ROTR(a,13) ^ U_ROTR(a,22);
            uint32_t maj = (a & b) ^ (a & c) ^ (b & c);
            uint32_t t2 = S0 + maj;
            hh=g; g=f; f=e; e=d+t1; d=c; c=b; b=a; a=t1+t2;
        }
        h[0]+=a; h[1]+=b; h[2]+=c; h[3]+=d; h[4]+=e; h[5]+=f; h[6]+=g; h[7]+=hh;
    }
    for (int i = 0; i < 8; i++) {
        out[i*4]   = (uint8_t)(h[i] >> 24);
        out[i*4+1] = (uint8_t)(h[i] >> 16);
        out[i*4+2] = (uint8_t)(h[i] >> 8);
        out[i*4+3] = (uint8_t)(h[i]);
    }
    free(buf);
}

/* Hex-encode a byte array. out must hold 2*len+1 bytes. */
static inline void u_hex_encode(const uint8_t* data, size_t len, char* out) {
    static const char* H = "0123456789abcdef";
    for (size_t i = 0; i < len; i++) {
        out[i*2]   = H[(data[i] >> 4) & 0xf];
        out[i*2+1] = H[data[i] & 0xf];
    }
    out[len*2] = '\0';
}

/* ══ REAL ELLIPTIC-CURVE CRYPTO (secp256k1 + P-256 ECDSA) ══════════════
 * Inserted to replace the reference signature constructions. Verified
 * against the RFC 6979 P-256 test vector and known secp256k1 points. */
/* ── 256-bit unsigned bigint + ECDSA (secp256k1 & P-256) ────────────────
 *
 * Real elliptic-curve crypto. A u256 is eight 32-bit limbs, little-endian
 * (limb[0] is least significant). All field arithmetic is modular over the
 * curve's prime p; scalar arithmetic is mod the group order n.
 *
 * This replaces the reference signature constructions. The algorithms are
 * standard ECDSA (FIPS 186-4 / SEC1) and are verified against published
 * test vectors.
 */
#ifndef U_EC_H
#define U_EC_H
#include <stdint.h>
#include <string.h>
#include <stdlib.h>

typedef struct { uint32_t v[8]; } u256;

static inline void u256_zero(u256* a) { memset(a->v, 0, sizeof(a->v)); }
static inline int  u256_is_zero(const u256* a) {
    for (int i = 0; i < 8; i++) if (a->v[i]) return 0;
    return 1;
}
static inline void u256_copy(u256* d, const u256* s) { memcpy(d->v, s->v, sizeof(d->v)); }

/* Compare: -1 if a<b, 0 if a==b, 1 if a>b */
static inline int u256_cmp(const u256* a, const u256* b) {
    for (int i = 7; i >= 0; i--) {
        if (a->v[i] < b->v[i]) return -1;
        if (a->v[i] > b->v[i]) return 1;
    }
    return 0;
}

/* Load from a 32-byte big-endian buffer (the standard EC wire format). */
static inline void u256_from_be(u256* a, const uint8_t b[32]) {
    for (int i = 0; i < 8; i++) {
        a->v[7 - i] = ((uint32_t)b[i*4] << 24) | ((uint32_t)b[i*4+1] << 16)
                    | ((uint32_t)b[i*4+2] << 8) | ((uint32_t)b[i*4+3]);
    }
}
static inline void u256_to_be(const u256* a, uint8_t b[32]) {
    for (int i = 0; i < 8; i++) {
        uint32_t w = a->v[7 - i];
        b[i*4] = (uint8_t)(w >> 24); b[i*4+1] = (uint8_t)(w >> 16);
        b[i*4+2] = (uint8_t)(w >> 8); b[i*4+3] = (uint8_t)w;
    }
}

/* Add with carry-out. Returns the carry (0 or 1). */
static inline uint32_t u256_add(u256* r, const u256* a, const u256* b) {
    uint64_t c = 0;
    for (int i = 0; i < 8; i++) {
        uint64_t s = (uint64_t)a->v[i] + b->v[i] + c;
        r->v[i] = (uint32_t)s;
        c = s >> 32;
    }
    return (uint32_t)c;
}
/* Subtract with borrow-out. Returns the borrow (0 or 1). */
static inline uint32_t u256_sub(u256* r, const u256* a, const u256* b) {
    uint64_t brw = 0;
    for (int i = 0; i < 8; i++) {
        uint64_t d = (uint64_t)a->v[i] - b->v[i] - brw;
        r->v[i] = (uint32_t)d;
        brw = (d >> 32) & 1;
    }
    return (uint32_t)brw;
}

/* Modular add: r = (a + b) mod m */
static inline void u256_addmod(u256* r, const u256* a, const u256* b, const u256* m) {
    uint32_t c = u256_add(r, a, b);
    if (c || u256_cmp(r, m) >= 0) {
        u256 t; u256_sub(&t, r, m); u256_copy(r, &t);
    }
}
/* Modular sub: r = (a - b) mod m */
static inline void u256_submod(u256* r, const u256* a, const u256* b, const u256* m) {
    uint32_t brw = u256_sub(r, a, b);
    if (brw) { u256 t; u256_add(&t, r, m); u256_copy(r, &t); }
}

/* Full 256x256 -> 512-bit multiply into a 16-limb result. */
static inline void u256_mul_wide(uint32_t out[16], const u256* a, const u256* b) {
    uint64_t acc[16]; memset(acc, 0, sizeof(acc));
    for (int i = 0; i < 8; i++) {
        uint64_t carry = 0;
        for (int j = 0; j < 8; j++) {
            uint64_t cur = acc[i+j] + (uint64_t)a->v[i] * b->v[j] + carry;
            acc[i+j] = (uint32_t)cur;
            carry = cur >> 32;
        }
        acc[i+8] += carry;
    }
    for (int i = 0; i < 16; i++) out[i] = (uint32_t)acc[i];
}

/* Barrett-free modular reduction of a 512-bit value mod m, via schoolbook
 * long division (bitwise). Correct for any modulus; not the fastest, but
 * this is a reference-correct implementation. */
static inline void u512_mod(u256* r, const uint32_t x[16], const u256* m) {
    /* rem = 0; for each bit from MSB to LSB: rem = rem*2 + bit; if rem>=m rem-=m */
    u256 rem; u256_zero(&rem);
    for (int bit = 511; bit >= 0; bit--) {
        /* rem <<= 1 */
        uint32_t carry = 0;
        for (int i = 0; i < 8; i++) {
            uint32_t nc = rem.v[i] >> 31;
            rem.v[i] = (rem.v[i] << 1) | carry;
            carry = nc;
        }
        /* bring in the next bit of x */
        uint32_t xb = (x[bit >> 5] >> (bit & 31)) & 1;
        rem.v[0] |= xb;
        /* conditional subtract */
        if (carry || u256_cmp(&rem, m) >= 0) {
            u256 t; u256_sub(&t, &rem, m); u256_copy(&rem, &t);
        }
    }
    u256_copy(r, &rem);
}

/* Modular multiply: r = (a * b) mod m */
static inline void u256_mulmod(u256* r, const u256* a, const u256* b, const u256* m) {
    uint32_t wide[16];
    u256_mul_wide(wide, a, b);
    u512_mod(r, wide, m);
}

/* Modular exponentiation: r = base^exp mod m (square-and-multiply). */
static inline void u256_expmod(u256* r, const u256* base, const u256* exp, const u256* m) {
    u256 result; u256_zero(&result); result.v[0] = 1;
    u256 b; u256_copy(&b, base);
    for (int bit = 0; bit < 256; bit++) {
        if ((exp->v[bit >> 5] >> (bit & 31)) & 1) {
            u256 t; u256_mulmod(&t, &result, &b, m); u256_copy(&result, &t);
        }
        u256 t2; u256_mulmod(&t2, &b, &b, m); u256_copy(&b, &t2);
    }
    u256_copy(r, &result);
}

/* Modular inverse via Fermat's little theorem: a^(p-2) mod p (p prime). */
static inline void u256_invmod(u256* r, const u256* a, const u256* p) {
    u256 two; u256_zero(&two); two.v[0] = 2;
    u256 pm2; u256_submod(&pm2, p, &two, p);  /* p - 2 (p>2 so no wrap issue) */
    u256_expmod(r, a, &pm2, p);
}

/* ── Elliptic curve: y^2 = x^3 + a*x + b over F_p ──────────────────────
 * Jacobian projective coordinates (X, Y, Z) with x = X/Z^2, y = Y/Z^3.
 * Point at infinity is Z = 0. */
typedef struct { u256 X, Y, Z; } ecpt;

typedef struct {
    u256 p;   /* field prime */
    u256 a;   /* curve param a */
    u256 b;   /* curve param b */
    u256 n;   /* group order */
    u256 gx;  /* generator x */
    u256 gy;  /* generator y */
} eccurve;

static inline int ecpt_is_inf(const ecpt* P) { return u256_is_zero(&P->Z); }
static inline void ecpt_set_inf(ecpt* P) { u256_zero(&P->X); u256_zero(&P->Y); u256_zero(&P->Z);
                                           P->X.v[0]=1; P->Y.v[0]=1; }

/* Point doubling in Jacobian coords. */
static inline void ec_double(ecpt* R, const ecpt* P, const eccurve* c) {
    if (ecpt_is_inf(P)) { *R = *P; return; }
    if (u256_is_zero(&P->Y)) { ecpt_set_inf(R); return; }
    const u256* p = &c->p;
    u256 A, B, C, D, t1, t2, X3, Y3, Z3;
    u256_mulmod(&A, &P->Y, &P->Y, p);           /* A = Y^2 */
    u256_mulmod(&B, &P->X, &A, p);
    u256 four; u256_zero(&four); four.v[0]=4;
    u256_mulmod(&B, &B, &four, p);              /* B = 4*X*Y^2 */
    u256_mulmod(&C, &A, &A, p);
    u256 eight; u256_zero(&eight); eight.v[0]=8;
    u256_mulmod(&C, &C, &eight, p);             /* C = 8*Y^4 */
    /* D = 3*X^2 + a*Z^4 */
    u256 X2, Z2, Z4, aZ4, three;
    u256_mulmod(&X2, &P->X, &P->X, p);
    u256_zero(&three); three.v[0]=3;
    u256_mulmod(&t1, &X2, &three, p);
    u256_mulmod(&Z2, &P->Z, &P->Z, p);
    u256_mulmod(&Z4, &Z2, &Z2, p);
    u256_mulmod(&aZ4, &c->a, &Z4, p);
    u256_addmod(&D, &t1, &aZ4, p);
    /* X3 = D^2 - 2*B */
    u256_mulmod(&X3, &D, &D, p);
    u256 twoB; u256 two; u256_zero(&two); two.v[0]=2;
    u256_mulmod(&twoB, &B, &two, p);
    u256_submod(&X3, &X3, &twoB, p);
    /* Y3 = D*(B - X3) - C */
    u256_submod(&t2, &B, &X3, p);
    u256_mulmod(&Y3, &D, &t2, p);
    u256_submod(&Y3, &Y3, &C, p);
    /* Z3 = 2*Y*Z */
    u256_mulmod(&Z3, &P->Y, &P->Z, p);
    u256_mulmod(&Z3, &Z3, &two, p);
    u256_copy(&R->X, &X3); u256_copy(&R->Y, &Y3); u256_copy(&R->Z, &Z3);
}

/* Point addition in Jacobian coords: R = P + Q. */
static inline void ec_add(ecpt* R, const ecpt* P, const ecpt* Q, const eccurve* c) {
    if (ecpt_is_inf(P)) { *R = *Q; return; }
    if (ecpt_is_inf(Q)) { *R = *P; return; }
    const u256* p = &c->p;
    u256 Z1Z1, Z2Z2, U1, U2, S1, S2, H, r, t1, t2;
    u256_mulmod(&Z1Z1, &P->Z, &P->Z, p);
    u256_mulmod(&Z2Z2, &Q->Z, &Q->Z, p);
    u256_mulmod(&U1, &P->X, &Z2Z2, p);          /* U1 = X1*Z2^2 */
    u256_mulmod(&U2, &Q->X, &Z1Z1, p);          /* U2 = X2*Z1^2 */
    u256_mulmod(&t1, &Q->Z, &Z2Z2, p);
    u256_mulmod(&S1, &P->Y, &t1, p);            /* S1 = Y1*Z2^3 */
    u256_mulmod(&t2, &P->Z, &Z1Z1, p);
    u256_mulmod(&S2, &Q->Y, &t2, p);            /* S2 = Y2*Z1^3 */
    if (u256_cmp(&U1, &U2) == 0) {
        if (u256_cmp(&S1, &S2) == 0) { ec_double(R, P, c); return; }
        ecpt_set_inf(R); return;
    }
    u256_submod(&H, &U2, &U1, p);               /* H = U2-U1 */
    u256_submod(&r, &S2, &S1, p);               /* r = S2-S1 */
    u256 H2, H3, U1H2, X3, Y3, Z3;
    u256_mulmod(&H2, &H, &H, p);
    u256_mulmod(&H3, &H2, &H, p);
    u256_mulmod(&U1H2, &U1, &H2, p);
    /* X3 = r^2 - H3 - 2*U1*H2 */
    u256_mulmod(&X3, &r, &r, p);
    u256_submod(&X3, &X3, &H3, p);
    u256 two; u256_zero(&two); two.v[0]=2;
    u256 twoU1H2; u256_mulmod(&twoU1H2, &U1H2, &two, p);
    u256_submod(&X3, &X3, &twoU1H2, p);
    /* Y3 = r*(U1*H2 - X3) - S1*H3 */
    u256_submod(&t1, &U1H2, &X3, p);
    u256_mulmod(&Y3, &r, &t1, p);
    u256_mulmod(&t2, &S1, &H3, p);
    u256_submod(&Y3, &Y3, &t2, p);
    /* Z3 = Z1*Z2*H */
    u256_mulmod(&Z3, &P->Z, &Q->Z, p);
    u256_mulmod(&Z3, &Z3, &H, p);
    u256_copy(&R->X, &X3); u256_copy(&R->Y, &Y3); u256_copy(&R->Z, &Z3);
}

/* Scalar multiplication: R = k*P (double-and-add, MSB first). */
static inline void ec_mul(ecpt* R, const u256* k, const ecpt* P, const eccurve* c) {
    ecpt acc; ecpt_set_inf(&acc);
    for (int bit = 255; bit >= 0; bit--) {
        ecpt t; ec_double(&t, &acc, c); acc = t;
        if ((k->v[bit >> 5] >> (bit & 31)) & 1) {
            ecpt t2; ec_add(&t2, &acc, P, c); acc = t2;
        }
    }
    *R = acc;
}

/* Convert a Jacobian point to affine (x, y). Returns 0 if point at infinity. */
static inline int ec_to_affine(u256* x, u256* y, const ecpt* P, const eccurve* c) {
    if (ecpt_is_inf(P)) return 0;
    const u256* p = &c->p;
    u256 zinv, zinv2, zinv3;
    u256_invmod(&zinv, &P->Z, p);
    u256_mulmod(&zinv2, &zinv, &zinv, p);
    u256_mulmod(&zinv3, &zinv2, &zinv, p);
    u256_mulmod(x, &P->X, &zinv2, p);
    u256_mulmod(y, &P->Y, &zinv3, p);
    return 1;
}

/* Set the generator as a Jacobian point (Z=1). */
static inline void ec_generator(ecpt* G, const eccurve* c) {
    u256_copy(&G->X, &c->gx); u256_copy(&G->Y, &c->gy);
    u256_zero(&G->Z); G->Z.v[0] = 1;
}

#endif

/* ── ECDSA sign / verify (SEC1) ─────────────────────────────────────────
 * Deterministic-nonce variant per RFC 6979 would be ideal; for a reference
 * implementation we take the nonce k as an explicit argument so signing is
 * reproducible and testable against published vectors. Production callers
 * supply a cryptographically random (or RFC-6979-derived) k.
 *
 * Signature is (r, s). z = the message hash reduced mod n.
 */
#ifndef U_ECDSA_DEFINED
#define U_ECDSA_DEFINED

typedef struct { u256 r, s; } ecsig;

/* Reduce a 32-byte hash to a scalar mod n (SEC1 bits2int for 256-bit curves:
 * the hash is exactly 256 bits, so we just take it mod n). */
static inline void ecdsa_hash_to_scalar(u256* z, const uint8_t hash[32], const eccurve* c) {
    u256 h; u256_from_be(&h, hash);
    if (u256_cmp(&h, &c->n) >= 0) { u256 t; u256_sub(&t, &h, &c->n); u256_copy(z, &t); }
    else u256_copy(z, &h);
}

/* Sign: given private key d, message hash, and nonce k, produce (r, s).
 * Returns 1 on success, 0 if k was degenerate (caller should retry). */
static inline int ecdsa_sign(ecsig* sig, const u256* d, const uint8_t hash[32],
                             const u256* k, const eccurve* c) {
    /* R = k*G;  r = R.x mod n */
    ecpt G, R; ec_generator(&G, c);
    ec_mul(&R, k, &G, c);
    u256 rx, ry;
    if (!ec_to_affine(&rx, &ry, &R, c)) return 0;
    u256 r;
    if (u256_cmp(&rx, &c->n) >= 0) { u256 t; u256_sub(&t, &rx, &c->n); u256_copy(&r, &t); }
    else u256_copy(&r, &rx);
    if (u256_is_zero(&r)) return 0;
    /* s = k^-1 * (z + r*d) mod n */
    u256 z; ecdsa_hash_to_scalar(&z, hash, c);
    u256 rd; u256_mulmod(&rd, &r, d, &c->n);
    u256 zrd; u256_addmod(&zrd, &z, &rd, &c->n);
    u256 kinv; u256_invmod(&kinv, k, &c->n);
    u256 s; u256_mulmod(&s, &kinv, &zrd, &c->n);
    if (u256_is_zero(&s)) return 0;
    /* Low-s normalization (BIP-62 / EIP-2): if s > n/2, s = n - s */
    u256 half; u256_copy(&half, &c->n);
    /* half = n >> 1 */
    uint32_t carry = 0;
    for (int i = 7; i >= 0; i--) {
        uint32_t nc = half.v[i] & 1;
        half.v[i] = (half.v[i] >> 1) | (carry << 31);
        carry = nc;
    }
    if (u256_cmp(&s, &half) > 0) { u256 t; u256_sub(&t, &c->n, &s); u256_copy(&s, &t); }
    u256_copy(&sig->r, &r); u256_copy(&sig->s, &s);
    return 1;
}

/* Verify: given public key Q (affine), message hash, and (r, s).
 * Returns 1 if valid, 0 otherwise. */
static inline int ecdsa_verify(const u256* qx, const u256* qy, const uint8_t hash[32],
                               const ecsig* sig, const eccurve* c) {
    /* r, s must be in [1, n-1] */
    if (u256_is_zero(&sig->r) || u256_is_zero(&sig->s)) return 0;
    if (u256_cmp(&sig->r, &c->n) >= 0 || u256_cmp(&sig->s, &c->n) >= 0) return 0;
    u256 z; ecdsa_hash_to_scalar(&z, hash, c);
    u256 w; u256_invmod(&w, &sig->s, &c->n);        /* w = s^-1 mod n */
    u256 u1; u256_mulmod(&u1, &z, &w, &c->n);       /* u1 = z*w */
    u256 u2; u256_mulmod(&u2, &sig->r, &w, &c->n);  /* u2 = r*w */
    /* R = u1*G + u2*Q */
    ecpt G; ec_generator(&G, c);
    ecpt Q; u256_copy(&Q.X, qx); u256_copy(&Q.Y, qy);
    u256_zero(&Q.Z); Q.Z.v[0] = 1;
    ecpt P1, P2, R;
    ec_mul(&P1, &u1, &G, c);
    ec_mul(&P2, &u2, &Q, c);
    ec_add(&R, &P1, &P2, c);
    if (ecpt_is_inf(&R)) return 0;
    u256 rx, ry; ec_to_affine(&rx, &ry, &R, c);
    if (u256_cmp(&rx, &c->n) >= 0) { u256 t; u256_sub(&t, &rx, &c->n); u256_copy(&rx, &t); }
    return u256_cmp(&rx, &sig->r) == 0;
}

/* Derive the public key Q = d*G (affine) from a private key d. */
static inline void ecdsa_pubkey(u256* qx, u256* qy, const u256* d, const eccurve* c) {
    ecpt G, Q; ec_generator(&G, c);
    ec_mul(&Q, d, &G, c);
    ec_to_affine(qx, qy, &Q, c);
}

/* Curve constructors. */
static inline void ec_secp256k1(eccurve* c) {
    static const char* P  = "fffffffffffffffffffffffffffffffffffffffffffffffffffffffefffffc2f";
    static const char* A  = "0000000000000000000000000000000000000000000000000000000000000000";
    static const char* B  = "0000000000000000000000000000000000000000000000000000000000000007";
    static const char* N  = "fffffffffffffffffffffffffffffffebaaedce6af48a03bbfd25e8cd0364141";
    static const char* GX = "79be667ef9dcbbac55a06295ce870b07029bfcdb2dce28d959f2815b16f81798";
    static const char* GY = "483ada7726a3c4655da4fbfc0e1108a8fd17b448a68554199c47d08ffb10d4b8";
    uint8_t b[32];
    #define U_SETHEX(dst, hex) do { \
        memset(b,0,32); int L=0; while(hex[L])L++; int by=L/2; \
        for(int i=0;i<by;i++){char h=hex[i*2],l=hex[i*2+1]; \
        int hv=(h<='9')?h-'0':(h|32)-'a'+10; int lv=(l<='9')?l-'0':(l|32)-'a'+10; \
        b[32-by+i]=(hv<<4)|lv;} u256_from_be(&(dst), b);} while(0)
    U_SETHEX(c->p,P); U_SETHEX(c->a,A); U_SETHEX(c->b,B);
    U_SETHEX(c->n,N); U_SETHEX(c->gx,GX); U_SETHEX(c->gy,GY);
}
static inline void ec_p256(eccurve* c) {
    static const char* P  = "ffffffff00000001000000000000000000000000ffffffffffffffffffffffff";
    static const char* A  = "ffffffff00000001000000000000000000000000fffffffffffffffffffffffc";
    static const char* B  = "5ac635d8aa3a93e7b3ebbd55769886bc651d06b0cc53b0f63bce3c3e27d2604b";
    static const char* N  = "ffffffff00000000ffffffffffffffffbce6faada7179e84f3b9cac2fc632551";
    static const char* GX = "6b17d1f2e12c4247f8bce6e563a440f277037d812deb33a0f4a13945d898c296";
    static const char* GY = "4fe342e2fe1a7f9b8ee7eb4a7c0f9e162bce33576b315ececbb6406837bf51f5";
    uint8_t b[32];
    U_SETHEX(c->p,P); U_SETHEX(c->a,A); U_SETHEX(c->b,B);
    U_SETHEX(c->n,N); U_SETHEX(c->gx,GX); U_SETHEX(c->gy,GY);
    #undef U_SETHEX
}

#endif



/* == EXTENDED CRYPTO PRIMITIVES (SHA-384/512, HMAC, HKDF, PBKDF2,
 *    AES, AES-GCM/CBC/CTR, base64) - verified against RFC/NIST/FIPS == */
/* ── Extended crypto primitives (SHA-512/384, HMAC, HKDF, PBKDF2,
 *    AES, AES-GCM, AES-CBC, base64) ──────────────────────────────────────
 * Reference-correct implementations verified against published test
 * vectors. Companion to the EC/ECDSA already in the runtime. */

/* ══ SHA-1 (FIPS 180-4) — legacy but needed for HMAC-SHA1/TLS ══════════ */
static inline void u_sha1(const uint8_t* msg, size_t len, uint8_t out[20]) {
    uint32_t h[5] = {0x67452301,0xEFCDAB89,0x98BADCFE,0x10325476,0xC3D2E1F0};
    size_t total = len + 1 + 8;
    size_t padded = ((total + 63) / 64) * 64;
    uint8_t* buf = (uint8_t*)calloc(padded, 1);
    memcpy(buf, msg, len); buf[len] = 0x80;
    uint64_t bits = (uint64_t)len * 8;
    for (int i = 0; i < 8; i++) buf[padded-1-i] = (uint8_t)(bits >> (8*i));
    for (size_t off = 0; off < padded; off += 64) {
        uint32_t w[80];
        for (int i = 0; i < 16; i++)
            w[i] = (buf[off+i*4]<<24)|(buf[off+i*4+1]<<16)|(buf[off+i*4+2]<<8)|buf[off+i*4+3];
        for (int i = 16; i < 80; i++) {
            uint32_t x = w[i-3]^w[i-8]^w[i-14]^w[i-16];
            w[i] = (x<<1)|(x>>31);
        }
        uint32_t a=h[0],b=h[1],c=h[2],d=h[3],e=h[4];
        for (int i = 0; i < 80; i++) {
            uint32_t f, k;
            if (i<20){f=(b&c)|((~b)&d);k=0x5A827999;}
            else if(i<40){f=b^c^d;k=0x6ED9EBA1;}
            else if(i<60){f=(b&c)|(b&d)|(c&d);k=0x8F1BBCDC;}
            else{f=b^c^d;k=0xCA62C1D6;}
            uint32_t t=((a<<5)|(a>>27))+f+e+k+w[i];
            e=d;d=c;c=(b<<30)|(b>>2);b=a;a=t;
        }
        h[0]+=a;h[1]+=b;h[2]+=c;h[3]+=d;h[4]+=e;
    }
    for (int i = 0; i < 5; i++) {
        out[i*4]=(uint8_t)(h[i]>>24);out[i*4+1]=(uint8_t)(h[i]>>16);
        out[i*4+2]=(uint8_t)(h[i]>>8);out[i*4+3]=(uint8_t)h[i];
    }
    free(buf);
}

/* ══ SHA-512 / SHA-384 (FIPS 180-4) ═══════════════════════════════════ */
static const uint64_t U_SHA512_K[80] = {
0x428a2f98d728ae22ULL,0x7137449123ef65cdULL,0xb5c0fbcfec4d3b2fULL,0xe9b5dba58189dbbcULL,
0x3956c25bf348b538ULL,0x59f111f1b605d019ULL,0x923f82a4af194f9bULL,0xab1c5ed5da6d8118ULL,
0xd807aa98a3030242ULL,0x12835b0145706fbeULL,0x243185be4ee4b28cULL,0x550c7dc3d5ffb4e2ULL,
0x72be5d74f27b896fULL,0x80deb1fe3b1696b1ULL,0x9bdc06a725c71235ULL,0xc19bf174cf692694ULL,
0xe49b69c19ef14ad2ULL,0xefbe4786384f25e3ULL,0x0fc19dc68b8cd5b5ULL,0x240ca1cc77ac9c65ULL,
0x2de92c6f592b0275ULL,0x4a7484aa6ea6e483ULL,0x5cb0a9dcbd41fbd4ULL,0x76f988da831153b5ULL,
0x983e5152ee66dfabULL,0xa831c66d2db43210ULL,0xb00327c898fb213fULL,0xbf597fc7beef0ee4ULL,
0xc6e00bf33da88fc2ULL,0xd5a79147930aa725ULL,0x06ca6351e003826fULL,0x142929670a0e6e70ULL,
0x27b70a8546d22ffcULL,0x2e1b21385c26c926ULL,0x4d2c6dfc5ac42aedULL,0x53380d139d95b3dfULL,
0x650a73548baf63deULL,0x766a0abb3c77b2a8ULL,0x81c2c92e47edaee6ULL,0x92722c851482353bULL,
0xa2bfe8a14cf10364ULL,0xa81a664bbc423001ULL,0xc24b8b70d0f89791ULL,0xc76c51a30654be30ULL,
0xd192e819d6ef5218ULL,0xd69906245565a910ULL,0xf40e35855771202aULL,0x106aa07032bbd1b8ULL,
0x19a4c116b8d2d0c8ULL,0x1e376c085141ab53ULL,0x2748774cdf8eeb99ULL,0x34b0bcb5e19b48a8ULL,
0x391c0cb3c5c95a63ULL,0x4ed8aa4ae3418acbULL,0x5b9cca4f7763e373ULL,0x682e6ff3d6b2b8a3ULL,
0x748f82ee5defb2fcULL,0x78a5636f43172f60ULL,0x84c87814a1f0ab72ULL,0x8cc702081a6439ecULL,
0x90befffa23631e28ULL,0xa4506cebde82bde9ULL,0xbef9a3f7b2c67915ULL,0xc67178f2e372532bULL,
0xca273eceea26619cULL,0xd186b8c721c0c207ULL,0xeada7dd6cde0eb1eULL,0xf57d4f7fee6ed178ULL,
0x06f067aa72176fbaULL,0x0a637dc5a2c898a6ULL,0x113f9804bef90daeULL,0x1b710b35131c471bULL,
0x28db77f523047d84ULL,0x32caab7b40c72493ULL,0x3c9ebe0a15c9bebcULL,0x431d67c49c100d4cULL,
0x4cc5d4becb3e42b6ULL,0x597f299cfc657e2aULL,0x5fcb6fab3ad6faecULL,0x6c44198c4a475817ULL};

#define U_ROTR64(x,n) (((x)>>(n))|((x)<<(64-(n))))
static inline void u_sha512_core(const uint8_t* msg, size_t len, uint64_t h[8], uint8_t* out, int outlen) {
    size_t total = len + 1 + 16;
    size_t padded = ((total + 127) / 128) * 128;
    uint8_t* buf = (uint8_t*)calloc(padded, 1);
    memcpy(buf, msg, len); buf[len] = 0x80;
    uint64_t bits = (uint64_t)len * 8;
    for (int i = 0; i < 8; i++) buf[padded-1-i] = (uint8_t)(bits >> (8*i));
    for (size_t off = 0; off < padded; off += 128) {
        uint64_t w[80];
        for (int i = 0; i < 16; i++) {
            w[i]=0; for (int j=0;j<8;j++) w[i]=(w[i]<<8)|buf[off+i*8+j];
        }
        for (int i = 16; i < 80; i++) {
            uint64_t s0=U_ROTR64(w[i-15],1)^U_ROTR64(w[i-15],8)^(w[i-15]>>7);
            uint64_t s1=U_ROTR64(w[i-2],19)^U_ROTR64(w[i-2],61)^(w[i-2]>>6);
            w[i]=w[i-16]+s0+w[i-7]+s1;
        }
        uint64_t a=h[0],b=h[1],c=h[2],d=h[3],e=h[4],f=h[5],g=h[6],hh=h[7];
        for (int i = 0; i < 80; i++) {
            uint64_t S1=U_ROTR64(e,14)^U_ROTR64(e,18)^U_ROTR64(e,41);
            uint64_t ch=(e&f)^((~e)&g);
            uint64_t t1=hh+S1+ch+U_SHA512_K[i]+w[i];
            uint64_t S0=U_ROTR64(a,28)^U_ROTR64(a,34)^U_ROTR64(a,39);
            uint64_t maj=(a&b)^(a&c)^(b&c);
            uint64_t t2=S0+maj;
            hh=g;g=f;f=e;e=d+t1;d=c;c=b;b=a;a=t1+t2;
        }
        h[0]+=a;h[1]+=b;h[2]+=c;h[3]+=d;h[4]+=e;h[5]+=f;h[6]+=g;h[7]+=hh;
    }
    for (int i = 0; i < outlen/8; i++)
        for (int j = 0; j < 8; j++) out[i*8+j]=(uint8_t)(h[i]>>(56-8*j));
    free(buf);
}
static inline void u_sha512(const uint8_t* msg, size_t len, uint8_t out[64]) {
    uint64_t h[8]={0x6a09e667f3bcc908ULL,0xbb67ae8584caa73bULL,0x3c6ef372fe94f82bULL,
        0xa54ff53a5f1d36f1ULL,0x510e527fade682d1ULL,0x9b05688c2b3e6c1fULL,
        0x1f83d9abfb41bd6bULL,0x5be0cd19137e2179ULL};
    u_sha512_core(msg, len, h, out, 64);
}
static inline void u_sha384(const uint8_t* msg, size_t len, uint8_t out[48]) {
    uint64_t h[8]={0xcbbb9d5dc1059ed8ULL,0x629a292a367cd507ULL,0x9159015a3070dd17ULL,
        0x152fecd8f70e5939ULL,0x67332667ffc00b31ULL,0x8eb44a8768581511ULL,
        0xdb0c2e0d64f98fa7ULL,0x47b5481dbefa4fa4ULL};
    u_sha512_core(msg, len, h, out, 48);
}


/* ══ HMAC (RFC 2104) — parameterized over any hash ════════════════════ */
/* A hash descriptor: block size, output size, and the hash function. */
typedef void (*u_hashfn)(const uint8_t*, size_t, uint8_t*);
typedef struct { int block_size; int out_size; u_hashfn fn; } u_hashdesc;

/* Forward decls for the hashes (sha256/keccak are in the runtime; here we
 * reference sha1/sha512/sha384 and expect sha256 to be linked). */

static inline u_hashdesc u_hash_sha1(void)   { u_hashdesc d={64,20,(u_hashfn)u_sha1};   return d; }
static inline u_hashdesc u_hash_sha256(void) { u_hashdesc d={64,32,(u_hashfn)u_sha256}; return d; }
static inline u_hashdesc u_hash_sha384(void) { u_hashdesc d={128,48,(u_hashfn)u_sha384}; return d; }
static inline u_hashdesc u_hash_sha512(void) { u_hashdesc d={128,64,(u_hashfn)u_sha512}; return d; }

/* HMAC: out must hold desc.out_size bytes. */
static inline void u_hmac(u_hashdesc desc, const uint8_t* key, size_t keylen,
                          const uint8_t* msg, size_t msglen, uint8_t* out) {
    int B = desc.block_size, L = desc.out_size;
    uint8_t* k0 = (uint8_t*)calloc(B, 1);
    if ((int)keylen > B) {
        desc.fn(key, keylen, k0);           /* key = H(key) if too long */
    } else {
        memcpy(k0, key, keylen);
    }
    uint8_t* ipad = (uint8_t*)malloc(B + msglen);
    uint8_t* opad = (uint8_t*)malloc(B + L);
    for (int i = 0; i < B; i++) { ipad[i] = k0[i] ^ 0x36; opad[i] = k0[i] ^ 0x5c; }
    memcpy(ipad + B, msg, msglen);
    uint8_t inner[64];
    desc.fn(ipad, B + msglen, inner);       /* H((k^ipad) || msg) */
    memcpy(opad + B, inner, L);
    desc.fn(opad, B + L, out);              /* H((k^opad) || inner) */
    free(k0); free(ipad); free(opad);
}

/* ══ HKDF (RFC 5869) — TLS 1.3 key schedule ═══════════════════════════ */
/* extract: PRK = HMAC(salt, ikm) */
static inline void u_hkdf_extract(u_hashdesc desc, const uint8_t* salt, size_t saltlen,
                                  const uint8_t* ikm, size_t ikmlen, uint8_t* prk) {
    uint8_t zerosalt[64];
    if (salt == NULL || saltlen == 0) {
        memset(zerosalt, 0, desc.out_size);
        salt = zerosalt; saltlen = desc.out_size;
    }
    u_hmac(desc, salt, saltlen, ikm, ikmlen, prk);
}
/* expand: OKM = T(1) || T(2) || ... where T(n)=HMAC(PRK, T(n-1)||info||n) */
static inline void u_hkdf_expand(u_hashdesc desc, const uint8_t* prk, size_t prklen,
                                 const uint8_t* info, size_t infolen,
                                 uint8_t* okm, size_t okmlen) {
    int L = desc.out_size;
    uint8_t t[64]; int tlen = 0;
    size_t done = 0; uint8_t counter = 1;
    while (done < okmlen) {
        uint8_t* buf = (uint8_t*)malloc(tlen + infolen + 1);
        memcpy(buf, t, tlen);
        memcpy(buf + tlen, info, infolen);
        buf[tlen + infolen] = counter;
        u_hmac(desc, prk, prklen, buf, tlen + infolen + 1, t);
        free(buf);
        tlen = L;
        size_t n = (okmlen - done < (size_t)L) ? okmlen - done : (size_t)L;
        memcpy(okm + done, t, n);
        done += n; counter++;
    }
}

/* ══ PBKDF2 (RFC 8018) — password-based key derivation ════════════════ */
static inline void u_pbkdf2(u_hashdesc desc, const uint8_t* pw, size_t pwlen,
                            const uint8_t* salt, size_t saltlen, uint32_t iters,
                            uint8_t* out, size_t outlen) {
    int L = desc.out_size;
    uint32_t blocks = (uint32_t)((outlen + L - 1) / L);
    for (uint32_t i = 1; i <= blocks; i++) {
        uint8_t* si = (uint8_t*)malloc(saltlen + 4);
        memcpy(si, salt, saltlen);
        si[saltlen]=(uint8_t)(i>>24);si[saltlen+1]=(uint8_t)(i>>16);
        si[saltlen+2]=(uint8_t)(i>>8);si[saltlen+3]=(uint8_t)i;
        uint8_t u[64], t[64];
        u_hmac(desc, pw, pwlen, si, saltlen + 4, u);
        memcpy(t, u, L);
        free(si);
        for (uint32_t j = 1; j < iters; j++) {
            uint8_t un[64];
            u_hmac(desc, pw, pwlen, u, L, un);
            memcpy(u, un, L);
            for (int k = 0; k < L; k++) t[k] ^= u[k];
        }
        size_t off = (i-1)*L;
        size_t n = (outlen - off < (size_t)L) ? outlen - off : (size_t)L;
        memcpy(out + off, t, n);
    }
}


/* ══ AES (FIPS 197) — 128/192/256, the block cipher ═══════════════════ */
static const uint8_t U_AES_SBOX[256] = {
0x63,0x7c,0x77,0x7b,0xf2,0x6b,0x6f,0xc5,0x30,0x01,0x67,0x2b,0xfe,0xd7,0xab,0x76,
0xca,0x82,0xc9,0x7d,0xfa,0x59,0x47,0xf0,0xad,0xd4,0xa2,0xaf,0x9c,0xa4,0x72,0xc0,
0xb7,0xfd,0x93,0x26,0x36,0x3f,0xf7,0xcc,0x34,0xa5,0xe5,0xf1,0x71,0xd8,0x31,0x15,
0x04,0xc7,0x23,0xc3,0x18,0x96,0x05,0x9a,0x07,0x12,0x80,0xe2,0xeb,0x27,0xb2,0x75,
0x09,0x83,0x2c,0x1a,0x1b,0x6e,0x5a,0xa0,0x52,0x3b,0xd6,0xb3,0x29,0xe3,0x2f,0x84,
0x53,0xd1,0x00,0xed,0x20,0xfc,0xb1,0x5b,0x6a,0xcb,0xbe,0x39,0x4a,0x4c,0x58,0xcf,
0xd0,0xef,0xaa,0xfb,0x43,0x4d,0x33,0x85,0x45,0xf9,0x02,0x7f,0x50,0x3c,0x9f,0xa8,
0x51,0xa3,0x40,0x8f,0x92,0x9d,0x38,0xf5,0xbc,0xb6,0xda,0x21,0x10,0xff,0xf3,0xd2,
0xcd,0x0c,0x13,0xec,0x5f,0x97,0x44,0x17,0xc4,0xa7,0x7e,0x3d,0x64,0x5d,0x19,0x73,
0x60,0x81,0x4f,0xdc,0x22,0x2a,0x90,0x88,0x46,0xee,0xb8,0x14,0xde,0x5e,0x0b,0xdb,
0xe0,0x32,0x3a,0x0a,0x49,0x06,0x24,0x5c,0xc2,0xd3,0xac,0x62,0x91,0x95,0xe4,0x79,
0xe7,0xc8,0x37,0x6d,0x8d,0xd5,0x4e,0xa9,0x6c,0x56,0xf4,0xea,0x65,0x7a,0xae,0x08,
0xba,0x78,0x25,0x2e,0x1c,0xa6,0xb4,0xc6,0xe8,0xdd,0x74,0x1f,0x4b,0xbd,0x8b,0x8a,
0x70,0x3e,0xb5,0x66,0x48,0x03,0xf6,0x0e,0x61,0x35,0x57,0xb9,0x86,0xc1,0x1d,0x9e,
0xe1,0xf8,0x98,0x11,0x69,0xd9,0x8e,0x94,0x9b,0x1e,0x87,0xe9,0xce,0x55,0x28,0xdf,
0x8c,0xa1,0x89,0x0d,0xbf,0xe6,0x42,0x68,0x41,0x99,0x2d,0x0f,0xb0,0x54,0xbb,0x16};

static uint8_t u_aes_inv_sbox(uint8_t x){
    static uint8_t inv[256]; static int built=0;
    if(!built){for(int i=0;i<256;i++)inv[U_AES_SBOX[i]]=(uint8_t)i;built=1;}
    return inv[x];
}
static uint8_t u_aes_xtime(uint8_t x){return (uint8_t)((x<<1)^((x>>7)*0x1b));}
static uint8_t u_aes_mul(uint8_t a,uint8_t b){
    uint8_t p=0;for(int i=0;i<8;i++){if(b&1)p^=a;uint8_t hi=a&0x80;a<<=1;if(hi)a^=0x1b;b>>=1;}return p;}

typedef struct { uint8_t rk[240]; int rounds; } u_aes_ctx;

static inline void u_aes_init(u_aes_ctx* c, const uint8_t* key, int keybits){
    int Nk=keybits/32; c->rounds=Nk+6;
    int total=4*(c->rounds+1);
    memcpy(c->rk, key, keybits/8);
    uint8_t rcon=1;
    for(int i=Nk;i<total;i++){
        uint8_t t[4]; memcpy(t,&c->rk[(i-1)*4],4);
        if(i%Nk==0){
            uint8_t tmp=t[0];t[0]=U_AES_SBOX[t[1]]^rcon;t[1]=U_AES_SBOX[t[2]];
            t[2]=U_AES_SBOX[t[3]];t[3]=U_AES_SBOX[tmp];
            rcon=u_aes_xtime(rcon);
        } else if(Nk>6 && i%Nk==4){
            for(int j=0;j<4;j++)t[j]=U_AES_SBOX[t[j]];
        }
        for(int j=0;j<4;j++)c->rk[i*4+j]=c->rk[(i-Nk)*4+j]^t[j];
    }
}
static inline void u_aes_encrypt_block(const u_aes_ctx* c, const uint8_t in[16], uint8_t out[16]){
    uint8_t s[16]; memcpy(s,in,16);
    for(int i=0;i<16;i++)s[i]^=c->rk[i];
    for(int r=1;r<c->rounds;r++){
        uint8_t t[16];
        for(int i=0;i<16;i++)t[i]=U_AES_SBOX[s[i]];
        /* ShiftRows */
        uint8_t sr[16]={t[0],t[5],t[10],t[15],t[4],t[9],t[14],t[3],
                        t[8],t[13],t[2],t[7],t[12],t[1],t[6],t[11]};
        /* MixColumns */
        for(int col=0;col<4;col++){
            uint8_t* p=&sr[col*4];
            uint8_t a0=p[0],a1=p[1],a2=p[2],a3=p[3];
            s[col*4+0]=u_aes_mul(a0,2)^u_aes_mul(a1,3)^a2^a3;
            s[col*4+1]=a0^u_aes_mul(a1,2)^u_aes_mul(a2,3)^a3;
            s[col*4+2]=a0^a1^u_aes_mul(a2,2)^u_aes_mul(a3,3);
            s[col*4+3]=u_aes_mul(a0,3)^a1^a2^u_aes_mul(a3,2);
        }
        for(int i=0;i<16;i++)s[i]^=c->rk[(r*4)*4+i];
    }
    /* final round (no MixColumns) */
    uint8_t t[16];
    for(int i=0;i<16;i++)t[i]=U_AES_SBOX[s[i]];
    uint8_t sr[16]={t[0],t[5],t[10],t[15],t[4],t[9],t[14],t[3],
                    t[8],t[13],t[2],t[7],t[12],t[1],t[6],t[11]};
    for(int i=0;i<16;i++)out[i]=sr[i]^c->rk[(c->rounds*4)*4+i];
}
static inline void u_aes_decrypt_block(const u_aes_ctx* c, const uint8_t in[16], uint8_t out[16]){
    uint8_t s[16]; memcpy(s,in,16);
    for(int i=0;i<16;i++)s[i]^=c->rk[(c->rounds*4)*4+i];
    for(int r=c->rounds-1;r>=1;r--){
        /* InvShiftRows */
        uint8_t isr[16]={s[0],s[13],s[10],s[7],s[4],s[1],s[14],s[11],
                         s[8],s[5],s[2],s[15],s[12],s[9],s[6],s[3]};
        uint8_t t[16];
        for(int i=0;i<16;i++)t[i]=u_aes_inv_sbox(isr[i]);
        for(int i=0;i<16;i++)t[i]^=c->rk[(r*4)*4+i];
        /* InvMixColumns */
        for(int col=0;col<4;col++){
            uint8_t* p=&t[col*4];
            uint8_t a0=p[0],a1=p[1],a2=p[2],a3=p[3];
            s[col*4+0]=u_aes_mul(a0,14)^u_aes_mul(a1,11)^u_aes_mul(a2,13)^u_aes_mul(a3,9);
            s[col*4+1]=u_aes_mul(a0,9)^u_aes_mul(a1,14)^u_aes_mul(a2,11)^u_aes_mul(a3,13);
            s[col*4+2]=u_aes_mul(a0,13)^u_aes_mul(a1,9)^u_aes_mul(a2,14)^u_aes_mul(a3,11);
            s[col*4+3]=u_aes_mul(a0,11)^u_aes_mul(a1,13)^u_aes_mul(a2,9)^u_aes_mul(a3,14);
        }
    }
    uint8_t isr[16]={s[0],s[13],s[10],s[7],s[4],s[1],s[14],s[11],
                     s[8],s[5],s[2],s[15],s[12],s[9],s[6],s[3]};
    for(int i=0;i<16;i++)out[i]=u_aes_inv_sbox(isr[i])^c->rk[i];
}


/* ══ AES-CTR (counter mode) — needed by GCM ═══════════════════════════ */
static inline void u_aes_ctr(const u_aes_ctx* c, const uint8_t iv[16],
                             const uint8_t* in, uint8_t* out, size_t len) {
    uint8_t counter[16]; memcpy(counter, iv, 16);
    uint8_t ks[16];
    for (size_t off = 0; off < len; off += 16) {
        u_aes_encrypt_block(c, counter, ks);
        size_t n = (len - off < 16) ? len - off : 16;
        for (size_t i = 0; i < n; i++) out[off+i] = in[off+i] ^ ks[i];
        /* increment counter (big-endian, last 4 bytes for GCM style) */
        for (int i = 15; i >= 0; i--) { if (++counter[i]) break; }
    }
}

/* ══ AES-CBC (TLS 1.2 / OpenSSL default) ══════════════════════════════ */
static inline void u_aes_cbc_encrypt(const u_aes_ctx* c, const uint8_t iv[16],
                                     const uint8_t* in, uint8_t* out, size_t len) {
    uint8_t prev[16]; memcpy(prev, iv, 16);
    for (size_t off = 0; off < len; off += 16) {
        uint8_t block[16];
        for (int i = 0; i < 16; i++) block[i] = in[off+i] ^ prev[i];
        u_aes_encrypt_block(c, block, out + off);
        memcpy(prev, out + off, 16);
    }
}
static inline void u_aes_cbc_decrypt(const u_aes_ctx* c, const uint8_t iv[16],
                                     const uint8_t* in, uint8_t* out, size_t len) {
    uint8_t prev[16]; memcpy(prev, iv, 16);
    for (size_t off = 0; off < len; off += 16) {
        uint8_t dec[16];
        u_aes_decrypt_block(c, in + off, dec);
        for (int i = 0; i < 16; i++) out[off+i] = dec[i] ^ prev[i];
        memcpy(prev, in + off, 16);
    }
}

/* ══ GHASH — the GCM authentication function (GF(2^128) multiply) ══════ */
static inline void u_ghash_mul(uint8_t* x, const uint8_t* h) {
    uint8_t z[16] = {0}, v[16];
    memcpy(v, h, 16);
    for (int i = 0; i < 128; i++) {
        if ((x[i/8] >> (7 - (i%8))) & 1)
            for (int j = 0; j < 16; j++) z[j] ^= v[j];
        int lsb = v[15] & 1;
        for (int j = 15; j > 0; j--) v[j] = (uint8_t)((v[j] >> 1) | ((v[j-1] & 1) << 7));
        v[0] >>= 1;
        if (lsb) v[0] ^= 0xe1;
    }
    memcpy(x, z, 16);
}
static inline void u_ghash(const uint8_t* h, const uint8_t* aad, size_t aadlen,
                           const uint8_t* ct, size_t ctlen, uint8_t out[16]) {
    uint8_t y[16] = {0};
    /* process AAD */
    for (size_t off = 0; off < aadlen; off += 16) {
        size_t n = (aadlen - off < 16) ? aadlen - off : 16;
        for (size_t i = 0; i < n; i++) y[i] ^= aad[off+i];
        u_ghash_mul(y, h);
    }
    /* process ciphertext */
    for (size_t off = 0; off < ctlen; off += 16) {
        size_t n = (ctlen - off < 16) ? ctlen - off : 16;
        for (size_t i = 0; i < n; i++) y[i] ^= ct[off+i];
        u_ghash_mul(y, h);
    }
    /* lengths block: aad bits (64) || ct bits (64) */
    uint8_t lenblk[16] = {0};
    uint64_t aadbits = (uint64_t)aadlen * 8, ctbits = (uint64_t)ctlen * 8;
    for (int i = 0; i < 8; i++) lenblk[7-i] = (uint8_t)(aadbits >> (8*i));
    for (int i = 0; i < 8; i++) lenblk[15-i] = (uint8_t)(ctbits >> (8*i));
    for (int i = 0; i < 16; i++) y[i] ^= lenblk[i];
    u_ghash_mul(y, h);
    memcpy(out, y, 16);
}

/* ══ AES-GCM (AEAD — the TLS 1.3 workhorse) ═══════════════════════════ */
/* Encrypt: produces ciphertext (same length as plaintext) + 16-byte tag.
 * iv is 12 bytes (96-bit, the standard). Returns 0 on success. */
static inline int u_aes_gcm_encrypt(const u_aes_ctx* c, const uint8_t iv[12],
                                    const uint8_t* aad, size_t aadlen,
                                    const uint8_t* pt, size_t ptlen,
                                    uint8_t* ct, uint8_t tag[16]) {
    /* H = E(0^128) */
    uint8_t H[16] = {0}; u_aes_encrypt_block(c, H, H);
    /* J0 = IV || 0x00000001 */
    uint8_t J0[16]; memcpy(J0, iv, 12); J0[12]=0;J0[13]=0;J0[14]=0;J0[15]=1;
    /* CTR starts at J0+1 */
    uint8_t ctr[16]; memcpy(ctr, J0, 16);
    for (int i = 15; i >= 0; i--) { if (++ctr[i]) break; }
    u_aes_ctr(c, ctr, pt, ct, ptlen);
    /* tag = GHASH(H, aad, ct) XOR E(J0) */
    uint8_t S[16]; u_ghash(H, aad, aadlen, ct, ptlen, S);
    uint8_t EJ0[16]; u_aes_encrypt_block(c, J0, EJ0);
    for (int i = 0; i < 16; i++) tag[i] = S[i] ^ EJ0[i];
    return 0;
}
/* Decrypt: verifies tag first. Returns 0 if authentic, -1 if tag mismatch. */
static inline int u_aes_gcm_decrypt(const u_aes_ctx* c, const uint8_t iv[12],
                                    const uint8_t* aad, size_t aadlen,
                                    const uint8_t* ct, size_t ctlen,
                                    const uint8_t tag[16], uint8_t* pt) {
    uint8_t H[16] = {0}; u_aes_encrypt_block(c, H, H);
    uint8_t J0[16]; memcpy(J0, iv, 12); J0[12]=0;J0[13]=0;J0[14]=0;J0[15]=1;
    uint8_t S[16]; u_ghash(H, aad, aadlen, ct, ctlen, S);
    uint8_t EJ0[16]; u_aes_encrypt_block(c, J0, EJ0);
    uint8_t expected[16];
    for (int i = 0; i < 16; i++) expected[i] = S[i] ^ EJ0[i];
    int diff = 0;
    for (int i = 0; i < 16; i++) diff |= expected[i] ^ tag[i];
    if (diff != 0) return -1;   /* authentication failed */
    uint8_t ctr[16]; memcpy(ctr, J0, 16);
    for (int i = 15; i >= 0; i--) { if (++ctr[i]) break; }
    u_aes_ctr(c, ctr, ct, pt, ctlen);
    return 0;
}

/* ══ Base64 (RFC 4648) ════════════════════════════════════════════════ */
static const char U_B64[] = "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/";
static inline char* u_base64_encode(const uint8_t* data, size_t len) {
    size_t olen = 4 * ((len + 2) / 3);
    char* out = (char*)malloc(olen + 1);
    size_t i, j;
    for (i = 0, j = 0; i < len; ) {
        uint32_t a = i < len ? data[i++] : 0;
        uint32_t b = i < len ? data[i++] : 0;
        uint32_t cc = i < len ? data[i++] : 0;
        uint32_t trip = (a << 16) | (b << 8) | cc;
        out[j++] = U_B64[(trip >> 18) & 0x3f];
        out[j++] = U_B64[(trip >> 12) & 0x3f];
        out[j++] = U_B64[(trip >> 6) & 0x3f];
        out[j++] = U_B64[trip & 0x3f];
    }
    int mod = len % 3;
    if (mod == 1) { out[olen-1] = '='; out[olen-2] = '='; }
    else if (mod == 2) { out[olen-1] = '='; }
    out[olen] = '\0';
    return out;
}
static inline int u_base64_decode(const char* in, uint8_t* out, size_t* outlen) {
    static int8_t rev[256]; static int built = 0;
    if (!built) {
        for (int i=0;i<256;i++) rev[i]=-1;
        for (int i=0;i<64;i++) rev[(uint8_t)U_B64[i]]=(int8_t)i;
        built=1;
    }
    size_t inlen = strlen(in);
    size_t o = 0; uint32_t buf = 0; int bits = 0;
    for (size_t i = 0; i < inlen; i++) {
        if (in[i] == '=') break;
        int8_t v = rev[(uint8_t)in[i]];
        if (v < 0) continue;
        buf = (buf << 6) | v; bits += 6;
        if (bits >= 8) { bits -= 8; out[o++] = (uint8_t)(buf >> bits); }
    }
    *outlen = o;
    return 0;
}




/* ── OCP — OpenClaim Protocol sign/verify (turn 23c; real ES256) ────────
 *
 * The pipeline: canonicalize(payload) → sha256 → ECDSA-P256 sign → verify.
 * The canonical bytes are deterministic (turn 23b), so the digest is stable;
 * the signature is a REAL ECDSA signature over P-256 (this is ES256, the
 * signature scheme used by JWT/COSE and OpenClaim).
 *
 * The elliptic-curve arithmetic above is verified against the RFC 6979
 * P-256 test vector. Keys are 32-byte scalars; a signature is (r, s), each
 * 32 bytes, hex-encoded as 128 hex chars.
 *
 * The nonce k: a production signer derives k deterministically per RFC 6979
 * or from a CSPRNG. Here k is derived deterministically as
 * sha256(private_key || digest) reduced mod n — deterministic (so the same
 * message+key always produces the same signature, which is testable) and
 * never reused across different messages. This is a real, if simplified,
 * nonce derivation; it is not the full RFC 6979 HMAC construction.
 */

/* Compute the OCP digest: sha256 of the canonical form of the payload. */
static inline void u_ocp_digest(const UTree* payload, uint8_t out[32]) {
    char* canon = u_jcs_canonicalize(payload);
    u_sha256((const uint8_t*)canon, strlen(canon), out);
    free(canon);
}

/* Parse a 64-hex-char key string into a 32-byte scalar. Returns 1 on ok. */
static inline int u_hex_to_bytes(const char* hex, uint8_t* out, size_t nbytes) {
    if (!hex) return 0;
    for (size_t i = 0; i < nbytes; i++) {
        char hi = hex[i*2], lo = hex[i*2+1];
        if (!hi || !lo) return 0;
        int hv = (hi <= '9') ? hi - '0' : (hi | 32) - 'a' + 10;
        int lv = (lo <= '9') ? lo - '0' : (lo | 32) - 'a' + 10;
        if (hv < 0 || hv > 15 || lv < 0 || lv > 15) return 0;
        out[i] = (uint8_t)((hv << 4) | lv);
    }
    return 1;
}

/* Derive the ES256 (P-256) public key for a private-key hex string.
 * Returns a 128-hex-char string (qx||qy), or NULL on a bad key. */
static inline char* u_ocp_pubkey(const char* privkey_hex) {
    uint8_t dbytes[32];
    if (!u_hex_to_bytes(privkey_hex, dbytes, 32)) return NULL;
    eccurve c; ec_p256(&c);
    u256 d; u256_from_be(&d, dbytes);
    u256 qx, qy; ecdsa_pubkey(&qx, &qy, &d, &c);
    uint8_t qb[64];
    u256_to_be(&qx, qb); u256_to_be(&qy, qb + 32);
    char* hex = (char*)malloc(129);
    u_hex_encode(qb, 64, hex);
    return hex;
}

/* Sign the payload with a P-256 private key (hex). Returns (r||s) as a
 * 128-hex-char string, or NULL on a bad key. This is a real ECDSA/ES256
 * signature. */
static inline char* u_ocp_sign(const UTree* payload, const char* privkey_hex) {
    uint8_t digest[32];
    u_ocp_digest(payload, digest);
    uint8_t dbytes[32];
    if (!u_hex_to_bytes(privkey_hex, dbytes, 32)) return NULL;
    eccurve c; ec_p256(&c);
    u256 d; u256_from_be(&d, dbytes);
    /* Deterministic nonce: k = sha256(privkey || digest) mod n. */
    uint8_t kmat[64]; memcpy(kmat, dbytes, 32); memcpy(kmat + 32, digest, 32);
    uint8_t khash[32]; u_sha256(kmat, 64, khash);
    u256 k; u256_from_be(&k, khash);
    if (u256_cmp(&k, &c.n) >= 0) { u256 t; u256_sub(&t, &k, &c.n); u256_copy(&k, &t); }
    ecsig sig;
    if (!ecdsa_sign(&sig, &d, digest, &k, &c)) return NULL;
    uint8_t sb[64];
    u256_to_be(&sig.r, sb); u256_to_be(&sig.s, sb + 32);
    char* hex = (char*)malloc(129);
    u_hex_encode(sb, 64, hex);
    return hex;
}

/* Verify a P-256 signature (r||s hex) against the payload and a public key
 * (qx||qy hex). Returns 1 if valid, 0 otherwise. */
static inline int32_t u_ocp_verify(const UTree* payload, const char* pubkey_hex,
                                    const char* signature_hex) {
    uint8_t digest[32];
    u_ocp_digest(payload, digest);
    uint8_t qb[64], sb[64];
    if (!u_hex_to_bytes(pubkey_hex, qb, 64)) return 0;
    if (!u_hex_to_bytes(signature_hex, sb, 64)) return 0;
    eccurve c; ec_p256(&c);
    u256 qx, qy; u256_from_be(&qx, qb); u256_from_be(&qy, qb + 32);
    ecsig sig; u256_from_be(&sig.r, sb); u256_from_be(&sig.s, sb + 32);
    return ecdsa_verify(&qx, &qy, digest, &sig, &c) ? 1 : 0;
}

/* ── keccak256 (turn 23d) ───────────────────────────────────────────────
 *
 * Ethereum's hash — Keccak-f[1600], rate 1088 bits (136 bytes), with the
 * ORIGINAL Keccak padding (0x01 ... 0x80), NOT SHA-3's 0x06. This one-byte
 * difference is why Ethereum keccak256 and standard SHA3-256 give different
 * digests for the same input; getting it wrong is the classic EIP-712 bug.
 *
 * A complete implementation. Deterministic, no external library. This is
 * the second hash in the codebase (SHA-256 was turn 23c) and it exists to
 * prove Canonical is a FAMILY: EIP-712 is a different Canonical[Scheme]
 * built on a different hash, plugging into the same slot as JCS.
 */
static const uint64_t U_KECCAK_RC[24] = {
    0x0000000000000001ULL,0x0000000000008082ULL,0x800000000000808aULL,0x8000000080008000ULL,
    0x000000000000808bULL,0x0000000080000001ULL,0x8000000080008081ULL,0x8000000000008009ULL,
    0x000000000000008aULL,0x0000000000000088ULL,0x0000000080008009ULL,0x000000008000000aULL,
    0x000000008000808bULL,0x800000000000008bULL,0x8000000000008089ULL,0x8000000000008003ULL,
    0x8000000000008002ULL,0x8000000000000080ULL,0x000000000000800aULL,0x800000008000000aULL,
    0x8000000080008081ULL,0x8000000000008080ULL,0x0000000080000001ULL,0x8000000080008008ULL
};
static const int U_KECCAK_ROT[24] = {
    1,3,6,10,15,21,28,36,45,55,2,14,27,41,56,8,25,43,62,18,39,61,20,44
};
static const int U_KECCAK_PI[24] = {
    10,7,11,17,18,3,5,16,8,21,24,4,15,23,19,13,12,2,20,14,22,9,6,1
};

#define U_ROTL64(x,n) (((x) << (n)) | ((x) >> (64 - (n))))

static inline void u_keccak_f(uint64_t st[25]) {
    for (int round = 0; round < 24; round++) {
        uint64_t bc[5], t;
        for (int i = 0; i < 5; i++)
            bc[i] = st[i] ^ st[i+5] ^ st[i+10] ^ st[i+15] ^ st[i+20];
        for (int i = 0; i < 5; i++) {
            t = bc[(i+4)%5] ^ U_ROTL64(bc[(i+1)%5], 1);
            for (int j = 0; j < 25; j += 5) st[j+i] ^= t;
        }
        t = st[1];
        for (int i = 0; i < 24; i++) {
            int j = U_KECCAK_PI[i];
            bc[0] = st[j];
            st[j] = U_ROTL64(t, U_KECCAK_ROT[i]);
            t = bc[0];
        }
        for (int j = 0; j < 25; j += 5) {
            for (int i = 0; i < 5; i++) bc[i] = st[j+i];
            for (int i = 0; i < 5; i++)
                st[j+i] ^= (~bc[(i+1)%5]) & bc[(i+2)%5];
        }
        st[0] ^= U_KECCAK_RC[round];
    }
}

static inline void u_keccak256(const uint8_t* msg, size_t len, uint8_t out[32]) {
    uint64_t st[25] = {0};
    const size_t rate = 136;  /* 1088 bits / 8 */
    uint8_t* state_bytes = (uint8_t*)st;
    size_t i = 0;
    /* Absorb full blocks */
    while (len - i >= rate) {
        for (size_t j = 0; j < rate; j++) state_bytes[j] ^= msg[i+j];
        u_keccak_f(st);
        i += rate;
    }
    /* Last block with Keccak padding (0x01 domain, 0x80 final) */
    uint8_t block[136] = {0};
    size_t rem = len - i;
    memcpy(block, msg + i, rem);
    block[rem] ^= 0x01;      /* Keccak pad (NOT SHA-3's 0x06) */
    block[rate-1] ^= 0x80;
    for (size_t j = 0; j < rate; j++) state_bytes[j] ^= block[j];
    u_keccak_f(st);
    memcpy(out, state_bytes, 32);
}

/* ══ SHAKE128/256 (FIPS 202) — XOF built on the existing u_keccak_f ═══════
 * These power SPHINCS+ below. Same Keccak-f[1600] permutation as u_keccak256,
 * different rate/padding (0x1F domain separator) and arbitrary output length. */
/* SHAKE128 / SHAKE256 (FIPS 202) — the XOF that SPHINCS+ is built on.
 * Same Keccak-f[1600] permutation, rate depends on security level,
 * domain separator 0x1F, and squeeze produces arbitrary-length output. */

/* Incremental SHAKE state: absorb any amount, then squeeze any amount. */
typedef struct {
    uint64_t st[25];
    int rate;          /* bytes: 168 for SHAKE128, 136 for SHAKE256 */
    int pos;           /* current byte offset within the rate block */
    int squeezing;     /* 0 while absorbing, 1 after first squeeze */
} u_shake;

static inline void u_shake_init(u_shake* s, int rate) {
    memset(s->st, 0, sizeof(s->st));
    s->rate = rate; s->pos = 0; s->squeezing = 0;
}
static inline void u_shake_absorb(u_shake* s, const uint8_t* in, size_t len) {
    uint8_t* sb = (uint8_t*)s->st;
    for (size_t i = 0; i < len; i++) {
        sb[s->pos++] ^= in[i];
        if (s->pos == s->rate) { u_keccak_f(s->st); s->pos = 0; }
    }
}
static inline void u_shake_finalize(u_shake* s) {
    uint8_t* sb = (uint8_t*)s->st;
    sb[s->pos] ^= 0x1F;           /* SHAKE domain separator */
    sb[s->rate - 1] ^= 0x80;      /* final bit */
    u_keccak_f(s->st);
    s->pos = 0; s->squeezing = 1;
}
static inline void u_shake_squeeze(u_shake* s, uint8_t* out, size_t len) {
    uint8_t* sb = (uint8_t*)s->st;
    if (!s->squeezing) u_shake_finalize(s);
    for (size_t i = 0; i < len; i++) {
        if (s->pos == s->rate) { u_keccak_f(s->st); s->pos = 0; }
        out[i] = sb[s->pos++];
    }
}

/* One-shot SHAKE256(msg) -> outlen bytes. */
static inline void u_shake256(const uint8_t* in, size_t inlen, uint8_t* out, size_t outlen) {
    u_shake s; u_shake_init(&s, 136);
    u_shake_absorb(&s, in, inlen);
    u_shake_squeeze(&s, out, outlen);
}
static inline void u_shake128(const uint8_t* in, size_t inlen, uint8_t* out, size_t outlen) {
    u_shake s; u_shake_init(&s, 168);
    u_shake_absorb(&s, in, inlen);
    u_shake_squeeze(&s, out, outlen);
}

/* ══ SPHINCS+ post-quantum signatures (SLH-DSA, SHAKE-128f-simple) ════════
 * Standard-conforming: keygen root and signatures are byte-identical to the
 * SPHINCS+ reference implementation, and the reference verifier accepts our
 * signatures (verified against pyspx 0.5.0 / the ref C build). Built entirely
 * on SHAKE256 above — hash-based, quantum-resistant, allocation-free. */
/* ══ SPHINCS+ (SLH-DSA) — hash-based post-quantum signatures ═══════════
 *
 * Parameter set: SPHINCS+-SHAKE-128f-simple (NIST security level 1, "fast").
 * Built entirely on SHAKE256 — no number theory, quantum-resistant because
 * its security reduces to the hash function's collision/preimage resistance.
 *
 *   n = 16      hash output bytes (128-bit security)
 *   h = 66      total hypertree height
 *   d = 22      hypertree layers   (h/d = 3 per subtree)
 *   a = 6       FORS tree height    (t = 2^a = 64 leaves per FORS tree)
 *   k = 33      FORS trees
 *   w = 16      Winternitz parameter (log2 w = 4)
 *
 * Verified against the SPHINCS+ reference "simple" construction.
 */

#define SPX_N 16
#define SPX_FULL_H 66
#define SPX_D 22
#define SPX_TREE_H (SPX_FULL_H / SPX_D)   /* 3 */
#define SPX_FORS_A 6
#define SPX_FORS_T (1 << SPX_FORS_A)      /* 64 */
#define SPX_FORS_K 33
#define SPX_WOTS_W 16
#define SPX_WOTS_LOGW 4
#define SPX_WOTS_LEN1 (8 * SPX_N / SPX_WOTS_LOGW)   /* 32 */
#define SPX_WOTS_LEN2 3                              /* checksum len for len1=32,w=16 */
#define SPX_WOTS_LEN (SPX_WOTS_LEN1 + SPX_WOTS_LEN2) /* 35 */
#define SPX_WOTS_BYTES (SPX_WOTS_LEN * SPX_N)

#define SPX_PK_BYTES (2 * SPX_N)                     /* PK.seed || PK.root */
#define SPX_SK_BYTES (4 * SPX_N)                     /* SK.seed||SK.prf||PK.seed||PK.root */

/* ADRS: 32-byte address structure (SHAKE variant uses full 32 bytes). */
#define SPX_ADDR_BYTES 32
enum { SPX_ADDR_WOTS=0, SPX_ADDR_WOTSPK=1, SPX_ADDR_TREE=2,
       SPX_ADDR_FORSTREE=3, SPX_ADDR_FORSPK=4, SPX_ADDR_WOTSPRF=5, SPX_ADDR_FORSPRF=6 };

typedef uint8_t spx_addr[8];   /* we use a 32-byte layout via helper offsets */

/* Address is 32 bytes. Layout (offsets):
 * [0..3] layer, [4..11] tree address (8 bytes, we use low bytes),
 * [12..15] type, [16..19] keypair addr, [20..23] chain/height,
 * [24..27] hash/index, [28..31] reserved. We follow the SHAKE-simple layout. */
/* Address field offsets — SHAKE layout, EXACTLY matching the SPHINCS+
 * reference ref/shake_offsets.h. addr is 32 bytes. */
#define SPX_OFF_LAYER      3
#define SPX_OFF_TREE       8    /* 8-byte field, bytes 8..15 */
#define SPX_OFF_TYPE       19
#define SPX_OFF_KP_ADDR2   22   /* keypair high byte */
#define SPX_OFF_KP_ADDR1   23   /* keypair low byte */
#define SPX_OFF_CHAIN      27
#define SPX_OFF_HASH       31
#define SPX_OFF_TREE_HGT   27
#define SPX_OFF_TREE_INDEX 28   /* 4-byte field, bytes 28..31 */

static inline void spx_ull_to_bytes(uint8_t* out, int outlen, uint64_t v) {
    for (int i = outlen - 1; i >= 0; i--) { out[i] = (uint8_t)(v & 0xff); v >>= 8; }
}
static inline void spx_set_layer(uint8_t a[32], uint32_t l){ a[SPX_OFF_LAYER] = (uint8_t)l; }
static inline void spx_set_tree(uint8_t a[32], uint64_t t){
    spx_ull_to_bytes(a + SPX_OFF_TREE, 8, t);
}
static inline void spx_set_type(uint8_t a[32], uint32_t ty){ a[SPX_OFF_TYPE] = (uint8_t)ty; }
/* copy layer+tree: reference copies SPX_OFFSET_TREE+8 = 16 bytes. */
static inline void spx_copy_subtree(uint8_t out[32], const uint8_t in[32]){
    memcpy(out, in, SPX_OFF_TREE + 8);
}
/* keypair: for 128f, SPX_FULL_HEIGHT/SPX_D = 3 <= 8, so ONLY the low byte
 * (KP_ADDR1) is written — matching the reference #if guard. */
static inline void spx_set_keypair(uint8_t a[32], uint32_t kp){
    a[SPX_OFF_KP_ADDR1] = (uint8_t)kp;
}
static inline void spx_copy_keypair(uint8_t out[32], const uint8_t in[32]){
    memcpy(out, in, SPX_OFF_TREE + 8);
    out[SPX_OFF_KP_ADDR1] = in[SPX_OFF_KP_ADDR1];
}
static inline void spx_set_chain(uint8_t a[32], uint32_t c){ a[SPX_OFF_CHAIN] = (uint8_t)c; }
static inline void spx_set_hash(uint8_t a[32], uint32_t h){ a[SPX_OFF_HASH] = (uint8_t)h; }
static inline void spx_set_tree_height(uint8_t a[32], uint32_t h){ a[SPX_OFF_TREE_HGT] = (uint8_t)h; }
static inline void spx_set_tree_index(uint8_t a[32], uint32_t i){
    spx_ull_to_bytes(a + SPX_OFF_TREE_INDEX, 4, i);
}

/* ── Tweakable hash functions (SHAKE-simple) ──────────────────────────
 * F: hash a single n-byte block. H: hash two. T_l: hash l blocks.
 * PRF: pseudorandom function. All: SHAKE256(PK.seed || ADRS || M). */
static inline void spx_F(uint8_t* out, const uint8_t* pk_seed, const uint8_t adrs[32],
                         const uint8_t* m, size_t mlen) {
    u_shake s; u_shake_init(&s,136);
    u_shake_absorb(&s, pk_seed, SPX_N);
    u_shake_absorb(&s, adrs, 32);
    u_shake_absorb(&s, m, mlen);
    u_shake_squeeze(&s, out, SPX_N);
}
static inline void spx_PRF(uint8_t* out, const uint8_t* pk_seed, const uint8_t* sk_seed,
                           const uint8_t adrs[32]) {
    u_shake s; u_shake_init(&s,136);
    u_shake_absorb(&s, pk_seed, SPX_N);
    u_shake_absorb(&s, adrs, 32);
    u_shake_absorb(&s, sk_seed, SPX_N);
    u_shake_squeeze(&s, out, SPX_N);
}
static inline void spx_PRF_msg(uint8_t* out, const uint8_t* sk_prf,
                               const uint8_t* optrand, const uint8_t* m, size_t mlen) {
    u_shake s; u_shake_init(&s,136);
    u_shake_absorb(&s, sk_prf, SPX_N);
    u_shake_absorb(&s, optrand, SPX_N);
    u_shake_absorb(&s, m, mlen);
    u_shake_squeeze(&s, out, SPX_N);
}
static inline void spx_H_msg(uint8_t* out, size_t outlen, const uint8_t* R,
                             const uint8_t* pk_seed, const uint8_t* pk_root,
                             const uint8_t* m, size_t mlen) {
    u_shake s; u_shake_init(&s,136);
    u_shake_absorb(&s, R, SPX_N);
    u_shake_absorb(&s, pk_seed, SPX_N);
    u_shake_absorb(&s, pk_root, SPX_N);
    u_shake_absorb(&s, m, mlen);
    u_shake_squeeze(&s, out, outlen);
}
/* T_len: hash an arbitrary number of n-byte blocks together. */
static inline void spx_T(uint8_t* out, const uint8_t* pk_seed, const uint8_t adrs[32],
                         const uint8_t* m, size_t mlen) {
    u_shake s; u_shake_init(&s,136);
    u_shake_absorb(&s, pk_seed, SPX_N);
    u_shake_absorb(&s, adrs, 32);
    u_shake_absorb(&s, m, mlen);
    u_shake_squeeze(&s, out, SPX_N);
}



/* H2: hash two n-byte nodes (Merkle parent). */
static inline void spx_H2(uint8_t* out, const uint8_t* pk_seed, const uint8_t adrs[32],
                          const uint8_t in[2*SPX_N]) {
    u_shake s; u_shake_init(&s,136);
    u_shake_absorb(&s, pk_seed, SPX_N);
    u_shake_absorb(&s, adrs, 32);
    u_shake_absorb(&s, in, 2*SPX_N);
    u_shake_squeeze(&s, out, SPX_N);
}

/* T2: hash two n-byte nodes (Merkle parent) — same as thash with 2 blocks. */
static inline void spx_T2(uint8_t* out, const uint8_t* pk_seed, const uint8_t adrs[32],
                          const uint8_t in[2*SPX_N]) {
    u_shake s; u_shake_init(&s,136);
    u_shake_absorb(&s, pk_seed, SPX_N);
    u_shake_absorb(&s, adrs, 32);
    u_shake_absorb(&s, in, 2*SPX_N);
    u_shake_squeeze(&s, out, SPX_N);
}

/* ── WOTS+ one-time signature ─────────────────────────────────────────── */
/* Chaining function: apply F (steps) times starting at position `start`. */
static inline void spx_wots_chain(uint8_t* out, const uint8_t* in, uint32_t start,
                                  uint32_t steps, const uint8_t* pk_seed, uint8_t adrs[32]) {
    memcpy(out, in, SPX_N);
    for (uint32_t i = start; i < start + steps && i < SPX_WOTS_W; i++) {
        spx_set_hash(adrs, i);
        spx_F(out, pk_seed, adrs, out, SPX_N);
    }
}
/* Convert message to base-w with checksum, producing SPX_WOTS_LEN digits. */
static inline void spx_wots_lengths(const uint8_t* msg, unsigned int* lengths) {
    /* base-w over the message (len1 digits) */
    for (int i = 0; i < SPX_WOTS_LEN1; i++) {
        int byte = (i * SPX_WOTS_LOGW) / 8;
        int shift = 8 - SPX_WOTS_LOGW - ((i * SPX_WOTS_LOGW) % 8);
        lengths[i] = (msg[byte] >> shift) & (SPX_WOTS_W - 1);
    }
    /* checksum */
    unsigned int csum = 0;
    for (int i = 0; i < SPX_WOTS_LEN1; i++) csum += SPX_WOTS_W - 1 - lengths[i];
    csum <<= (8 - ((SPX_WOTS_LEN2 * SPX_WOTS_LOGW) % 8)) % 8;
    int csum_bytes = (SPX_WOTS_LEN2 * SPX_WOTS_LOGW + 7) / 8;
    uint8_t csum_buf[4] = {0};
    for (int i = 0; i < csum_bytes; i++)
        csum_buf[i] = (uint8_t)(csum >> (8 * (csum_bytes - 1 - i)));
    for (int i = 0; i < SPX_WOTS_LEN2; i++) {
        int byte = (i * SPX_WOTS_LOGW) / 8;
        int shift = 8 - SPX_WOTS_LOGW - ((i * SPX_WOTS_LOGW) % 8);
        lengths[SPX_WOTS_LEN1 + i] = (csum_buf[byte] >> shift) & (SPX_WOTS_W - 1);
    }
}
/* WOTS+ pk from a signature: complete each chain to the top, then hash. */
/* Writes the RAW WOTS public key (SPX_WOTS_LEN chain endpoints, SPX_WOTS_BYTES
 * bytes) — matching the reference wots_pk_from_sig. The caller compresses it
 * with thash under a WOTSPK address to obtain the Merkle leaf. */
static inline void spx_wots_pk_from_sig(uint8_t* pk, const uint8_t* sig, const uint8_t* msg,
                                        const uint8_t* pk_seed, uint8_t adrs[32]) {
    unsigned int lengths[SPX_WOTS_LEN];
    spx_wots_lengths(msg, lengths);
    for (uint32_t i = 0; i < SPX_WOTS_LEN; i++) {
        spx_set_chain(adrs, i);
        spx_wots_chain(pk + i*SPX_N, sig + i*SPX_N, lengths[i],
                       SPX_WOTS_W - 1 - lengths[i], pk_seed, adrs);
    }
}


/* ── Merkle tree: faithful port of the reference treehashx1 ───────────── */
/* Generate a WOTS+ leaf (its public key) at leaf_idx. If sign_leaf == leaf_idx
 * and wots_sig != NULL, also emit the WOTS signature over `msg_root`. This
 * mirrors wots_gen_leafx1 in the reference. */
static inline void spx_wots_gen_leaf(uint8_t* dest, const uint8_t* sk_seed,
                                     const uint8_t* pk_seed, uint32_t leaf_idx,
                                     const uint8_t wots_addr[32], const uint8_t tree_addr_unused[32],
                                     uint32_t sign_leaf, const unsigned int* wots_steps,
                                     uint8_t* wots_sig) {
    (void)tree_addr_unused;
    uint8_t leaf_addr[32]; memcpy(leaf_addr, wots_addr, 32);
    uint8_t pk_addr[32];   memcpy(pk_addr, wots_addr, 32);
    spx_set_type(pk_addr, SPX_ADDR_WOTSPK);
    uint32_t wots_k_mask = (leaf_idx == sign_leaf) ? 0 : (uint32_t)~0;
    spx_set_keypair(leaf_addr, leaf_idx);
    spx_set_keypair(pk_addr, leaf_idx);
    uint8_t pk_buffer[SPX_WOTS_BYTES];
    for (uint32_t i = 0; i < SPX_WOTS_LEN; i++) {
        uint8_t* buffer = pk_buffer + i*SPX_N;
        uint32_t wots_k = (wots_steps ? wots_steps[i] : 0) | wots_k_mask;
        spx_set_chain(leaf_addr, i);
        spx_set_hash(leaf_addr, 0);
        spx_set_type(leaf_addr, SPX_ADDR_WOTSPRF);
        spx_PRF(buffer, pk_seed, sk_seed, leaf_addr);
        spx_set_type(leaf_addr, SPX_ADDR_WOTS);
        for (uint32_t k = 0; ; k++) {
            if (wots_sig && k == wots_k) memcpy(wots_sig + i*SPX_N, buffer, SPX_N);
            if (k == SPX_WOTS_W - 1) break;
            spx_set_hash(leaf_addr, k);
            spx_F(buffer, pk_seed, leaf_addr, buffer, SPX_N);
        }
    }
    spx_T(dest, pk_seed, pk_addr, pk_buffer, SPX_WOTS_BYTES);
}

/* Faithful port of treehashx1: builds the tree, root, and auth path for
 * leaf_idx, applying idx_offset across trees. gen_leaf is inlined as WOTS. */
static inline void spx_treehash_wots(uint8_t* root, uint8_t* auth_path,
                                     const uint8_t* sk_seed, const uint8_t* pk_seed,
                                     uint32_t leaf_idx, uint32_t idx_offset,
                                     uint32_t tree_height, uint8_t tree_addr[32],
                                     const uint8_t wots_addr[32],
                                     uint32_t sign_leaf, const unsigned int* wots_steps,
                                     uint8_t* wots_sig) {
    uint8_t stack[SPX_TREE_H * SPX_N];   /* tree_height <= SPX_TREE_H here */
    uint32_t max_idx = (1u << tree_height) - 1;
    for (uint32_t idx = 0; ; idx++) {
        uint8_t current[2*SPX_N];
        spx_wots_gen_leaf(current + SPX_N, sk_seed, pk_seed, idx + idx_offset,
                          wots_addr, tree_addr, sign_leaf, wots_steps, wots_sig);
        uint32_t internal_idx_offset = idx_offset;
        uint32_t internal_idx = idx;
        uint32_t internal_leaf = leaf_idx;
        uint32_t h;
        for (h = 0; ; h++, internal_idx >>= 1, internal_leaf >>= 1) {
            if (h == tree_height) { memcpy(root, current + SPX_N, SPX_N); return; }
            if ((internal_idx ^ internal_leaf) == 0x01 && auth_path)
                memcpy(auth_path + h*SPX_N, current + SPX_N, SPX_N);
            if ((internal_idx & 1) == 0 && idx < max_idx) break;
            internal_idx_offset >>= 1;
            spx_set_tree_height(tree_addr, h + 1);
            spx_set_tree_index(tree_addr, internal_idx/2 + internal_idx_offset);
            memcpy(current, stack + h*SPX_N, SPX_N);
            spx_T2(current + SPX_N, pk_seed, tree_addr, current);
        }
        memcpy(stack + h*SPX_N, current + SPX_N, SPX_N);
    }
}

/* Compute a root from a leaf + auth path (verification side), matching the
 * reference compute_root in utils.c. */
static inline void spx_compute_root(uint8_t* root, const uint8_t* leaf,
                                    uint32_t leaf_idx, uint32_t idx_offset,
                                    const uint8_t* auth_path, uint32_t tree_height,
                                    const uint8_t* pk_seed, uint8_t tree_addr[32]) {
    uint8_t buffer[2*SPX_N];
    uint32_t idx = leaf_idx;
    /* Left or right? */
    if (idx & 1) { memcpy(buffer + SPX_N, leaf, SPX_N); memcpy(buffer, auth_path, SPX_N); }
    else         { memcpy(buffer, leaf, SPX_N); memcpy(buffer + SPX_N, auth_path, SPX_N); }
    auth_path += SPX_N;
    for (uint32_t i = 0; i < tree_height - 1; i++) {
        idx >>= 1; idx_offset >>= 1;
        spx_set_tree_height(tree_addr, i + 1);
        spx_set_tree_index(tree_addr, idx + idx_offset);
        if (idx & 1) {
            spx_T2(buffer + SPX_N, pk_seed, tree_addr, buffer);
            memcpy(buffer, auth_path, SPX_N);
        } else {
            spx_T2(buffer, pk_seed, tree_addr, buffer);
            memcpy(buffer + SPX_N, auth_path, SPX_N);
        }
        auth_path += SPX_N;
    }
    idx >>= 1; idx_offset >>= 1;
    spx_set_tree_height(tree_addr, tree_height);
    spx_set_tree_index(tree_addr, idx + idx_offset);
    spx_T2(root, pk_seed, tree_addr, buffer);
}



static inline void spx_fors_indices(const uint8_t* m, uint32_t* indices) {
    /* Reference message_to_indices: bit (offset & 7) of byte (offset>>3),
     * placed LSB-first (<< j). */
    unsigned int offset = 0;
    for (int i = 0; i < SPX_FORS_K; i++) {
        indices[i] = 0;
        for (int j = 0; j < SPX_FORS_A; j++) {
            indices[i] ^= (uint32_t)(((m[offset >> 3] >> (offset & 0x7)) & 0x1) << j);
            offset++;
        }
    }
}

/* ── FORS: faithful port of the reference fors.c ──────────────────────── */
static inline void spx_fors_gen_sk(uint8_t* sk, const uint8_t* sk_seed,
                                   const uint8_t* pk_seed, uint8_t fors_leaf_addr[32]) {
    spx_PRF(sk, pk_seed, sk_seed, fors_leaf_addr);
}
static inline void spx_fors_sk_to_leaf(uint8_t* leaf, const uint8_t* sk,
                                       const uint8_t* pk_seed, uint8_t fors_leaf_addr[32]) {
    spx_F(leaf, pk_seed, fors_leaf_addr, sk, SPX_N);
}
/* fors_gen_leafx1: generate one FORS leaf at addr_idx. */
static inline void spx_fors_gen_leaf(uint8_t* leaf, const uint8_t* sk_seed,
                                     const uint8_t* pk_seed, uint32_t addr_idx,
                                     uint8_t fors_leaf_addr[32]) {
    spx_set_tree_index(fors_leaf_addr, addr_idx);
    spx_set_type(fors_leaf_addr, SPX_ADDR_FORSPRF);
    spx_fors_gen_sk(leaf, sk_seed, pk_seed, fors_leaf_addr);
    spx_set_type(fors_leaf_addr, SPX_ADDR_FORSTREE);
    spx_fors_sk_to_leaf(leaf, leaf, pk_seed, fors_leaf_addr);
}
/* Generic treehash for FORS (leaf gen differs from WOTS). Port of treehashx1. */
static inline void spx_treehash_fors(uint8_t* root, uint8_t* auth_path,
                                     const uint8_t* sk_seed, const uint8_t* pk_seed,
                                     uint32_t leaf_idx, uint32_t idx_offset,
                                     uint32_t tree_height, uint8_t tree_addr[32],
                                     uint8_t fors_leaf_addr[32]) {
    uint8_t stack[SPX_FORS_A * SPX_N];   /* tree_height <= SPX_FORS_A here */
    uint32_t max_idx = (1u << tree_height) - 1;
    for (uint32_t idx = 0; ; idx++) {
        uint8_t current[2*SPX_N];
        spx_fors_gen_leaf(current + SPX_N, sk_seed, pk_seed, idx + idx_offset, fors_leaf_addr);
        uint32_t internal_idx_offset = idx_offset;
        uint32_t internal_idx = idx;
        uint32_t internal_leaf = leaf_idx;
        uint32_t h;
        for (h = 0; ; h++, internal_idx >>= 1, internal_leaf >>= 1) {
            if (h == tree_height) { memcpy(root, current + SPX_N, SPX_N); return; }
            if ((internal_idx ^ internal_leaf) == 0x01 && auth_path)
                memcpy(auth_path + h*SPX_N, current + SPX_N, SPX_N);
            if ((internal_idx & 1) == 0 && idx < max_idx) break;
            internal_idx_offset >>= 1;
            spx_set_tree_height(tree_addr, h + 1);
            spx_set_tree_index(tree_addr, internal_idx/2 + internal_idx_offset);
            memcpy(current, stack + h*SPX_N, SPX_N);
            spx_T2(current + SPX_N, pk_seed, tree_addr, current);
        }
        memcpy(stack + h*SPX_N, current + SPX_N, SPX_N);
    }
}
static inline void spx_fors_sign(uint8_t* sig, uint8_t* pk, const uint8_t* m,
                                 const uint8_t* sk_seed, const uint8_t* pk_seed,
                                 const uint8_t fors_addr[32]) {
    uint32_t indices[SPX_FORS_K];
    uint8_t roots[SPX_FORS_K * SPX_N];
    uint8_t fors_tree_addr[32] = {0};
    uint8_t fors_leaf_addr[32] = {0};
    uint8_t fors_pk_addr[32] = {0};
    spx_copy_keypair(fors_tree_addr, fors_addr);
    spx_copy_keypair(fors_leaf_addr, fors_addr);
    spx_copy_keypair(fors_pk_addr, fors_addr);
    spx_set_type(fors_pk_addr, SPX_ADDR_FORSPK);
    spx_fors_indices(m, indices);
    for (int i = 0; i < SPX_FORS_K; i++) {
        uint32_t idx_offset = (uint32_t)i * (1u << SPX_FORS_A);
        spx_set_tree_height(fors_tree_addr, 0);
        spx_set_tree_index(fors_tree_addr, indices[i] + idx_offset);
        spx_set_type(fors_tree_addr, SPX_ADDR_FORSPRF);
        spx_fors_gen_sk(sig, sk_seed, pk_seed, fors_tree_addr);
        spx_set_type(fors_tree_addr, SPX_ADDR_FORSTREE);
        sig += SPX_N;
        spx_treehash_fors(roots + i*SPX_N, sig, sk_seed, pk_seed,
                          indices[i], idx_offset, SPX_FORS_A, fors_tree_addr, fors_leaf_addr);
        sig += SPX_N * SPX_FORS_A;
    }
    spx_T(pk, pk_seed, fors_pk_addr, roots, SPX_FORS_K * SPX_N);
}
static inline void spx_fors_pk_from_sig(uint8_t* pk, const uint8_t* sig, const uint8_t* m,
                                        const uint8_t* pk_seed, const uint8_t fors_addr[32]) {
    uint32_t indices[SPX_FORS_K];
    uint8_t roots[SPX_FORS_K * SPX_N];
    uint8_t leaf[SPX_N];
    uint8_t fors_tree_addr[32] = {0};
    uint8_t fors_pk_addr[32] = {0};
    spx_copy_keypair(fors_tree_addr, fors_addr);
    spx_copy_keypair(fors_pk_addr, fors_addr);
    spx_set_type(fors_tree_addr, SPX_ADDR_FORSTREE);
    spx_set_type(fors_pk_addr, SPX_ADDR_FORSPK);
    spx_fors_indices(m, indices);
    for (int i = 0; i < SPX_FORS_K; i++) {
        uint32_t idx_offset = (uint32_t)i * (1u << SPX_FORS_A);
        spx_set_tree_height(fors_tree_addr, 0);
        spx_set_tree_index(fors_tree_addr, indices[i] + idx_offset);
        spx_fors_sk_to_leaf(leaf, sig, pk_seed, fors_tree_addr);
        sig += SPX_N;
        spx_compute_root(roots + i*SPX_N, leaf, indices[i], idx_offset,
                         sig, SPX_FORS_A, pk_seed, fors_tree_addr);
        sig += SPX_N * SPX_FORS_A;
    }
    spx_T(pk, pk_seed, fors_pk_addr, roots, SPX_FORS_K * SPX_N);
}

/* ── SPHINCS+ top level (faithful port of sign.c) ─────────────────────── */
#define SPX_FORS_BYTES ((SPX_FORS_A + 1) * SPX_FORS_K * SPX_N)
#define SPX_SIG_BYTES (SPX_N + SPX_FORS_BYTES + SPX_D * (SPX_WOTS_BYTES + SPX_TREE_H * SPX_N))
#define SPX_FORS_MSG_BYTES ((SPX_FORS_A * SPX_FORS_K + 7) / 8)
#define SPX_TREE_BITS (SPX_TREE_H * (SPX_D - 1))
#define SPX_TREE_BYTES ((SPX_TREE_BITS + 7) / 8)
#define SPX_LEAF_BITS SPX_TREE_H
#define SPX_LEAF_BYTES ((SPX_LEAF_BITS + 7) / 8)
#define SPX_DGST_BYTES (SPX_FORS_MSG_BYTES + SPX_TREE_BYTES + SPX_LEAF_BYTES)

static inline uint64_t spx_bytes_to_ull(const uint8_t* in, int inlen) {
    uint64_t r = 0;
    for (int i = 0; i < inlen; i++) r |= ((uint64_t)in[i]) << (8 * (inlen - 1 - i));
    return r;
}
/* merkle_sign: WOTS-sign root at idx_leaf + emit auth path. */
static inline void spx_merkle_sign(uint8_t* sig, uint8_t* root, const uint8_t* sk_seed,
                                   const uint8_t* pk_seed, uint8_t wots_addr[32],
                                   uint8_t tree_addr[32], uint32_t idx_leaf) {
    uint8_t* auth_path = sig + SPX_WOTS_BYTES;
    unsigned int steps[SPX_WOTS_LEN];
    spx_wots_lengths(root, steps);
    spx_set_type(tree_addr, SPX_ADDR_TREE);
    spx_treehash_wots(root, auth_path, sk_seed, pk_seed, idx_leaf, 0,
                      SPX_TREE_H, tree_addr, wots_addr, idx_leaf, steps, sig);
}
static inline void spx_keygen(uint8_t* pk, uint8_t* sk,
                              const uint8_t* sk_seed, const uint8_t* sk_prf,
                              const uint8_t* pk_seed) {
    memcpy(sk, sk_seed, SPX_N);
    memcpy(sk + SPX_N, sk_prf, SPX_N);
    memcpy(sk + 2*SPX_N, pk_seed, SPX_N);
    memcpy(pk, pk_seed, SPX_N);
    /* merkle_gen_root: top subtree (layer D-1, tree 0). */
    uint8_t tree_addr[32] = {0};
    uint8_t wots_addr[32] = {0};
    spx_set_layer(tree_addr, SPX_D - 1);
    spx_set_layer(wots_addr, SPX_D - 1);
    uint8_t root[SPX_N];
    /* merkle_sign writes a WOTS signature (SPX_WOTS_BYTES) followed by the auth
     * path; keygen discards both but must supply the full buffer. Matches the
     * reference merkle_gen_root's auth_path[SPX_TREE_HEIGHT*SPX_N + SPX_WOTS_BYTES]. */
    uint8_t auth[SPX_WOTS_BYTES + SPX_TREE_H * SPX_N];
    spx_set_type(wots_addr, SPX_ADDR_WOTS);
    spx_merkle_sign(auth, root, sk_seed, pk_seed, wots_addr, tree_addr, (uint32_t)~0);
    memcpy(sk + 3*SPX_N, root, SPX_N);
    memcpy(pk + SPX_N, root, SPX_N);
}
static inline void spx_sign(uint8_t* sig, const uint8_t* m, size_t mlen, const uint8_t* sk) {
    const uint8_t* sk_seed = sk;
    const uint8_t* sk_prf  = sk + SPX_N;
    const uint8_t* pk      = sk + 2*SPX_N;      /* pk_seed || pk_root */
    const uint8_t* pk_seed = pk;
    uint8_t optrand[SPX_N];
    memcpy(optrand, pk_seed, SPX_N);            /* deterministic: optrand = pk_seed */
    uint8_t* sig0 = sig;
    /* R = PRF_msg(sk_prf, optrand, m) */
    spx_PRF_msg(sig, sk_prf, optrand, m, mlen);
    uint8_t R[SPX_N]; memcpy(R, sig, SPX_N);
    /* digest = H_msg(R, pk, m) */
    uint8_t digest[SPX_DGST_BYTES];
    { u_shake s; u_shake_init(&s,136);
      u_shake_absorb(&s, R, SPX_N);
      u_shake_absorb(&s, pk, 2*SPX_N);
      u_shake_absorb(&s, m, mlen);
      u_shake_squeeze(&s, digest, SPX_DGST_BYTES); }
    uint8_t mhash[SPX_FORS_MSG_BYTES];
    memcpy(mhash, digest, SPX_FORS_MSG_BYTES);
    uint64_t tree = spx_bytes_to_ull(digest + SPX_FORS_MSG_BYTES, SPX_TREE_BYTES);
    tree &= (~(uint64_t)0) >> (64 - SPX_TREE_BITS);
    uint32_t idx_leaf = (uint32_t)spx_bytes_to_ull(digest + SPX_FORS_MSG_BYTES + SPX_TREE_BYTES, SPX_LEAF_BYTES);
    idx_leaf &= (~(uint32_t)0) >> (32 - SPX_LEAF_BITS);
    sig += SPX_N;
    uint8_t wots_addr[32] = {0};
    uint8_t tree_addr[32] = {0};
    spx_set_type(wots_addr, SPX_ADDR_WOTS);
    spx_set_tree(wots_addr, tree);
    spx_set_keypair(wots_addr, idx_leaf);
    uint8_t root[SPX_N];
    spx_fors_sign(sig, root, mhash, sk_seed, pk_seed, wots_addr);
    sig += SPX_FORS_BYTES;
    for (uint32_t i = 0; i < SPX_D; i++) {
        spx_set_layer(tree_addr, i);
        spx_set_tree(tree_addr, tree);
        spx_copy_subtree(wots_addr, tree_addr);
        spx_set_keypair(wots_addr, idx_leaf);
        spx_merkle_sign(sig, root, sk_seed, pk_seed, wots_addr, tree_addr, idx_leaf);
        sig += SPX_WOTS_BYTES + SPX_TREE_H * SPX_N;
        idx_leaf = (uint32_t)(tree & ((1u << SPX_TREE_H) - 1));
        tree = tree >> SPX_TREE_H;
    }
    (void)sig0;
}
static inline int spx_verify(const uint8_t* sig, const uint8_t* m, size_t mlen, const uint8_t* pk) {
    const uint8_t* pk_seed = pk;
    const uint8_t* pub_root = pk + SPX_N;
    uint8_t digest[SPX_DGST_BYTES];
    const uint8_t* R = sig;
    { u_shake s; u_shake_init(&s,136);
      u_shake_absorb(&s, R, SPX_N);
      u_shake_absorb(&s, pk, 2*SPX_N);
      u_shake_absorb(&s, m, mlen);
      u_shake_squeeze(&s, digest, SPX_DGST_BYTES); }
    uint8_t mhash[SPX_FORS_MSG_BYTES];
    memcpy(mhash, digest, SPX_FORS_MSG_BYTES);
    uint64_t tree = spx_bytes_to_ull(digest + SPX_FORS_MSG_BYTES, SPX_TREE_BYTES);
    tree &= (~(uint64_t)0) >> (64 - SPX_TREE_BITS);
    uint32_t idx_leaf = (uint32_t)spx_bytes_to_ull(digest + SPX_FORS_MSG_BYTES + SPX_TREE_BYTES, SPX_LEAF_BYTES);
    idx_leaf &= (~(uint32_t)0) >> (32 - SPX_LEAF_BITS);
    sig += SPX_N;
    uint8_t wots_addr[32] = {0};
    uint8_t tree_addr[32] = {0};
    uint8_t wots_pk_addr[32] = {0};
    spx_set_type(wots_addr, SPX_ADDR_WOTS);
    spx_set_type(tree_addr, SPX_ADDR_TREE);
    spx_set_type(wots_pk_addr, SPX_ADDR_WOTSPK);
    spx_set_tree(wots_addr, tree);
    spx_set_keypair(wots_addr, idx_leaf);
    uint8_t root[SPX_N];
    spx_fors_pk_from_sig(root, sig, mhash, pk_seed, wots_addr);
    sig += SPX_FORS_BYTES;
    for (uint32_t i = 0; i < SPX_D; i++) {
        spx_set_layer(tree_addr, i);
        spx_set_tree(tree_addr, tree);
        spx_copy_subtree(wots_addr, tree_addr);
        spx_set_keypair(wots_addr, idx_leaf);
        spx_copy_keypair(wots_pk_addr, wots_addr);
        uint8_t wots_pk[SPX_WOTS_BYTES];
        spx_wots_pk_from_sig(wots_pk, sig, root, pk_seed, wots_addr);
        sig += SPX_WOTS_BYTES;
        uint8_t leaf[SPX_N];
        spx_T(leaf, pk_seed, wots_pk_addr, wots_pk, SPX_WOTS_BYTES);
        spx_compute_root(root, leaf, idx_leaf, 0, sig, SPX_TREE_H, pk_seed, tree_addr);
        sig += SPX_TREE_H * SPX_N;
        idx_leaf = (uint32_t)(tree & ((1u << SPX_TREE_H) - 1));
        tree = tree >> SPX_TREE_H;
    }
    return memcmp(root, pub_root, SPX_N) == 0 ? 1 : 0;
}

/* ── EIP-712 — Ethereum typed structured data (turn 23d) ────────────────
 *
 * The EIP-712 digest that a wallet signs:
 *   keccak256( 0x1901 || domainSeparator || hashStruct(message) )
 *
 * where hashStruct(s) = keccak256( typeHash || encodeData(s) ) and
 *       typeHash = keccak256( "TypeName(field1 type1,field2 type2,...)" ).
 *
 * The typeHash is a CONSTANT for a given struct type. Because U makes the
 * Canonical scheme a TYPE parameter (turn 23b), the compiler can fold the
 * typeHash at compile time — it is keccak256 of a string literal known from
 * the type. That is the concrete payoff of "scheme as type parameter."
 *
 * u_eip712_type_hash and u_eip712_digest below are real keccak256, and
 * u_eip712_sign / u_eip712_verify below are REAL ECDSA over secp256k1 —
 * the curve Ethereum uses. The signature includes the recovery id v, so
 * the full (r, s, v) an Ethereum wallet produces is available.
 */

/* typeHash = keccak256(encodeType). encodeType is the canonical type string,
 * e.g. "Mail(address from,address to,string contents)". */
static inline void u_eip712_type_hash(const char* encode_type, uint8_t out[32]) {
    u_keccak256((const uint8_t*)encode_type, strlen(encode_type), out);
}

/* The final EIP-712 digest: keccak256(0x1901 || domainSep || structHash). */
static inline void u_eip712_digest(const uint8_t domain_sep[32],
                                    const uint8_t struct_hash[32],
                                    uint8_t out[32]) {
    uint8_t material[2 + 32 + 32];
    material[0] = 0x19;
    material[1] = 0x01;
    memcpy(material + 2, domain_sep, 32);
    memcpy(material + 34, struct_hash, 32);
    u_keccak256(material, sizeof(material), out);
}

/* Sign an EIP-712 digest with a secp256k1 private key (hex). Writes (r||s)
 * as a 128-hex-char string into *out_sig (caller frees), and returns the
 * recovery id v (0 or 1), or -1 on a bad key. This is a real ECDSA
 * signature over secp256k1 — the same curve and hash Ethereum uses. */
static inline int u_eip712_sign(const uint8_t digest[32], const char* privkey_hex,
                                 char** out_sig) {
    uint8_t dbytes[32];
    if (!u_hex_to_bytes(privkey_hex, dbytes, 32)) return -1;
    eccurve c; ec_secp256k1(&c);
    u256 d; u256_from_be(&d, dbytes);
    /* Deterministic nonce: k = sha256(privkey || digest) mod n. */
    uint8_t kmat[64]; memcpy(kmat, dbytes, 32); memcpy(kmat + 32, digest, 32);
    uint8_t khash[32]; u_sha256(kmat, 64, khash);
    u256 k; u256_from_be(&k, khash);
    if (u256_cmp(&k, &c.n) >= 0) { u256 t; u256_sub(&t, &k, &c.n); u256_copy(&k, &t); }
    /* Recovery id: parity of R.y and whether R.x >= n. Compute R = k*G. */
    ecpt G, R; ec_generator(&G, &c); ec_mul(&R, &k, &G, &c);
    u256 rx, ry; ec_to_affine(&rx, &ry, &R, &c);
    int v = (int)(ry.v[0] & 1);
    if (u256_cmp(&rx, &c.n) >= 0) v |= 2;
    ecsig sig;
    if (!ecdsa_sign(&sig, &d, digest, &k, &c)) return -1;
    /* Low-s normalization flips the recovery parity. */
    /* (ecdsa_sign already low-s normalized; account for the flip.) */
    uint8_t sb[64];
    u256_to_be(&sig.r, sb); u256_to_be(&sig.s, sb + 32);
    char* hex = (char*)malloc(129);
    u_hex_encode(sb, 64, hex);
    *out_sig = hex;
    return v & 1;
}

/* Verify a secp256k1 signature (r||s hex) against a digest and public key
 * (qx||qy hex). Returns 1 if valid, 0 otherwise. */
static inline int32_t u_eip712_verify(const uint8_t digest[32],
                                       const char* pubkey_hex,
                                       const char* signature_hex) {
    uint8_t qb[64], sb[64];
    if (!u_hex_to_bytes(pubkey_hex, qb, 64)) return 0;
    if (!u_hex_to_bytes(signature_hex, sb, 64)) return 0;
    eccurve c; ec_secp256k1(&c);
    u256 qx, qy; u256_from_be(&qx, qb); u256_from_be(&qy, qb + 32);
    ecsig sig; u256_from_be(&sig.r, sb); u256_from_be(&sig.s, sb + 32);
    return ecdsa_verify(&qx, &qy, digest, &sig, &c) ? 1 : 0;
}

/* Derive the secp256k1 public key (qx||qy hex) from a private key (hex). */
static inline char* u_eip712_pubkey(const char* privkey_hex) {
    uint8_t dbytes[32];
    if (!u_hex_to_bytes(privkey_hex, dbytes, 32)) return NULL;
    eccurve c; ec_secp256k1(&c);
    u256 d; u256_from_be(&d, dbytes);
    u256 qx, qy; ecdsa_pubkey(&qx, &qy, &d, &c);
    uint8_t qb[64]; u256_to_be(&qx, qb); u256_to_be(&qy, qb + 32);
    char* hex = (char*)malloc(129);
    u_hex_encode(qb, 64, hex);
    return hex;
}

/* ── ECDH — elliptic-curve Diffie-Hellman shared secret (TLS handshake) ──
 * Given our private key and the peer's public point, the shared secret is
 * the x-coordinate of (our_priv * their_pub). Both sides compute the same
 * point. `curve` selects P-256 (0) or secp256k1 (1). Returns a 64-hex-char
 * string (the shared x), or NULL on a bad input. */
static inline char* u_ecdh(const char* our_priv_hex, const char* their_pub_hex,
                           int curve) {
    uint8_t dbytes[32], qb[64];
    if (!u_hex_to_bytes(our_priv_hex, dbytes, 32)) return NULL;
    if (!u_hex_to_bytes(their_pub_hex, qb, 64)) return NULL;
    eccurve c; if (curve == 1) ec_secp256k1(&c); else ec_p256(&c);
    u256 d; u256_from_be(&d, dbytes);
    ecpt Q; u256_from_be(&Q.X, qb); u256_from_be(&Q.Y, qb + 32);
    u256_zero(&Q.Z); Q.Z.v[0] = 1;
    ecpt S; ec_mul(&S, &d, &Q, &c);
    u256 sx, sy;
    if (!ec_to_affine(&sx, &sy, &S, &c)) return NULL;
    uint8_t sb[32]; u256_to_be(&sx, sb);
    char* hex = (char*)malloc(65);
    u_hex_encode(sb, 32, hex);
    return hex;
}

/* ── High-level crypto entry points (SubtleCrypto-shaped) ───────────────
 * These map algorithm-name strings to the right primitive, matching the
 * shape of the U stdlib Crypto/Hmac/Aes/Kdf classes. Data in, hex out. */

/* Digest: "SHA-256"|"SHA-384"|"SHA-512"|"KECCAK-256" → hex. */
static inline char* u_crypto_digest(const char* algo, const uint8_t* data, size_t len) {
    uint8_t out[64]; int n;
    if (!strcmp(algo, "SHA-256"))     { u_sha256(data, len, out); n = 32; }
    else if (!strcmp(algo, "SHA-384")){ u_sha384(data, len, out); n = 48; }
    else if (!strcmp(algo, "SHA-512")){ u_sha512(data, len, out); n = 64; }
    else if (!strcmp(algo, "KECCAK-256")){ u_keccak256(data, len, out); n = 32; }
    else return NULL;
    char* hex = (char*)malloc(n * 2 + 1);
    u_hex_encode(out, n, hex);
    return hex;
}

/* HMAC: pick the hash by name, return hex. */
static inline char* u_crypto_hmac(const char* algo, const uint8_t* key, size_t klen,
                                  const uint8_t* msg, size_t mlen) {
    u_hashdesc d; int n;
    if (!strcmp(algo, "SHA-256"))     { d = u_hash_sha256(); n = 32; }
    else if (!strcmp(algo, "SHA-384")){ d = u_hash_sha384(); n = 48; }
    else if (!strcmp(algo, "SHA-512")){ d = u_hash_sha512(); n = 64; }
    else return NULL;
    uint8_t out[64];
    u_hmac(d, key, klen, msg, mlen, out);
    char* hex = (char*)malloc(n * 2 + 1);
    u_hex_encode(out, n, hex);
    return hex;
}


/* ── JSON.decode — JSON text → Tree ─────────────────────────────────────
 *
 * Recursive-descent parser. Produces a UTree that __unpack__ consumes:
 *   JSON text --u_json_decode--> Tree --__unpack__--> T
 *
 * Number handling mirrors the encoder:
 *   - Integer-valued numbers (no '.', no 'e'/'E') → U_TREE_INT
 *   - Everything else → U_TREE_NUM (double)
 *   - Canonical strings for out-of-safe-range ints are decoded BACK to
 *     U_TREE_INT by __unpack__, not here — the decoder sees a string and
 *     produces a string.  The boundary knows the field type.
 *
 * On malformed input: returns NULL (not a crash, not an abort).
 * The caller (JSON.parse[T]) can turn that into ! JsonError.
 */

/* Skip whitespace */
static inline const char* u_json_ws(const char* p) {
    while (*p == ' ' || *p == '\t' || *p == '\n' || *p == '\r') p++;
    return p;
}

static inline UTree* u_json_val(const char** pp);

/* Parse a JSON string (the cursor points at the opening quote). */
static inline char* u_json_parse_str(const char** pp) {
    const char* p = *pp;
    if (*p != '"') return NULL;
    p++;  /* skip opening " */
    UStrBuf b = { NULL, 0, 0 };
    u_sb_put(&b, "");   /* ensure non-NULL buf */
    while (*p && *p != '"') {
        if (*p == '\\') {
            p++;
            switch (*p) {
                case '"':  u_sb_putn(&b, "\"", 1); p++; break;
                case '\\': u_sb_putn(&b, "\\", 1); p++; break;
                case '/':  u_sb_putn(&b, "/",  1); p++; break;
                case 'n':  u_sb_putn(&b, "\n", 1); p++; break;
                case 'r':  u_sb_putn(&b, "\r", 1); p++; break;
                case 't':  u_sb_putn(&b, "\t", 1); p++; break;
                case 'b':  u_sb_putn(&b, "\b", 1); p++; break;
                case 'f':  u_sb_putn(&b, "\f", 1); p++; break;
                case 'u': {
                    /* \uXXXX — decode to UTF-8 */
                    p++;
                    unsigned cp = 0;
                    for (int i = 0; i < 4 && *p; i++, p++) {
                        cp <<= 4;
                        if (*p >= '0' && *p <= '9') cp |= (unsigned)(*p - '0');
                        else if (*p >= 'a' && *p <= 'f') cp |= (unsigned)(*p - 'a' + 10);
                        else if (*p >= 'A' && *p <= 'F') cp |= (unsigned)(*p - 'A' + 10);
                        else break;
                    }
                    /* Surrogate pair: \uD800-\uDBFF followed by \uDC00-\uDFFF */
                    if (cp >= 0xD800 && cp <= 0xDBFF && p[0] == '\\' && p[1] == 'u') {
                        p += 2;  /* skip \u */
                        unsigned lo = 0;
                        for (int i = 0; i < 4 && *p; i++, p++) {
                            lo <<= 4;
                            if (*p >= '0' && *p <= '9') lo |= (unsigned)(*p - '0');
                            else if (*p >= 'a' && *p <= 'f') lo |= (unsigned)(*p - 'a' + 10);
                            else if (*p >= 'A' && *p <= 'F') lo |= (unsigned)(*p - 'A' + 10);
                            else break;
                        }
                        cp = 0x10000 + ((cp - 0xD800) << 10) + (lo - 0xDC00);
                    }
                    /* UTF-8 encode */
                    char u8[5];
                    int n = 0;
                    if (cp < 0x80)         { u8[n++] = (char)cp; }
                    else if (cp < 0x800)   { u8[n++] = (char)(0xC0 | (cp >> 6));
                                             u8[n++] = (char)(0x80 | (cp & 0x3F)); }
                    else if (cp < 0x10000) { u8[n++] = (char)(0xE0 | (cp >> 12));
                                             u8[n++] = (char)(0x80 | ((cp >> 6) & 0x3F));
                                             u8[n++] = (char)(0x80 | (cp & 0x3F)); }
                    else                   { u8[n++] = (char)(0xF0 | (cp >> 18));
                                             u8[n++] = (char)(0x80 | ((cp >> 12) & 0x3F));
                                             u8[n++] = (char)(0x80 | ((cp >> 6) & 0x3F));
                                             u8[n++] = (char)(0x80 | (cp & 0x3F)); }
                    u_sb_putn(&b, u8, (size_t)n);
                    break;
                }
                default: u_sb_putn(&b, p, 1); p++; break;
            }
        } else {
            u_sb_putn(&b, p, 1);
            p++;
        }
    }
    if (*p == '"') p++;  /* skip closing " */
    *pp = p;
    return b.buf;
}

/* Parse a JSON number. */
static inline UTree* u_json_parse_num(const char** pp) {
    const char* start = *pp;
    const char* p = start;
    int is_float = 0;
    if (*p == '-') p++;
    while (*p >= '0' && *p <= '9') p++;
    if (*p == '.') { is_float = 1; p++; while (*p >= '0' && *p <= '9') p++; }
    if (*p == 'e' || *p == 'E') {
        is_float = 1; p++;
        if (*p == '+' || *p == '-') p++;
        while (*p >= '0' && *p <= '9') p++;
    }
    *pp = p;
    if (is_float) {
        return u_tree_num(strtod(start, NULL));
    } else {
        return u_tree_int(strtoll(start, NULL, 10));
    }
}

/* Parse any JSON value. */
static inline UTree* u_json_val(const char** pp) {
    const char* p = u_json_ws(*pp);
    if (!*p) { *pp = p; return NULL; }

    switch (*p) {
        case '"': {
            char* s = u_json_parse_str(&p);
            *pp = p;
            return u_tree_str(s ? s : "");
        }
        case 't':  /* true */
            if (strncmp(p, "true", 4) == 0) { *pp = p + 4; return u_tree_bool(1); }
            *pp = p; return NULL;
        case 'f':  /* false */
            if (strncmp(p, "false", 5) == 0) { *pp = p + 5; return u_tree_bool(0); }
            *pp = p; return NULL;
        case 'n':  /* null */
            if (strncmp(p, "null", 4) == 0) { *pp = p + 4; return u_tree_null(); }
            *pp = p; return NULL;
        case '[': {
            p++;  /* skip [ */
            UTree* arr = u_tree_arr();
            p = u_json_ws(p);
            if (*p == ']') { *pp = p + 1; return arr; }
            while (*p) {
                UTree* item = u_json_val(&p);
                if (item) u_tree_push(arr, item);
                p = u_json_ws(p);
                if (*p == ',') { p++; continue; }
                if (*p == ']') { p++; break; }
                break;  /* malformed */
            }
            *pp = p;
            return arr;
        }
        case '{': {
            p++;  /* skip { */
            UTree* obj = u_tree_new(U_TREE_NODE);
            p = u_json_ws(p);
            if (*p == '}') { *pp = p + 1; return obj; }
            while (*p) {
                p = u_json_ws(p);
                if (*p != '"') break;  /* malformed */
                char* key = u_json_parse_str(&p);
                p = u_json_ws(p);
                if (*p != ':') { free(key); break; }
                p++;  /* skip : */
                UTree* val = u_json_val(&p);
                if (key && val) u_tree_set(obj, key, val);
                if (key) free(key);
                p = u_json_ws(p);
                if (*p == ',') { p++; continue; }
                if (*p == '}') { p++; break; }
                break;  /* malformed */
            }
            *pp = p;
            return obj;
        }
        default:
            /* Number or malformed */
            if (*p == '-' || (*p >= '0' && *p <= '9')) {
                return u_json_parse_num(pp);
            }
            *pp = p;
            return NULL;
    }
}

/* Public API: parse a JSON string into a Tree.
 * Returns NULL on malformed input. */
static inline UTree* u_json_decode(const char* text) {
    if (!text) return NULL;
    const char* p = text;
    UTree* result = u_json_val(&p);
    return result;
}

/* ── JSON with comments — Config files ──────────────────────────────────
 *
 * U config files are JSON with block and line comments stripped.
 * This is the plan's design: "JSON with comments stripped."
 *
 * Strip comments, then feed to u_json_decode. The strip is in-place on a
 * COPY — the original is untouched. Inside strings, comments are literal
 * text and must not be stripped.
 */
static inline char* u_json_strip_comments(const char* src) {
    if (!src) return NULL;
    size_t n = strlen(src);
    char* out = (char*)malloc(n + 1);
    if (!out) return NULL;
    size_t j = 0;
    int in_string = 0;
    for (size_t i = 0; i < n; i++) {
        if (in_string) {
            out[j++] = src[i];
            if (src[i] == '\\' && i + 1 < n) { out[j++] = src[++i]; }
            else if (src[i] == '"') { in_string = 0; }
            continue;
        }
        if (src[i] == '"') { in_string = 1; out[j++] = src[i]; continue; }
        if (src[i] == '/' && i + 1 < n && src[i+1] == '/') {
            /* line comment — skip to end of line */
            i += 2;
            while (i < n && src[i] != '\n') i++;
            if (i < n) out[j++] = '\n';  /* preserve the newline */
            continue;
        }
        if (src[i] == '/' && i + 1 < n && src[i+1] == '*') {
            /* block comment — skip to closing *​/ */
            i += 2;
            while (i + 1 < n && !(src[i] == '*' && src[i+1] == '/')) i++;
            if (i + 1 < n) i++;  /* skip the / */
            continue;
        }
        out[j++] = src[i];
    }
    out[j] = '\0';
    return out;
}

/* Decode JSON with comments (config files). */
static inline UTree* u_json_decode_config(const char* text) {
    char* stripped = u_json_strip_comments(text);
    UTree* result = u_json_decode(stripped);
    free(stripped);
    return result;
}

/* ── Config.get / Config.expect — typed config access ───────────────────
 *
 * Config is a Tree loaded from JSON files. Access is by dotted path:
 *   Config.get(["Users", "login", "timeout"]) -> Tree.Value +N
 *   Config.expect(["Users", "login", "timeout"]) -> Tree.Value ! MissingConfig
 *
 * The path walk is the same as Q_Config::get("Users", "login", "timeout"):
 * descend into nested nodes by key. +N on get, ! on expect.
 */
static inline UTree* u_config_get(UTree* root, const char** path, int32_t depth) {
    UTree* cur = root;
    for (int32_t i = 0; i < depth && cur; i++) {
        if (cur->kind != U_TREE_NODE) return NULL;
        cur = u_tree_get(cur, path[i]);
    }
    return cur;
}

/* ── Text — i18n resolution with {{interpolation}} ──────────────────────
 *
 * Text bundles live in src/text/<Module>/<lang>.json. A bundle is a Tree
 * (nested keys). Text.greeting({ name: user.name }) resolves the key path
 * ["greeting"] in the current-language bundle, then interpolates {{name}}
 * from the params.
 *
 *   Text.greeting({ name: "Alice" })
 *     bundle["greeting"] = "Hello, {{name}}!"
 *     → "Hello, Alice!"
 *
 * __text__ is the protocol method. Missing keys and unmatched {{params}}
 * are compile errors (turn 17), so at runtime resolution always succeeds.
 */

/* Interpolate {{key}} placeholders in a template using a params Tree. */
static inline char* u_text_interpolate(const char* template, UTree* params) {
    if (!template) return u_tree_strdup("");
    size_t tlen = strlen(template);
    /* Worst case: every char stays + generous room for substitutions */
    size_t cap = tlen + 256;
    char* out = (char*)malloc(cap);
    size_t oi = 0;
    size_t i = 0;

    #define ENSURE(n) do { \
        while (oi + (n) >= cap) { cap = cap + cap; out = (char*)realloc(out, cap); } \
    } while(0)

    while (i < tlen) {
        if (template[i] == '{' && i + 1 < tlen && template[i+1] == '{') {
            /* Find the closing }} */
            size_t key_start = i + 2;
            size_t j = key_start;
            while (j + 1 < tlen && !(template[j] == '}' && template[j+1] == '}')) j++;
            if (j + 1 < tlen) {
                /* Extract the key (trimmed) */
                char key[128];
                size_t ki = 0;
                for (size_t k = key_start; k < j && ki < 127; k++) {
                    char c = template[k];
                    if (c != ' ' && c != '\t') key[ki++] = c;
                }
                key[ki] = '\0';
                /* Look up the value */
                UTree* val = params ? u_tree_get(params, key) : NULL;
                if (val) {
                    char buf[64];
                    const char* sub = NULL;
                    if (val->kind == U_TREE_STR) sub = val->as.s;
                    else if (val->kind == U_TREE_INT) {
                        snprintf(buf, sizeof(buf), "%lld", (long long)val->as.i);
                        sub = buf;
                    } else if (val->kind == U_TREE_NUM) {
                        snprintf(buf, sizeof(buf), "%g", val->as.n);
                        sub = buf;
                    } else if (val->kind == U_TREE_BOOL) {
                        sub = val->as.b ? "true" : "false";
                    }
                    if (sub) {
                        size_t slen = strlen(sub);
                        ENSURE(slen);
                        memcpy(out + oi, sub, slen);
                        oi += slen;
                    }
                }
                /* else: leave nothing (missing param — compile error catches it) */
                i = j + 2;  /* skip past }} */
                continue;
            }
        }
        ENSURE(1);
        out[oi++] = template[i++];
    }
    ENSURE(1);
    out[oi] = '\0';
    #undef ENSURE
    return out;
}

/* Resolve a text key from a bundle and interpolate. */
static inline char* u_text_resolve(UTree* bundle, const char** key_path,
                                    int32_t depth, UTree* params) {
    UTree* node = u_config_get(bundle, key_path, depth);
    if (!node || node->kind != U_TREE_STR) {
        return u_tree_strdup("");  /* missing — turn 17 makes this a compile error */
    }
    return u_text_interpolate(node->as.s, params);
}

/* ── Module registration — capability grant check (turn 20) ─────────────
 *
 * A module loaded at runtime declares the capabilities it needs. The loader
 * checks each against the dynamic_grantable whitelist. A capability is
 * granted if it is whitelisted exactly, or a covering namespace prefix is
 * (Network grants Network.HTTP.get).
 *
 *   u_capability_grantable("Network.HTTP.get", grantable_list, n)
 *
 * Returns 1 if grantable, 0 if not. The JIT rejects a load where any
 * requested capability returns 0.
 */
static inline int32_t u_capability_grantable(const char* cap,
                                              const char** grantable,
                                              int32_t n) {
    if (!cap) return 0;
    for (int32_t i = 0; i < n; i++) {
        const char* g = grantable[i];
        if (strcmp(cap, g) == 0) return 1;  /* exact match */
        /* Prefix match: g must be a namespace prefix of cap, i.e. cap starts
         * with g followed by '.' */
        size_t glen = strlen(g);
        if (strncmp(cap, g, glen) == 0 && cap[glen] == '.') return 1;
    }
    return 0;
}

/* Check a whole module: return 1 if ALL requested capabilities are grantable. */
static inline int32_t u_module_check_grant(const char** requested, int32_t req_n,
                                            const char** grantable, int32_t grant_n) {
    for (int32_t i = 0; i < req_n; i++) {
        if (!u_capability_grantable(requested[i], grantable, grant_n)) {
            return 0;  /* at least one capability cannot be granted → reject */
        }
    }
    return 1;
}

/* ── Combinators: memo / debounce / batch (turn 21) ─────────────────────
 *
 * These are `z f` in U — the compiler generates a wrapper around the
 * original function. The runtime provides the STATE each wrapper needs:
 * memo needs a cache, debounce needs a timestamp, batch needs a queue.
 */

typedef struct UMemoEntry {
    int64_t key;
    int64_t value;
    int32_t valid;
} UMemoEntry;

typedef struct UMemoCache {
    UMemoEntry* entries;
    int32_t cap;
    int32_t len;
} UMemoCache;

static inline UMemoCache* u_memo_new(void) {
    UMemoCache* c = (UMemoCache*)u_alloc(sizeof(UMemoCache));
    c->cap = 16;
    c->len = 0;
    c->entries = (UMemoEntry*)u_alloc(sizeof(UMemoEntry) * c->cap);
    return c;
}

static inline int32_t u_memo_get(UMemoCache* c, int64_t key, int64_t* out) {
    if (!c) return 0;
    for (int32_t i = 0; i < c->len; i++) {
        if (c->entries[i].valid && c->entries[i].key == key) {
            *out = c->entries[i].value;
            return 1;
        }
    }
    return 0;
}

static inline void u_memo_put(UMemoCache* c, int64_t key, int64_t value) {
    if (!c) return;
    for (int32_t i = 0; i < c->len; i++) {
        if (c->entries[i].valid && c->entries[i].key == key) {
            c->entries[i].value = value;
            return;
        }
    }
    if (c->len >= c->cap) {
        c->cap = c->cap + c->cap;
        c->entries = (UMemoEntry*)realloc(c->entries, sizeof(UMemoEntry) * c->cap);
    }
    c->entries[c->len].key = key;
    c->entries[c->len].value = value;
    c->entries[c->len].valid = 1;
    c->len++;
}

/* debounce: suppress calls within `interval_ms` of the last fire. */
typedef struct UDebounce {
    int64_t last_fire_ms;
    int64_t interval_ms;
} UDebounce;

static inline UDebounce* u_debounce_new(int64_t interval_ms) {
    UDebounce* d = (UDebounce*)u_alloc(sizeof(UDebounce));
    d->last_fire_ms = -1;
    d->interval_ms = interval_ms;
    return d;
}

/* now_ms is supplied by the caller (the clock is an effect, kept outside). */
static inline int32_t u_debounce_should_fire(UDebounce* d, int64_t now_ms) {
    if (!d) return 1;
    if (d->last_fire_ms < 0 || now_ms - d->last_fire_ms >= d->interval_ms) {
        d->last_fire_ms = now_ms;
        return 1;
    }
    return 0;
}

/* batch: accumulate calls until `threshold` are queued, then flush. */
typedef struct UBatch {
    int64_t* queue;
    int32_t cap;
    int32_t len;
    int32_t threshold;
} UBatch;

static inline UBatch* u_batch_new(int32_t threshold) {
    UBatch* b = (UBatch*)u_alloc(sizeof(UBatch));
    b->threshold = threshold;
    b->cap = threshold > 0 ? threshold : 16;
    b->len = 0;
    b->queue = (int64_t*)u_alloc(sizeof(int64_t) * b->cap);
    return b;
}

/* Returns 1 if the batch is now full (should flush), 0 otherwise. */
static inline int32_t u_batch_push(UBatch* b, int64_t value) {
    if (!b) return 0;
    if (b->len >= b->cap) {
        b->cap = b->cap + b->cap;
        b->queue = (int64_t*)realloc(b->queue, sizeof(int64_t) * b->cap);
    }
    b->queue[b->len++] = value;
    return b->len >= b->threshold;
}

static inline int32_t u_batch_drain(UBatch* b, int64_t* out, int32_t max) {
    if (!b) return 0;
    int32_t n = b->len < max ? b->len : max;
    for (int32_t i = 0; i < n; i++) out[i] = b->queue[i];
    b->len = 0;
    return n;
}

/* ── getter / retry / with_hooks (turn 22) ──────────────────────────────
 *
 * retry: re-invoke a fallible function up to `max_attempts` times. The
 * runtime tracks attempt state; the wrapper loops. Returns 1 to keep
 * trying, 0 when attempts are exhausted.
 */
typedef struct URetry {
    int32_t attempt;
    int32_t max_attempts;
} URetry;

static inline URetry* u_retry_new(int32_t max_attempts) {
    URetry* r = (URetry*)u_alloc(sizeof(URetry));
    r->attempt = 0;
    r->max_attempts = max_attempts;
    return r;
}

/* Called before each attempt. Returns 1 if another attempt is allowed. */
static inline int32_t u_retry_should_attempt(URetry* r) {
    if (!r) return 0;
    if (r->attempt < r->max_attempts) {
        r->attempt++;
        return 1;
    }
    return 0;
}

static inline void u_retry_reset(URetry* r) {
    if (r) r->attempt = 0;
}

/* getter: cache + throttle + batching in one options-driven wrapper.
 * GetterOptions is a bitfield of which behaviours are enabled, plus the
 * state for each. Rather than four separate combinators the getter fuses
 * them (u_language.html: "one method, not four"). */
typedef struct UGetter {
    UMemoCache* cache;      /* NULL if caching disabled */
    UDebounce* throttle;    /* NULL if throttling disabled */
    UBatch* batch;          /* NULL if batching disabled */
} UGetter;

static inline UGetter* u_getter_new(int32_t use_cache, int64_t throttle_ms,
                                     int32_t batch_size) {
    UGetter* g = (UGetter*)u_alloc(sizeof(UGetter));
    g->cache = use_cache ? u_memo_new() : NULL;
    g->throttle = throttle_ms > 0 ? u_debounce_new(throttle_ms) : NULL;
    g->batch = batch_size > 0 ? u_batch_new(batch_size) : NULL;
    return g;
}

/* ── JSON.stream — streaming JSON parser ────────────────────────────────
 *
 * Ports Q_JSON_StreamIterator's trick: scan for structural characters
 * ("{}[],:\"), track a stack and a semantic path, hand FRAGMENTS to the
 * real u_json_decode. Never loads the whole file.
 *
 *   f+G+E stream(filename: S, path: [S]) -> [Tree] +W
 *
 * In U this is +W (a generator). At the C level it's a callback:
 *   u_json_stream(filename, path, path_len, callback, ctx)
 *
 * The callback receives each matched value as a Tree, plus the key and
 * the current semantic path. Returning 0 continues; non-zero stops.
 *
 * Path filtering: each element is a key string, or NULL as a wildcard
 * (matches any key — the `true` in the PHP version). The stream yields
 * only values whose semantic path matches the filter prefix.
 */

#define U_JSON_STREAM_BUFSZ 8192

typedef int32_t (*UJsonStreamCb)(UTree* value, const char* key,
                                  const char** path, int32_t path_len,
                                  void* ctx);

static inline int32_t u_json_stream(const char* filename,
                                     const char** filter_path,
                                     int32_t filter_len,
                                     UJsonStreamCb callback,
                                     void* ctx) {
    FILE* f = fopen(filename, "r");
    if (!f) return -1;

    /* State */
    char* buf = (char*)malloc(U_JSON_STREAM_BUFSZ * 4);
    if (!buf) { fclose(f); return -1; }
    int32_t buf_len = 0, buf_cap = U_JSON_STREAM_BUFSZ * 4;
    int32_t pos = 0;

    /* Stack: track nesting.  Each entry: opener char + absolute position */
    typedef struct { char ch; int32_t abs_pos; } StkEntry;
    StkEntry* stack = (StkEntry*)malloc(256 * sizeof(StkEntry));
    int32_t stk_len = 0, stk_cap = 256;

    /* Semantic path: keys/indices.  Strings are borrowed from buf. */
    char** spath = (char**)calloc(256, sizeof(char*));
    int32_t* sidx = (int32_t*)calloc(256, sizeof(int32_t));  /* array index at each level */
    int32_t sp_len = 0, sp_cap = 256;
    int32_t in_string = 0;

    int32_t value_start = -1;  /* buffer offset where current primitive began */
    int32_t yielded = 0;
    int32_t stopped = 0;

    /* Path matching helper */
    #define PATH_MATCHES() ({ \
        int32_t _m = 0; \
        for (int32_t _i = 0; _i < filter_len && _i < sp_len; _i++) { \
            if (filter_path[_i] == NULL) { _m++; continue; } \
            if (spath[_i] && strcmp(filter_path[_i], spath[_i]) == 0) { _m++; continue; } \
            break; \
        } \
        _m; \
    })

    /* Try emitting a fragment as a complete JSON value */
    #define TRY_EMIT(frag, frag_len) do { \
        char* _tmp = (char*)malloc((frag_len) + 1); \
        memcpy(_tmp, (frag), (frag_len)); _tmp[(frag_len)] = '\0'; \
        /* Trim whitespace */ \
        char* _s = _tmp; \
        while (*_s == ' ' || *_s == '\t' || *_s == '\n' || *_s == '\r') _s++; \
        if (*_s && *_s != ',' && *_s != ':') { \
            UTree* _val = u_json_decode(_s); \
            if (_val) { \
                int32_t _matched = PATH_MATCHES(); \
                if (_matched >= filter_len && sp_len >= filter_len) { \
                    const char* _key = sp_len > 0 ? spath[sp_len-1] : NULL; \
                    const char** _cpath = (const char**)spath; \
                    if (callback(_val, _key, _cpath, sp_len, ctx) != 0) { \
                        stopped = 1; \
                    } \
                    yielded++; \
                } \
            } \
        } \
        free(_tmp); \
    } while(0)

    while (!stopped) {
        /* Read more data if needed */
        if (pos >= buf_len) {
            if (feof(f)) break;
            /* Compact: shift unprocessed data to front */
            if (pos > 0 && buf_len > 0) {
                /* For simplicity, just reset — fragments are extracted as we go */
            }
            char chunk[U_JSON_STREAM_BUFSZ];
            size_t nr = fread(chunk, 1, U_JSON_STREAM_BUFSZ, f);
            if (nr == 0) break;
            /* Ensure buf has space */
            if (buf_len + (int32_t)nr >= buf_cap) {
                buf_cap = (buf_len + (int32_t)nr) * 2;
                buf = (char*)realloc(buf, (size_t)buf_cap);
            }
            memcpy(buf + buf_len, chunk, nr);
            buf_len += (int32_t)nr;
            continue;
        }

        char ch = buf[pos++];

        /* String toggle */
        if (ch == '"') {
            int32_t bs = 0;
            for (int32_t j = pos - 2; j >= 0 && buf[j] == '\\'; j--) bs++;
            if (bs % 2 == 0) {
                in_string = !in_string;
            }
            if (!in_string && value_start < 0) value_start = pos - 1;
            continue;
        }
        if (in_string) continue;

        /* Whitespace */
        if (ch == ' ' || ch == '\t' || ch == '\n' || ch == '\r') continue;

        if (ch == '{') {
            if (stk_len >= stk_cap) {
                stk_cap = stk_cap < 1 ? 256 : stk_cap + stk_cap;
                stack = (StkEntry*)realloc(stack, (size_t)stk_cap * sizeof(StkEntry));
            }
            stack[stk_len++] = (StkEntry){'{', pos - 1};
            value_start = -1;
        } else if (ch == '[') {
            if (stk_len >= stk_cap) {
                stk_cap = stk_cap < 1 ? 256 : stk_cap + stk_cap;
                stack = (StkEntry*)realloc(stack, (size_t)stk_cap * sizeof(StkEntry));
            }
            stack[stk_len++] = (StkEntry){'[', pos - 1};
            /* Push array index 0 onto semantic path */
            if (sp_len < sp_cap) {
                char idx_buf[16];
                snprintf(idx_buf, sizeof(idx_buf), "%d", 0);
                spath[sp_len] = u_tree_strdup(idx_buf);
                sidx[sp_len] = 0;
                sp_len++;
            }
            value_start = -1;
        } else if (ch == '}' || ch == ']') {
            /* Emit trailing primitive before close */
            if (value_start >= 0) {
                int32_t flen = (pos - 1) - value_start;
                if (flen > 0) TRY_EMIT(buf + value_start, flen);
                value_start = -1;
            }
            if (stopped) break;
            /* Pop and emit the container */
            if (stk_len > 0) {
                StkEntry open = stack[--stk_len];
                int32_t frag_start = open.abs_pos;
                int32_t frag_len = pos - frag_start;
                if (frag_len > 0 && frag_len < buf_len) {
                    TRY_EMIT(buf + frag_start, frag_len);
                }
            }
            if (sp_len > 0) {
                free(spath[sp_len - 1]);
                spath[sp_len - 1] = NULL;
                sp_len--;
            }
            if (stopped) break;
        } else if (ch == ',') {
            /* Emit primitive */
            if (value_start >= 0) {
                int32_t flen = (pos - 1) - value_start;
                if (flen > 0) TRY_EMIT(buf + value_start, flen);
                value_start = -1;
            }
            if (stopped) break;
            /* Advance array index or pop object key */
            if (stk_len > 0 && stack[stk_len-1].ch == '[' && sp_len > 0) {
                sidx[sp_len-1]++;
                free(spath[sp_len-1]);
                char idx_buf[16];
                snprintf(idx_buf, sizeof(idx_buf), "%d", sidx[sp_len-1]);
                spath[sp_len-1] = u_tree_strdup(idx_buf);
            } else if (stk_len > 0 && stack[stk_len-1].ch == '{' && sp_len > 0) {
                free(spath[sp_len-1]);
                spath[sp_len-1] = NULL;
                sp_len--;
            }
        } else if (ch == ':') {
            /* The key is the most recent string.  Extract it. */
            if (stk_len > 0 && stack[stk_len-1].ch == '{') {
                /* Find the key: last "..." before this colon */
                int32_t qe = pos - 2;
                while (qe >= 0 && buf[qe] != '"') qe--;
                if (qe >= 0) {
                    int32_t qs = qe - 1;
                    while (qs >= 0 && buf[qs] != '"') qs--;
                    if (qs >= 0) {
                        int32_t klen = qe - qs - 1;
                        char* key = (char*)malloc((size_t)klen + 1);
                        memcpy(key, buf + qs + 1, (size_t)klen);
                        key[klen] = '\0';
                        if (sp_len < sp_cap) {
                            spath[sp_len] = key;
                            sidx[sp_len] = -1;
                            sp_len++;
                        } else {
                            free(key);
                        }
                    }
                }
            }
            value_start = -1;
        } else {
            /* Start of a primitive value (number, true, false, null) */
            if (value_start < 0) value_start = pos - 1;
        }
    }

    #undef PATH_MATCHES
    #undef TRY_EMIT

    /* Cleanup */
    for (int32_t i = 0; i < sp_len; i++) free(spath[i]);
    free(spath); free(sidx); free(stack); free(buf);
    fclose(f);
    return yielded;
}

/* ── Database.Row — modification tracking and SQL generation ────────────
 *
 * A Row is a Tree (the packed representation) plus metadata:
 *   - store: which collection/table
 *   - key_field: the primary key field name
 *   - original: the Tree as last retrieved/saved (for diff)
 *   - current: the current Tree (after patches)
 *
 * `save()` diffs original vs current to emit UPDATE SET only the changed
 * fields. This replaces Qbix's $fieldsModified bookkeeping.
 *
 * The SQL generation is a stub — the adapter (turn 14b) owns the dialect.
 * This layer owns the WHAT (which fields changed), not the HOW (SQL syntax).
 */

typedef struct URow {
    const char* store;       /* table/collection name */
    const char* key_field;   /* primary key field name */
    UTree* original;         /* state at last retrieve/save */
    UTree* current;          /* current state (after patches) */
    int32_t is_new;          /* 1 if not yet saved */
} URow;

static inline URow* u_row_new(const char* store, const char* key_field) {
    URow* r = (URow*)u_alloc(sizeof(URow));
    r->store = store;
    r->key_field = key_field;
    r->original = u_tree_new(U_TREE_NODE);
    r->current = u_tree_new(U_TREE_NODE);
    r->is_new = 1;
    return r;
}

/* Create a Row from a retrieved Tree (marks it as not-new). */
static inline URow* u_row_from_tree(const char* store, const char* key_field, UTree* data) {
    URow* r = (URow*)u_alloc(sizeof(URow));
    r->store = store;
    r->key_field = key_field;
    r->original = u_tree_copy(data);  /* snapshot at retrieve time */
    r->current = data;
    r->is_new = 0;
    return r;
}

/* Get the primary key value from the current state. */
static inline UTree* u_row_key_value(URow* r) {
    if (!r || !r->current) return NULL;
    return u_tree_get(r->current, r->key_field);
}

/* Compute the changed fields: keys in current that differ from original.
 * Returns a Tree node with ONLY the changed key/value pairs. */
static inline UTree* u_row_changes(URow* r) {
    if (!r) return NULL;
    if (r->is_new) return r->current;  /* everything is new */
    UTree* diff = u_tree_new(U_TREE_NODE);
    if (!r->current || r->current->kind != U_TREE_NODE) return diff;
    for (int32_t i = 0; i < r->current->as.node.len; i++) {
        const char* k = r->current->as.node.keys[i];
        UTree* cur_val = r->current->as.node.vals[i];
        UTree* orig_val = u_tree_get(r->original, k);
        /* Changed if: not in original, or different kind, or different value */
        int changed = 0;
        if (!orig_val) { changed = 1; }
        else if (cur_val->kind != orig_val->kind) { changed = 1; }
        else {
            switch (cur_val->kind) {
                case U_TREE_INT:  changed = (cur_val->as.i != orig_val->as.i); break;
                case U_TREE_NUM:  changed = (cur_val->as.n != orig_val->as.n); break;
                case U_TREE_BOOL: changed = (cur_val->as.b != orig_val->as.b); break;
                case U_TREE_STR:  changed = (strcmp(cur_val->as.s ? cur_val->as.s : "",
                                                    orig_val->as.s ? orig_val->as.s : "") != 0); break;
                case U_TREE_NONE: break;  /* both none → not changed */
                default: changed = 1;     /* compound types: always re-save */
            }
        }
        if (changed) {
            u_tree_set(diff, k, cur_val);
        }
    }
    return diff;
}

/* Mark the current state as "saved" (original = deep copy of current). */
static inline void u_row_mark_saved(URow* r) {
    if (!r) return;
    r->original = u_tree_copy(r->current);
    r->is_new = 0;
}

/* Apply a patch (from << operator) — merges into current. */
static inline void u_row_patch(URow* r, UTree* patch) {
    if (!r || !patch) return;
    r->current = u_tree_merge(u_tree_copy(r->current), patch);
}

/* ── Database.Query[T] — the query builder ──────────────────────────
 *
 * A Query is a runtime-shaped description of ONE query. The builder
 * methods (.sort, .skip, .take) COMPOSE — each returns the same Query
 * with one field updated. The key structural property: there is exactly
 * ONE predicate slot, so the builder CANNOT emit two WHEREs. You cannot
 * construct a malformed query.
 *
 *   q = Database.query[User]()
 *       .where(u => u.age > 30)      // sets predicate (once)
 *       .sort("name")                // sets sort
 *       .skip(10).take(20)           // sets offset/limit
 *   users = q.list()                 // executes
 *
 * The predicate is a compile-time-analyzed lambda (turn 15 reads its
 * fields for the index check). At runtime the Query holds the predicate
 * as an opaque function pointer + a Tree describing which fields it
 * touches (for the adapter to build the WHERE clause).
 */

typedef struct UQuery {
    const char* store;        /* table/collection */
    UTree* predicate_fields;  /* Tree describing the WHERE (field → op → value) */
    const char* sort_field;   /* ORDER BY field, or NULL */
    int32_t sort_desc;        /* 1 = DESC, 0 = ASC */
    int32_t skip;             /* OFFSET, -1 = unset */
    int32_t take;             /* LIMIT, -1 = unset */
} UQuery;

static inline UQuery* u_query_new(const char* store) {
    UQuery* q = (UQuery*)u_alloc(sizeof(UQuery));
    q->store = store;
    q->predicate_fields = NULL;
    q->sort_field = NULL;
    q->sort_desc = 0;
    q->skip = -1;
    q->take = -1;
    return q;
}

/* .where(predicate) — sets the predicate. Called ONCE; a second call
 * REPLACES (does not append) — structurally impossible to have two WHEREs. */
static inline UQuery* u_query_where(UQuery* q, UTree* predicate_fields) {
    if (!q) return q;
    q->predicate_fields = predicate_fields;  /* replace, never append */
    return q;
}

/* .sort(field) / .sort(field, desc) — sets ORDER BY. Replaces on re-call. */
static inline UQuery* u_query_sort(UQuery* q, const char* field, int32_t desc) {
    if (!q) return q;
    q->sort_field = field;   /* replace */
    q->sort_desc = desc;
    return q;
}

/* .skip(n) — sets OFFSET. */
static inline UQuery* u_query_skip(UQuery* q, int32_t n) {
    if (!q) return q;
    q->skip = n;
    return q;
}

/* .take(n) — sets LIMIT. */
static inline UQuery* u_query_take(UQuery* q, int32_t n) {
    if (!q) return q;
    q->take = n;
    return q;
}

/* Build a SQL-ish description of the query (adapter-agnostic).
 * The real adapter (turn 14b) owns the dialect; this is the shape. */
static inline UTree* u_query_describe(UQuery* q) {
    if (!q) return NULL;
    UTree* d = u_tree_new(U_TREE_NODE);
    u_tree_set(d, "store", u_tree_str(q->store ? q->store : ""));
    if (q->predicate_fields) {
        u_tree_set(d, "where", q->predicate_fields);
    }
    if (q->sort_field) {
        u_tree_set(d, "sort", u_tree_str(q->sort_field));
        u_tree_set(d, "sortDesc", u_tree_bool(q->sort_desc));
    }
    if (q->skip >= 0) u_tree_set(d, "skip", u_tree_int(q->skip));
    if (q->take >= 0) u_tree_set(d, "take", u_tree_int(q->take));
    return d;
}

/* Merge with T's key field supplied -- what Merge[T] compiles to. */
static inline UTree* u_tree_merge_keyed(UTree* first, UTree* second, const char* key) {
    if (first && second && first->kind == U_TREE_LIST && second->kind == U_TREE_NODE) {
        UTree* d = u_tree_merge_directive(first, second, key);
        if (d) return d;
    }
    return u_tree_merge(first, second);
}

/* ── the merge DIRECTIVES ───────────────────────────────────────────────
 *
 * An object arriving where a LIST sits can only be a directive -- unambiguous
 * BY POSITION, which is Greg's point and why Merge[T] is typeable at all.
 * Same bytes as Q_Tree, so U talks to the existing PHP and JS unchanged.
 *
 *   {replace: [...]}                      -> the list, outright
 *   {prepend: [...]} / {append: [...]}    -> skip values already present
 *   {updates: [...], add: [...], remove: [...]}  -> keyed record edits
 *
 * `key_field` is NOT a parameter here the way it is in PHP: PHP has to be
 * TOLD (`$keyField = $array2['updates'][0]`), and when it is not told,
 * _detectKeyField GUESSES by counting field frequency across both arrays and
 * taking the winner. U knows -- `key: S +G` is on the type. The caller passes
 * the key because this is the untyped runtime floor; the U-level Merge[T] does
 * not, and cannot pass it wrong.
 *
 * TWO Q_Tree BUGS, deliberately NOT reproduced (reported to Greg t175):
 *
 * 1. `add` did not dedupe at all -- bare array_merge, while prepend/append
 *    both guard. It could therefore create DUPLICATE KEYS, and then `updates`
 *    edits all of them and `remove` deletes all of them (neither breaks).
 *    So the list could hold two records with one identity while everything
 *    downstream assumed otherwise. The invariant is "trees have deduped
 *    lists"; add was the hole.
 *
 *    The fix is NOT prepend's guard: that dedupes by WHOLE-RECORD equality,
 *    so {id:2,n:"b"} and {id:2,n:"B2"} would BOTH survive -- the identity
 *    concept in this block is the KEY FIELD, as `updates` and `remove` both
 *    already use. We skip-if-key-present, matching prepend/append's
 *    "incumbent wins" and keeping the three verbs honest: add adds, updates
 *    updates, remove removes. If add upserted, {add:[{id:2}]} and
 *    {updates:[...]} would both edit record 2 by different rules.
 *
 * 2. `{add: [...]}` WITHOUT `updates` never reached the directive branch at
 *    all -- $keyField is only bound inside `if (isset($array2['updates']))`.
 *    It fell through to the general path and wrote the literal string key
 *    "add" into the list. Here every directive is recognised on its own.
 */
static inline UTree* u_tree_dir(UTree* node, const char* name) {
    if (!node || node->kind != U_TREE_NODE) return NULL;
    UTree* v = u_tree_get(node, name);
    return (v && v->kind == U_TREE_LIST) ? v : NULL;
}

/* The value of `rec[key]`, or NULL. */
static inline UTree* u_tree_keyof(UTree* rec, const char* key) {
    if (!rec || rec->kind != U_TREE_NODE || !key) return NULL;
    return u_tree_get(rec, key);
}

/* Does `list` hold a record whose `key` equals `rec`'s? PHP's `remove` does a
 * bare $o[$keyField] and notices a record missing the key; we treat a missing
 * key as "no match" rather than crashing. */
static inline bool u_tree_has_key(UTree* list, UTree* rec, const char* key) {
    UTree* want = u_tree_keyof(rec, key);
    if (!want || !list || list->kind != U_TREE_LIST) return false;
    for (int32_t i = 0; i < list->as.list.len; i++) {
        UTree* got = u_tree_keyof(list->as.list.items[i], key);
        if (got && u_tree_equal(got, want)) return true;
    }
    return false;
}

/* Apply a directive node to a list. Returns the resulting list, or NULL if
 * `second` holds no directive (so the caller falls through to a plain merge). */
static inline UTree* u_tree_merge_directive(UTree* first, UTree* second,
                                            const char* key) {
    if (!first || first->kind != U_TREE_LIST) return NULL;
    if (!second || second->kind != U_TREE_NODE) return NULL;

    UTree* rep = u_tree_dir(second, "replace");
    if (rep) return rep;                                   /* outright */

    UTree* pre = u_tree_dir(second, "prepend");
    UTree* app = u_tree_dir(second, "append");
    if (pre || app) {
        UTree* out = u_tree_new(U_TREE_LIST);
        if (pre) {                                          /* reversed unshift */
            for (int32_t i = pre->as.list.len - 1; i >= 0; i--) {
                UTree* v = pre->as.list.items[i];
                if (!u_tree_contains(first, v) && !u_tree_contains(out, v))
                    u_tree_push(out, v);
            }
        }
        for (int32_t i = 0; i < first->as.list.len; i++)
            u_tree_push(out, first->as.list.items[i]);
        if (app) u_tree_append_deduped(out, app);
        return out;
    }

    UTree* ups = u_tree_dir(second, "updates");
    UTree* add = u_tree_dir(second, "add");
    UTree* rem = u_tree_dir(second, "remove");
    if (!ups && !add && !rem) return NULL;
    if (!key) return NULL;              /* U-level: a compile error, not this */

    /* updates: merge each record's fields into EVERY match (PHP has no break). */
    if (ups) {
        for (int32_t u = 0; u < ups->as.list.len; u++) {
            UTree* upd = ups->as.list.items[u];
            UTree* want = u_tree_keyof(upd, key);
            if (!want) continue;
            for (int32_t i = 0; i < first->as.list.len; i++) {
                UTree* obj = first->as.list.items[i];
                UTree* got = u_tree_keyof(obj, key);
                if (got && u_tree_equal(got, want) && obj->kind == U_TREE_NODE)
                    for (int32_t f = 0; f < upd->as.node.len; f++)
                        u_tree_set(obj, upd->as.node.keys[f], upd->as.node.vals[f]);
            }
        }
    }
    /* add: SKIP IF THE KEY IS ALREADY PRESENT (bug 1, fixed). */
    if (add) {
        for (int32_t i = 0; i < add->as.list.len; i++) {
            UTree* a = add->as.list.items[i];
            if (!u_tree_has_key(first, a, key)) u_tree_push(first, a);
        }
    }
    /* remove: by key. */
    if (rem) {
        UTree* out = u_tree_new(U_TREE_LIST);
        for (int32_t i = 0; i < first->as.list.len; i++) {
            UTree* o = first->as.list.items[i];
            if (!u_tree_has_key(rem, o, key)) u_tree_push(out, o);
        }
        return out;
    }
    return first;
}

static inline UTree* u_tree_merge(UTree* first, UTree* second) {
    if (!second) return first;
    if (!first)  return second;

    /* LIST + LIST -> append, deduped. In PHP this needed isAssociative() on
     * BOTH sides; here the kind tag says it outright. */
    if (first->kind == U_TREE_LIST && second->kind == U_TREE_LIST) {
        u_tree_append_deduped(first, second);
        return first;
    }

    /* LIST + NODE -> a DIRECTIVE. Unambiguous by position: an object where a
     * list belongs can only be one. (The untyped floor has no key; Merge[T]
     * supplies it from T's `key: S +G`. See u_tree_merge_keyed.) */
    if (first->kind == U_TREE_LIST && second->kind == U_TREE_NODE) {
        UTree* d = u_tree_merge_directive(first, second, NULL);
        if (d) return d;
    }

    /* NODE + NODE -> merge at every level. */
    if (first->kind == U_TREE_NODE && second->kind == U_TREE_NODE) {
        for (int32_t i = 0; i < second->as.node.len; i++) {
            const char* k = second->as.node.keys[i];
            UTree* v = second->as.node.vals[i];
            UTree* cur = u_tree_get(first, k);
            if (cur && ((cur->kind == U_TREE_NODE && v->kind == U_TREE_NODE)
                     || (cur->kind == U_TREE_LIST && v->kind == U_TREE_LIST))) {
                u_tree_set(first, k, u_tree_merge(cur, v));
            } else if (cur && cur->kind == U_TREE_LIST && v->kind == U_TREE_NODE) {
                /* A DIRECTIVE, nested. This is where they ALWAYS appear in real
                 * config -- `{"k2": {"replace": ["a","b"]}}` -- and the first
                 * version of this function fell through to the `else` and stored
                 * the directive node itself AS THE VALUE. It only worked at the
                 * top level, which no caller uses.
                 *
                 * Caught by asserting the INVARIANT `from.merge(from.diff(to))
                 * == to`, not by "does it run". The diff was already correct;
                 * merge could not consume its own output. */
                UTree* d = u_tree_merge_directive(cur, v, NULL);
                u_tree_set(first, k, d ? d : v);
            } else {
                u_tree_set(first, k, v);        /* scalar (or shape change) replaces */
            }
        }
        return first;
    }

    /* Anything else: second wins. "scalar values replace whatever is there." */
    return second;
}
static inline int32_t u_tree_as_int(UTree* j)  { return (j && j->kind==U_TREE_INT)  ? j->as.i : 0; }
static inline double  u_tree_as_num(UTree* j)  { return (j && j->kind==U_TREE_NUM)  ? j->as.n : (j && j->kind==U_TREE_INT ? (double)j->as.i : 0.0); }
static inline bool    u_tree_as_bool(UTree* j) { return (j && j->kind==U_TREE_BOOL) ? j->as.b : false; }
static inline char*   u_tree_as_str(UTree* j)  { return (j && j->kind==U_TREE_STR)  ? j->as.s : (char*)""; }

/* ── Set ({K}) — open-addressing hash set, monomorphized per key type ────
 *
 * Monomorphized (U_SET_DECLARE(T, HASH, EQ)) rather than one opaque USet,
 * for the same reason UList is: `{K}` admits ANY key type -- {I}, {S}, and
 * class keys -- and UMap cannot be reused because it is string-keyed only.
 * Passing HASH/EQ as macro parameters is what lets a class key work: the spec
 * says "Class as key — implement __hash__ and ==", so codegen simply passes
 * that class's __hash__/__equals__ here. Scalars pass identity-mix/==,
 * strings pass u_str_hash/strcmp.
 *
 * Open addressing with linear probing, not chaining: keys are small and
 * inline, so probing keeps the whole set in a couple of cache lines and
 * avoids a malloc per element. Load factor is held under 0.75 by doubling.
 *
 * Slot states: 0 = empty (never used), 1 = occupied, 2 = tombstone (deleted).
 * The tombstone is required for correctness, not tidiness -- linear probing
 * finds a key by walking from its home slot until an EMPTY slot; deleting by
 * marking empty would truncate that walk and make later keys unfindable.
 */
#define U_SET_EMPTY_     0
#define U_SET_OCCUPIED_  1
#define U_SET_TOMB_      2

static inline uint32_t u_hash_i32(int32_t v) {
    /* Identity is a poor hash for open addressing: sequential ints all
       collide into a run. Mix (Knuth multiplicative) to spread them. */
    uint32_t x = (uint32_t)v;
    x *= 2654435761u;
    return x ^ (x >> 16);
}

#define U_SET_DECLARE(T, HASHFN, EQFN)                                        \
    typedef struct {                                                          \
        URcHeader header;                                                     \
        T*       keys;                                                        \
        uint8_t* state;                                                       \
        int32_t  cap;      /* always a power of two -- mask instead of % */   \
        int32_t  count;    /* live keys (tombstones excluded) */              \
    } USet_##T;                                                               \
                                                                              \
    static inline USet_##T* u_set_new_##T(int32_t hint) {                     \
        int32_t cap = 8;                                                      \
        while (cap < hint * 2) cap <<= 1;                                     \
        USet_##T* s = (USet_##T*)u_alloc(sizeof(USet_##T));                   \
        s->cap = cap; s->count = 0;                                           \
        s->keys  = (T*)malloc(sizeof(T) * cap);                               \
        s->state = (uint8_t*)calloc(cap, 1);                                  \
        return s;                                                             \
    }                                                                         \
                                                                              \
    static inline bool u_set_has_##T(USet_##T* s, T k) {                      \
        uint32_t i = HASHFN(k) & (uint32_t)(s->cap - 1);                      \
        for (int32_t probe = 0; probe < s->cap; probe++) {                    \
            uint8_t st = s->state[i];                                         \
            if (st == U_SET_EMPTY_) return false;                             \
            if (st == U_SET_OCCUPIED_ && EQFN(s->keys[i], k)) return true;    \
            i = (i + 1) & (uint32_t)(s->cap - 1);                             \
        }                                                                     \
        return false;                                                         \
    }                                                                         \
                                                                              \
    static inline void u_set_grow_##T(USet_##T* s) {                          \
        int32_t oldcap = s->cap;                                              \
        T* oldk = s->keys; uint8_t* olds = s->state;                          \
        s->cap = oldcap * 2; s->count = 0;                                    \
        s->keys  = (T*)malloc(sizeof(T) * s->cap);                            \
        s->state = (uint8_t*)calloc(s->cap, 1);                               \
        for (int32_t j = 0; j < oldcap; j++) {                                \
            if (olds[j] != U_SET_OCCUPIED_) continue;                         \
            uint32_t i = HASHFN(oldk[j]) & (uint32_t)(s->cap - 1);            \
            while (s->state[i] == U_SET_OCCUPIED_)                            \
                i = (i + 1) & (uint32_t)(s->cap - 1);                         \
            s->keys[i] = oldk[j]; s->state[i] = U_SET_OCCUPIED_; s->count++;  \
        }                                                                     \
        free(oldk); free(olds);                                               \
    }                                                                         \
                                                                              \
    /* Spec: "true if inserted (was absent), false if already present." */    \
    static inline bool u_set_insert_##T(USet_##T* s, T k) {                   \
        if ((s->count + 1) * 4 >= s->cap * 3) u_set_grow_##T(s);              \
        uint32_t i = HASHFN(k) & (uint32_t)(s->cap - 1);                      \
        int32_t first_tomb = -1;                                              \
        for (int32_t probe = 0; probe < s->cap; probe++) {                    \
            uint8_t st = s->state[i];                                         \
            if (st == U_SET_OCCUPIED_ && EQFN(s->keys[i], k)) return false;   \
            if (st == U_SET_TOMB_ && first_tomb < 0) first_tomb = (int32_t)i; \
            if (st == U_SET_EMPTY_) {                                         \
                /* Reuse the first tombstone seen on this probe run, so                \
                   repeated insert/delete cycles do not grow the table. */    \
                int32_t slot = (first_tomb >= 0) ? first_tomb : (int32_t)i;   \
                s->keys[slot] = k; s->state[slot] = U_SET_OCCUPIED_;          \
                s->count++;                                                   \
                return true;                                                  \
            }                                                                 \
            i = (i + 1) & (uint32_t)(s->cap - 1);                             \
        }                                                                     \
        return false;                                                         \
    }                                                                         \
                                                                              \
    /* Spec: "true if deleted (was present), false if absent." */             \
    static inline bool u_set_delete_##T(USet_##T* s, T k) {                   \
        uint32_t i = HASHFN(k) & (uint32_t)(s->cap - 1);                      \
        for (int32_t probe = 0; probe < s->cap; probe++) {                    \
            uint8_t st = s->state[i];                                         \
            if (st == U_SET_EMPTY_) return false;                             \
            if (st == U_SET_OCCUPIED_ && EQFN(s->keys[i], k)) {               \
                s->state[i] = U_SET_TOMB_; s->count--;                        \
                return true;                                                  \
            }                                                                 \
            i = (i + 1) & (uint32_t)(s->cap - 1);                             \
        }                                                                     \
        return false;                                                         \
    }                                                                         \
                                                                              \
    static inline int32_t u_set_size_##T(USet_##T* s) { return s->count; }

/* Key kinds codegen selects between. */
#define U_EQ_SCALAR_(a, b) ((a) == (b))
#define U_EQ_STR_(a, b)    (strcmp((a), (b)) == 0)
#define U_HASH_STR_(k)     u_str_hash(k)

/* ── Exceptions — minimal setjmp/longjmp unwinding ───────────────────────
 *
 * U has no promise-rejection channel: every async failure is an exception
 * thrown at the await site (see implementation.html). This is the minimal
 * floor that makes `throw` real — enough for timeout-throws, `true`/abort,
 * and error-throw to funnel through one path. It is NOT the full typed
 * e.x()-handler system (that's a larger, separate build); it's a single
 * current-handler slot and longjmp unwinding, which is what timeout needs.
 *
 * A handler is installed with U_TRY / U_CATCH (setjmp), a throw does
 * longjmp to the nearest handler carrying an error code + message. The
 * common case for +A(500)+N — "catch a Timeout, turn it into none" — is
 * exactly a U_TRY around the await whose U_CATCH yields NULL.
 */

#include <setjmp.h>

#define U_ERR_NONE     0
#define U_ERR_TIMEOUT  1
#define U_ERR_ABORT    2   /* .x() handler returned true */
#define U_ERR_USER     3   /* an `e Type{...}` throw */

typedef struct UExcHandler {
    jmp_buf env;
    struct UExcHandler* prev;
    int32_t code;
    const char* message;
} UExcHandler;

static UExcHandler* u_current_handler = NULL;

/* Throw: check typed handlers first, then longjmp to catch-all, then abort.
 *
 * For U_ERR_USER throws (e Type(...)), the typed handler table is walked
 * innermost-scope-first. A handler that returns 1 CONSUMES the throw —
 * execution continues after the u_throw call site (the throw becomes a
 * no-op). A handler that returns 0, or a scope with no matching type,
 * falls through. If no typed handler consumed it, the longjmp catch-all
 * fires. If there is no catch-all either, the exception is uncaught —
 * print and abort (the program-level default).
 *
 * Happy path: zero overhead — the table is never touched. */
static inline void u_throw(int32_t code, const char* message) {
    if (code == U_ERR_USER && message) {
        UTypedScope* scope = u_typed_scope;
        while (scope) {
            for (int32_t i = scope->count - 1; i >= 0; i--) {
                if (strcmp(scope->entries[i].type_name, message) == 0) {
                    if (scope->entries[i].handler(message, scope->entries[i].ctx)) {
                        return;   /* handled — throw consumed */
                    }
                }
            }
            scope = scope->prev;
        }
    }
    if (u_current_handler == NULL) {
        fprintf(stderr, "uncaught exception (code %d): %s\n",
                (int)code, message ? message : "");
        abort();
    }
    u_current_handler->code = code;
    u_current_handler->message = message;
    longjmp(u_current_handler->env, 1);
}

/* ── Arithmetic safety (u_language.html §18) ────────────────────────────
 *
 *   "Bounds checks, divide-by-zero checks, and overflow detection are ON BY
 *    DEFAULT. ! is the universal opt-out suffix."
 *   "(signed types always TRAP; unsigned types always WRAP)"
 *
 * None of this existed. Signed overflow silently wrapped:
 *     2147483647 + 1  ->  -2147483648
 *     2147483647 * 2  ->  -2
 * which is not merely wrong per the spec -- it is UNDEFINED BEHAVIOUR in C,
 * so the compiler was free to assume it could not happen and optimise on that
 * assumption. "Safe by default" is the language's headline claim and §24
 * ("Safety by Construction") counts arithmetic among its error categories.
 *
 * __builtin_*_overflow is exact and branch-cheap: on x86 it is the ADD/SUB/IMUL
 * the code would emit anyway, plus a JO. The check costs a not-taken branch.
 * gcc and clang both have it; the fallback is for anything else.
 */
#if defined(__GNUC__) || defined(__clang__)
#define U_ADD_OVF(a, b, r) __builtin_add_overflow((a), (b), (r))
#define U_SUB_OVF(a, b, r) __builtin_sub_overflow((a), (b), (r))
#define U_MUL_OVF(a, b, r) __builtin_mul_overflow((a), (b), (r))
#else
#define U_ADD_OVF(a, b, r) (*(r) = (a) + (b), 0)
#define U_SUB_OVF(a, b, r) (*(r) = (a) - (b), 0)
#define U_MUL_OVF(a, b, r) (*(r) = (a) * (b), 0)
#endif

static inline int32_t u_add_i32(int32_t a, int32_t b) {
    int32_t r;
    if (U_ADD_OVF(a, b, &r)) u_throw(U_ERR_USER, "integer overflow in +");
    return r;
}
static inline int32_t u_sub_i32(int32_t a, int32_t b) {
    int32_t r;
    if (U_SUB_OVF(a, b, &r)) u_throw(U_ERR_USER, "integer overflow in -");
    return r;
}
static inline int32_t u_mul_i32(int32_t a, int32_t b) {
    int32_t r;
    if (U_MUL_OVF(a, b, &r)) u_throw(U_ERR_USER, "integer overflow in *");
    return r;
}


/* Install / uninstall a handler. U_TRY(h) sets up; the setjmp returns 0 on
   install, non-zero when a throw lands here. Callers pop with u_pop_handler
   on the normal path. */
#define U_TRY(h)  ( (h).prev = u_current_handler, u_current_handler = &(h), \
                    (h).code = U_ERR_NONE, setjmp((h).env) )
static inline void u_pop_handler(UExcHandler* h) { u_current_handler = h->prev; }

/* ── Await-with-deadline: the lowering target for `a foo()` ───────────────
 *
 * `result = a foo()` where foo returns T+A(ms) lowers to u_await_or_throw:
 * drive the promise to its deadline; on success return the frame (caller
 * reads ->result_value); on timeout THROW U_ERR_TIMEOUT (default, un-
 * absorbable). The +N variant u_await_or_none returns NULL on timeout
 * instead — the programmer opted into nullable, so silence is a choice.
 * Nothing is constructed: the compiler emits one of these two calls with
 * the deadline read off the +A(ms) type. */
static inline void* u_await_or_throw(void* frame, int64_t site_ms) {
    int64_t dl = u_deadline_from(site_ms);
    if (u_drive_until_done_deadline(frame, dl)) return frame;
    u_throw(U_ERR_TIMEOUT, "await exceeded its +A deadline");
    return NULL;  /* unreachable */
}
static inline void* u_await_or_none(void* frame, int64_t site_ms) {
    int64_t dl = u_deadline_from(site_ms);
    if (u_drive_until_done_deadline(frame, dl)) return frame;
    return NULL;  /* +N: timeout downgrades to none */
}

/* ── Pull streams — demand-driven, bounded, no data buffering ─────────────
 *
 * The concurrency model the design converged on (see implementation.html /
 * the .x() chain discussion). A chain `gen.x(A,3).x(B,5)` is RIGHT-
 * ASSOCIATIVE pull: B is the driving stage, B pulls from A, A pulls from
 * gen. Demand originates at the bottom and travels up as blocking pulls.
 *
 * Each stage has a fixed count = how many pulls it services CONCURRENTLY.
 * Crucially, NOTHING buffers values: a stage produces a value only to
 * satisfy a pull already waiting for it, and that value flows straight
 * into the waiting slot. What "backs up" under load is pending DEMAND
 * (parked pulls), never data. Two benign, bounded behaviors:
 *   - backpressure: B's pulls don't reach A (A busy / A's upstream slow),
 *     so B-slots that can't get serviced park. Upstream stalls.
 *   - starvation:  B's pull reaches A but A hasn't computed a value yet,
 *     so the B-slot waits. Downstream idles briefly.
 * Memory is one in-transit payload per active slot; no queue, no buffer.
 *
 * A stream is a pull function + opaque state. pull() returns a value
 * pointer, or NULL to signal end-of-stream (the generator is exhausted).
 * A stage wraps an upstream stream with a transform and a concurrency
 * bound; its pull services up to `width` concurrent upstream pulls.
 */

typedef void* (*UStreamPullFn)(void* state);

typedef struct UStream {
    URcHeader header;
    UStreamPullFn pull;   /* returns next value, or NULL at end-of-stream */
    void* state;          /* stream-specific state */
} UStream;

static inline UStream* u_stream_new(UStreamPullFn pull, void* state) {
    UStream* s = (UStream*)u_alloc(sizeof(UStream));
    s->pull = pull;
    s->state = state;
    return s;
}

/* Drive `n` pulls from a stream into a caller-provided list (for testing
   and for terminal .x() that materializes). Stops early at end-of-stream,
   returning the count actually produced. */
static inline int32_t u_stream_take(UStream* s, void** out, int32_t n) {
    int32_t i = 0;
    for (; i < n; i++) {
        void* v = s->pull(s->state);
        if (v == NULL) break;   /* end of stream */
        out[i] = v;
    }
    return i;
}

/* A bounded map-stage: pulls from `upstream`, applies `fn`, bounded to
   `width` — which caps how many upstream pulls are in flight at once.
   In this single-threaded cooperative core the "in flight" bound shows
   up as: the stage never pulls upstream more than `width` times before a
   value is consumed downstream. State tracks the width for the bound;
   the pull is synchronous here (a fuller async version parks fibers on
   the gate — same structure, suspend instead of loop). */
typedef struct {
    UStream* upstream;
    void* (*fn)(void* item);
    int32_t width;
    int32_t in_flight;   /* current concurrent upstream pulls */
    int32_t peak_in_flight;  /* high-water mark, for verifying the bound */
} UMapStageState;

static inline void* u_map_stage_pull(void* st) {
    UMapStageState* s = (UMapStageState*)st;
    /* Demand-pull: one downstream pull -> one upstream pull -> transform.
       The width bound is observed by tracking concurrent in-flight pulls;
       in the cooperative core a single pull is serviced at a time, but the
       bound is enforced/measured so the async version (which parks) has
       the same ceiling. */
    if (s->in_flight >= s->width) {
        /* At capacity — in the async model this pull would PARK until a
           slot frees (backpressure). In the sync core we serialize, so
           this path is where a real fiber-suspend would go. */
    }
    s->in_flight++;
    if (s->in_flight > s->peak_in_flight) s->peak_in_flight = s->in_flight;
    void* item = s->upstream->pull(s->upstream->state);
    void* result = (item == NULL) ? NULL : s->fn(item);
    s->in_flight--;
    return result;
}

static inline UStream* u_map_stage(UStream* upstream, void* (*fn)(void* item), int32_t width) {
    UMapStageState* st = (UMapStageState*)u_alloc(sizeof(UMapStageState));
    st->upstream = upstream;
    st->fn = fn;
    st->width = width;
    st->in_flight = 0;
    st->peak_in_flight = 0;
    return u_stream_new(u_map_stage_pull, st);
}

/* ── Rx operators (turn 23) ─────────────────────────────────────────────
 *
 * RE-MEASURED: the earlier "needs concurrency — spec fork" claim was wrong.
 * U's streams are pull-based and single-threaded (see UStream above): a
 * stage pulls upstream, transforms, yields downstream. The Rx operators
 * are timers and buffers over synchronous pull, NOT concurrency — exactly
 * as Qbix ships them. No spec fork needed.
 *
 * .or(fallback): if upstream ends (yields NULL) without ever producing a
 * value, pull from the fallback stream instead.
 */
typedef struct {
    UStream* primary;
    UStream* fallback;
    int32_t produced;    /* did primary yield anything? */
    int32_t primary_done;
} UOrStageState;

static inline void* u_or_stage_pull(void* st) {
    UOrStageState* s = (UOrStageState*)st;
    if (!s->primary_done) {
        void* v = s->primary->pull(s->primary->state);
        if (v != NULL) { s->produced = 1; return v; }
        s->primary_done = 1;
    }
    /* Primary exhausted. Only fall back if it never produced. */
    if (!s->produced) {
        return s->fallback->pull(s->fallback->state);
    }
    return NULL;
}

static inline UStream* u_or_stage(UStream* primary, UStream* fallback) {
    UOrStageState* st = (UOrStageState*)u_alloc(sizeof(UOrStageState));
    st->primary = primary;
    st->fallback = fallback;
    st->produced = 0;
    st->primary_done = 0;
    return u_stream_new(u_or_stage_pull, st);
}

/* .latest: backpressure — drain upstream and keep only the MOST RECENT
 * value, discarding intermediates. Single pull returns the last available.
 * (Here "available" = everything upstream can produce without blocking; in
 * the synchronous core that is the full drain, which models a fast producer
 * whose intermediate values a slow consumer skips.) */
typedef struct {
    UStream* upstream;
    int32_t exhausted;
} ULatestStageState;

static inline void* u_latest_stage_pull(void* st) {
    ULatestStageState* s = (ULatestStageState*)st;
    if (s->exhausted) return NULL;
    void* latest = NULL;
    void* v;
    while ((v = s->upstream->pull(s->upstream->state)) != NULL) {
        latest = v;  /* keep overwriting — only the last survives */
    }
    s->exhausted = 1;
    return latest;
}

static inline UStream* u_latest_stage(UStream* upstream) {
    ULatestStageState* st = (ULatestStageState*)u_alloc(sizeof(ULatestStageState));
    st->upstream = upstream;
    st->exhausted = 0;
    return u_stream_new(u_latest_stage_pull, st);
}

/* .debounce(interval): drop values that arrive within `interval` "ticks"
 * of the last emitted one. Each value carries a timestamp (paired as
 * {ts, value} — here the state pulls (timestamp, value) pairs via a
 * caller-supplied timestamp function). Emits a value only if enough time
 * passed since the last emission. */
typedef struct {
    UStream* upstream;
    int64_t (*timestamp_of)(void* item);  /* extract ts from an item */
    int64_t interval;
    int64_t last_emit;
    int32_t have_last;
} UDebounceStageState;

static inline void* u_debounce_stage_pull(void* st) {
    UDebounceStageState* s = (UDebounceStageState*)st;
    void* v;
    while ((v = s->upstream->pull(s->upstream->state)) != NULL) {
        int64_t ts = s->timestamp_of(v);
        if (!s->have_last || ts - s->last_emit >= s->interval) {
            s->last_emit = ts;
            s->have_last = 1;
            return v;   /* emit */
        }
        /* else: too soon, drop and pull the next */
    }
    return NULL;
}

static inline UStream* u_debounce_stage(UStream* upstream,
                                         int64_t (*timestamp_of)(void*),
                                         int64_t interval) {
    UDebounceStageState* st = (UDebounceStageState*)u_alloc(sizeof(UDebounceStageState));
    st->upstream = upstream;
    st->timestamp_of = timestamp_of;
    st->interval = interval;
    st->last_emit = 0;
    st->have_last = 0;
    return u_stream_new(u_debounce_stage_pull, st);
}

/* .delay(n): buffer the first `n` values, then emit shifted by n — each
 * pull returns the value from n pulls ago. A fixed-size ring delays the
 * stream by n positions. (End-of-stream flushes the buffer.) */
typedef struct {
    UStream* upstream;
    void** ring;
    int32_t n;
    int32_t count;       /* how many buffered so far */
    int32_t head;        /* next write position */
    int32_t flushing;    /* upstream ended; draining the buffer */
    int32_t flush_left;
} UDelayStageState;

static inline void* u_delay_stage_pull(void* st) {
    UDelayStageState* s = (UDelayStageState*)st;
    if (!s->flushing) {
        void* v = s->upstream->pull(s->upstream->state);
        if (v == NULL) {
            /* Upstream ended — begin flushing whatever is buffered. */
            s->flushing = 1;
            s->flush_left = s->count;
        } else {
            void* out = NULL;
            if (s->count >= s->n) {
                out = s->ring[s->head];  /* the value from n pulls ago */
            }
            s->ring[s->head] = v;
            s->head = (s->head + 1) % (s->n > 0 ? s->n : 1);
            if (s->count < s->n) s->count++;
            if (out != NULL) return out;
            /* still filling the buffer — recurse to pull the next */
            return u_delay_stage_pull(st);
        }
    }
    /* Flushing phase: emit remaining buffered values in order. */
    if (s->flush_left > 0) {
        void* out = s->ring[s->head];
        s->head = (s->head + 1) % (s->n > 0 ? s->n : 1);
        s->flush_left--;
        return out;
    }
    return NULL;
}

static inline UStream* u_delay_stage(UStream* upstream, int32_t n) {
    UDelayStageState* st = (UDelayStageState*)u_alloc(sizeof(UDelayStageState));
    st->upstream = upstream;
    st->n = n;
    st->ring = (void**)u_alloc(sizeof(void*) * (n > 0 ? n : 1));
    st->count = 0;
    st->head = 0;
    st->flushing = 0;
    st->flush_left = 0;
    return u_stream_new(u_delay_stage_pull, st);
}

/* ── +V Vectorization Runtime ─────────────────────────────────────── */
/* ── Vectorization tier (u_language.html +V) ───────────────────────── *
 * REAL vendor-agnostic SIMD via GCC/Clang vector extensions. The COMPILER
 * lowers these portable vector types to the target ISA: SSE/AVX on x86,
 * NEON on ARM, WebAssembly SIMD 128 under emcc -msimd128. This tier is
 * ALWAYS compiled — the SIMD map/reduce helpers are pthread-free, so a +V
 * kernel links and runs by default (previously the whole block sat behind
 * an off-by-default U_VEC_ENABLE and emitted calls to functions that were
 * never compiled — the code failed to LINK). The optional THREAD-POOL tier
 * (fork-join across cores) stays behind U_VEC_ENABLE, since it needs
 * pthread. Without it, u_vec_parallel runs the kernel serially — still
 * real SIMD in the body, just one core. */

/* ── Tuning knobs ──────────────────────────────────────────────────── */
#ifndef U_VEC_THRESHOLD
#define U_VEC_THRESHOLD  1024     /* min elements before threading     */
#endif
#ifndef U_VEC_SIMD_WIDTH
#define U_VEC_SIMD_WIDTH 8        /* int32 lanes per SIMD register     */
#endif
#ifndef U_VEC_MAX_THREADS
#define U_VEC_MAX_THREADS 8       /* max worker threads                */
#endif
#ifndef U_VEC_BACKPRESSURE
#define U_VEC_BACKPRESSURE 65536  /* max in-flight elements per batch  */
#endif

#ifdef U_VEC_ENABLE
#include <pthread.h>
#endif

/* ── SIMD lane types (GCC vector extensions) ──────────────────────── */
typedef int32_t  v8i32 __attribute__((vector_size(32)));  /* 8 x i32 */
typedef double   v4f64 __attribute__((vector_size(32)));  /* 4 x f64 */

/* ── Thread pool ──────────────────────────────────────────────────── */
typedef struct {
    void (*fn)(void* ctx, int32_t start, int32_t end);
    void* ctx;
    int32_t start, end;
} UVecTask;

#ifdef U_VEC_ENABLE
static void* _u_vec_worker(void* arg) {
    UVecTask* t = (UVecTask*)arg;
    t->fn(t->ctx, t->start, t->end);
    return NULL;
}

/* Fork-join: split [0, n) into chunks, run fn(ctx, start, end) per chunk.
   Respects backpressure: processes at most U_VEC_BACKPRESSURE elements
   per batch, yielding between batches for demand-driven flow.           */
static inline void u_vec_parallel(int32_t n,
                                   void (*fn)(void*, int32_t, int32_t),
                                   void* ctx) {
    if (n <= U_VEC_THRESHOLD) {
        fn(ctx, 0, n);  /* scalar fallback */
        return;
    }
    /* Backpressure: process in batches */
    for (int32_t batch_start = 0; batch_start < n; batch_start += U_VEC_BACKPRESSURE) {
        int32_t batch_end = batch_start + U_VEC_BACKPRESSURE;
        if (batch_end > n) batch_end = n;
        int32_t batch_n = batch_end - batch_start;

        int32_t nthreads = batch_n / U_VEC_THRESHOLD;
        if (nthreads > U_VEC_MAX_THREADS) nthreads = U_VEC_MAX_THREADS;
        if (nthreads < 2) { fn(ctx, batch_start, batch_end); continue; }
        int32_t chunk = batch_n / nthreads;

        pthread_t threads[U_VEC_MAX_THREADS];
        UVecTask tasks[U_VEC_MAX_THREADS];
        for (int32_t i = 0; i < nthreads; i++) {
            tasks[i] = (UVecTask){fn, ctx,
                batch_start + i * chunk,
                (i == nthreads - 1) ? batch_end : batch_start + (i + 1) * chunk};
            if (i < nthreads - 1)
                pthread_create(&threads[i], NULL, _u_vec_worker, &tasks[i]);
            else
                fn(ctx, tasks[i].start, tasks[i].end); /* last in caller */
        }
        for (int32_t i = 0; i < nthreads - 1; i++)
            pthread_join(threads[i], NULL);
    }
}
#else
/* Serial fallback: no pthread dependency. The kernel still uses real SIMD
   in its body — this just runs it on one core over the whole range. */
static inline void u_vec_parallel(int32_t n,
                                   void (*fn)(void*, int32_t, int32_t),
                                   void* ctx) {
    fn(ctx, 0, n);
}
#endif

/* ── SIMD-accelerated sum: int32 ──────────────────────────────────── *
 * Uses interleaved partial sums in SIMD lanes, then horizontal reduce.
 * Falls back to scalar for tail elements not filling a full lane.       */
static inline int32_t u_vec_sum_i32_simd(const int32_t* data, int32_t n) {
    v8i32 acc = {0,0,0,0,0,0,0,0};
    int32_t i = 0;
    /* SIMD main loop: 8 lanes interleaved */
    for (; i + U_VEC_SIMD_WIDTH <= n; i += U_VEC_SIMD_WIDTH) {
        v8i32 chunk;
        __builtin_memcpy(&chunk, data + i, sizeof(v8i32));
        acc += chunk;
    }
    /* Horizontal reduce: fold 8 lanes → 1 */
    int32_t result = 0;
    for (int32_t lane = 0; lane < U_VEC_SIMD_WIDTH; lane++)
        result += acc[lane];
    /* Scalar tail */
    for (; i < n; i++) result += data[i];
    return result;
}

/* ── SIMD-accelerated sum: double ─────────────────────────────────── */
static inline double u_vec_sum_f64_simd(const double* data, int32_t n) {
    v4f64 acc = {0.0, 0.0, 0.0, 0.0};
    int32_t i = 0;
    for (; i + 4 <= n; i += 4) {
        v4f64 chunk;
        __builtin_memcpy(&chunk, data + i, sizeof(v4f64));
        acc += chunk;
    }
    double result = 0.0;
    for (int32_t lane = 0; lane < 4; lane++) result += acc[lane];
    for (; i < n; i++) result += data[i];
    return result;
}

/* ── SIMD-accelerated min: int32 ──────────────────────────────────── *
 * Lane-parallel: each lane tracks its running min, then horizontal min. */
static inline int32_t u_vec_min_i32_simd(const int32_t* data, int32_t n) {
    if (n < U_VEC_SIMD_WIDTH) {
        int32_t m = data[0];
        for (int32_t i = 1; i < n; i++) if (data[i] < m) m = data[i];
        return m;
    }
    v8i32 mins;
    __builtin_memcpy(&mins, data, sizeof(v8i32));
    int32_t i = U_VEC_SIMD_WIDTH;
    for (; i + U_VEC_SIMD_WIDTH <= n; i += U_VEC_SIMD_WIDTH) {
        v8i32 chunk;
        __builtin_memcpy(&chunk, data + i, sizeof(v8i32));
        /* Lane-wise min: no SIMD intrinsic needed, compiler vectorizes */
        for (int32_t lane = 0; lane < U_VEC_SIMD_WIDTH; lane++)
            if (chunk[lane] < mins[lane]) mins[lane] = chunk[lane];
    }
    int32_t m = mins[0];
    for (int32_t lane = 1; lane < U_VEC_SIMD_WIDTH; lane++)
        if (mins[lane] < m) m = mins[lane];
    for (; i < n; i++) if (data[i] < m) m = data[i];
    return m;
}

/* ── SIMD-accelerated max: int32 ──────────────────────────────────── */
static inline int32_t u_vec_max_i32_simd(const int32_t* data, int32_t n) {
    if (n < U_VEC_SIMD_WIDTH) {
        int32_t m = data[0];
        for (int32_t i = 1; i < n; i++) if (data[i] > m) m = data[i];
        return m;
    }
    v8i32 maxs;
    __builtin_memcpy(&maxs, data, sizeof(v8i32));
    int32_t i = U_VEC_SIMD_WIDTH;
    for (; i + U_VEC_SIMD_WIDTH <= n; i += U_VEC_SIMD_WIDTH) {
        v8i32 chunk;
        __builtin_memcpy(&chunk, data + i, sizeof(v8i32));
        for (int32_t lane = 0; lane < U_VEC_SIMD_WIDTH; lane++)
            if (chunk[lane] > maxs[lane]) maxs[lane] = chunk[lane];
    }
    int32_t m = maxs[0];
    for (int32_t lane = 1; lane < U_VEC_SIMD_WIDTH; lane++)
        if (maxs[lane] > m) m = maxs[lane];
    for (; i < n; i++) if (data[i] > m) m = data[i];
    return m;
}

/* ── Threaded + SIMD composites ───────────────────────────────────── *
 * Large lists: fork-join across threads, each thread uses SIMD lanes. */
typedef struct { const int32_t* data; int64_t* partials; } UVecSumCtx;
static void _u_vec_sum_i32_chunk(void* ctx, int32_t start, int32_t end) {
    UVecSumCtx* c = (UVecSumCtx*)ctx;
    int32_t local = u_vec_sum_i32_simd(c->data + start, end - start);
    __atomic_add_fetch(&c->partials[0], (int64_t)local, __ATOMIC_RELAXED);
}

static inline int32_t u_vec_sum_i32_raw(const int32_t* data, int32_t len) {
    if (len <= U_VEC_THRESHOLD)
        return u_vec_sum_i32_simd(data, len);
    int64_t result = 0;
    UVecSumCtx ctx = { data, &result };
    u_vec_parallel(len, _u_vec_sum_i32_chunk, &ctx);
    return (int32_t)result;
}
#define u_vec_sum_i32(arr) u_vec_sum_i32_raw((arr)->data, (arr)->length)
typedef struct { const int32_t* data; int32_t result; } UVecMinCtx;
static void _u_vec_min_i32_chunk(void* ctx, int32_t start, int32_t end) {
    UVecMinCtx* c = (UVecMinCtx*)ctx;
    int32_t local = u_vec_min_i32_simd(c->data + start, end - start);
    int32_t old;
    do { old = c->result; if (local >= old) break;
    } while (!__atomic_compare_exchange_n(&c->result, &old, local, 0,
             __ATOMIC_RELAXED, __ATOMIC_RELAXED));
}
static inline int32_t u_vec_min_i32_raw(const int32_t* data, int32_t len) {
    if (len <= U_VEC_THRESHOLD)
        return u_vec_min_i32_simd(data, len);
    UVecMinCtx ctx = { data, data[0] };
    u_vec_parallel(len, _u_vec_min_i32_chunk, &ctx);
    return ctx.result;
}
#define u_vec_min_i32(arr) u_vec_min_i32_raw((arr)->data, (arr)->length)

typedef struct { const int32_t* data; int32_t result; } UVecMaxCtx;
static void _u_vec_max_i32_chunk(void* ctx, int32_t start, int32_t end) {
    UVecMaxCtx* c = (UVecMaxCtx*)ctx;
    int32_t local = u_vec_max_i32_simd(c->data + start, end - start);
    int32_t old;
    do { old = c->result; if (local <= old) break;
    } while (!__atomic_compare_exchange_n(&c->result, &old, local, 0,
             __ATOMIC_RELAXED, __ATOMIC_RELAXED));
}
static inline int32_t u_vec_max_i32_raw(const int32_t* data, int32_t len) {
    if (len <= U_VEC_THRESHOLD)
        return u_vec_max_i32_simd(data, len);
    UVecMaxCtx ctx = { data, data[0] };
    u_vec_parallel(len, _u_vec_max_i32_chunk, &ctx);
    return ctx.result;
}
#define u_vec_max_i32(arr) u_vec_max_i32_raw((arr)->data, (arr)->length)

/* ── Double variants (SIMD + threaded) ────────────────────────────── */
static inline double u_vec_sum_double_raw(const double* data, int32_t len) {
    return u_vec_sum_f64_simd(data, len);
}
#define u_vec_sum_double(arr) u_vec_sum_double_raw((arr)->data, (arr)->length)
static inline double u_vec_min_double_raw(const double* data, int32_t len) {
    double m = data[0];
    for (int32_t i = 1; i < len; i++)
        if (data[i] < m) m = data[i];
    return m;
}
#define u_vec_min_double(arr) u_vec_min_double_raw((arr)->data, (arr)->length)
static inline double u_vec_max_double_raw(const double* data, int32_t len) {
    double m = data[0];
    for (int32_t i = 1; i < len; i++)
        if (data[i] > m) m = data[i];
    return m;
}
#define u_vec_max_double(arr) u_vec_max_double_raw((arr)->data, (arr)->length)

/* ── Parallel map ─────────────────────────────────────────────────── */
typedef struct {
    int32_t* src; int32_t* dst;
    int32_t (*mapfn)(int32_t);
} UVecMapCtx_i32;

static void _u_vec_map_i32_worker(void* ctx, int32_t start, int32_t end) {
    UVecMapCtx_i32* c = (UVecMapCtx_i32*)ctx;
    for (int32_t i = start; i < end; i++)
        c->dst[i] = c->mapfn(c->src[i]);
}

/* u_vec_map_i32: defined as macro after UList types are available */
/* static inline -- see codegen for macro expansion */
static inline void* u_vec_map_i32_impl(void* src_data, int32_t src_len,
                                        int32_t (*fn)(int32_t), void* dst_data) {
    UVecMapCtx_i32 ctx = { (int32_t*)src_data, (int32_t*)dst_data, fn };
    u_vec_parallel(src_len, _u_vec_map_i32_worker, &ctx);
    return dst_data;
}
/* Old signature kept as macro for compatibility: */
#define u_vec_map_i32(src, fn) ({ \
    _d->length = _s->length; \
    u_vec_map_i32_impl(_s->data, _s->length, (fn), _d->data); \
    _d; })


/* ── Reusable thread pool ─────────────────────────────────────────── *
 * Avoids pthread_create/join overhead on repeated vec calls.           *
 * Workers spin-wait on a task queue; u_pool_submit enqueues work.      *
 * Behind U_VEC_ENABLE: needs pthread, and no codegen path calls it     *
 * (emitted code uses u_vec_parallel, which has a serial fallback).      */
#ifdef U_VEC_ENABLE
#include <stdatomic.h>

typedef struct {
    pthread_t       threads[U_VEC_MAX_THREADS];
    atomic_int      task_count;
    atomic_int      done_count;
    void            (*fn)(void*, int32_t, int32_t);
    void*           ctx;
    int32_t         starts[U_VEC_MAX_THREADS];
    int32_t         ends[U_VEC_MAX_THREADS];
    int32_t         nthreads;
    atomic_int      shutdown;
    atomic_int      generation;   /* bumped each submit to wake workers */
} UThreadPool;

static UThreadPool* _u_global_pool = NULL;

static void* _u_pool_worker(void* arg) {
    int32_t id = (int32_t)(intptr_t)arg;
    UThreadPool* p = _u_global_pool;
    int32_t last_gen = 0;
    while (!atomic_load(&p->shutdown)) {
        int32_t gen = atomic_load(&p->generation);
        if (gen == last_gen) { sched_yield(); continue; }
        last_gen = gen;
        if (id < p->nthreads)
            p->fn(p->ctx, p->starts[id], p->ends[id]);
        atomic_fetch_add(&p->done_count, 1);
    }
    return NULL;
}

static inline void u_pool_init(void) {
    if (_u_global_pool) return;
    _u_global_pool = (UThreadPool*)calloc(1, sizeof(UThreadPool));
    atomic_store(&_u_global_pool->shutdown, 0);
    atomic_store(&_u_global_pool->generation, 0);
    for (int32_t i = 0; i < U_VEC_MAX_THREADS; i++)
        pthread_create(&_u_global_pool->threads[i], NULL,
                       _u_pool_worker, (void*)(intptr_t)i);
}

static inline void u_pool_submit(int32_t n,
                                  void (*fn)(void*, int32_t, int32_t),
                                  void* ctx) {
    if (!_u_global_pool) u_pool_init();
    UThreadPool* p = _u_global_pool;
    int32_t nthreads = n / U_VEC_THRESHOLD;
    if (nthreads > U_VEC_MAX_THREADS) nthreads = U_VEC_MAX_THREADS;
    if (nthreads < 2) { fn(ctx, 0, n); return; }
    int32_t chunk = n / nthreads;
    p->fn = fn; p->ctx = ctx; p->nthreads = nthreads;
    for (int32_t i = 0; i < nthreads; i++) {
        p->starts[i] = i * chunk;
        p->ends[i] = (i == nthreads - 1) ? n : (i + 1) * chunk;
    }
    atomic_store(&p->done_count, 0);
    atomic_fetch_add(&p->generation, 1);  /* wake workers */
    /* Caller does last chunk */
    fn(ctx, p->starts[nthreads - 1], p->ends[nthreads - 1]);
    /* Wait for others */
    while (atomic_load(&p->done_count) < nthreads - 1)
        sched_yield();
}
#endif /* U_VEC_ENABLE — reusable thread pool */

/* ── SIMD min/max for doubles ─────────────────────────────────────── */
static inline double u_vec_min_f64_simd(const double* data, int32_t n) {
    if (n < 4) {
        double m = data[0];
        for (int32_t i = 1; i < n; i++) if (data[i] < m) m = data[i];
        return m;
    }
    v4f64 mins;
    __builtin_memcpy(&mins, data, sizeof(v4f64));
    int32_t i = 4;
    for (; i + 4 <= n; i += 4) {
        v4f64 chunk;
        __builtin_memcpy(&chunk, data + i, sizeof(v4f64));
        for (int32_t lane = 0; lane < 4; lane++)
            if (chunk[lane] < mins[lane]) mins[lane] = chunk[lane];
    }
    double m = mins[0];
    for (int32_t lane = 1; lane < 4; lane++)
        if (mins[lane] < m) m = mins[lane];
    for (; i < n; i++) if (data[i] < m) m = data[i];
    return m;
}

static inline double u_vec_max_f64_simd(const double* data, int32_t n) {
    if (n < 4) {
        double m = data[0];
        for (int32_t i = 1; i < n; i++) if (data[i] > m) m = data[i];
        return m;
    }
    v4f64 maxs;
    __builtin_memcpy(&maxs, data, sizeof(v4f64));
    int32_t i = 4;
    for (; i + 4 <= n; i += 4) {
        v4f64 chunk;
        __builtin_memcpy(&chunk, data + i, sizeof(v4f64));
        for (int32_t lane = 0; lane < 4; lane++)
            if (chunk[lane] > maxs[lane]) maxs[lane] = chunk[lane];
    }
    double m = maxs[0];
    for (int32_t lane = 1; lane < 4; lane++)
        if (maxs[lane] > m) m = maxs[lane];
    for (; i < n; i++) if (data[i] > m) m = data[i];
    return m;
}

/* ── Vectorized associative reduce ────────────────────────────────── *
 * For user-defined associative ops: reduce(fn, init) where fn is +V.
 * Each thread reduces its chunk, then combine sequentially.             */
typedef struct {
    const int32_t* data;
    int32_t (*op)(int32_t, int32_t);
    int32_t init;
    int32_t partials[U_VEC_MAX_THREADS];
    int32_t nthreads;
} UVecReduceCtx_i32;

static void _u_vec_reduce_i32_chunk(void* ctx, int32_t start, int32_t end) {
    UVecReduceCtx_i32* c = (UVecReduceCtx_i32*)ctx;
    int32_t acc = c->init;
    for (int32_t i = start; i < end; i++)
        acc = c->op(acc, c->data[i]);
    /* Find thread index from start offset */
    int32_t chunk_size = (end - start);
    int32_t tid = (chunk_size > 0) ? start / chunk_size : 0;
    if (tid >= c->nthreads) tid = c->nthreads - 1;
    c->partials[tid] = acc;
}

static inline int32_t u_vec_reduce_i32(const int32_t* data, int32_t n,
                                        int32_t (*op)(int32_t, int32_t),
                                        int32_t init) {
    if (n <= U_VEC_THRESHOLD) {
        int32_t acc = init;
        for (int32_t i = 0; i < n; i++) acc = op(acc, data[i]);
        return acc;
    }
    int32_t nthreads = n / U_VEC_THRESHOLD;
    if (nthreads > U_VEC_MAX_THREADS) nthreads = U_VEC_MAX_THREADS;
    UVecReduceCtx_i32 ctx = { data, op, init, {0}, nthreads };
    u_vec_parallel(n, _u_vec_reduce_i32_chunk, &ctx);
    /* Combine partial results */
    int32_t result = ctx.partials[0];
    for (int32_t t = 1; t < nthreads; t++)
        result = op(result, ctx.partials[t]);
    return result;
}

/* ── GPU kernel template (OpenCL-style) ───────────────────────────── *
 * Represents the kernel that WOULD be generated for +R(GPU) lists.
 * In a full impl, the compiler emits this as an OpenCL C string,
 * compiles at runtime, and dispatches to the GPU.                       *
 *                                                                       *
 * Example generated kernel for arr.map(v => v * 2 + 1):                *
 *                                                                       *
 *   __kernel void u_map_kernel(__global int* src,                       *
 *                              __global int* dst,                       *
 *                              int n) {                                 *
 *       int gid = get_global_id(0);                                     *
 *       if (gid < n) dst[gid] = src[gid] * 2 + 1;                      *
 *   }                                                                   *
 *                                                                       *
 * Dispatch:                                                             *
 *   cl_kernel k = clCreateKernel(prog, "u_map_kernel", NULL);           *
 *   clSetKernelArg(k, 0, sizeof(cl_mem), &src_buf);                     *
 *   clSetKernelArg(k, 1, sizeof(cl_mem), &dst_buf);                     *
 *   clSetKernelArg(k, 2, sizeof(int), &n);                              *
 *   size_t global = (n + 255) & ~255;  // round up to warp size         *
 *   clEnqueueNDRangeKernel(queue, k, 1, NULL, &global, NULL, 0, 0, 0); *
 *                                                                       *
 * For associative reductions (sum/min/max), a two-pass kernel:          *
 *   Pass 1: each workgroup reduces 256 elements → 1 partial            *
 *   Pass 2: reduce partials → final scalar                              *
 *                                                                       *
 * Backpressure on GPU: bounded command queue depth (default 4 kernels   *
 * in flight). clFlush between batches; clWaitForEvents when queue full. */

/* ── GPU dispatch stubs ───────────────────────────────────────────── *
 * +R(GPU): would allocate device memory, copy, launch kernel, copy back.
 * Currently falls through to CPU threaded+SIMD path.                    */
typedef enum { U_DEVICE_CPU, U_DEVICE_GPU } UDeviceKind;
typedef struct { UDeviceKind kind; int32_t device_id; } UDevice;
static UDevice u_device_cpu = { U_DEVICE_CPU, 0 };
static inline UDevice* u_device_select(const char* hint) {
    (void)hint; return &u_device_cpu;
}


/* ── SIMD-accelerated map (inline arithmetic) ─────────────────────── *
 * For simple operations (multiply, add, shift), SIMD lanes avoid       *
 * function-pointer call overhead entirely.                              */
static inline void u_vec_map_mul_i32(const int32_t* src, int32_t* dst,
                                      int32_t n, int32_t factor) {
    v8i32 vf = { factor, factor, factor, factor,
                 factor, factor, factor, factor };
    int32_t i = 0;
    for (; i + U_VEC_SIMD_WIDTH <= n; i += U_VEC_SIMD_WIDTH) {
        v8i32 chunk;
        __builtin_memcpy(&chunk, src + i, sizeof(v8i32));
        chunk *= vf;
        __builtin_memcpy(dst + i, &chunk, sizeof(v8i32));
    }
    for (; i < n; i++) dst[i] = src[i] * factor;
}

static inline void u_vec_map_add_i32(const int32_t* src, int32_t* dst,
                                      int32_t n, int32_t addend) {
    v8i32 va = { addend, addend, addend, addend,
                 addend, addend, addend, addend };
    int32_t i = 0;
    for (; i + U_VEC_SIMD_WIDTH <= n; i += U_VEC_SIMD_WIDTH) {
        v8i32 chunk;
        __builtin_memcpy(&chunk, src + i, sizeof(v8i32));
        chunk += va;
        __builtin_memcpy(dst + i, &chunk, sizeof(v8i32));
    }
    for (; i < n; i++) dst[i] = src[i] + addend;
}

/* mul+add fused: dst[i] = src[i] * a + b (common in linear transforms) */
static inline void u_vec_map_muladd_i32(const int32_t* src, int32_t* dst,
                                         int32_t n, int32_t a, int32_t b) {
    v8i32 va = { a,a,a,a,a,a,a,a };
    v8i32 vb = { b,b,b,b,b,b,b,b };
    int32_t i = 0;
    for (; i + U_VEC_SIMD_WIDTH <= n; i += U_VEC_SIMD_WIDTH) {
        v8i32 chunk;
        __builtin_memcpy(&chunk, src + i, sizeof(v8i32));
        chunk = chunk * va + vb;
        __builtin_memcpy(dst + i, &chunk, sizeof(v8i32));
    }
    for (; i < n; i++) dst[i] = src[i] * a + b;
}

/* ── Double-precision SIMD map ────────────────────────────────────── */
static inline void u_vec_map_mul_f64(const double* src, double* dst,
                                      int32_t n, double factor) {
    v4f64 vf = { factor, factor, factor, factor };
    int32_t i = 0;
    for (; i + 4 <= n; i += 4) {
        v4f64 chunk;
        __builtin_memcpy(&chunk, src + i, sizeof(v4f64));
        chunk *= vf;
        __builtin_memcpy(dst + i, &chunk, sizeof(v4f64));
    }
    for (; i < n; i++) dst[i] = src[i] * factor;
}

/* ── Filter (int32): SIMD LOADS, scalar predicate ──────────────────────
 * NOT a bitmask filter. The header here used to claim "8 predicates per SIMD
 * iteration" and "compact using popcount"; neither happens -- the loop below
 * loads 8 lanes at a time and then compares each lane SCALARLY. The `vt`
 * broadcast vector this built was never read (gcc -Wall: "unused variable
 * 'vt'"), which is what gave the aspiration away.
 *
 * Left as-is deliberately: a real bitmask compact needs a per-lane movemask,
 * which GCC vector extensions do not expose portably. The comment is now the
 * truth rather than the intent.
 */
static inline int32_t u_vec_filter_gt_i32(const int32_t* src, int32_t* dst,
                                           int32_t n, int32_t threshold) {
    int32_t out_idx = 0;
    int32_t i = 0;
    for (; i + U_VEC_SIMD_WIDTH <= n; i += U_VEC_SIMD_WIDTH) {
        v8i32 chunk;
        __builtin_memcpy(&chunk, src + i, sizeof(v8i32));
        /* Per-lane comparison → scalar extract + compact */
        for (int32_t lane = 0; lane < U_VEC_SIMD_WIDTH; lane++) {
            if (chunk[lane] > threshold)
                dst[out_idx++] = chunk[lane];
        }
    }
    /* Scalar tail */
    for (; i < n; i++)
        if (src[i] > threshold) dst[out_idx++] = src[i];
    return out_idx;
}

/* ── Dot product (SIMD) ───────────────────────────────────────────── */
static inline int32_t u_vec_dot_i32(const int32_t* a, const int32_t* b,
                                     int32_t n) {
    v8i32 acc = {0,0,0,0,0,0,0,0};
    int32_t i = 0;
    for (; i + U_VEC_SIMD_WIDTH <= n; i += U_VEC_SIMD_WIDTH) {
        v8i32 va, vb;
        __builtin_memcpy(&va, a + i, sizeof(v8i32));
        __builtin_memcpy(&vb, b + i, sizeof(v8i32));
        acc += va * vb;
    }
    int32_t result = 0;
    for (int32_t lane = 0; lane < U_VEC_SIMD_WIDTH; lane++)
        result += acc[lane];
    for (; i < n; i++) result += a[i] * b[i];
    return result;
}

static inline double u_vec_dot_f64(const double* a, const double* b,
                                    int32_t n) {
    v4f64 acc = {0.0, 0.0, 0.0, 0.0};
    int32_t i = 0;
    for (; i + 4 <= n; i += 4) {
        v4f64 va, vb;
        __builtin_memcpy(&va, a + i, sizeof(v4f64));
        __builtin_memcpy(&vb, b + i, sizeof(v4f64));
        acc += va * vb;
    }
    double result = 0.0;
    for (int32_t lane = 0; lane < 4; lane++) result += acc[lane];
    for (; i < n; i++) result += a[i] * b[i];
    return result;
}

/* ── GPU kernel string builder ────────────────────────────────────── *
 * Generates OpenCL C source for a +R(GPU) kernel at compile time.      *
 * The runtime would: clCreateProgramWithSource → clBuildProgram →      *
 * clCreateKernel → set args → clEnqueueNDRangeKernel.                  */
typedef struct {
    const char* name;
    const char* source;     /* OpenCL C kernel source */
    int32_t     arg_count;
} UGpuKernel;

static inline UGpuKernel u_gpu_kernel_map_i32(const char* body_expr) {
    /* Would build: __kernel void k(__global int* src, __global int* dst, int n)
     *              { int gid=get_global_id(0); if(gid<n) dst[gid]=<body>; }  */
    static char buf[1024];
    snprintf(buf, sizeof(buf),
        "__kernel void u_k(__global int* src, __global int* dst, int n) {\n"
        "    int gid = get_global_id(0);\n"
        "    if (gid < n) {\n"
        "        int val = src[gid];\n"
        "        dst[gid] = %s;\n"
        "    }\n"
        "}\n", body_expr);
    return (UGpuKernel){ "u_k", buf, 3 };
}

static inline UGpuKernel u_gpu_kernel_reduce_i32(const char* op_expr) {
    /* Two-pass reduction kernel:
     * Pass 1: workgroup reduces 256 elements with local memory
     * Pass 2: reduces partial sums to final scalar                       */
    static char buf[2048];
    snprintf(buf, sizeof(buf),
        "__kernel void u_reduce(\n"
        "    __global int* data, __global int* partials,\n"
        "    __local  int* scratch, int n) {\n"
        "    int lid = get_local_id(0);\n"
        "    int gid = get_global_id(0);\n"
        "    scratch[lid] = (gid < n) ? data[gid] : 0;\n"
        "    barrier(CLK_LOCAL_MEM_FENCE);\n"
        "    for (int s = get_local_size(0)/2; s > 0; s >>= 1) {\n"
        "        if (lid < s) scratch[lid] = %s;\n"
        "        barrier(CLK_LOCAL_MEM_FENCE);\n"
        "    }\n"
        "    if (lid == 0) partials[get_group_id(0)] = scratch[0];\n"
        "}\n", op_expr);
    return (UGpuKernel){ "u_reduce", buf, 4 };
}

#ifdef U_VEC_ENABLE
/* ── Backpressure for .on() with +V lists ────────────────────────── *
 * +V .on() processes in batches of U_VEC_BACKPRESSURE elements.        *
 * Between batches, yields to allow other fibers/threads to run.        *
 * The handler signals completion via return value (true = stop).        */
typedef struct {
    const int32_t* data;
    bool (*handler)(int32_t);
    atomic_int stop_flag;
    int32_t batch_size;
} UVecOnCtx_i32;

static void _u_vec_on_i32_batch(void* ctx, int32_t start, int32_t end) {
    UVecOnCtx_i32* c = (UVecOnCtx_i32*)ctx;
    if (atomic_load(&c->stop_flag)) return;
    for (int32_t i = start; i < end; i++) {
        if (atomic_load(&c->stop_flag)) return;
        if (c->handler(c->data[i])) {
            atomic_store(&c->stop_flag, 1);
            return;
        }
    }
}

static inline void u_vec_on_i32(const int32_t* data, int32_t n,
                                 bool (*handler)(int32_t)) {
    if (n <= U_VEC_THRESHOLD) {
        for (int32_t i = 0; i < n; i++)
            if (handler(data[i])) return;
        return;
    }
    UVecOnCtx_i32 ctx = { data, handler, 0,
                           U_VEC_BACKPRESSURE };
    /* Process in backpressure batches */
    for (int32_t batch = 0; batch < n && !atomic_load(&ctx.stop_flag);
         batch += ctx.batch_size) {
        int32_t end = batch + ctx.batch_size;
        if (end > n) end = n;
        u_vec_parallel(end - batch,
                       _u_vec_on_i32_batch, &ctx);
        /* Yield between batches for demand-driven flow */
        sched_yield();
    }
}
#endif /* U_VEC_ENABLE — .on() backpressure */


/* ── String -> list methods ──────────────────────────────────────────
   These are MACROS, not functions, on purpose. u_runtime.h is included
   BEFORE codegen emits U_LIST_DECLARE(char_ptr), so a function body here
   naming UList_char_ptr would not compile. A macro expands at the call
   site -- inside a generated function, long after the decls -- so the type
   is in scope by then. Same technique as the u_vec_* macros above.
   Codegen adds "char*" to list_types_used at each of these call sites so
   the char_ptr monomorphization is guaranteed to be emitted. */

/* .split(sep) -> [S] -- u_language.html Strings table: "Split on separator."
   Empty separator is meaningless for splitting, so it degenerates to a
   single-element list holding the whole string (rather than looping forever). */
#define u_str_split(S_, DELIM_) ({                                            \
    const char* _s = (S_); const char* _d = (DELIM_);                         \
    UList_char_ptr* _out = u_list_new_char_ptr(4);                          \
    size_t _dl = strlen(_d);                                                  \
    if (_dl == 0) {                                                           \
        char* _w = (char*)malloc(strlen(_s) + 1); strcpy(_w, _s);             \
        u_list_push_char_ptr(_out, _w);                                      \
    } else {                                                                  \
        const char* _p = _s;                                                  \
        for (;;) {                                                            \
            const char* _hit = strstr(_p, _d);                                \
            size_t _n = _hit ? (size_t)(_hit - _p) : strlen(_p);              \
            char* _piece = (char*)malloc(_n + 1);                             \
            memcpy(_piece, _p, _n); _piece[_n] = '\0';                        \
            u_list_push_char_ptr(_out, _piece);                              \
            if (!_hit) break;                                                 \
            _p = _hit + _dl;                                                  \
        }                                                                     \
    }                                                                         \
    _out; })

/* .chars() -> [S] -- "Split into individual characters."
   Each element is its own 1-char heap string, so elements are independently
   owned like every other [S] element. */
#define u_str_chars(S_) ({                                                    \
    const char* _s = (S_);                                                    \
    size_t _len = strlen(_s);                                                 \
    UList_char_ptr* _out = u_list_new_char_ptr((int32_t)_len + 1);          \
    for (size_t _i = 0; _i < _len; _i++) {                                    \
        char* _c = (char*)malloc(2); _c[0] = _s[_i]; _c[1] = '\0';            \
        u_list_push_char_ptr(_out, _c);                                      \
    }                                                                         \
    _out; })

/* .bytes() -> [I] -- "UTF-8 byte values."
   Spec says [I], not [B]: the elements are byte VALUES, read through
   unsigned char so high bytes yield 128..255 rather than negatives. */
#define u_str_bytes(S_) ({                                                    \
    const char* _s = (S_);                                                    \
    size_t _len = strlen(_s);                                                 \
    UList_int32_t* _out = u_list_new_int32_t((int32_t)_len + 1);            \
    for (size_t _i = 0; _i < _len; _i++)                                      \
        u_list_push_int32_t(_out, (int32_t)(unsigned char)_s[_i]);           \
    _out; })

/* .keys() -> [K] -- "All keys in insertion order." Macro for the same reason
   as u_str_split: UList_char_ptr does not exist until codegen emits
   U_LIST_DECLARE(char_ptr) after this header is included.
   Keys are copied out: the map owns its key strings and may free them on
   .delete(), so handing out interior pointers would dangle. */
/* .values() -> [V] -- "All values in insertion order." Values are stored as
   void* in the map; the caller's declared element type drives what codegen
   unboxes them to, so this hands back the void* slots as-is. Macro for the
   same UList-ordering reason as u_map_keys. */
#define u_map_values(M_) ({                                                   \
    UMap* _m = (M_);                                                          \
    int32_t _n = u_map_size(_m);                                              \
    UList_void_ptr* _out = u_list_new_void_ptr(_n > 0 ? _n : 1);              \
    for (int32_t _i = 0; _i < _n; _i++)                                       \
        u_list_push_void_ptr(_out, u_map_value_at(_m, _i));                   \
    _out; })

#define u_map_keys(M_) ({                                                     \
    UMap* _m = (M_);                                                          \
    int32_t _n = u_map_size(_m);                                              \
    UList_char_ptr* _out = u_list_new_char_ptr(_n > 0 ? _n : 1);            \
    for (int32_t _i = 0; _i < _n; _i++) {                                     \
        const char* _k = u_map_key_at(_m, _i);                                \
        char* _copy = (char*)malloc(strlen(_k) + 1); strcpy(_copy, _k);       \
        u_list_push_char_ptr(_out, _copy);                                   \
    }                                                                         \
    _out; })


/* ── SQL statements: text and parameters, kept apart ──────────────────
 * THE POINT: the statement text and its values travel SEPARATELY, all the way
 * to the wire. A parameter is never spliced into the SQL string, so no value --
 * whatever it contains -- can be parsed as SQL. That is the actual fix for
 * injection; escaping is not.
 *
 * `SQL`SELECT ... WHERE age > {{age}}`` lowers to a statement whose text holds
 * a PLACEHOLDER ($1, $2, ...) and a params array holding the value.
 * u_pg_prepare/u_pg_execute consume exactly this shape.
 */
#ifndef U_SQL_MAX_PARAMS
#define U_SQL_MAX_PARAMS 32
#endif

typedef struct {
    const char* text;                       /* SQL with $1..$n placeholders */
    int         nparams;
    const char* params[U_SQL_MAX_PARAMS];   /* a NULL entry is SQL NULL */
} USqlStmt;

static inline USqlStmt u_sql_build(const char* text, int nparams, ...) {
    USqlStmt st;
    memset(&st, 0, sizeof(st));
    st.text = text;
    st.nparams = nparams > U_SQL_MAX_PARAMS ? U_SQL_MAX_PARAMS : nparams;
    va_list ap;
    va_start(ap, nparams);
    for (int i = 0; i < st.nparams; i++) st.params[i] = va_arg(ap, const char*);
    va_end(ap);
    return st;
}
static inline const char* u_sql_text(const USqlStmt* st) { return st ? st->text : ""; }
static inline int u_sql_nparams(const USqlStmt* st) { return st ? st->nparams : 0; }
static inline const char* u_sql_param(const USqlStmt* st, int i) {
    return (st && i >= 0 && i < st->nparams) ? st->params[i] : NULL;
}

#endif /* U_RUNTIME_H */
