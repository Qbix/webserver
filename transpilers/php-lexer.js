/* php-lexer.js — Tokenizer for PHP source code.
 * Produces tokens consumed by php-parser.js.
 * Handles: strings, heredoc, nowdoc, numbers, keywords, operators,
 * variables ($name), class/function declarations, type hints.
 */

;(function (exports) {
'use strict'

const TK = {
  // Literals
  STRING: 'STRING', NUMBER: 'NUMBER', IDENT: 'IDENT', VARIABLE: 'VARIABLE',
  // Structural
  OPEN_PAREN: '(', CLOSE_PAREN: ')', OPEN_BRACE: '{', CLOSE_BRACE: '}',
  OPEN_BRACKET: '[', CLOSE_BRACKET: ']', SEMICOLON: ';', COMMA: ',',
  // Operators
  OP: 'OP', ARROW: '->', DOUBLE_ARROW: '=>', DOUBLE_COLON: '::',
  DOT: '.', CONCAT: '.', QUESTION: '?', COLON: ':',
  NULL_COALESCE: '??', SPACESHIP: '<=>',
  // Keywords
  KEYWORD: 'KEYWORD',
  // Special
  EOF: 'EOF'
}

const KEYWORDS = new Set([
  'if', 'else', 'elseif', 'while', 'for', 'foreach', 'as', 'do',
  'switch', 'case', 'default', 'break', 'continue', 'return',
  'function', 'class', 'extends', 'implements', 'interface', 'trait',
  'public', 'private', 'protected', 'static', 'abstract', 'final',
  'new', 'throw', 'try', 'catch', 'finally',
  'use', 'namespace', 'const', 'var', 'echo', 'print',
  'true', 'false', 'null', 'isset', 'unset', 'empty',
  'array', 'list', 'yield', 'match', 'fn', 'instanceof', 'do', 'and', 'or', 'xor', 'yield',
  'int', 'float', 'string', 'bool', 'void', 'mixed', 'self', 'parent',
])

function tokenize (src) {
  const tokens = []
  let i = 0, line = 1, col = 1
  const n = src.length

  // Skip <?php opening tag
  if (src.startsWith('<?php')) { i = 5; col = 6 }
  else if (src.startsWith('<?=')) {
    // Short echo tag — treat as echo
    i = 3; col = 4
    tokens.push({ type: TK.KEYWORD, value: 'echo', line, col: 1 })
  }
  else if (src.startsWith('<?')) { i = 2; col = 3 }

  while (i < n) {
    const ch = src[i]

    // Whitespace
    if (ch === ' ' || ch === '\t' || ch === '\r') { i++; col++; continue }
    if (ch === '\n') { i++; line++; col = 1; continue }

    // Comments
    if (ch === '/' && src[i + 1] === '/') {
      while (i < n && src[i] !== '\n') i++
      continue
    }
    if (ch === '#' && src[i + 1] !== '[') {
      while (i < n && src[i] !== '\n') i++
      continue
    }
    if (ch === '/' && src[i + 1] === '*') {
      i += 2
      while (i < n - 1 && !(src[i] === '*' && src[i + 1] === '/')) {
        if (src[i] === '\n') { line++; col = 0 }
        i++
      }
      i += 2; continue
    }

    // Skip PHP attributes #[...]
    if (ch === '#' && src[i + 1] === '[') {
      let depth = 1; i += 2
      while (i < n && depth > 0) {
        if (src[i] === '[') depth++
        else if (src[i] === ']') depth--
        i++
      }
      continue
    }

    const start = { line, col }

    // Variables: $name or $$varvar
    if (ch === '$') {
      let j = i + 1
      if (j < n && src[j] === '$') {
        // $$varvar — variable variable
        j++
        while (j < n && /[a-zA-Z0-9_]/.test(src[j])) j++
        tokens.push({ type: TK.VARIABLE, value: src.slice(i, j), line, col, varvar: true })
        col += j - i; i = j; continue
      }
      while (j < n && /[a-zA-Z0-9_]/.test(src[j])) j++
      tokens.push({ type: TK.VARIABLE, value: src.slice(i, j), line, col })
      col += j - i; i = j; continue
    }

    // Numbers
    if (/[0-9]/.test(ch) || (ch === '.' && i + 1 < n && /[0-9]/.test(src[i + 1]))) {
      let j = i
      if (src[j] === '0' && src[j + 1] === 'x') { j += 2; while (j < n && /[0-9a-fA-F]/.test(src[j])) j++ }
      else { while (j < n && /[0-9.]/.test(src[j])) j++ }
      if (j < n && (src[j] === 'e' || src[j] === 'E')) {
        j++; if (j < n && (src[j] === '+' || src[j] === '-')) j++
        while (j < n && /[0-9]/.test(src[j])) j++
      }
      tokens.push({ type: TK.NUMBER, value: src.slice(i, j), line, col })
      col += j - i; i = j; continue
    }

    // Heredoc/Nowdoc
    if (ch === '<' && i + 2 < n && src[i+1] === '<' && src[i+2] === '<') {
      let j = i + 3;
      while (j < n && src[j] === ' ') j++;
      const nowdoc = src[j] === "'";
      if (nowdoc) j++;
      let tag = '';
      while (j < n && /[a-zA-Z0-9_]/.test(src[j])) { tag += src[j]; j++ }
      if (nowdoc && src[j] === "'") j++;
      while (j < n && src[j] !== '\n') j++;
      if (j < n) { j++; line++ }
      let val = '';
      while (j < n) {
        let lt = '';
        while (j < n && src[j] !== '\n') { lt += src[j]; j++ }
        if (lt.trim() === tag || lt.trim() === tag + ';') { if (j < n) { j++; line++ } break }
        val += lt + '\n';
        if (j < n) { j++; line++ }
      }
      if (val.endsWith('\n')) val = val.slice(0, -1);
      tokens.push({ type: TK.STRING, value: val, quote: nowdoc ? "'" : '"', heredoc: true, line, col });
      i = j; col = 1; continue
    }

    // Double-quoted strings (may have $var interpolation)
    if (ch === '"') {
      let j = i + 1, val = '', hasInterp = false;
      while (j < n && src[j] !== '"') {
        if (src[j] === '\\') { val += src[j] + src[j + 1]; j += 2 }
        else if (src[j] === '$') { hasInterp = true; val += src[j]; j++ }
        else { val += src[j]; j++ }
      }
      j++;
      tokens.push({ type: TK.STRING, value: val, quote: '"', interpolated: hasInterp, line, col });
      col += j - i; i = j; continue
    }

    // Single-quoted strings
    if (ch === "'") {
      let j = i + 1, val = '';
      while (j < n && src[j] !== "'") {
        if (src[j] === '\\') { val += src[j] + src[j + 1]; j += 2 }
        else { val += src[j]; j++ }
      }
      j++;
      tokens.push({ type: TK.STRING, value: val, quote: "'", line, col });
      col += j - i; i = j; continue
    }

    // Identifiers / keywords
    if (/[a-zA-Z_]/.test(ch)) {
      let j = i
      while (j < n && /[a-zA-Z0-9_]/.test(src[j])) j++
      const word = src.slice(i, j)
      const type = KEYWORDS.has(word) ? TK.KEYWORD : TK.IDENT
      tokens.push({ type, value: word, line, col })
      col += j - i; i = j; continue
    }

    // Multi-char operators
    const two = src.slice(i, i + 2)
    const three = src.slice(i, i + 3)

    if (three === '===' || three === '!==' || three === '<=>') {
      tokens.push({ type: TK.OP, value: three, line, col })
      i += 3; col += 3; continue
    }
    if (two === '<<' ) { tokens.push({ type: TK.OP, value: '<<', line, col }); i += 2; col += 2; continue }
    if (two === '>>' ) { tokens.push({ type: TK.OP, value: '>>', line, col }); i += 2; col += 2; continue }
    if (two === '++' ) { tokens.push({ type: TK.OP, value: '++', line, col }); i += 2; col += 2; continue }
    if (two === '--' ) { tokens.push({ type: TK.OP, value: '--', line, col }); i += 2; col += 2; continue }
    if (two === '->' ) { tokens.push({ type: TK.ARROW, value: '->', line, col }); i += 2; col += 2; continue }
    if (two === '=>' ) { tokens.push({ type: TK.DOUBLE_ARROW, value: '=>', line, col }); i += 2; col += 2; continue }
    if (two === '::' ) { tokens.push({ type: TK.DOUBLE_COLON, value: '::', line, col }); i += 2; col += 2; continue }
    if (two === '??' ) { tokens.push({ type: TK.NULL_COALESCE, value: '??', line, col }); i += 2; col += 2; continue }
    if (two === '==' || two === '!=' || two === '<=' || two === '>=' ||
        two === '&&' || two === '||' || two === '+=' || two === '-=' ||
        two === '*=' || two === '/=' || two === '.=' || two === '**') {
      tokens.push({ type: TK.OP, value: two, line, col })
      i += 2; col += 2; continue
    }

    // Single-char operators and structural tokens
    const single = {
      '(': TK.OPEN_PAREN, ')': TK.CLOSE_PAREN,
      '{': TK.OPEN_BRACE, '}': TK.CLOSE_BRACE,
      '[': TK.OPEN_BRACKET, ']': TK.CLOSE_BRACKET,
      ';': TK.SEMICOLON, ',': TK.COMMA,
      '.': TK.CONCAT, '?': TK.QUESTION, ':': TK.COLON,
    }
    if (single[ch]) {
      tokens.push({ type: single[ch], value: ch, line, col })
      i++; col++; continue
    }
    // @ error suppression — skip silently
    if (ch === '@') { i++; col++; continue }
    if ('+-*/%=<>&|!^~'.includes(ch)) {
      tokens.push({ type: TK.OP, value: ch, line, col })
      i++; col++; continue
    }

    // ?> PHP closing tag — skip HTML until <?php reopens
    if (ch === '?' && i + 1 < n && src[i+1] === '>') {
      i += 2
      // Skip HTML content until <?php
      while (i < n) {
        if (src[i] === '<' && src.slice(i, i+5) === '<?php') { i += 5; break }
        if (src[i] === '<' && src.slice(i, i+3) === '<?=') {
          i += 3
          tokens.push({ type: TK.KEYWORD, value: 'echo', line, col })
          break
        }
        if (src[i] === '<' && src.slice(i, i+2) === '<?') { i += 2; break }
        if (src[i] === '\n') { line++; col = 0 }
        i++
      }
      continue
    }

    // Skip unknown
    i++; col++
  }

  tokens.push({ type: TK.EOF, value: '', line, col })
  return tokens
}

exports.TK = TK
exports.tokenize = tokenize
exports.KEYWORDS = KEYWORDS

})(typeof module !== 'undefined' ? module.exports : (window.PhpLexer = {}))
