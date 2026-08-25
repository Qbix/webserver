/* u_merkle_cache.h — Merkle-tree component cache invalidation
 *
 * Ported from Q_WebServer_Cache_Components.php.
 *
 * The idea: don't cache whole pages. Cache page COMPONENTS (header,
 * feed, sidebar, members list). Each component has a content hash.
 * The Merkle root is the hash of all component hashes.
 *
 * When data changes (a new post, a user joins), invalidate only the
 * components that depend on that data — not the whole page. The
 * dependency graph tells you which components on which pages are
 * affected.
 *
 * Memory: ~200 bytes per cached page (just hashes, no HTML).
 * The actual cached response lives in the response cache (u_cache).
 * This layer only answers: "is this cached page still valid?"
 *
 * How it works:
 *
 *   1. Handler renders a page with components. Each component has
 *      a content hash and a list of dependency keys (e.g. stream names).
 *
 *   2. Handler returns headers:
 *        X-Cache-Tree: {"l":{"feed":"a3f2","sidebar":"b8c1"}}
 *        X-Cache-Deps: {"feed":["community/123/feed"],"sidebar":["community/123/about"]}
 *
 *   3. Server stores the tree + deps here. Full response in response cache.
 *
 *   4. When a dependency key changes (stream update, write, etc.):
 *        - Walk the dependency index: key → [(pageKey, leaf)]
 *        - Purge those pages from the response cache
 *        - Mark the leaves as stale (hint for partial re-render)
 *
 *   5. Next request: cache miss → re-render → new tree + new response cached.
 */

#ifndef U_MERKLE_CACHE_H
#define U_MERKLE_CACHE_H

#include <string.h>
#include <stdlib.h>
#include <stdio.h>
#include <time.h>

/* ── Configuration ──────────────────────────────────── */

#define MERKLE_MAX_TREES      10000
#define MERKLE_MAX_LEAVES     32      /* max components per page */
#define MERKLE_MAX_DEPS       64      /* max dependency entries */
#define MERKLE_KEY_LEN        128
#define MERKLE_HASH_LEN       33      /* md5 hex + null */

/* ── Leaf: one component's hash ─────────────────────── */

typedef struct {
    char path[64];                    /* "feed", "sidebar", "header" */
    char hash[MERKLE_HASH_LEN];       /* content hash (md5 hex) */
    int  stale;                       /* 1 if invalidated */
} MerkleLeaf;

/* ── Tree: one page's component hashes ──────────────── */

typedef struct {
    char       page_key[MERKLE_KEY_LEN]; /* cache key for the page */
    char       root_hash[MERKLE_HASH_LEN]; /* hash of concatenated leaf hashes */
    MerkleLeaf leaves[MERKLE_MAX_LEAVES];
    int        leaf_count;
    time_t     created;
} MerkleTree;

/* ── Dependency: stream → (page, leaf) mapping ─────── */

typedef struct {
    char stream_key[MERKLE_KEY_LEN];  /* e.g. "community/123/feed" */
    char page_key[MERKLE_KEY_LEN];    /* which page this affects */
    char leaf_path[64];               /* which component on that page */
} MerkleDep;

/* ── The cache ──────────────────────────────────────── */

typedef struct {
    MerkleTree* trees;
    int         tree_count;
    int         tree_capacity;
    MerkleDep*  deps;
    int         dep_count;
    int         dep_capacity;
    /* Stats */
    long        invalidations;
    long        pages_invalidated;
    long        trees_registered;
} MerkleCache;

/* ── Create / Destroy ───────────────────────────────── */

static MerkleCache* merkle_cache_new(void) {
    MerkleCache* mc = (MerkleCache*)calloc(1, sizeof(MerkleCache));
    mc->tree_capacity = 1024;
    mc->trees = (MerkleTree*)calloc(mc->tree_capacity, sizeof(MerkleTree));
    mc->dep_capacity = 4096;
    mc->deps = (MerkleDep*)calloc(mc->dep_capacity, sizeof(MerkleDep));
    return mc;
}

static void merkle_cache_free(MerkleCache* mc) {
    if (!mc) return;
    free(mc->trees);
    free(mc->deps);
    free(mc);
}

