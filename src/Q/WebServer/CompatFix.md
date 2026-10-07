# Postmortem: JIT String-Doubling in `transformSource()`

**Date:** 2026-10-06
**Severity:** P1 — session cookies and session data broken in CI
**Status:** Resolved

## Summary

Three CI tests failed: session cookies were missing, session IDs changed to `"syntax"`, and session data didn't persist. The root cause was a PHP 8.3 tracing JIT bug that doubled the content of a string variable when the JIT compiler kicked in mid-loop. The transformed PHP source came out with its first lines duplicated, producing a parse error that silently broke session handling. The fix replaces string concatenation with array accumulation in `transformSource()`.

## Timeline

1. Commit `4103371` (Sep 29) added an opcache.enable_cli re-exec block to `qbixserver.php` (lines 32–58). If opcache isn't enabled for CLI mode, the server re-executes itself with `-d opcache.enable_cli=1`. In CI, `shivammathur/setup-php` pre-configures `opcache.jit=1235` in php.ini, so the re-exec activates the tracing JIT.
2. CI tests that depend on sessions began failing. The transformed source for files containing `session_start()`, `header()`, or `setcookie()` came out corrupted — the first N bytes of output were duplicated in place, producing invalid PHP that triggered a `ParseError`.
3. The `ParseError` was caught silently by the compat layer's stream wrapper, so the file loaded as empty. Session and cookie shims never registered. Tests saw no session cookie, and `session_id()` returned `"syntax"` (a fragment of the parse error message leaking into the session name).

## Impact

Any PHP file processed by `transformSource()` whose token loop ran long enough to cross the JIT's hot-loop threshold came out with corrupted source. In practice this meant the compat shims for `header()`, `setcookie()`, and `session_start()` were silently absent, breaking cookie and session handling. The bug only manifested under `opcache.jit=1235` (tracing mode with optimization level 5), which is the default set by `setup-php` in CI.

## Root Cause

### The code path

`Q_WebServer_Compat::transformSource()` uses `token_get_all()` to break PHP source into tokens, then iterates over them in a `for` loop, building a rewritten source string by concatenating each token:

```php
$out = '';
for ($i = 0; $i < $count; $i++) {
    $token = $tokens[$i];
    if (!is_array($token)) {
        $out .= $token;
        continue;
    }
    // ... branch per token type ...
    $out .= $token[1];
}
$result = $changed ? $out : $source;
```

There are 12 distinct `$out .= ...` sites across the branches (require/include rewriting, function replacement, constant replacement, namespace-qualified names, and the default passthrough).

### How the tracing JIT compiles a loop

PHP 8.3's tracing JIT (mode `1235`: `1`=AVX, `2`=register allocation, `3`=tracing, `5`=max optimization) works by watching loops at runtime:

1. **Counting.** Every loop back-edge has a counter. Each time the interpreter jumps back to the loop header, the counter increments.

2. **Recording.** When the counter hits the `opcache.jit_hot_loop` threshold (default: 64 iterations), the interpreter stops and the JIT records a *trace* — the exact sequence of opcodes executed through one complete loop iteration, including which branch was taken at every conditional.

3. **Compiling.** The trace is compiled to native x86-64 machine code. The compiler builds an SSA (Static Single Assignment) form of the trace. At the loop header, every variable that was live before compilation and is also modified inside the loop gets a *phi node* — a merge point that says "this variable's value is either what it was before the trace started, or what the previous iteration produced."

4. **On-Stack Replacement (OSR).** The interpreter's stack frame is handed off to the compiled code. The compiled trace resumes execution at the loop header, picking up the values of all local variables from the interpreter's frame.

### Where it goes wrong

For the `$out` variable, the trace SSA looks like this:

```
#35.CV5($out) [rc1, rcn, string] = Phi(#30.CV5($out), #239.CV5($out))
                                        ↑ pre-trace       ↑ post-iteration
#239.CV5($out) = Phi(#55, #233, #236, #206, #209, #76, #115, #120, #145, #148, #154, #181)
                     ↑    ↑     ↑     ...
                     (one SSA variable per $out .= ... site)
```

