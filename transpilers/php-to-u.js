/* php-to-u.js — Convert PHP AST to U source code.
 *
 * Handles: functions → f, classes → d, $this → t, arrays → maps/lists,
 * type hints → U types, try/catch → x.on(), foreach → .on(),
 * static methods → +G, echo → log(), isset → != none, etc.
 *
 * Design: mechanical translation with fixup notes. The output compiles
 * in U's parser; correctness may need manual adjustment for PHP
 * patterns that have no 1:1 U equivalent (mutable arrays, global state).
 */

;(function (exports) {
'use strict'

// ── Type mapping ──────────────────────────────────────────────

const TYPE_MAP = {
  'int': 'I', 'integer': 'I', 'float': 'N', 'double': 'N',
  'string': 'S', 'bool': 'L', 'boolean': 'L',
  'array': '{S: Tree}', 'void': 'none', 'null': 'none',
  'mixed': 'Tree', 'self': 't', 'static': 't',
  'callable': '(Tree) -> Tree', 'object': 'Tree',
}

function mapType (phpType) {
  if (!phpType) return null
  const t = phpType.toLowerCase()
  return TYPE_MAP[t] || phpType
}

// ── Name conversion ───────────────────────────────────────────

function phpVarToU (name) {
  // PHP $camelCase → U snake_case (optional, keep camelCase for now)
  // Ensure at least 2 chars (U requirement)
  if (name.length === 1) return name + name // $x → xx
  return name
}

function phpClassToU (name) {
  // Q_Config → Q.Config, Q_Uri → Q.Uri
  return name.replace(/_/g, '.')
}

// ── PHP built-in function mapping ─────────────────────────────

const FUNC_MAP = {
  'strlen': { u: '.len', method: true },
  'count': { u: '.len', method: true },
  'substr': { u: '.slice', method: true },
  'strpos': { u: '.index_of', method: true },
  'str_replace': { u: '.replace', method: true, reorder: [2, 0, 1] },
  'strtolower': { u: '.lower', method: true },
  'strtoupper': { u: '.upper', method: true },
  'trim': { u: '.trim', method: true },
  'ltrim': { u: '.trim_start', method: true },
  'rtrim': { u: '.trim_end', method: true },
  'explode': { u: '.split', method: true, reorder: [1, 0] },
  'implode': { u: '.join', method: true, reorder: [1, 0] },
  'in_array': { u: '.contains', method: true, reorder: [1, 0] },
  'array_push': { u: '.push', method: true, reorder: [0, 1] },
  'array_pop': { u: '.pop', method: true },
  'array_map': { u: '.map', method: true, reorder: [1, 0] },
  'array_filter': { u: '.filter', method: true, reorder: [1, 0] },
  'array_merge': { u: '+', binary: true },
  'array_keys': { u: '.keys', method: true },
  'array_values': { u: '.values', method: true },
  'json_encode': { u: 'json.encode', func: true },
  'json_decode': { u: 'json.decode', func: true },
  'file_get_contents': { u: 'filesystem.read', func: true },
  'file_put_contents': { u: 'filesystem.write', func: true },
  'is_null': { u: '== none', postfix: true },
  'is_string': { u: ':: S', postfix: true },
  'is_int': { u: ':: I', postfix: true },
  'is_array': { u: ':: {S: Tree}', postfix: true },
  'is_numeric': { u: ':: N', postfix: true },
  'intval': { u: '.__int__', method: true },
  'floatval': { u: '.__float__', method: true },
  'strval': { u: '.__string__', method: true },
  'var_dump': { u: 'log', func: true },
  'print_r': { u: 'log', func: true },
  'sprintf': { u: null, custom: true },
  'preg_match': { u: null, custom: true },
  'preg_replace': { u: null, custom: true },
  'preg_match_all': { u: null, custom: true },
  'preg_split': { u: null, custom: true },
  'array_walk': { u: '.on', method: true, note: 'callback args are (val, key)' },
  'array_walk_recursive': { u: '.on_recursive', method: true },
  'array_key_exists': { u: '.has', method: true, reorder: [1, 0] },
  'array_slice': { u: '.slice', method: true, reorder: [0, 1, 2] },
  'array_unique': { u: '.unique', method: true },
  'array_reverse': { u: '.reverse', method: true },
  'array_column': { u: '.pluck', method: true, reorder: [0, 1] },
  'array_combine': { u: null, custom: true },
  'array_splice': { u: '.splice', method: true },
  'array_shift': { u: '.shift', method: true },
  'array_unshift': { u: '.unshift', method: true },
  'array_chunk': { u: '.chunk', method: true, reorder: [0, 1] },
  'array_search': { u: '.index_of', method: true, reorder: [1, 0] },
  'array_diff': { u: '.diff', method: true },
  'array_intersect': { u: '.intersect', method: true },
  'array_flip': { u: '.flip', method: true },
  'array_sum': { u: '.sum', method: true },
  'sort': { u: '.sort', method: true },
  'usort': { u: '.sort', method: true },
  'ksort': { u: '.sort_keys', method: true },
  'str_contains': { u: '.contains', method: true, reorder: [0, 1] },
  'str_starts_with': { u: '.starts_with', method: true, reorder: [0, 1] },
  'str_ends_with': { u: '.ends_with', method: true, reorder: [0, 1] },
  'str_pad': { u: '.pad', method: true },
  'str_repeat': { u: '.repeat', method: true, reorder: [0, 1] },
  'substr_count': { u: '.count', method: true, reorder: [0, 1] },
  'ucfirst': { u: '.capitalize', method: true },
  'lcfirst': { u: '.uncapitalize', method: true },
  'nl2br': { u: '.replace', method: true },
  'htmlspecialchars': { u: '.html_escape', method: true },
  'htmlentities': { u: '.html_escape', method: true },
  'urlencode': { u: '.url_encode', method: true },
  'urldecode': { u: '.url_decode', method: true },
  'rawurlencode': { u: '.url_encode', method: true },
  'rawurldecode': { u: '.url_decode', method: true },
  'base64_encode': { u: 'encoding.base64_encode', func: true },
  'base64_decode': { u: 'encoding.base64_decode', func: true },
  'md5': { u: 'Crypto.md5', func: true },
  'sha1': { u: 'Crypto.sha1', func: true },
  'hash': { u: 'Crypto.hash', func: true },
  'hash_hmac': { u: 'Crypto.hmac', func: true },
  'crypt': { u: 'Crypto.crypt', func: true },
  'password_hash': { u: 'Crypto.password_hash', func: true },
  'password_verify': { u: 'Crypto.password_verify', func: true },
  'password_needs_rehash': { u: 'Crypto.password_needs_rehash', func: true },
  'openssl_random_pseudo_bytes': { u: 'Crypto.random_bytes', func: true },
  'openssl_encrypt': { u: 'Crypto.encrypt', func: true },
  'openssl_decrypt': { u: 'Crypto.decrypt', func: true },
  'openssl_sign': { u: 'Crypto.sign', func: true },
  'openssl_verify': { u: 'Crypto.verify', func: true },
  'mcrypt_create_iv': { u: 'Crypto.random_bytes', func: true },
  'chr': { u: 'S.from_byte', func: true },
  'ord': { u: '.byte', method: true },
  'pack': { u: 'encoding.pack', func: true },
  'unpack': { u: 'encoding.unpack', func: true },
  'hex2bin': { u: 'encoding.hex_decode', func: true },
  'bin2hex': { u: 'encoding.hex_encode', func: true },
  'http_build_query': { u: 'Q.Uri.encode_query', func: true },
  'parse_url': { u: 'Q.Uri.parse', func: true },
  'parse_str': { u: 'Q.Uri.parse_query', func: true },
  'header': { u: 'Q.Response.header', func: true },
  'setcookie': { u: 'Q.Response.set_cookie', func: true },
  'session_start': { u: 'Q.Session.start', func: true },
  'session_id': { u: 'Q.Session.id', func: true },
  'function_exists': { u: 'reflect.has_function', func: true },
  'class_exists': { u: 'reflect.has_class', func: true },
  'defined': { u: 'reflect.has_const', func: true },
  'define': { u: null, custom: true },
  'random_bytes': { u: 'Crypto.random_bytes', func: true },
  'time': { u: 'time.now', func: true },
  'microtime': { u: 'time.now_ms', func: true },
  'date': { u: 'time.format', func: true },
  'strtotime': { u: 'time.parse', func: true },
  'ceil': { u: 'math.ceil', func: true },
  'floor': { u: 'math.floor', func: true },
  'round': { u: 'math.round', func: true },
  'abs': { u: 'math.abs', func: true },
  'min': { u: 'math.min', func: true },
  'max': { u: 'math.max', func: true },
  'pow': { u: 'math.pow', func: true },
  'sqrt': { u: 'math.sqrt', func: true },
  'log': { u: 'math.log', func: true },
  'rand': { u: 'math.rand', func: true },
  'mt_rand': { u: 'math.rand', func: true },
  'number_format': { u: null, custom: true },
  'class_exists': { u: null, comment: '// class_exists — use :: type check in U' },
  'method_exists': { u: null, comment: '// method_exists — use :: or reflect in U' },
  'property_exists': { u: null, comment: '// property_exists — use .has() or reflect in U' },
  'get_class': { u: null, comment: '// get_class — use :: or reflect in U' },
  'is_callable': { u: null, comment: '// is_callable — check type in U' },
  'call_user_func': { u: null, custom: true },
  'call_user_func_array': { u: null, custom: true },
  'extract': { u: null, custom: true },
}

// ── Regex literal parsing ─────────────────────────────────────

function parseRegexLiteral (node) {
  // PHP regex: '/pattern/flags' → { pattern, flags }
  if (node && node.type === 'String') {
    const raw = node.value
    const m = raw.match(/^\/(.+)\/([gimsux]*)$/)
    if (m) return { pattern: m[1], flags: m[2] }
    // Also handle # delimiters
    const m2 = raw.match(/^#(.+)#([gimsux]*)$/)
    if (m2) return { pattern: m2[1], flags: m2[2] }
    return { pattern: raw, flags: '' }
  }
  return { pattern: emitExpr(node), flags: '' }
}

// ── Emitter ───────────────────────────────────────────────────

function emit (node, indent) {
  if (!node) return ''
  indent = indent || ''
  const tab = indent + '\t'

  switch (node.type) {
    case 'Program':
      return node.body.map(n => emit(n, '')).filter(Boolean).join('\n\n')

    case 'FunctionDecl': {
      const params = node.params.map(p => {
        const t = mapType(p.typeHint)
        const name = phpVarToU(p.name)
        let s = t ? `${name}: ${t}` : name
        if (p.nullable) s = t ? `${name}: ${t} +N` : `${name} +N`
        return s
      }).join(', ')
      const ret = node.returnType ? ` -> ${mapType(node.returnType.type)}` : ''
      const body = node.body.map(s => emit(s, tab)).filter(Boolean).join('\n')
      return `f ${node.name}(${params})${ret}\n${body}`
    }

    case 'ClassDecl': {
      const name = phpClassToU(node.name)
      const parent = node.parent ? ` : ${phpClassToU(node.parent)}` : ''
      const members = node.body.map(m => emit(m, tab)).filter(Boolean).join('\n\n')
      return `d ${name}${parent}\n\n${members}`
    }

    case 'MethodDecl': {
      const isStatic = node.mods.includes('static')
      const isPrivate = node.mods.includes('private')
      const mods = isStatic ? '+G' : ''
      const prefix = isPrivate ? '_' : ''
      const params = node.params.map(p => {
        const t = mapType(p.typeHint)
        const name = phpVarToU(p.name)
        return t ? `${name}: ${t}` : name
      }).join(', ')
      const ret = node.returnType ? ` -> ${mapType(node.returnType.type)}` : ''
      const body = node.body.map(s => emit(s, tab)).filter(Boolean).join('\n')
      if (!body) return `${indent}f${mods} ${prefix}${node.name}(${params})${ret}\n${tab}...`
      return `${indent}f${mods} ${prefix}${node.name}(${params})${ret}\n${body}`
    }

    case 'PropertyDecl': {
      const t = mapType(node.typeHint) || 'Tree'
      const isStatic = node.mods.includes('static')
      const isPrivate = node.mods.includes('private')
      const mods = isStatic ? ' +G +M' : ' +M'
      const prefix = isPrivate ? '_' : ''
      const def = node.value ? ` = ${emitExpr(node.value)}` : ''
      return `${indent}${prefix}${node.name}: ${t}${mods}${def}`
    }

    case 'ClassConst': {
      return `${indent}${node.name}: Tree -M = ${emitExpr(node.value)}`
    }

    case 'If': {
      const test = emitExpr(node.test)
      const body = node.body.map(s => emit(s, tab)).filter(Boolean).join('\n')
      let out = `${indent}${test} ? (\n${body}\n${indent})`
      if (node.elseBody) {
        const eb = node.elseBody.map(s => emit(s, tab)).filter(Boolean).join('\n')
        out += ` ! (\n${eb}\n${indent})`
      }
      return out
    }

    case 'While': {
      const test = emitExpr(node.test)
      const body = node.body.map(s => emit(s, tab)).filter(Boolean).join('\n')
      return `${indent}// while ${test}\n${indent}${test} ? (\n${body}\n${indent})`
    }

    case 'Foreach': {
      const expr = emitExpr(node.expr)
      const val = phpVarToU(node.value)
      const body = node.body.map(s => emit(s, tab + '\t')).filter(Boolean).join('\n')
      if (node.key) {
        return `${indent}${expr}.on((${phpVarToU(node.key)}, ${val}) => (\n${body}\n${tab}none\n${indent}))`
      }
      return `${indent}${expr}.on(${val} => (\n${body}\n${tab}none\n${indent}))`
    }

    case 'For': {
      const init = node.init ? emitExpr(node.init) : ''
      const test = node.test ? emitExpr(node.test) : 'true'
      const body = node.body.map(s => emit(s, tab)).filter(Boolean).join('\n')
      return `${indent}${init}\n${indent}// for loop: ${test}\n${indent}${test} ? (\n${body}\n${indent})`
    }

    case 'Return': {
      const val = node.value ? emitExpr(node.value) : 'none'
      return `${indent}r => ${val}`
    }

    case 'Echo': {
      const args = node.args.map(emitExpr).join(' + ')
      return `${indent}log(${args})`
    }

    case 'Throw': {
      return `${indent}x ${emitExpr(node.expr)}`
    }

    case 'TryCatch': {
      if (node.catches.length === 0) {
        return node.body.map(s => emit(s, indent)).filter(Boolean).join('\n')
      }
      // Try to extract a single expression from the try body
      const tryStmts = node.body.filter(s => s.type !== 'Unknown')
      // Single assignment: result = expr → result = expr x.on(...)
      if (tryStmts.length === 1 && tryStmts[0].type === 'ExprStmt' &&
          tryStmts[0].expr.type === 'Assign') {
        const assign = tryStmts[0].expr
        const lhs = emitExpr(assign.left)
        const rhs = emitExpr(assign.right)
        if (node.catches.length === 1 && node.catches[0].body.length <= 2) {
          // Single catch with simple body → x fallback or x.on()
          const c = node.catches[0]
          const catchStmts = c.body.filter(s => s.type !== 'Unknown')
          const lastStmt = catchStmts[catchStmts.length - 1]
          // If catch just assigns same var: result = fallback
          if (lastStmt && lastStmt.type === 'ExprStmt' &&
              lastStmt.expr.type === 'Assign' &&
              emitExpr(lastStmt.expr.left) === lhs) {
            const fallback = emitExpr(lastStmt.expr.right)
            return `${indent}${lhs} = ${rhs} x.on(\n${tab}(${phpVarToU(c.name)}: ${phpClassToU(c.type)}) => ${fallback}\n${indent})`
          }
          // If catch returns: → x.on(handler)
          if (lastStmt && lastStmt.type === 'Return') {
            const fallback = emitExpr(lastStmt.value)
            return `${indent}${lhs} = ${rhs} x.on(\n${tab}(${phpVarToU(c.name)}: ${phpClassToU(c.type)}) => ${fallback}\n${indent})`
          }
        }
        // Multiple catches → x.on(handler1, handler2)
        const handlers = node.catches.map(c => {
          const catchBody = c.body.map(s => emit(s, tab + '\t')).filter(Boolean).join('\n')
          return `${tab}(${phpVarToU(c.name)}: ${phpClassToU(c.type)}) => (\n${catchBody}\n${tab})`
        }).join(',\n')
        return `${indent}${lhs} = ${rhs} x.on(\n${handlers}\n${indent})`
      }
      // Single return in try → return expr x.on(...)
      if (tryStmts.length === 1 && tryStmts[0].type === 'Return') {
        const val = emitExpr(tryStmts[0].value)
        const handlers = node.catches.map(c => {
          const catchBody = c.body.map(s => emit(s, tab + '\t')).filter(Boolean).join('\n')
          return `${tab}(${phpVarToU(c.name)}: ${phpClassToU(c.type)}) => (\n${catchBody}\n${tab})`
        }).join(',\n')
        return `${indent}r => ${val} x.on(\n${handlers}\n${indent})`
      }
      // Multi-statement try body → wrap in a block, attach x.on()
      const body = tryStmts.map(s => emit(s, tab)).filter(Boolean).join('\n')
      const handlers = node.catches.map(c => {
        const catchBody = c.body.map(s => emit(s, tab + '\t')).filter(Boolean).join('\n')
        return `${tab}(${phpVarToU(c.name)}: ${phpClassToU(c.type)}) => (\n${catchBody}\n${tab})`
      }).join(',\n')
      return `${indent}(\n${body}\n${indent}) x.on(\n${handlers}\n${indent})`
    }

    case 'Switch': {
      const expr = emitExpr(node.expr)
      const cases = node.cases.map(c => {
        if (c.type === 'Default') {
          const body = c.body.filter(s => s.type !== 'Break').map(s => emit(s, tab)).join('\n')
          return `${indent}! (\n${body}\n${indent})`
        }
        const val = emitExpr(c.value)
        const body = c.body.filter(s => s.type !== 'Break').map(s => emit(s, tab)).join('\n')
        return `${indent}${expr} == ${val} ? (\n${body}\n${indent})`
      }).join('\n')
      return `${indent}// switch → conditional chain\n${cases}`
    }

    case 'ExprStmt':
      return `${indent}${emitExpr(node.expr)}`

    case 'NamespaceBlock': {
      const body = (node.body || []).map(s => emit(s, indent)).filter(Boolean).join('\n\n')
      return body
    }

    case 'Use': case 'Namespace':
      return `${indent}// ${node.type.toLowerCase()} ${node.path}`

    case 'ConstDecl':
      return `${indent}${node.name} = ${emitExpr(node.value)}`

    case 'DoWhile': {
      const body = node.body.map(s => emit(s, tab)).filter(Boolean).join('\n')
      const test = emitExpr(node.test)
      return indent + '// do-while\n' + body + '\n' + indent + test + ' ? (\n' + body + '\n' + indent + ')'
    }

    case 'Break': return ''
    case 'Unset': {
      return node.args.map(a => indent + emitExpr(a) + ' = none').join('\n')
    }

    case 'Yield': {
      const val = node.value ? emitExpr(node.value) : 'none'
      return indent + 'w ' + val + '  // yield'
    }

    case 'Continue': return indent + '// continue'
    case 'Unknown': return ''

    default:
      return `${indent}// TODO: ${node.type}`
  }
}

function emitExpr (node) {
  if (!node) return 'none'

  switch (node.type) {
    case 'Number': return node.value
    case 'String': {
      const raw = node.value || ''
      // PHP interpolated string: "$name has {$arr['key']}" → `{{name}} has {{arr.get("key")}}`
      if ((node.quote === '"' || node.heredoc) && raw.includes('$')) {
        // Convert PHP interpolation to U template
        let result = raw
        // {$var['key']} → {{var.get("key")}}
        result = result.replace(/\{\$(\w+)\['([^']+)'\]\}/g, '{{$1.get("$2")}}')
        result = result.replace(/\{\$(\w+)\["([^"]+)"\]\}/g, '{{$1.get("$2")}}')
        // {$var->prop} → {{var.prop}}
        result = result.replace(/\{\$(\w+)->(\w+)\}/g, '{{$1.$2}}')
        // {$var} → {{var}}
        result = result.replace(/\{\$(\w+)\}/g, '{{$1}}')
        // $var['key'] → {{var.get("key")}}
        result = result.replace(/\$(\w+)\['([^']+)'\]/g, '{{$1.get("$2")}}')
        result = result.replace(/\$(\w+)\["([^"]+)"\]/g, '{{$1.get("$2")}}')
        // $var->prop → {{var.prop}}
        result = result.replace(/\$(\w+)->(\w+)/g, '{{$1.$2}}')
        // $var → {{var}}
        result = result.replace(/\$(\w+)/g, '{{$1}}')
        // Fix single-char vars
        result = result.replace(/\{\{(\w)\}\}/g, (_, v) => '{{' + v + v + '}}')
        if (result.includes('{{')) return '`' + result + '`'
      }
      return '"' + raw + '"'
    }
    case 'Bool': return node.value ? 'true' : 'false'
    case 'Null': return 'none'
    case 'Var': return phpVarToU(node.name)
    case 'ListExpr': {
      // list($a, $b) used as lvalue — emit as destructuring
      return '[' + node.args.map(emitExpr).join(', ') + ']'
    }

    case 'Match': {
      // match($x) { 'a' => 1, 'b' => 2, default => 0 }
      const expr = emitExpr(node.expr)
      const arms = node.arms.map(a => {
        if (a.type === 'MatchDefault') return '! ' + emitExpr(a.value)
        return expr + ' == ' + emitExpr(a.pattern) + ' ? ' + emitExpr(a.value)
      }).join(' ')
      return arms
    }

    case 'CommaExpr': {
      return node.exprs.map(emitExpr).join('\n')
    }

    case 'VarVar': {
      // $$name → _vars.get(name) — variable variables use a local map
      return '_vars.get(' + phpVarToU(node.name) + ')'
    }
    case 'This': return 't'

    case 'Assign': {
      // list($a, $b) = expr → destructuring
      if (node.left && node.left.type === 'Call' && node.left.name === 'list') {
        const vars = node.left.args.map(a => emitExpr(a))
        const right = emitExpr(node.right)
        const lines = vars.map((v, i) => v + ' = ' + right + '[' + (i+1) + ']')
        return lines.join('\n')
      }
      const left = emitExpr(node.left)
      const right = emitExpr(node.right)
      if (node.op === '.=') return `${left} = ${left} + ${right}`
      if (node.op === '??=') return `${left} = ${left} ?? ${right}`
      return `${left} ${node.op} ${right}`
    }

    case 'Binary': {
      const left = emitExpr(node.left)
      const right = emitExpr(node.right)
      if (node.op === '.') return `${left} + ${right}`  // PHP string concat
      if (node.op === '<=>') return `${left}.compare(${right})`
      if (node.op === '===') return `${left} == ${right}`
      if (node.op === '!==') return `${left} != ${right}`
      if (node.op === '&') return `${left}.bit_and(${right})`
      if (node.op === '|') return `${left}.bit_or(${right})`
      if (node.op === '^') return `${left}.bit_xor(${right})`
      if (node.op === '<<') return `${left}.bit_shl(${right})`
      if (node.op === '>>') return `${left}.bit_shr(${right})`
      if (node.op === '&&') return `${left} ? ${right} ! false`
      if (node.op === '||') return `${left} ? true ! ${right}`
      return `${left} ${node.op} ${right}`
    }

    case 'Unary': {
      if (node.op === '~' ) return `${emitExpr(node.expr)}.bit_not()`
      if (node.op === '!' ) return `${emitExpr(node.expr)} == false`
      if (node.op === '++') return `${emitExpr(node.expr)} = ${emitExpr(node.expr)} + 1`
      if (node.op === '--') return `${emitExpr(node.expr)} = ${emitExpr(node.expr)} - 1`
      return `${node.op}${emitExpr(node.expr)}`
    }

    case 'Ternary':
      return `${emitExpr(node.test)} ? ${emitExpr(node.consequent)} ! ${emitExpr(node.alternate)}`

    case 'NullCoalesce':
      return `${emitExpr(node.left)} ?? ${emitExpr(node.right)}`

    case 'PropertyAccess': {
      const obj = emitExpr(node.object)
      return `${obj}.${node.property}`
    }

    case 'MethodCall': {
      const obj = emitExpr(node.object)
      const args = node.args.map(emitExpr).join(', ')
      return `${obj}.${node.method}(${args})`
    }

    case 'StaticCall': {
      const cls = typeof node.class === 'string' ? phpClassToU(node.class)
        : node.class.type === 'Var' ? phpClassToU(node.class.name)
        : emitExpr(node.class)
      const args = node.args.map(emitExpr).join(', ')
      return `${cls}.${node.method}(${args})`
    }

    case 'StaticAccess': {
      let cls = typeof node.class === 'string' ? phpClassToU(node.class)
        : emitExpr(node.class)
      if (cls === 'self' || cls === 'static') cls = 't'
      let prop = node.property
      if (prop.startsWith('$')) prop = prop.slice(1)
      return `${cls}.${prop}`
    }

    case 'Call': {
      const mapped = FUNC_MAP[node.name]
      if (mapped) {
        // Custom handlers for complex PHP functions
        if (mapped.custom) {
          switch (node.name) {
            case 'compact': {
              // compact('a', 'b', 'c') → { "a": aa, "b": bb, "c": cc }
              const pairs = node.args.map(a => {
                const name = a.type === 'String' ? a.value : emitExpr(a)
                const varName = phpVarToU(name.replace(/"/g, ''))
                return `"${name.replace(/"/g, '')}": ${varName}`
              }).join(', ')
              return `{ ${pairs} }`
            }
            case 'extract': {
              const arg = emitExpr(node.args[0])
              return '/* extract(' + arg + ') */'
            }
            case 'array_combine': {
              return 'Map.from_keys_values(' + emitExpr(node.args[0]) + ', ' + emitExpr(node.args[1]) + ')'
            }
            case 'call_user_func': {
              const fn2 = emitExpr(node.args[0])
              const ca = node.args.slice(1).map(emitExpr).join(', ')
              return fn2 + '(' + ca + ')'
            }
            case 'call_user_func_array': {
              if (node.args[0] && node.args[0].type === 'Array' && node.args[0].elements && node.args[0].elements.length === 2) {
                const obj2 = emitExpr(node.args[0].elements[0])
                const meth = node.args[0].elements[1].type === 'String' ? node.args[0].elements[1].value : emitExpr(node.args[0].elements[1])
                const ca2 = node.args.length > 1 ? emitExpr(node.args[1]) : ''
                return obj2 + '.' + meth + '(' + ca2 + ')'
              }
              return emitExpr(node.args[0]) + '(' + (node.args.length > 1 ? emitExpr(node.args[1]) : '') + ')'
            }
            case 'define': {
              return emitExpr(node.args[0]) + ' = ' + emitExpr(node.args[1])
            }
            case 'number_format': {
              return emitExpr(node.args[0]) + '.format(' + (node.args[1] ? emitExpr(node.args[1]) : '0') + ')'
            }
            case 'preg_match': {
              // preg_match('/pattern/flags', $str, $matches)
              const patternArg = node.args[0]
              const strArg = node.args[1]
              const matchArg = node.args[2]
              const { pattern, flags } = parseRegexLiteral(patternArg)
              const str = emitExpr(strArg)
              if (matchArg) {
                const m = emitExpr(matchArg)
                return `${m} = regex\`${pattern}\`${flags}.match(${str})`
              }
              return `regex\`${pattern}\`${flags}.test(${str})`
            }
            case 'preg_match_all': {
              const patternArg = node.args[0]
              const strArg = node.args[1]
              const matchArg = node.args[2]
              const { pattern, flags } = parseRegexLiteral(patternArg)
              const str = emitExpr(strArg)
              if (matchArg) {
                const m = emitExpr(matchArg)
                return `${m} = regex\`${pattern}\`${flags}.match_all(${str})`
              }
              return `regex\`${pattern}\`${flags}.match_all(${str})`
            }
            case 'preg_replace': {
              // preg_replace('/pattern/', $replacement, $subject)
              const patternArg = node.args[0]
              const replArg = node.args[1]
              const subjectArg = node.args[2]
              const { pattern, flags } = parseRegexLiteral(patternArg)
              return `${emitExpr(subjectArg)}.replace(regex\`${pattern}\`${flags}, ${emitExpr(replArg)})`
            }
            case 'preg_split': {
              const patternArg = node.args[0]
              const strArg = node.args[1]
              const { pattern, flags } = parseRegexLiteral(patternArg)
              return `${emitExpr(strArg)}.split(regex\`${pattern}\`${flags})`
            }
            case 'sprintf': {
              // sprintf("Hello %s, you are %d", $name, $age)
              // → `Hello {{name}}, you are {{age}}`
              if (node.args.length > 0 && node.args[0].type === 'String') {
                let fmt = node.args[0].value
                let argIdx = 1
                fmt = fmt.replace(/%[sdfu%]/g, (m) => {
                  if (m === '%%') return '%'
                  if (argIdx < node.args.length) {
                    return '{{' + emitExpr(node.args[argIdx++]) + '}}'
                  }
                  return m
                })
                return '`' + fmt + '`'
              }
              return `/* sprintf */ ${node.args.map(emitExpr).join(', ')}`
            }
          }
        }
        const args = (mapped.reorder || node.args.map((_, i) => i)).map(i => emitExpr(node.args[i]))
        if (mapped.method) return `${args[0]}${mapped.u}(${args.slice(1).join(', ')})`
        if (mapped.func) return `${mapped.u}(${args.join(', ')})`
        if (mapped.postfix) return `${args[0]} ${mapped.u}`
        if (mapped.binary) return `${args[0]} ${mapped.u} ${args[1]}`
        if (mapped.comment) return mapped.comment
      }
      const args = node.args.map(emitExpr).join(', ')
      return `${node.name}(${args})`
    }

    case 'New': {
      const name = phpClassToU(node.name)
      const args = node.args.map(emitExpr).join(', ')
      return `${name}(${args})`
    }

    case 'Index': {
      const obj = emitExpr(node.object)
      if (node.index === null) return `${obj}.push`
      const idx = emitExpr(node.index)
      // PHP array access → map.get() for string keys
      if (node.index.type === 'String') return `${obj}.get("${node.index.value}")`
      // U is 1-based: arr[0] → arr[1], arr[i] → arr[i + 1]
      if (node.index.type === 'Number') {
        return `${obj}[${parseInt(node.index.value) + 1}]`
      }
      return `${obj}[${idx} + 1]`
    }

    case 'Array': {
      const isMap = node.elements.some(e => e.type === 'KeyValue')
      if (isMap) {
        const pairs = node.elements.map(e => {
          if (e.type === 'KeyValue') return `${emitExpr(e.key)}: ${emitExpr(e.value)}`
          return emitExpr(e)
        }).join(', ')
        return `{ ${pairs} }`
      }
      return `[${node.elements.map(emitExpr).join(', ')}]`
    }

    case 'Closure': {
      const params = node.params.map(p => phpVarToU(p.name)).join(', ')
      if (node.body.length === 1 && node.body[0].type === 'Return') {
        return `(${params}) => ${emitExpr(node.body[0].value)}`
      }
      const body = node.body.map(s => emit(s, '\t\t')).filter(Boolean).join('\n')
      return `(${params}) => (\n${body}\n\t)`
    }

    case 'Isset': {
      return node.args.map(a => `${emitExpr(a)} != none`).join(' ? ')
    }

    case 'Empty': {
      const arg = emitExpr(node.args[0])
      return `(${arg} == none ? true ! ${arg}.len == 0)`
    }

    case 'Cast': {
      const expr = emitExpr(node.expr)
      const castMap = { 'int': '.__int__()', 'integer': '.__int__()', 'float': '.__float__()',
        'double': '.__float__()', 'string': '.__string__()', 'bool': '.__bool__()',
        'boolean': '.__bool__()', 'array': '' }
      const suffix = castMap[node.castType] || ''
      return suffix ? expr + suffix : expr
    }

    case 'DynamicAccess': {
      // $obj->{'prop'} → obj.get(prop)
      return emitExpr(node.object) + '.get(' + emitExpr(node.property) + ')'
    }

    case 'InstanceOf':
      return emitExpr(node.left) + ' :: ' + phpClassToU(node.class)

    case 'ArrowFunc': {
      const params = node.params.map(p => phpVarToU(p.name)).join(', ')
      return '(' + params + ') => ' + emitExpr(node.body)
    }

    default:
      return `/* TODO: ${node.type} */`
  }
}

function addSourceComments(code, filename) {
  // Add // @source filename:line comments to help map back
  if (!filename) return code
  const lines = code.split('\n')
  return lines.map((line, i) => {
    if (line.trim() && !line.trim().startsWith('//')) {
      return line + '  // @source ' + filename + ':' + (i + 1)
    }
    return line
  }).join('\n')
}

function convertViewTemplate (source) {
  // PHP view template: mixed HTML + <?php ?> — convert to html template literal
  // Replace <?php echo $var ?> with {{var}}
  // Replace <?= $var ?> with {{var}}  
  // Replace <?php code ?> with inline U code
  let result = source
  // Short echo tags: <?= expr ?>
  result = result.replace(/<\?=\s*(.+?)\s*\?>/g, (_, expr) => {
    const cleaned = expr.replace(/\$/g, '').replace(/->/g, '.').replace(/::/g, '.').trim()
    return '{{' + cleaned + '}}'
  })
  // Full echo: <?php echo expr ?>
  result = result.replace(/<\?php\s+echo\s+(.+?)\s*;?\s*\?>/g, (_, expr) => {
    const cleaned = expr.replace(/\$/g, '').replace(/->/g, '.').replace(/::/g, '.').trim()
    return '{{' + cleaned + '}}'
  })
  // Other PHP blocks: <?php code ?> — comment them
  result = result.replace(/<\?php\s+(.+?)\s*\?>/gs, (_, code) => {
    return '/* ' + code.replace(/\*\//g, '* /').slice(0, 60) + ' */'
  })
  // Strip remaining <?php and ?>
  result = result.replace(/<\?php/g, '').replace(/\?>/g, '')
  return 'html`' + result.trim() + '`'
}

function convertPHP (source, filename) {
  const lexer = typeof require !== 'undefined' ? require('./php-lexer') : window.PhpLexer
  const parser = typeof require !== 'undefined' ? require('./php-parser') : window.PhpParser
  const messages = []

  // Detect view templates (files that are primarily HTML with embedded PHP)
  const trimmed = source.trim()
  const isView = (
    (trimmed.startsWith('<') && !trimmed.startsWith('<?php')) ||
    (trimmed.startsWith('<?php') && trimmed.indexOf('?>') > 0 && trimmed.indexOf('?>') < 100 && 
     trimmed.indexOf('<', trimmed.indexOf('?>')) > 0)
  )
  if (isView) {
    try {
      const code = convertViewTemplate(source)
      return { code, messages }
    } catch (e) {
      // Fall through to normal parsing
    }
  }

  try {
    const tokens = lexer.tokenize(source)
    const ast = parser.parse(tokens)
    const code = emit(ast, '')
    // Build source map from AST line numbers
    const sourceMap = []
    const outLines = code.trim().split('\n')
    // Simple heuristic: each output line maps to the nearest AST line
    return { code: code.trim(), messages, sourceMap, filename: filename || '' }
  } catch (e) {
    messages.push({ line: 0, text: 'Parse error: ' + e.message, type: 'err' })
    return { code: '// Parse error\n// ' + e.message, messages }
  }
}

exports.convertPHP = convertPHP
exports.emit = emit
exports.mapType = mapType
exports.FUNC_MAP = FUNC_MAP

})(typeof module !== 'undefined' ? module.exports : (window.PhpToU = {}))