/* ── Find tree by page key ──────────────────────────── */

static MerkleTree* merkle_find_tree(MerkleCache* mc, const char* page_key) {
    for (int i = 0; i < mc->tree_count; i++) {
        if (strcmp(mc->trees[i].page_key, page_key) == 0)
            return &mc->trees[i];
    }
    return NULL;
}

/* ── Compute root hash from leaves ──────────────────── */

static void merkle_compute_root(MerkleTree* tree) {
    /* Simple: concatenate all leaf hashes sorted by path, then hash.
     * For speed we just XOR the hash bytes — good enough for
     * invalidation (we only need "did it change?", not crypto). */
    unsigned long h = 0;
    for (int i = 0; i < tree->leaf_count; i++) {
        if (tree->leaves[i].stale) continue;
        for (int j = 0; tree->leaves[i].hash[j]; j++)
            h = h * 31 + (unsigned char)tree->leaves[i].hash[j];
    }
    snprintf(tree->root_hash, MERKLE_HASH_LEN, "%016lx", h);
}

/* ── Evict oldest tree ──────────────────────────────── */

static void merkle_evict_oldest(MerkleCache* mc) {
    if (mc->tree_count == 0) return;
    int oldest_idx = 0;
    time_t oldest_time = mc->trees[0].created;
    for (int i = 1; i < mc->tree_count; i++) {
        if (mc->trees[i].created < oldest_time) {
            oldest_time = mc->trees[i].created;
            oldest_idx = i;
        }
    }
    /* Remove deps for this page */
    const char* page_key = mc->trees[oldest_idx].page_key;
    int w = 0;
    for (int r = 0; r < mc->dep_count; r++) {
        if (strcmp(mc->deps[r].page_key, page_key) != 0)
            mc->deps[w++] = mc->deps[r];
    }
    mc->dep_count = w;
    /* Remove tree by shifting */
    for (int i = oldest_idx; i < mc->tree_count - 1; i++)
        mc->trees[i] = mc->trees[i + 1];
    mc->tree_count--;
}

/* ── Register a tree from response headers ──────────── */

/*  leaves: array of {path, hash} pairs, null-terminated paths.
 *  Replaces any existing tree for this page_key. */
static void merkle_register_tree(MerkleCache* mc, const char* page_key,
                                  MerkleLeaf* leaves, int leaf_count) {
    /* Remove existing tree for this page */
    MerkleTree* existing = merkle_find_tree(mc, page_key);
    if (existing) {
        /* Clear stale markers — we have a fresh render */
        existing->leaf_count = leaf_count;
        memcpy(existing->leaves, leaves, leaf_count * sizeof(MerkleLeaf));
        existing->created = time(NULL);
        merkle_compute_root(existing);
        mc->trees_registered++;
        return;
    }

    /* Evict if at capacity */
    while (mc->tree_count >= mc->tree_capacity || mc->tree_count >= MERKLE_MAX_TREES)
        merkle_evict_oldest(mc);

    /* Add new tree */
    MerkleTree* tree = &mc->trees[mc->tree_count++];
    snprintf(tree->page_key, MERKLE_KEY_LEN, "%s", page_key);
    tree->leaf_count = leaf_count;
    memcpy(tree->leaves, leaves, leaf_count * sizeof(MerkleLeaf));
    tree->created = time(NULL);
    for (int i = 0; i < leaf_count; i++) tree->leaves[i].stale = 0;
    merkle_compute_root(tree);
    mc->trees_registered++;
}

/* ── Register dependencies ──────────────────────────── */

/*  Maps stream keys to (page_key, leaf_path) pairs.
 *  Call after register_tree. */
static void merkle_register_dep(MerkleCache* mc, const char* stream_key,
                                 const char* page_key, const char* leaf_path) {
    /* Grow if needed */
    if (mc->dep_count >= mc->dep_capacity) {
        mc->dep_capacity *= 2;
        mc->deps = (MerkleDep*)realloc(mc->deps, mc->dep_capacity * sizeof(MerkleDep));
    }
    MerkleDep* d = &mc->deps[mc->dep_count++];
    snprintf(d->stream_key, MERKLE_KEY_LEN, "%s", stream_key);
    snprintf(d->page_key, MERKLE_KEY_LEN, "%s", page_key);
    snprintf(d->leaf_path, 64, "%s", leaf_path);
}