The outer phi (#35) merges two sources: `#30` is the value of `$out` captured from the interpreter at OSR entry; `#239` is the value after a compiled iteration completes. The inner phi (#239) has 12 inputs, one for each `$out .= ...` path through the loop body.

At OSR entry, the JIT emits a `ZREG_LOAD` to capture `$out` from the interpreter's stack frame into a register. The compiled concat helper, `zend_jit_fast_assign_concat_helper()`, does an in-place `perealloc` when the string's refcount is 1 — it extends the buffer and copies the new piece onto the end. The bug is in how the phi node resolves after OSR: the first concat operation in the compiled code operates on `$out` as though it were starting from the value at trace entry, but the buffer has already been extended. The effect is that the content accumulated before trace entry (the first 64 iterations' worth of tokens) is replayed — the string's first N bytes appear twice.

### Experimental proof

We confirmed the mechanism with controlled experiments:

| `opcache.jit_hot_loop` | Bytes before doubling | Doubling present? |
|---|---|---|
| 32 | 76 | Yes |
| 48 | 126 | Yes |
| 64 | 144 | Yes |
| 96 | 217 | Yes |
| 128 | 288 | Yes |

The duplicated prefix length scales linearly with the hot-loop threshold — exactly as predicted if the bug fires at the OSR transition point.

Setting `opcache.jit_hot_func=1` (compile the whole function before the first call, so there is no mid-loop OSR) eliminates the corruption entirely. Setting `opcache.jit=1205` (function-level JIT, mode `0`, instead of tracing mode `3`) also eliminates it. Only tracing JIT with OSR triggers the bug.

Simple reproduction scripts with plain concat loops do not trigger it. The function must be complex enough — many branches, nested conditions, static method calls — to produce a trace with 12+ phi inputs for the accumulator variable. `transformSource()` has exactly that structure.

### Why the error was silent

The compat layer's `Q_WebServer_CompatFileWrapper` stream wrapper catches exceptions when evaluating transformed source. A `ParseError` from the doubled content was caught, and the file effectively loaded as empty. No error was logged to a place the CI tests checked. The only symptom was that the compat shims (`Q_WebServer_Compat::_header()`, `_setcookie()`, `_session_start()`) were never registered, so the test's `session_start()` call went to PHP's built-in implementation, which doesn't work correctly under the webserver's architecture.

## Fix

Replace string concatenation with array accumulation. Instead of building `$out` by appending strings, assign each token to `$out[$i]` (keyed by the token's loop index) and `implode('', $out)` at the end:

```php
// Before
$out = '';
// ...
$out .= $token;
// ...
$out = substr($out, 0, -1); // strip trailing backslash
// ...
$result = $changed ? $out : $source;

// After
$out = array();
// ...
$out[$i] = $token;
// ...
$out[$prevIdx] = ''; // zero out the backslash token directly
// ...
$result = $changed ? implode('', $out) : $source;
```

Array assignment (`$out[$i] = $value`) uses a different code path in the JIT — `ASSIGN_DIM` rather than `ASSIGN_CONCAT` — with different register allocation behavior at trace entry. The phi node for an array variable doesn't produce the stale-reference problem because the array's internal hash table is modified in place rather than reallocated and appended.

**File changed:** `src/Q/WebServer/Compat.php`
**Lines changed:** 13 (all inside `transformSource()`, lines 346–490)
**Behavioral change:** None. The output is byte-identical for all inputs. The backslash-strip is slightly cleaner (zeroes the separator token by index instead of truncating the accumulated string), but produces the same result.

## Why not fix the JIT instead?

This is a bug in PHP's tracing JIT compiler, not in our code. It could be reported to [bugs.php.net](https://bugs.php.net). However:

- The bug requires a very specific combination of function complexity, loop length, and concat pattern to trigger. A minimal reproducer that the PHP team could act on would be difficult to extract — our attempts to build one from scratch all failed; only the real `transformSource()` triggered it.
- Even if fixed upstream, the fix would only land in a future PHP release. CI runs on whatever `setup-php` provides today.
- The array-based approach is no slower in practice (the token count is small, and `implode` is a single C-level pass), and it's arguably clearer — each token occupies its original index.

## Lessons

1. **`opcache.jit=1235` is not a safe default for string-heavy token loops.** The tracing JIT's OSR transition can corrupt string accumulators in complex functions. If you concatenate in a loop with many branches, consider array accumulation instead.

2. **Silent error handling hides corruption.** The stream wrapper's catch-all meant the parse error never surfaced as an error — it surfaced as missing functionality three layers up. Logging or re-throwing parse errors from the wrapper would have cut diagnosis time significantly.

3. **CI environment changes can activate latent code paths.** The re-exec block that enabled opcache for CLI was the trigger. The JIT was always configured in CI's php.ini; the re-exec just started honoring it. Changes to process startup deserve the same scrutiny as changes to application logic.

4. **Scaling experiments beat source-code reading for JIT bugs.** Reading the JIT compiler source (trace SSA construction, phi nodes, register allocation) gave us a hypothesis, but the hot-loop threshold sweep — showing that doubled-prefix length scales linearly with the threshold — is what confirmed the mechanism. The JIT's compiled output is too complex to reason about statically with confidence.
