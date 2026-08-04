/* php-parser.js — Recursive descent parser for PHP.
 * Produces an AST consumed by php-to-u.js.
 * Handles: functions, classes, control flow, expressions, arrays,
 * closures, type hints, static methods, properties.
 */

;(function (exports) {
'use strict'

const TK = (typeof require !== 'undefined' ? require('./php-lexer') : window.PhpLexer).TK

function parse (tokens) {
  let pos = 0
  const peek = () => tokens[pos] || { type: TK.EOF, value: '' }
  const advance = () => tokens[pos++]
  const expect = (type, val) => {
    const t = advance()
    if (t.type !== type || (val !== undefined && t.value !== val))
      throw new Error(`Expected ${val || type} at line ${t.line}, got ${t.value}`)
    return t
  }
  const match = (type, val) => {
    const t = peek()
    if (t.type === type && (val === undefined || t.value === val)) { advance(); return true }
    return false
  }
  const at = (type, val) => {
    const t = peek()
    return t.type === type && (val === undefined || t.value === val)
  }

  function parseProgram () {
    const body = []
    while (!at(TK.EOF)) {
      try { body.push(parseStatement()) }
      catch (e) { advance(); /* skip bad token */ }
    }
    return { type: 'Program', body }
  }

  function parseStatement () {
    const t = peek()

    if (t.type === TK.KEYWORD) {
      switch (t.value) {
        case 'function': return parseFunctionDecl()
        case 'class': return parseClassDecl()
        case 'interface': return parseClassDecl('interface')
        case 'abstract': advance(); return parseClassDecl('abstract')
        case 'trait': return parseClassDecl('trait')
        case 'if': return parseIf()
        case 'do': return parseDoWhile()
        case 'while': return parseWhile()
        case 'for': return parseFor()
        case 'foreach': return parseForeach()
        case 'return': return parseReturn()
        case 'echo': case 'print': return parseEcho()
        case 'throw': return parseThrow()
        case 'try': return parseTryCatch()
        case 'switch': return parseSwitch()
        case 'use': case 'namespace': return parseUseNamespace()
        case 'const': return parseConst()
        case 'public': case 'private': case 'protected': case 'static':
          return parseClassMember()
        case 'unset': {
          advance()
          const args = parseArgList()
          match(TK.SEMICOLON)
          return { type: 'Unset', args }
        }
        case 'yield': {
          advance()
          const value = at(TK.SEMICOLON) ? null : parseExpr()
          match(TK.SEMICOLON)
          return { type: 'Yield', value }
        }
        case 'break': advance(); match(TK.SEMICOLON); return { type: 'Break' }
        case 'continue': advance(); match(TK.SEMICOLON); return { type: 'Continue' }
      }
    }

    // Skip stray closing braces/parens (parser recovery)
    if (at(TK.CLOSE_BRACE) || at(TK.CLOSE_PAREN) || at(TK.CLOSE_BRACKET)) {
      advance()
      return { type: 'Unknown' }
    }

    // Expression statement
    const expr = parseExpr()
    match(TK.SEMICOLON)
    return { type: 'ExprStmt', expr }
  }

  function parseFunctionDecl () {
    expect(TK.KEYWORD, 'function')
    const name = advance().value
    const params = parseParams()
    const returnType = parseReturnType()
    const body = parseBlock()
    return { type: 'FunctionDecl', name, params, returnType, body }
  }

  function parseParams () {
    expect(TK.OPEN_PAREN)
    const params = []
    while (!at(TK.CLOSE_PAREN) && !at(TK.EOF)) {
      const param = {}
      // Type hint
      if (at(TK.IDENT) || at(TK.KEYWORD) || at(TK.QUESTION)) {
        if (at(TK.QUESTION)) { advance(); param.nullable = true }
        if (at(TK.IDENT) || at(TK.KEYWORD)) {
          const next = tokens[pos + 1]
          if (next && (next.type === TK.VARIABLE || (next.type === TK.OP && next.value === '&'))) {
            param.typeHint = advance().value
          }
        }
      }
      // & for pass-by-reference (skip it — U doesn't have this)
      if (at(TK.OP, '&')) { advance(); param.byRef = true }
      // ... for variadic
      if (at(TK.OP, '.') && tokens[pos+1] && tokens[pos+1].type === TK.OP && tokens[pos+1].value === '.' &&
          tokens[pos+2] && tokens[pos+2].type === TK.OP && tokens[pos+2].value === '.') {
        advance(); advance(); advance(); param.variadic = true
      }
      if (at(TK.VARIABLE)) {
        param.name = advance().value.slice(1) // strip $
        if (match(TK.OP, '=')) param.default = parseExpr()
        params.push(param)
      } else { advance() } // skip unexpected
      match(TK.COMMA)
    }
    expect(TK.CLOSE_PAREN)
    return params
  }

  function parseReturnType () {
    if (match(TK.COLON)) {
      let nullable = false
      if (match(TK.QUESTION)) nullable = true
      const type = advance().value
      return { type, nullable }
    }
    return null
  }

  function parseBlock () {
    if (!match(TK.OPEN_BRACE)) return []
    const stmts = []
    while (!at(TK.CLOSE_BRACE) && !at(TK.EOF)) {
      stmts.push(parseStatement())
    }
    expect(TK.CLOSE_BRACE)
    return stmts
  }

  function parseClassDecl (kind) {
    if (!kind) { kind = advance().value } // 'class', 'interface', 'trait'
    else { advance() } // consume the keyword (class/interface/trait after abstract)
    const name = advance().value
    let parent = null, interfaces = []
    if (match(TK.KEYWORD, 'extends')) parent = advance().value
    if (match(TK.KEYWORD, 'implements')) {
      do { interfaces.push(advance().value) } while (match(TK.COMMA))
    }
    const body = parseClassBody()
    return { type: 'ClassDecl', name, parent, interfaces, body, kind }
  }

  function parseClassBody () {
    expect(TK.OPEN_BRACE)
    const members = []
    while (!at(TK.CLOSE_BRACE) && !at(TK.EOF)) {
      try { members.push(parseClassMember()) }
      catch (e) { advance() }
    }
    expect(TK.CLOSE_BRACE)
    return members
  }

  function parseClassMember () {
    const mods = []
    while (at(TK.KEYWORD) && ['public','private','protected','static','abstract','final','const'].includes(peek().value)) {
      mods.push(advance().value)
    }
    if (at(TK.KEYWORD, 'function')) {
      advance() // 'function'
      const name = advance().value
      const params = parseParams()
      const returnType = parseReturnType()
      const body = (mods.includes('abstract') || at(TK.SEMICOLON)) ? (match(TK.SEMICOLON), []) : parseBlock()
      return { type: 'MethodDecl', name, params, returnType, body, mods }
    }
    if (at(TK.KEYWORD, 'const')) {
      advance()
      const name = advance().value
      expect(TK.OP, '=')
      const value = parseExpr()
      match(TK.SEMICOLON)
      return { type: 'ClassConst', name, value, mods }
    }
    // Property: [type] $name [= default];
    let typeHint = null
    // Skip & (reference)
    if (at(TK.OP, '&')) advance()
    if (at(TK.IDENT) || at(TK.KEYWORD, 'int') || at(TK.KEYWORD, 'float') ||
        at(TK.KEYWORD, 'string') || at(TK.KEYWORD, 'bool') || at(TK.KEYWORD, 'array') ||
        at(TK.KEYWORD, 'mixed') || at(TK.KEYWORD, 'self') || at(TK.KEYWORD, 'callable') || at(TK.KEYWORD, 'callable') ||
        at(TK.QUESTION)) {
      if (at(TK.QUESTION)) { advance() }
      // Look ahead: if next is $var or &$var, this is a type hint
      let lookAhead = pos
      if (tokens[lookAhead + 1] && tokens[lookAhead + 1].type === TK.OP && tokens[lookAhead + 1].value === '&') lookAhead++
      if (tokens[lookAhead + 1] && tokens[lookAhead + 1].type === TK.VARIABLE) {
        typeHint = advance().value
        if (at(TK.OP, '&')) advance() // skip & after type
      }
    }
    if (at(TK.VARIABLE)) {
      const name = advance().value.slice(1)
      let value = null
      if (match(TK.OP, '=')) value = parseExpr()
      match(TK.SEMICOLON)
      return { type: 'PropertyDecl', name, typeHint, value, mods }
    }
    // Skip unknown — if it's a brace, skip the whole block
    if (at(TK.OPEN_BRACE)) {
      const body = parseBlock()
      return { type: 'Unknown' }
    }
    advance()
    return { type: 'Unknown' }
  }

  // ── Control Flow ────────────────────────────────────────────

  function parseIf () {
    expect(TK.KEYWORD, 'if')
    expect(TK.OPEN_PAREN)
    const test = parseExpr()
    expect(TK.CLOSE_PAREN)
    const body = at(TK.OPEN_BRACE) ? parseBlock() : [parseStatement()]
    let elseBody = null
    if (match(TK.KEYWORD, 'else')) {
      if (at(TK.KEYWORD, 'if')) elseBody = [parseIf()]
      else elseBody = at(TK.OPEN_BRACE) ? parseBlock() : [parseStatement()]
    }
    if (match(TK.KEYWORD, 'elseif')) {
      // elseif is like else + if but as one keyword
      expect(TK.OPEN_PAREN)
      const elseifTest = parseExpr()
      expect(TK.CLOSE_PAREN)
      const elseifBody = at(TK.OPEN_BRACE) ? parseBlock() : [parseStatement()]
      let elseifElse = null
      if (match(TK.KEYWORD, 'else')) {
        if (at(TK.KEYWORD, 'if')) elseifElse = [parseIf()]
        else elseifElse = at(TK.OPEN_BRACE) ? parseBlock() : [parseStatement()]
      }
      if (match(TK.KEYWORD, 'elseif')) {
        // chained elseif — recurse manually
        const inner = { type: 'If', test: parseExpr(), body: [], elseBody: null }
        // Actually this gets complex. Let's just wrap as an If
      }
      elseBody = [{ type: 'If', test: elseifTest, body: elseifBody, elseBody: elseifElse }]
    }
    return { type: 'If', test, body, elseBody }
  }

  function parseDoWhile () {
    expect(TK.KEYWORD, 'do')
    const body = at(TK.OPEN_BRACE) ? parseBlock() : [parseStatement()]
    expect(TK.KEYWORD, 'while')
    expect(TK.OPEN_PAREN)
    const test = parseExpr()
    expect(TK.CLOSE_PAREN)
    match(TK.SEMICOLON)
    return { type: 'DoWhile', test, body }
  }

  function parseWhile () {
    expect(TK.KEYWORD, 'while')
    expect(TK.OPEN_PAREN)
    const test = parseExpr()
    expect(TK.CLOSE_PAREN)
    const body = at(TK.OPEN_BRACE) ? parseBlock() : [parseStatement()]
    return { type: 'While', test, body }
  }

  function parseFor () {
    expect(TK.KEYWORD, 'for')
    expect(TK.OPEN_PAREN)
    // for init can have comma-separated expressions
    const inits = []
    while (!at(TK.SEMICOLON) && !at(TK.EOF)) {
      inits.push(parseExpr())
      if (!match(TK.COMMA)) break
    }
    const init = inits.length <= 1 ? (inits[0] || null) : { type: 'CommaExpr', exprs: inits }
    expect(TK.SEMICOLON)
    const test = at(TK.SEMICOLON) ? null : parseExpr()
    expect(TK.SEMICOLON)
    const update = at(TK.CLOSE_PAREN) ? null : parseExpr()
    expect(TK.CLOSE_PAREN)
    const body = at(TK.OPEN_BRACE) ? parseBlock() : [parseStatement()]
    return { type: 'For', init, test, update, body }
  }

  function parseForeach () {
    expect(TK.KEYWORD, 'foreach')
    expect(TK.OPEN_PAREN)
    const expr = parseExpr()
    expect(TK.KEYWORD, 'as')
    let key = null, value
    if (at(TK.OP, '&')) advance() // skip & reference
    const first = advance() // $var
    if (match(TK.DOUBLE_ARROW)) {
      key = first.value.replace('$', '')
      if (at(TK.OP, '&')) advance() // skip & reference  
      value = advance().value.replace('$', '')
    } else {
      value = first.value.replace('$', '')
    }
    expect(TK.CLOSE_PAREN)
    const body = at(TK.OPEN_BRACE) ? parseBlock() : [parseStatement()]
    return { type: 'Foreach', expr, key, value, body }
  }

  function parseReturn () {
    expect(TK.KEYWORD, 'return')
    if (at(TK.SEMICOLON)) { advance(); return { type: 'Return', value: null } }
    const value = parseExpr()
    match(TK.SEMICOLON)
    return { type: 'Return', value }
  }

  function parseEcho () {
    const kw = advance().value // echo or print
    const args = [parseExpr()]
    while (match(TK.COMMA)) args.push(parseExpr())
    match(TK.SEMICOLON)
    return { type: 'Echo', args }
  }

  function parseThrow () {
    expect(TK.KEYWORD, 'throw')
    const expr = parseExpr()
    match(TK.SEMICOLON)
    return { type: 'Throw', expr }
  }

  function parseTryCatch () {
    expect(TK.KEYWORD, 'try')
    const body = parseBlock()
    const catches = []
    while (match(TK.KEYWORD, 'catch')) {
      expect(TK.OPEN_PAREN)
      const type = advance().value
      const name = at(TK.VARIABLE) ? advance().value.slice(1) : '_'
      expect(TK.CLOSE_PAREN)
      const catchBody = parseBlock()
      catches.push({ type, name, body: catchBody })
    }
    let fin = null
    if (match(TK.KEYWORD, 'finally')) fin = parseBlock()
    return { type: 'TryCatch', body, catches, finally: fin }
  }

  function parseSwitch () {
    expect(TK.KEYWORD, 'switch')
    expect(TK.OPEN_PAREN)
    const expr = parseExpr()
    expect(TK.CLOSE_PAREN)
    expect(TK.OPEN_BRACE)
    const cases = []
    while (!at(TK.CLOSE_BRACE) && !at(TK.EOF)) {
      if (match(TK.KEYWORD, 'case')) {
        const val = parseExpr()
        expect(TK.COLON)
        const body = []
        while (!at(TK.KEYWORD, 'case') && !at(TK.KEYWORD, 'default') && !at(TK.CLOSE_BRACE))
          body.push(parseStatement())
        cases.push({ type: 'Case', value: val, body })
      } else if (match(TK.KEYWORD, 'default')) {
        expect(TK.COLON)
        const body = []
        while (!at(TK.KEYWORD, 'case') && !at(TK.CLOSE_BRACE))
          body.push(parseStatement())
        cases.push({ type: 'Default', body })
      } else advance()
    }
    expect(TK.CLOSE_BRACE)
    return { type: 'Switch', expr, cases }
  }

  function parseUseNamespace () {
    const kw = advance().value
    if (kw === 'namespace' && at(TK.OPEN_BRACE)) {
      // namespace { ... } — global namespace block, just parse the body
      const body = parseBlock()
      return { type: 'NamespaceBlock', body }
    }
    const parts = []
    while (!at(TK.SEMICOLON) && !at(TK.OPEN_BRACE) && !at(TK.EOF)) parts.push(advance().value)
    if (at(TK.OPEN_BRACE)) {
      // namespace Foo\Bar { ... }
      const body = parseBlock()
      return { type: 'NamespaceBlock', path: parts.join(''), body }
    }
    match(TK.SEMICOLON)
    return { type: kw === 'namespace' ? 'Namespace' : 'Use', path: parts.join('') }
  }

  function parseConst () {
    expect(TK.KEYWORD, 'const')
    const name = advance().value
    expect(TK.OP, '=')
    const value = parseExpr()
    match(TK.SEMICOLON)
    return { type: 'ConstDecl', name, value }
  }

  // ── Expressions ─────────────────────────────────────────────

  function parseExpr () { return parseAssignment() }

  function parseAssignment () {
    const left = parseTernary()
    if (at(TK.OP) && ['=', '+=', '-=', '*=', '/=', '.=', '??='].includes(peek().value)) {
      const op = advance().value
      const right = parseAssignment()
      return { type: 'Assign', left, op, right }
    }
    return left
  }

  function parseTernary () {
    const test = parseOr()
    if (match(TK.QUESTION)) {
      if (match(TK.COLON)) { // Short ternary $a ?: $b
        const alt = parseTernary()
        return { type: 'Ternary', test, consequent: test, alternate: alt }
      }
      const consequent = parseExpr()
      expect(TK.COLON)
      const alternate = parseTernary()
      return { type: 'Ternary', test, consequent, alternate }
    }
    if (match(TK.NULL_COALESCE)) {
      const right = parseTernary()
      return { type: 'NullCoalesce', left: test, right }
    }
    return test
  }

  function parseOr () {
    let left = parseAnd()
    while (at(TK.OP, '||') || at(TK.KEYWORD, 'or')) { advance(); left = { type: 'Binary', op: '||', left, right: parseAnd() } }
    return left
  }

  function parseAnd () {
    let left = parseEquality()
    while (at(TK.OP, '&&') || at(TK.KEYWORD, 'and')) { advance(); left = { type: 'Binary', op: '&&', left, right: parseEquality() } }
    return left
  }

  function parseEquality () {
    let left = parseComparison()
    // instanceof
    if (at(TK.KEYWORD, 'instanceof')) {
      advance()
      const right = advance()
      left = { type: 'InstanceOf', left, class: right.value }
    }
    while (at(TK.OP) && ['==', '!=', '===', '!=='].includes(peek().value)) {
      const op = advance().value
      left = { type: 'Binary', op, left, right: parseComparison() }
    }
    return left
  }

  function parseComparison () {
    let left = parseBitwise()
    if (at(TK.OP, '<=>')) {
      advance()
      left = { type: 'Binary', op: '<=>', left, right: parseBitwise() }
    }
    while (at(TK.OP) && ['<', '>', '<=', '>='].includes(peek().value)) {
      const op = advance().value
      left = { type: 'Binary', op, left, right: parseAddSub() }
    }
    return left
  }

  function parseBitwise () {
    let left = parseAddSub()
    while (at(TK.OP) && ['&', '|', '^', '<<', '>>'].includes(peek().value)) {
      const op = advance().value
      left = { type: 'Binary', op, left, right: parseAddSub() }
    }
    return left
  }

  function parseAddSub () {
    let left = parseMulDiv()
    while (at(TK.OP) && ['+', '-'].includes(peek().value) || at(TK.CONCAT)) {
      const op = advance().value
      left = { type: 'Binary', op, left, right: parseMulDiv() }
    }
    return left
  }

  function parseMulDiv () {
    let left = parseUnary()
    while (at(TK.OP) && ['*', '/', '%', '**'].includes(peek().value)) {
      const op = advance().value
      left = { type: 'Binary', op, left, right: parseUnary() }
    }
    return left
  }

  function parseUnary () {
    // Type casts: (int), (string), (bool), (float), (array), (object)
    if (at(TK.OPEN_PAREN)) {
      const next = tokens[pos + 1]
      if (next && next.type === TK.KEYWORD && ['int','integer','string','float','double','bool','boolean','array','object','unset'].includes(next.value)) {
        const next2 = tokens[pos + 2]
        if (next2 && next2.type === TK.CLOSE_PAREN) {
          advance(); const castType = advance().value; advance()
          return { type: 'Cast', castType, expr: parseUnary() }
        }
      }
    }
    if (at(TK.OP, '!')) { advance(); return { type: 'Unary', op: '!', expr: parseUnary() } }
    if (at(TK.OP, '~')) { advance(); return { type: 'Unary', op: '~', expr: parseUnary() } }
    if (at(TK.OP, '-')) { advance(); return { type: 'Unary', op: '-', expr: parseUnary() } }
    if (at(TK.OP, '++') || at(TK.OP, '--')) {
      const op = advance().value
      return { type: 'Unary', op, expr: parsePostfix(), prefix: true }
    }
    if (at(TK.KEYWORD, 'new')) {
      advance()
      const name = advance().value
      const args = at(TK.OPEN_PAREN) ? parseArgList() : []
      return { type: 'New', name, args }
    }
    if (at(TK.KEYWORD, 'isset')) {
      advance()
      const args = parseArgList()
      return { type: 'Isset', args }
    }
    if (at(TK.KEYWORD, 'empty')) {
      advance()
      const args = parseArgList()
      return { type: 'Empty', args }
    }
    return parsePostfix()
  }

  function parsePostfix () {
    // & reference operator — skip (U doesn't have references)
    if (at(TK.OP, '&')) advance()
    let left = parsePrimary()
    while (true) {
      if (at(TK.ARROW)) {
        advance()
        // Dynamic property: $obj->{'prop'} or $obj->{$var}
        if (at(TK.OPEN_BRACE)) {
          advance()
          const dynProp = parseExpr()
          expect(TK.CLOSE_BRACE)
          left = { type: 'DynamicAccess', object: left, property: dynProp }
          continue
        }
        const prop = advance().value
        if (at(TK.OPEN_PAREN)) {
          left = { type: 'MethodCall', object: left, method: prop, args: parseArgList() }
        } else {
          left = { type: 'PropertyAccess', object: left, property: prop }
        }
        continue
      }
      if (at(TK.DOUBLE_COLON)) {
        advance()
        const member = advance().value
        if (at(TK.OPEN_PAREN)) {
          left = { type: 'StaticCall', class: left, method: member, args: parseArgList() }
        } else {
          left = { type: 'StaticAccess', class: left, property: member }
        }
        continue
      }
      if (at(TK.OPEN_BRACKET)) {
        advance()
        const index = at(TK.CLOSE_BRACKET) ? null : parseExpr()
        expect(TK.CLOSE_BRACKET)
        left = { type: 'Index', object: left, index }
        continue
      }
      if (at(TK.OPEN_PAREN) && left.type === 'Var') {
        left = { type: 'Call', name: left.name, args: parseArgList() }
        continue
      }
      if (at(TK.OP) && ['++', '--'].includes(peek().value)) {
        left = { type: 'Unary', op: advance().value, expr: left, prefix: false }
        continue
      }
      break
    }
    return left
  }

  function parsePrimary () {
    const t = peek()
    if (t.type === TK.NUMBER) { advance(); return { type: 'Number', value: t.value } }
    if (t.type === TK.STRING) { advance(); return { type: 'String', value: t.value, quote: t.quote } }
    if (t.type === TK.VARIABLE) {
      advance()
      if (t.value === '$this') return { type: 'This' }
      if (t.varvar || t.value.startsWith('$$')) {
        return { type: 'VarVar', name: t.value.replace(/^\$\$/, '') }
      }
      return { type: 'Var', name: t.value.slice(1) }
    }
    if (t.type === TK.KEYWORD && ['true', 'false'].includes(t.value)) {
      advance(); return { type: 'Bool', value: t.value === 'true' }
    }
    if (t.type === TK.KEYWORD && t.value === 'null') {
      advance(); return { type: 'Null' }
    }
    if (t.type === TK.KEYWORD && t.value === 'array') {
      advance()
      if (!at(TK.OPEN_PAREN)) return { type: 'Var', name: 'array' }
      // array() is a special syntax — handle => inside
      expect(TK.OPEN_PAREN)
      const elements = []
      while (!at(TK.CLOSE_PAREN) && !at(TK.EOF)) {
        const val = parseExpr()
        if (match(TK.DOUBLE_ARROW)) {
          elements.push({ type: 'KeyValue', key: val, value: parseExpr() })
        } else {
          elements.push(val)
        }
        match(TK.COMMA)
      }
      expect(TK.CLOSE_PAREN)
      return { type: 'Array', elements }
    }
    // instanceof
    if (t.type === TK.IDENT || (t.type === TK.KEYWORD && ['self', 'parent', 'static'].includes(t.value))) {
      advance()
      if (at(TK.OPEN_PAREN)) return { type: 'Call', name: t.value, args: parseArgList() }
      return { type: 'Var', name: t.value }
    }
    // list($a, $b) = expr — destructuring
    if (t.type === TK.KEYWORD && t.value === 'list') {
      advance()
      const args = parseArgList()
      return { type: 'ListExpr', args }
    }
    // match($x) { 'a' => 1, 'b' => 2, default => 0 }
    if (t.type === TK.KEYWORD && t.value === 'match') {
      advance()
      expect(TK.OPEN_PAREN)
      const expr = parseExpr()
      expect(TK.CLOSE_PAREN)
      expect(TK.OPEN_BRACE)
      const arms = []
      while (!at(TK.CLOSE_BRACE) && !at(TK.EOF)) {
        if (match(TK.KEYWORD, 'default')) {
          expect(TK.DOUBLE_ARROW)
          arms.push({ type: 'MatchDefault', value: parseExpr() })
        } else {
          const pattern = parseExpr()
          expect(TK.DOUBLE_ARROW)
          const value = parseExpr()
          arms.push({ type: 'MatchArm', pattern, value })
        }
        match(TK.COMMA)
      }
      expect(TK.CLOSE_BRACE)
      return { type: 'Match', expr, arms }
    }
    // PHP 7.4 arrow functions: fn($x) => $x * 2
    if (t.type === TK.KEYWORD && t.value === 'fn') {
      advance()
      const params = parseParams()
      expect(TK.DOUBLE_ARROW)
      const body = parseExpr()
      return { type: 'ArrowFunc', params, body }
    }
    if (t.type === TK.KEYWORD && t.value === 'function') {
      // Anonymous function / closure
      advance()
      const params = parseParams()
      let uses = []
      if (match(TK.KEYWORD, 'use')) {
        expect(TK.OPEN_PAREN)
        while (!at(TK.CLOSE_PAREN)) {
          const byRef = match(TK.OP, '&')
          uses.push({ name: advance().value.replace('$', ''), byRef })
          match(TK.COMMA)
        }
        expect(TK.CLOSE_PAREN)
      }
      const returnType = parseReturnType()
      const body = parseBlock()
      return { type: 'Closure', params, uses, returnType, body }
    }
    if (match(TK.OPEN_PAREN)) {
      const expr = parseExpr()
      expect(TK.CLOSE_PAREN)
      return expr
    }
    if (match(TK.OPEN_BRACKET)) {
      // Array literal [a, b] or [k => v]
      const elements = []
      while (!at(TK.CLOSE_BRACKET) && !at(TK.EOF)) {
        const val = parseExpr()
        if (match(TK.DOUBLE_ARROW)) {
          elements.push({ type: 'KeyValue', key: val, value: parseExpr() })
        } else {
          elements.push(val)
        }
        match(TK.COMMA)
      }
      expect(TK.CLOSE_BRACKET)
      return { type: 'Array', elements }
    }
    // Unknown — skip
    advance()
    return { type: 'Unknown', value: t.value }
  }

  function parseArgList () {
    expect(TK.OPEN_PAREN)
    const args = []
    while (!at(TK.CLOSE_PAREN) && !at(TK.EOF)) {
      args.push(parseExpr())
      match(TK.COMMA)
    }
    expect(TK.CLOSE_PAREN)
    return args
  }

  return parseProgram()
}

exports.parse = parse

})(typeof module !== 'undefined' ? module.exports : (window.PhpParser = {}))
