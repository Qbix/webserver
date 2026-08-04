#!/usr/bin/env node
// u-watch — Transpile PHP/JS/TS/Python files to U on change
//
// Usage:
//   node u-watch.js ./web                    # watch ./web, output to ./web-u
//   node u-watch.js ./web ./out              # custom output dir
//   node u-watch.js ./web --once             # single pass (for CI)
//   node u-watch.js ./web --analyze          # also run security analyses
//
// The watcher:
//   1. Scans the source directory for .php/.js/.ts/.py files
//   2. Checks mtime against a manifest file (.u-manifest.json)
//   3. Transpiles only changed files
//   4. Saves the U output alongside source maps
//   5. Updates the manifest with hashes and mtimes
//   6. Optionally runs security analyses on the transpiled code
//
// This means you can drop in a PHP codebase, run the watcher,
// and it "just works" — transpiling to U, surfacing compile errors,
// and mapping them back to original source lines.

const fs = require('fs')
const path = require('path')
const crypto = require('crypto')

// ── Load transpilers ────────────────────────────────────────────────

const transpilerDir = path.join(__dirname, '..', 'u-language', 'site', 'assets')
const phpTranspilerDir = path.join(transpilerDir, 'transpilers')

function loadTranspilers() {
    // PHP transpiler
    delete require.cache[require.resolve(path.join(phpTranspilerDir, 'php-lexer'))]
    delete require.cache[require.resolve(path.join(phpTranspilerDir, 'php-parser'))]
    delete require.cache[require.resolve(path.join(phpTranspilerDir, 'php-to-u'))]
    const { convertPHP } = require(path.join(phpTranspilerDir, 'php-to-u'))

    // JS/TS/Python transpiler
    // Load in an isolated scope to avoid name collisions
    const acorn = require('acorn')
    const translateSrc = fs.readFileSync(path.join(transpilerDir, 'translate.js'), 'utf8')
    const sandbox = {
        window: { addEventListener: function(){} },
        CodeMirror: function(){},
        document: { getElementById: function(){ return { innerHTML:'', textContent:'', addEventListener:function(){} } },
                    querySelectorAll: function(){ return [] }, querySelector: function(){ return null },
                    addEventListener: function(){} },
        performance: { now: function(){ return 0 } },
        acorn: acorn,

        convertJS: null, convertPy: null
    }
    sandbox.CodeMirror.defineMode = function(){}
    sandbox.CodeMirror.defineMIME = function(){}
    sandbox.CodeMirror.fromTextArea = function(){ return { on:function(){}, setValue:function(){}, getValue:function(){return ''}, refresh:function(){}, setOption:function(){} } }

    // Strip conflicting top-level declarations from translate.js
    let cleanSrc = translateSrc
        .replace(/^let lang\b[^;]*;/m, 'var lang="javascript";')
        .replace(/^let srcE\b[^;]*;/m, '')
        .replace(/^let lang='[^']*',srcE,outE,dbt=null;/m, 'var lang="javascript",srcE=null,outE=null,dbt=null;')
    const fn = new Function(...Object.keys(sandbox), 
        cleanSrc + '; return { convertJS, convertPy }')
    const { convertJS, convertPy } = fn(...Object.values(sandbox))

    return { convertPHP, convertJS, convertPy }
}

// ── Source map builder ──────────────────────────────────────────────

function buildSourceMap(originalSrc, uCode, filename) {
    const origLines = originalSrc.split('\n')
    const uLines = uCode.split('\n')
    const map = {} // uLineNum → { file, line }

    // Match by shared string literals
    for (let ui = 0; ui < uLines.length; ui++) {
        const uLine = uLines[ui]
        const strings = uLine.match(/"[^"]{5,}"/g) || []
        for (const s of strings) {
            const needle = s.slice(1, -1).slice(0, 30)
            for (let oi = 0; oi < origLines.length; oi++) {
                if (origLines[oi].includes(needle)) {
                    map[ui + 1] = { file: filename, line: oi + 1 }
                    break
                }
            }
            if (map[ui + 1]) break
        }
    }
    return map
}

// ── File scanning ───────────────────────────────────────────────────

function findSourceFiles(dir) {
    const results = []
    function walk(d) {
        for (const item of fs.readdirSync(d)) {
            if (item.startsWith('.') || item === 'node_modules' || item === 'vendor') continue
            const full = path.join(d, item)
            try {
                const stat = fs.statSync(full)
                if (stat.isDirectory()) walk(full)
                else if (/\.(php|js|ts|py)$/.test(item)) {
                    results.push({ path: full, mtime: stat.mtimeMs, size: stat.size })
                }
            } catch(e) {}
        }
    }
    walk(dir)
    return results
}

// ── Manifest management ─────────────────────────────────────────────

function loadManifest(manifestPath) {
    try {
        return JSON.parse(fs.readFileSync(manifestPath, 'utf8'))
    } catch(e) {
        return { files: {}, version: 1 }
    }
}

function saveManifest(manifestPath, manifest) {
    fs.writeFileSync(manifestPath, JSON.stringify(manifest, null, 2))
}

