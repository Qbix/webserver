/* transpilers/index.js — Unified entry point for all source-to-U transpilers.
 *
 * Each transpiler follows the same API:
 *   convert(source: string) → { code: string, messages: Array }
 *
 * Messages: [{ line: number, text: string, type: 'err'|'warn'|'info' }]
 */

;(function (exports) {
'use strict'

// PHP-to-U transpiler
if (typeof require !== 'undefined') {
  const { convertPHP } = require('./php-to-u')
  exports.convertPHP = convertPHP
} else if (typeof window !== 'undefined' && window.PhpToU) {
  exports.convertPHP = window.PhpToU.convertPHP
}

// JS/TS/Python transpilers (from translate.js)
if (typeof window !== 'undefined') {
  if (window.convertJS) exports.convertJS = window.convertJS
  if (window.convertTS) exports.convertTS = window.convertTS
  if (window.convertPy) exports.convertPy = window.convertPy
}

exports.languages = {
  php:        { name: 'PHP',        ext: '.php', convert: 'convertPHP' },
  javascript: { name: 'JavaScript', ext: '.js',  convert: 'convertJS' },
  typescript: { name: 'TypeScript', ext: '.ts',  convert: 'convertTS' },
  python:     { name: 'Python',     ext: '.py',  convert: 'convertPy' },
}

})(typeof module !== 'undefined' ? module.exports : (window.Transpilers = {}))
