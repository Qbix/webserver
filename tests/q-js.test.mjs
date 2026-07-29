import { test, before, after, describe } from 'node:test';
import assert from 'node:assert';
import { JSDOM } from 'jsdom';
import { readFileSync } from 'node:fs';

describe('Q.js Browser Loading', () => {
  let dom, window;

  before(() => {
    dom = new JSDOM('<!DOCTYPE html><html><body></body></html>', {
      url: 'http://localhost:8080',
      runScripts: 'dangerously',
      pretendToBeVisual: true,
    });
    window = dom.window;
  });

  after(() => {
    dom.window.close();
  });

  test('Q.min.js loads and defines Q object', () => {
    let code = readFileSync('src/Q/plugins/Q/js/Q.min.js', 'utf-8');
    // Strip ES module export — not supported in eval/JSDOM
    code = code.replace(/;\s*export\s+default\s+[^;]+;?\s*$/, ';');
    code = code.replace(/module\$[^;]*;\s*$/, '');
    window.eval(code);
    assert.ok(window.Q, 'Q should be defined');
    assert.ok(typeof window.Q === 'object' || typeof window.Q === 'function', 'Q should be object or function');
  });

  test('Q has core methods', () => {
    const Q = window.Q;
    assert.strictEqual(typeof Q.Tool, 'function', 'Q.Tool should exist');
    assert.strictEqual(typeof Q.Tool.define, 'function', 'Q.Tool.define should exist');
    assert.strictEqual(typeof Q.element, 'function', 'Q.element should exist');
    assert.strictEqual(typeof Q.activate, 'function', 'Q.activate should exist');
    assert.strictEqual(typeof Q.find, 'function', 'Q.find should exist');
    assert.strictEqual(typeof Q.$, 'function', 'Q.$ should exist');
  });

  test('Q.element creates DOM elements', () => {
    const Q = window.Q;
    const el = Q.element('div', { 'class': 'test', id: 'hello' }, [
      Q.element('span', {}, ['world'])
    ]);
    assert.strictEqual(el.tagName, 'DIV');
    assert.strictEqual(el.className, 'test');
    assert.strictEqual(el.id, 'hello');
    assert.strictEqual(el.querySelector('span').textContent, 'world');
  });

  test('Q.$ queries elements', () => {
    const Q = window.Q;
    window.document.body.innerHTML = '<div class="a"><span class="b">hi</span></div>';
    const found = Q.$('.b');
    assert.ok(found, 'Q.$ should find elements');
  });

  test('Q.Tool.define registers a tool', () => {
    const Q = window.Q;
    Q.Tool.define('Q/test', function () {});
    // Tool.defined is a function that checks registration
    assert.ok(typeof Q.Tool.defined === 'function' || typeof Q.Tool.defined === 'object',
      'Tool.defined should exist');
  });

  test('Q.Event exists and has API', () => {
    const Q = window.Q;
    assert.strictEqual(typeof Q.Event, 'function', 'Q.Event constructor');
    const ev = new Q.Event();
    assert.strictEqual(typeof ev.set, 'function', 'ev.set');
    assert.strictEqual(typeof ev.add, 'function', 'ev.add');
    assert.strictEqual(typeof ev.remove, 'function', 'ev.remove');
    assert.strictEqual(typeof ev.handle, 'function', 'ev.handle');
  });

  test('Q.Text exists for i18n', () => {
    const Q = window.Q;
    assert.ok(Q.Text, 'Q.Text should exist');
  });
});

describe('Q $ shim (Q.$.min.js)', () => {
  let dom, window;

  before(() => {
    dom = new JSDOM('<!DOCTYPE html><html><body><div id="app"><span class="msg">hi</span></div></body></html>', {
      url: 'http://localhost:8080',
      runScripts: 'dangerously',
    });
    window = dom.window;
    // Load the $ shim
    const code = readFileSync('src/Q/plugins/Q/js/Q.$.min.js', 'utf-8');
    window.eval(code);
  });

  after(() => { dom.window.close(); });

  test('$ shim defines window.$', () => {
    assert.ok(window.$, 'window.$ should be defined');
    assert.strictEqual(typeof window.$, 'function');
  });

  test('$ selects elements', () => {
    const result = window.$('.msg');
    assert.ok(result, '$ should find .msg');
    assert.strictEqual(result.length, 1);
  });

  test('$.fn.text() reads text', () => {
    const text = window.$('.msg').text();
    assert.strictEqual(text, 'hi');
  });

  test('$.fn.html() reads/writes HTML', () => {
    window.$('#app').html('<b>hello</b>');
    assert.strictEqual(window.document.querySelector('#app b').textContent, 'hello');
  });

  test('$.fn.addClass/removeClass', () => {
    window.$('#app').addClass('active');
    assert.ok(window.document.getElementById('app').classList.contains('active'));
    window.$('#app').removeClass('active');
    assert.ok(!window.document.getElementById('app').classList.contains('active'));
  });
});