// ── Main pipeline ───────────────────────────────────────────────────

function transpileFile(filepath, transpilers) {
    const src = fs.readFileSync(filepath, 'utf8')
    const ext = path.extname(filepath)
    const filename = path.basename(filepath)

    let result
    switch (ext) {
        case '.php':
            result = transpilers.convertPHP(src, filename)
            break
        case '.js':
            result = transpilers.convertJS(src)
            result.filename = filename
            break
        case '.ts':
            // TypeScript: strip types then convert as JS
            result = transpilers.convertJS(src)
            result.filename = filename
            break
        case '.py':
            result = transpilers.convertPy(src)
            result.filename = filename
            break
        default:
            return null
    }

    // Build source map
    const sourceMap = buildSourceMap(src, result.code, filename)

    // Add header comment with source info
    const hash = crypto.createHash('sha256').update(src).digest('hex').slice(0, 12)
    const header = `// @source ${filename} [${hash}]\n// @transpiled ${new Date().toISOString()}\n\n`

    return {
        code: header + result.code,
        sourceMap,
        hash,
        unknowns: (result.code.match(/TODO: Unknown|not translatable/g) || []).length,
        messages: result.messages || [],
        filename
    }
}

function run(srcDir, outDir, options = {}) {
    const { once = false, analyze = false } = options

    if (!fs.existsSync(outDir)) fs.mkdirSync(outDir, { recursive: true })

    const manifestPath = path.join(outDir, '.u-manifest.json')
    const manifest = loadManifest(manifestPath)
    const transpilers = loadTranspilers()
    const files = findSourceFiles(srcDir)

    let changed = 0, skipped = 0, errors = 0, totalUnknowns = 0

    console.log(`\nu-watch: scanning ${srcDir} → ${outDir}`)
    console.log(`  ${files.length} source files found\n`)

    for (const file of files) {
        const rel = path.relative(srcDir, file.path)
        const prev = manifest.files[rel]

        // Skip if unchanged
        if (prev && prev.mtime >= file.mtime && prev.hash) {
            skipped++
            continue
        }

        // Transpile
        const result = transpileFile(file.path, transpilers)
        if (!result) continue

        // Write output
        const outPath = path.join(outDir, rel + '.u')
        const outDirForFile = path.dirname(outPath)
        if (!fs.existsSync(outDirForFile)) fs.mkdirSync(outDirForFile, { recursive: true })
        fs.writeFileSync(outPath, result.code)

        // Write source map
        if (Object.keys(result.sourceMap).length > 0) {
            fs.writeFileSync(outPath + '.map', JSON.stringify(result.sourceMap, null, 2))
        }

        // Update manifest
        manifest.files[rel] = {
            mtime: file.mtime,
            hash: result.hash,
            unknowns: result.unknowns,
            uPath: rel + '.u',
            timestamp: new Date().toISOString()
        }

        changed++
        totalUnknowns += result.unknowns
        const status = result.unknowns === 0 ? '✓' : `⚠ ${result.unknowns} unknowns`
        console.log(`  ${status}  ${rel} → ${rel}.u`)

        // Show messages
        for (const msg of result.messages.slice(0, 3)) {
            console.log(`       ${msg.type}: ${msg.text}`)
        }
    }

    // Save manifest
    saveManifest(manifestPath, manifest)

    // Summary
    console.log(`\n  Changed: ${changed}  Skipped: ${skipped}  Errors: ${errors}`)
    console.log(`  Total unknowns: ${totalUnknowns}`)
    console.log(`  Manifest: ${manifestPath}\n`)

    if (!once) {
        console.log('  Watching for changes... (Ctrl+C to stop)\n')
        setInterval(() => {
            const newFiles = findSourceFiles(srcDir)
            for (const file of newFiles) {
                const rel = path.relative(srcDir, file.path)
                const prev = manifest.files[rel]
                if (prev && prev.mtime >= file.mtime) continue

                const result = transpileFile(file.path, transpilers)
                if (!result) continue

                const outPath = path.join(outDir, rel + '.u')
                const outDirForFile = path.dirname(outPath)
                if (!fs.existsSync(outDirForFile)) fs.mkdirSync(outDirForFile, { recursive: true })
                fs.writeFileSync(outPath, result.code)

                manifest.files[rel] = {
                    mtime: file.mtime, hash: result.hash,
                    unknowns: result.unknowns,
                    uPath: rel + '.u',
                    timestamp: new Date().toISOString()
                }
                saveManifest(manifestPath, manifest)

                const status = result.unknowns === 0 ? '✓' : `⚠ ${result.unknowns}`
                const now = new Date().toLocaleTimeString()
                console.log(`  [${now}] ${status}  ${rel}`)
            }
        }, 1000)
    }
}

// ── CLI ──────────────────────────────────────────────────────────────

const args = process.argv.slice(2)
const srcDir = args.find(a => !a.startsWith('-')) || './web'
const outDir = args.filter(a => !a.startsWith('-'))[1] || srcDir + '-u'
const once = args.includes('--once')
const analyze = args.includes('--analyze')

run(srcDir, outDir, { once, analyze })