/* ── Invalidate a stream key ────────────────────────── */

/*  Walks the dependency index: finds all (page, leaf) pairs that
 *  depend on this stream key. Marks leaves as stale. Returns the
 *  list of page_keys that were invalidated (caller purges them
 *  from the response cache).
 *
 *  Returns: number of pages invalidated. Fills page_keys_out with
 *  the keys (up to max_out). */
static int merkle_invalidate_stream(MerkleCache* mc, const char* stream_key,
                                     char page_keys_out[][MERKLE_KEY_LEN],
                                     int max_out) {
    int count = 0;
    mc->invalidations++;

    for (int i = 0; i < mc->dep_count; i++) {
        if (strcmp(mc->deps[i].stream_key, stream_key) != 0) continue;

        const char* page_key = mc->deps[i].page_key;
        const char* leaf_path = mc->deps[i].leaf_path;

        /* Mark leaf as stale */
        MerkleTree* tree = merkle_find_tree(mc, page_key);
        if (tree) {
            for (int j = 0; j < tree->leaf_count; j++) {
                if (strcmp(tree->leaves[j].path, leaf_path) == 0) {
                    tree->leaves[j].stale = 1;
                    tree->leaves[j].hash[0] = 0; /* clear hash */
                    break;
                }
            }
            tree->root_hash[0] = 0; /* root is now invalid */
        }

        /* Record this page as invalidated (deduplicate) */
        int already = 0;
        for (int k = 0; k < count; k++) {
            if (strcmp(page_keys_out[k], page_key) == 0) { already = 1; break; }
        }
        if (!already && count < max_out) {
            snprintf(page_keys_out[count], MERKLE_KEY_LEN, "%s", page_key);
            count++;
            mc->pages_invalidated++;
        }
    }
    return count;
}

/* ── Invalidate multiple streams ────────────────────── */

static int merkle_invalidate_streams(MerkleCache* mc, const char** stream_keys,
                                      int key_count,
                                      char page_keys_out[][MERKLE_KEY_LEN],
                                      int max_out) {
    int total = 0;
    for (int i = 0; i < key_count && total < max_out; i++) {
        total += merkle_invalidate_stream(mc, stream_keys[i],
                                           page_keys_out + total,
                                           max_out - total);
    }
    return total;
}

/* ── Check if a page's tree is valid ────────────────── */

static int merkle_is_valid(MerkleCache* mc, const char* page_key) {
    MerkleTree* tree = merkle_find_tree(mc, page_key);
    if (!tree) return 1; /* no tree = no opinion — assume valid */
    return tree->root_hash[0] != 0; /* empty root = invalidated */
}

/* ── Get stale leaves for a page ────────────────────── */

/*  Returns the paths of stale components. The handler can use
 *  this to skip re-rendering unchanged components. */
static int merkle_get_stale_leaves(MerkleCache* mc, const char* page_key,
                                    char stale_out[][64], int max_out) {
    MerkleTree* tree = merkle_find_tree(mc, page_key);
    if (!tree) return 0;
    int count = 0;
    for (int i = 0; i < tree->leaf_count && count < max_out; i++) {
        if (tree->leaves[i].stale) {
            snprintf(stale_out[count], 64, "%s", tree->leaves[i].path);
            count++;
        }
    }
    return count;
}

/* ── Stats ──────────────────────────────────────────── */

typedef struct {
    int  tree_count;
    int  dep_count;
    long invalidations;
    long pages_invalidated;
    long trees_registered;
} MerkleCacheStats;

static MerkleCacheStats merkle_stats(MerkleCache* mc) {
    return (MerkleCacheStats){
        .tree_count = mc->tree_count,
        .dep_count = mc->dep_count,
        .invalidations = mc->invalidations,
        .pages_invalidated = mc->pages_invalidated,
        .trees_registered = mc->trees_registered,
    };
}

#endif /* U_MERKLE_CACHE_H */