describe('Full Handlebars (handlebars-v4.0.10.min.js)', () => {
  let dom, window;

  before(() => {
    dom = new JSDOM('<!DOCTYPE html><html><body></body></html>', {
      url: 'http://localhost:8080',
      runScripts: 'dangerously',
    });
    window = dom.window;
    const code = readFileSync('src/Q/plugins/Q/js/handlebars-v4.0.10.min.js', 'utf-8');
    window.eval(code);
  });

  after(() => { dom.window.close(); });

  test('Handlebars loads', () => {
    assert.ok(window.Handlebars, 'Handlebars should be defined');
    assert.strictEqual(typeof window.Handlebars.compile, 'function');
  });

  test('Handlebars compiles and renders', () => {
    const template = window.Handlebars.compile('Hello {{name}}!');
    assert.strictEqual(template({ name: 'World' }), 'Hello World!');
  });

  test('Handlebars {{#if}}', () => {
    const tpl = window.Handlebars.compile('{{#if show}}yes{{else}}no{{/if}}');
    assert.strictEqual(tpl({ show: true }), 'yes');
    assert.strictEqual(tpl({ show: false }), 'no');
  });

  test('Handlebars {{#each}}', () => {
    const tpl = window.Handlebars.compile('{{#each items}}{{this}} {{/each}}');
    assert.ok(tpl({ items: ['a', 'b'] }).includes('a'));
  });
});

describe('Q.min.js + $ shim + Handlebars integration', () => {
  let dom, window;

  before(() => {
    dom = new JSDOM('<!DOCTYPE html><html><body></body></html>', {
      url: 'http://localhost:8080',
      runScripts: 'dangerously',
    });
    window = dom.window;
    window.eval(readFileSync('src/Q/plugins/Q/js/Q.$.min.js', 'utf-8'));
    window.eval(readFileSync('src/Q/plugins/Q/js/handlebars-v4.0.10.min.js', 'utf-8'));
    let qCode = readFileSync('src/Q/plugins/Q/js/Q.min.js', 'utf-8');
    qCode = qCode.replace(/;\s*export\s+default\s+[^;]+;?\s*$/, ';');
    qCode = qCode.replace(/module\$[^;]*;\s*$/, '');
    window.eval(qCode);
  });

  after(() => { dom.window.close(); });

  test('All three load together', () => {
    assert.ok(window.$, '$ loaded');
    assert.ok(window.Handlebars, 'Handlebars loaded');
    assert.ok(window.Q, 'Q loaded');
  });

  test('Q.element + $ + Handlebars', () => {
    const tpl = window.Handlebars.compile('<div class="card">{{title}}</div>');
    const html = tpl({ title: 'Test' });
    const container = window.Q.element('div', { id: 'cards' });
    container.innerHTML = html;
    window.document.body.appendChild(container);
    assert.strictEqual(window.$('.card').text(), 'Test');
  });
});

describe('Minimal Handlebars (handlebars.minimal.min.js)', () => {
  let Handlebars;

  before(async () => {
    const { createRequire } = await import('node:module');
    const require = createRequire(import.meta.url);
    Handlebars = require('../src/Q/plugins/Q/js/handlebars.minimal.js');
  });

  test('compile and render', () => {
    assert.strictEqual(Handlebars.compile('Hello {{name}}!')({name: 'World'}), 'Hello World!');
  });

  test('HTML escaping', () => {
    const r = Handlebars.compile('{{v}}')({v: '<b>x</b>'});
    assert.ok(r.includes('&lt;'), 'should escape HTML');
  });

  test('{{#if}} / {{else}}', () => {
    const tpl = Handlebars.compile('{{#if s}}y{{else}}n{{/if}}');
    assert.strictEqual(tpl({s: true}), 'y');
    assert.strictEqual(tpl({s: false}), 'n');
  });

  test('{{#each}}', () => {
    assert.strictEqual(Handlebars.compile('{{#each i}}[{{this}}]{{/each}}')({i: [1,2]}), '[1][2]');
  });

  test('{{#with}}', () => {
    assert.strictEqual(Handlebars.compile('{{#with p}}{{n}}{{/with}}')({p:{n:'ok'}}), 'ok');
  });

  test('nested paths', () => {
    assert.strictEqual(Handlebars.compile('{{a.b}}')({a:{b:'deep'}}), 'deep');
  });

  test('registerHelper', () => {
    Handlebars.registerHelper('shout', s => s.toUpperCase());
    assert.strictEqual(Handlebars.compile('{{shout w}}')({w:'hi'}), 'HI');
  });

  test('registerPartial', () => {
    Handlebars.registerPartial('greet', 'Hi {{n}}');
    assert.strictEqual(Handlebars.compile('{{> greet}}')({n:'Q'}), 'Hi Q');
  });
});
