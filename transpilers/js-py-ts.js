
/* The playground uses plain textareas, so CodeMirror may be absent. The
   translators themselves need nothing but acorn -- only the editor wiring did.
   Stubbing it here keeps this file usable headless (the validation harness runs
   it in node) and in a page that has no editor. */
if (typeof CodeMirror === 'undefined') {
  var CodeMirror = function () { return null; };
  CodeMirror.defineMode = function () {};
  CodeMirror.defineMIME = function () {};
  CodeMirror.fromTextArea = function () {
    return { on: function () {}, setValue: function () {}, getValue: function () { return ''; },
             refresh: function () {}, setOption: function () {} };
  };
}

/* U translators — JavaScript / TypeScript / Python -> U.
   Extracted from the js-to-u prototype. Entry points: convertJS(src),
   convertPy(src). Requires acorn for the JS/TS paths; the Python path is
   a hand-written scanner. Liberal in what it accepts, strict in what it
   emits -- output is fed to the real u2c, so 'looks like U' is not
   enough. */

// U Mode inline
(function(C){"use strict";C.defineMode("u-lang",function(){const k=new Set("a c d e f o r t w x z".split(" ")),l=new Set(["none","true","false"]),t=new Set("I N S L B Q U".split(" "));return{startState:()=>({ic:false}),token:function(s,st){if(st.ic){if(s.match("*/")){st.ic=false;return"u-comment"}s.next();return"u-comment"}if(s.match("/*")){st.ic=true;return"u-comment"}if(s.match("//")){s.skipToEnd();return"u-comment"}if(s.match('"')){while(!s.eol()){var c=s.next();if(c==="\\")s.next();else if(c==='"')break}return"u-string"}if(s.match(/^-?\d+(\.\d+)?([eE][+-]?\d+)?/))return"u-number";if(s.match(/^[+-][A-Z](\([^)]*\))?/))return"u-modifier";if(s.match("=>")||s.match("??")||s.match("?.")||s.match("::")||s.match("...")||s.match("..")||s.match("!=")||s.match("==")||s.match(">=")||s.match("<=")||s.match("+=")||s.match("-="))return"u-operator";if(s.match(/^[?!@&|<>+\-*\/%=^~]/))return"u-operator";if(s.match(/^[a-zA-Z_]\w*/)){var w=s.current();if(k.has(w))return"u-keyword";if(l.has(w))return"u-bool";if(t.has(w)&&w.length===1)return"u-type";if(w[0]===w[0].toUpperCase()&&w.length>1&&/[a-z]/.test(w))return"u-type";return"u-ident"}if(s.match(/^[(){}\[\]:;,.]/))return"u-operator";s.next();return null},lineComment:"//"}}); C.defineMIME("text/x-u","u-lang")})(CodeMirror);

const EX={javascript:[
["Hello World","function greet(name) {\n  return \"Hello, \" + name + \"!\";\n}\nconsole.log(greet(\"World\"));"],
["Fibonacci","function fib(n) {\n  if (n < 2) return n;\n  return fib(n - 1) + fib(n - 2);\n}"],
["Factorial","function factorial(n) {\n  if (n <= 1) return 1;\n  return n * factorial(n - 1);\n}"],
["Map + Filter","const nums = [1, 2, 3, 4, 5];\nconst result = nums\n  .filter(x => x > 2)\n  .map(x => x * 2);"],
["Reduce sum","function sum(arr) {\n  return arr.reduce((acc, val) => acc + val, 0);\n}"],
["Find first","function findBig(arr) {\n  return arr.find(x => x > 100);\n}"],
["Class + method","class Point {\n  constructor(x, y) {\n    this.x = x;\n    this.y = y;\n  }\n  distanceTo(other) {\n    const dx = this.x - other.x;\n    const dy = this.y - other.y;\n    return Math.sqrt(dx * dx + dy * dy);\n  }\n}"],
["Inheritance","class Shape {\n  area() { return 0; }\n}\nclass Circle extends Shape {\n  constructor(r) { super(); this.r = r; }\n  area() { return 3.14159 * this.r * this.r; }\n}"],
["Null guard","function safeDivide(a, b) {\n  if (b === 0) return null;\n  return a / b;\n}"],
["Clamp","function clamp(val, lo, hi) {\n  if (val < lo) return lo;\n  if (val > hi) return hi;\n  return val;\n}"],
["String ops","function normalize(s) {\n  return s.strip().toLowerCase().replace(/ /g, \"_\");\n}"],
["Higher-order","function apply(fn, value) {\n  return fn(value);\n}\nconst result = apply(x => x * 2, 21);"],
["Default param","function greet(name = \"World\") {\n  return \"Hello, \" + name;\n}"],
["Ternary chain","function classify(n) {\n  return n > 0 ? \"positive\" : n < 0 ? \"negative\" : \"zero\";\n}"],
["Object literal","const config = {\n  host: \"localhost\",\n  port: 5432,\n  debug: false\n};"],
["Arrow fns","const double = x => x * 2;\nconst add = (a, b) => a + b;\nconst greet = name => \"Hi \" + name;"],
["Split + map","const text = \"hello world foo\";\nconst lengths = text.split(\" \").map(w => w.length);"],
["For loop","function sumRange(n) {\n  let total = 0;\n  for (let i = 1; i <= n; i++) {\n    total += i;\n  }\n  return total;\n}"],
["Async","async function fetchData(url) {\n  const response = await fetch(url);\n  return response.json();\n}"],
["Set check","function hasItem(s, item) {\n  return s.has(item);\n}"],
["typeof guard","function isNum(val) {\n  if (typeof val === 'number') return true;\n  return false;\n}"],
["Template literal","function greet(name, age) {\n  return \`Hello \${name}, you are \${age}\`;\n}"]
],python:[
["Hello World","def greet(name):\n    return f\"Hello, {name}!\"\nprint(greet(\"World\"))"],
["Fibonacci","def fib(n):\n    if n < 2:\n        return n\n    return fib(n - 1) + fib(n - 2)"],
["Factorial","def factorial(n):\n    if n <= 1:\n        return 1\n    return n * factorial(n - 1)"],
["List comp","nums = [1, 2, 3, 4, 5]\nresult = [x * 2 for x in nums if x > 2]"],
["Class","class Point:\n    def __init__(self, x, y):\n        self.x = x\n        self.y = y\n    def distance_to(self, other):\n        dx = self.x - other.x\n        dy = self.y - other.y\n        return (dx * dx + dy * dy) ** 0.5"],
["Inheritance","class Shape:\n    def area(self):\n        return 0\n\nclass Circle(Shape):\n    def __init__(self, r):\n        self.r = r\n    def area(self):\n        return 3.14159 * self.r * self.r"],
["Guard","def safe_divide(a, b):\n    if b == 0:\n        return None\n    return a / b"],
["Clamp","def clamp(val, lo, hi):\n    if val < lo:\n        return lo\n    if val > hi:\n        return hi\n    return val"],
["String ops","def normalize(s):\n    return s.strip().lower().replace(\" \", \"_\")"],
["Default param","def greet(name=\"World\"):\n    return f\"Hello, {name}\""],
["Lambda+map","double = lambda x: x * 2\nnums = list(map(double, [1, 2, 3]))"],
["Ternary","def classify(n):\n    return \"positive\" if n > 0 else \"negative\" if n < 0 else \"zero\""],
["Dict","config = {\n    \"host\": \"localhost\",\n    \"port\": 5432,\n    \"debug\": False\n}"],
["For loop","def sum_range(n):\n    total = 0\n    for i in range(1, n + 1):\n        total += i\n    return total"],
["Async","async def fetch_data(url):\n    response = await aiohttp.get(url)\n    return await response.json()"],
["Set check","def has_item(s, item):\n    return item in s"],
["Split+join","def word_count(text):\n    words = text.split(\" \")\n    return len(words)"],
["Type hints","def add(a: int, b: int) -> int:\n    return a + b"],
["Enumerate","def find_index(arr, target):\n    for i, val in enumerate(arr):\n        if val == target:\n            return i\n    return -1"],
["Type check","def is_string(val):\n    return isinstance(val, str)"],
["Type convert","def to_int(val):\n    return int(val)"],
["Decorator","def logged(fn):\n    def wrapper(*args):\n        print(f\"calling {fn.__name__}\")\n        return fn(*args)\n    return wrapper"]
],typescript:[
["Hello World","function greet(name: string): string {\n  return \"Hello, \" + name + \"!\";\n}"],
["Typed function","function add(a: number, b: number): number {\n  return a + b;\n}"],
["Interface","interface Drawable {\n  draw(): void;\n  resize(w: number, h: number): void;\n}"],
["Generic class","class Box<T> {\n  value: T;\n  constructor(val: T) { this.value = val; }\n  get(): T { return this.value; }\n}"],
["Enum","enum Color {\n  Red = 0,\n  Green = 1,\n  Blue = 2\n}"],
["Nullable","function safeDivide(a: number, b: number): number | null {\n  if (b === 0) return null;\n  return a / b;\n}"],
["Array methods","function process(items: number[]): number {\n  return items\n    .filter(x => x > 0)\n    .map(x => x * 2)\n    .reduce((a, b) => a + b, 0);\n}"],
["Class + method","class Point {\n  x: number;\n  y: number;\n  constructor(x: number, y: number) {\n    this.x = x;\n    this.y = y;\n  }\n  distanceTo(other: Point): number {\n    const dx = this.x - other.x;\n    const dy = this.y - other.y;\n    return Math.sqrt(dx * dx + dy * dy);\n  }\n}"],
["Async","async function fetchData(url: string): Promise<string> {\n  const response = await fetch(url);\n  return response.text();\n}"],
["Union type","function format(val: number | string): string {\n  if (typeof val === 'number') return val.toString();\n  return val;\n}"],
["Readonly","interface Config {\n  readonly host: string;\n  readonly port: number;\n}"],
["Record","const scores: Record<string, number> = {\n  alice: 95,\n  bob: 87\n};"],
["Type guard","function isString(val: unknown): val is string {\n  return typeof val === 'string';\n}"],
["Optional param","function greet(name: string, greeting?: string): string {\n  return (greeting || 'Hello') + ', ' + name;\n}"],
["Destructuring","function getXY(point: {x: number, y: number}): [number, number] {\n  const { x, y } = point;\n  return [x, y];\n}"],
["Map/Set","function uniqueWords(text: string): Set<string> {\n  return new Set(text.split(' '));\n}"],
["For...of","function sum(arr: number[]): number {\n  let total = 0;\n  for (const x of arr) total += x;\n  return total;\n}"],
["Switch","function httpStatus(code: number): string {\n  switch(code) {\n    case 200: return 'OK';\n    case 404: return 'Not Found';\n    case 500: return 'Server Error';\n    default: return 'Unknown';\n  }\n}"],
["Try/catch","function safeParse(json: string): any {\n  try {\n    return JSON.parse(json);\n  } catch (e) {\n    return null;\n  }\n}"],
["Generic fn","function first<T>(arr: T[]): T | undefined {\n  return arr[0];\n}"]
]
};

let lang='javascript',srcE,outE,dbt=null;
function popEx(){const s=document.getElementById('examples');if(!s)return;s.innerHTML='<option value="">Load example\u2026</option>';EX[lang].forEach(([n],i)=>{const o=document.createElement('option');o.value=i;o.textContent=n;s.appendChild(o)})}
function loadExample(i){if(i===''||!srcE)return;srcE.setValue(EX[lang][+i][1]);document.getElementById('examples').value=''}
function switchLang(l){lang=l;
  // TypeScript uses JS parser (Acorn) — TS type annotations stripped pre-parse
  const cmMode = (l === 'typescript') ? 'javascript' : l;document.querySelectorAll('.tab').forEach(t=>t.classList.toggle('active',t.dataset.lang===l));document.getElementById('src-lang').textContent=l==='javascript'?'JavaScript':l==='typescript'?'TypeScript':'Python';srcE.setOption('mode',cmMode);popEx();doTranspile()}

function uName(nm){if(typeof nm!=='string')return nm;
// U forbids single-letter NAMES (variables, params, functions, classes).
// Rename by doubling the letter: n->nn, x->xx, i->ii. Properties are emitted
// elsewhere and are left single-letter (they're valid after '.').
if(nm.length===1&&/[A-Za-z]/.test(nm))return nm+nm;
return nm;}
function inferType(n,ctx){if(!n)return null;ctx=ctx||{};if(n.type==='Literal'){if(typeof n.value==='number')return Number.isInteger(n.value)?'I':'N';if(typeof n.value==='string')return'S';if(typeof n.value==='boolean')return'L';if(n.value===null)return'none'}if(n.type==='TemplateLiteral')return'S';if(n.type==='ArrayExpression')return'[I] +R';if(n.type==='BinaryExpression'){if(['==','!=','<','>','<=','>=','===','!=='].includes(n.operator))return'L';const l=inferType(n.left),r=inferType(n.right);if(l==='N'||r==='N')return'N';if(l==='S'||r==='S')return'S';return'I'}if(n.type==='UnaryExpression'&&n.operator==='!')return'L';if(n.type==='ConditionalExpression')return inferType(n.consequent);return null}

function jx(n){if(!n)return'none';switch(n.type){
case'Literal':if(n.regex){var _pat=n.regex.pattern;if(/^[a-zA-Z0-9 _\-,.]+$/.test(_pat))return JSON.stringify(_pat);return'Regex`'+_pat+'`'+(n.regex.flags||'')}return n.value===null?'none':n.value===true?'true':n.value===false?'false':typeof n.value==='string'?JSON.stringify(n.value):String(n.value);
case'Identifier':return n.name==='undefined'?'none':n.name==='Infinity'?'w':uName(n.name);
case'TemplateLiteral':{let s='`';n.quasis.forEach((q,i)=>{s+=q.value.raw;if(i<n.expressions.length)s+='{{'+jx(n.expressions[i])+'}}'});return s+'`'}
case'BinaryExpression':case'LogicalExpression':{
      // typeof x === 'string' → x :: S
      if ((n.operator === '===' || n.operator === '==') && n.left.type === 'UnaryExpression' && n.left.operator === 'typeof' && n.right.type === 'Literal') {
        const typeMap = {string:'S',number:'N',boolean:'L',undefined:'none',object:'+R'};
        const uType = typeMap[n.right.value] || n.right.value;
        return jx(n.left.argument) + ' :: ' + uType;
      }
      if ((n.operator === '!==' || n.operator === '!=') && n.left.type === 'UnaryExpression' && n.left.operator === 'typeof' && n.right.type === 'Literal') {
        const typeMap = {string:'S',number:'N',boolean:'L',undefined:'none'};
        const uType = typeMap[n.right.value] || n.right.value;
        return '!(' + jx(n.left.argument) + ' :: ' + uType + ')';
      }let o=n.operator;if(o==='===')o='==';if(o==='!==')o='!=';if(o==='&&')o='&';if(o==='||')o='??';if(o==='**')o='^';return jx(n.left)+' '+o+' '+jx(n.right)}
case'AwaitExpression':return jx(n.argument);
      case'UnaryExpression':return n.operator==='!'?'!'+jx(n.argument):n.operator==='-'?'-'+jx(n.argument):n.operator+jx(n.argument);
case'UpdateExpression':return n.argument.name+' = '+n.argument.name+(n.operator==='++'?' + 1':' - 1');
case'AssignmentExpression':{const l=jx(n.left);if(n.operator==='=')return l+' = '+jx(n.right);const o=n.operator.slice(0,-1);return l+' = '+l+' '+o+' '+jx(n.right)}
case'ConditionalExpression':return jx(n.test)+' ? '+jx(n.consequent)+' ! '+jx(n.alternate);
case'MemberExpression':{let o=jx(n.object);if(o==='console')return'log';if(o==='Math'){const m=n.property.name||n.property.value;if(m==='PI')return'3.14159';if(m==='sqrt')return'// use ^ 0.5';return m}const p=n.computed?(n.property.type==='Literal'&&typeof n.property.value==='number'?'['+(n.property.value+1)+']':'['+jx(n.property)+' + 1]'):'.'+(n.property.name||n.property.value);return o+p}
case'CallExpression':{if(n.callee.type==='MemberExpression'&&jx(n.callee.object)==='Math'){const mm=n.callee.property.name||n.callee.property.value;const ar=n.arguments.map(x=>jx(x));if(mm==='sqrt')return'('+ar[0]+') ^ 0.5';if(mm==='pow')return'('+ar[0]+') ^ '+ar[1];if(mm==='abs')return'('+ar[0]+').abs()';if(mm==='floor')return'('+ar[0]+').floor()';if(mm==='ceil')return'('+ar[0]+').ceil()';if(mm==='round')return'('+ar[0]+').round()';if(mm==='max')return ar.join('.max(')+')'.repeat(Math.max(0,ar.length-1));if(mm==='min')return ar.join('.min(')+')'.repeat(Math.max(0,ar.length-1));return'('+ar.join(', ')+')'}if(n.callee.type==='MemberExpression'&&(n.callee.property.name==='toString')){return'S('+jx(n.callee.object)+')'}if(n.callee.type==='MemberExpression'&&jx(n.callee.object)==='JSON'){const jm=n.callee.property.name;if(jm==='parse')return'json.decode('+n.arguments.map(x=>jx(x)).join(', ')+')';if(jm==='stringify')return'json.encode('+n.arguments.map(x=>jx(x)).join(', ')+')';return'json.'+jm+'('+n.arguments.map(x=>jx(x)).join(', ')+')'}const c=jx(n.callee),a=n.arguments.map(x=>jx(x)).join(', ');if(c==='log'||c==='log.log')return'log('+a+')';
      if(c==='Array.isArray')return'('+a+' :: [I])';
      if(c==='Object.keys')return a+'.keys()';
      if(c==='Object.values')return a+'.values()';
      if(c==='String'||c==='S')return'S('+a+')';
      if(c==='Number'||c==='parseInt'||c==='parseFloat')return'N('+a+')';
      if(c==='Boolean')return'L('+a+')';if(c.includes('Math.sqrt'))return'('+a+') ^ 0.5';let cc=c;cc=cc.replace('.forEach','.on').replace('.indexOf','.index_of').replace('.toLowerCase','.to_lower').replace('.toUpperCase','.to_upper').replace('.startsWith','.starts_with').replace('.endsWith','.ends_with').replace('.includes','.includes');// More method mappings
      cc=cc.replace('.forEach(','.on(').replace('.indexOf(','.index(').replace('.toLowerCase()','.lower()').replace('.toUpperCase()','.upper()').replace('.startsWith(','.startswith(').replace('.endsWith(','.endswith(').replace('.toString()','')  /* S(x) is the U form */.replace('.padStart(','.pad_start(').replace('.padEnd(','.pad_end(').replace('.charAt(','.char_at(').replace('.substring(','.slice(').replace('.some(','.any(').replace('.every(','.all(').replace('.findIndex(','.index(');
      cc=cc.replace(/([a-zA-Z_]\w*(?:\.\w+)*)\.to_string\(\)/g,'S($1)').replace(/([a-zA-Z_]\w*(?:\.\w+)*)\.toString\(\)/g,'S($1)');
      if(cc.endsWith('.length'))cc=cc.replace('.length','.len');return cc+'('+a+')'}
case'ArrowFunctionExpression':case'FunctionExpression':{const pl=n.params.map(x=>uName(x.type==='AssignmentPattern'?jx(x.left):(x.name||jx(x))));const p=pl.length===1?pl[0]:'('+pl.join(', ')+')';if(n.body.type==='BlockStatement'){if(n.body.body.length===1&&n.body.body[0].type==='ReturnStatement')return p+' => '+jx(n.body.body[0].argument);return p+' => ('+n.body.body.map(s=>js(s,'\t')).join('; ')+')'}return p+' => '+jx(n.body)}
case'ArrayExpression':return'['+n.elements.map(e=>jx(e)).join(', ')+']';
case'ObjectExpression':return'{'+n.properties.map(p=>{const k=p.key.name||p.key.value||jx(p.key);return k+': '+jx(p.value)}).join(', ')+'}';
case'ThisExpression':return't';
case'NewExpression':return jx(n.callee)+'('+n.arguments.map(a=>jx(a)).join(', ')+')';
case'ChainExpression':return jx(n.expression);
case'SpreadElement':return'...'+jx(n.argument);
case'SequenceExpression':return n.expressions.map(e=>jx(e)).join('; ');
case'ImportExpression':return'/* dynamic import */ require('+jx(n.source)+')';
case'ArrayPattern':return'['+n.elements.map(e=>e?jx(e):'_').join(', ')+']';
case'AssignmentPattern':return jx(n.left)+' = '+jx(n.right);
default:return'/* '+n.type+' */'}}

function grt(b){if(!b)return null;for(const s of b){if(s.type==='ReturnStatement'&&s.argument)return inferType(s.argument);if(s.type==='IfStatement'){const t=grt(s.consequent.body||[s.consequent]);if(t)return t}}return null}

function js(n,ind){if(ind==null)ind='\t';if(!n)return'';switch(n.type){
case'FunctionDeclaration':{const nm=uName(n.id?n.id.name:'_anon');// Scan body for parameter type hints
      const paramTypes = {};
      const msgs = [];
      if (n.body && n.body.body) scanBodyForTypes(n.body.body, paramTypes, msgs);
      const ps=n.params.map(p=>{const _orig = p.type==='AssignmentPattern'?(p.left.name||jx(p.left)):(p.name||jx(p));const pname = uName(_orig);const nn = pname;
      const pt = p.type==='AssignmentPattern' ? inferType(p.right) : (paramTypes[_orig] || null);const df=p.type==='AssignmentPattern'?' = '+jx(p.right):'';return pt?nn+': '+pt+df:nn+': I'+df}).join(', ');const b=n.body.body.map(s=>js(s,ind+'\t')).join('\n');const rt=grt(n.body.body);return(n.async?'f+A ':'f ')+nm+'('+ps+')'+(rt?' -> '+rt:'')+'\n'+b}
case'ReturnStatement':return ind+'r => '+(n.argument?jx(n.argument):'none');
case'VariableDeclaration':{
      const d = n.declarations[0];
      // Destructuring: const {a, b} = obj → {a, b} = obj
      if (d.id.type === 'ObjectPattern') {
        const names = d.id.properties.map(p => uName(p.key.name || jx(p.key)));
        const init = jx(d.init);
        return ind + '{' + names.join(', ') + '} = ' + init;
      }
      // Array destructuring: const [a, b] = arr → first = arr[0]; second = arr[1]
      if (d.id.type === 'ArrayPattern') {
        const elems = d.id.elements.map((e2, i) => ind + (e2 ? uName(e2.name || jx(e2)) : '_') + ' = ' + jx(d.init) + '[' + (i+1) + ']');
        return elems.join('\n');
      }
      return n.declarations.map(d=>{const nm=d.id.name||jx(d.id);if(!d.init)return ind+nm+' = none';const v=jx(d.init);const tp=inferType(d.init);const mt=n.kind==='let'?' +M':'';return ind+nm+(tp?': '+tp+mt:'')+' = '+v}).join('\n');}
case'ExpressionStatement':return ind+jx(n.expression);
case'IfStatement':{const asList=b=>!b?[]:b.type==='BlockStatement'?b.body:[b];const cons=asList(n.consequent);const alt=asList(n.alternate);const isRet=st=>st&&st.type==='ReturnStatement';
// if (cond) return x   ->   cond ? r => x
if(alt.length===0&&cons.length===1&&isRet(cons[0])){const r=cons[0].argument;return ind+jx(n.test)+' ? r => '+(r?jx(r):'none')}
// if (cond) <single stmt>   ->   cond ? (stmt)
if(alt.length===0&&cons.length===1){return ind+jx(n.test)+' ? ('+js(cons[0],'').trim()+')'}
// if (cond) return a; else return b   ->   cond ? r => a ! r => b
if(cons.length===1&&isRet(cons[0])&&alt.length===1&&isRet(alt[0])){const a=cons[0].argument,b=alt[0].argument;return ind+jx(n.test)+' ? r => '+(a?jx(a):'none')+' ! r => '+(b?jx(b):'none')}
// General fallback: emit guarded blocks as commented structure (never crash).
let s=ind+'// if '+jx(n.test)+'\n'+cons.map(x=>js(x,ind+'\t')).join('\n');if(alt.length){s+='\n'+ind+'// else\n'+alt.map(x=>js(x,ind+'\t')).join('\n')}return s}
case'WhileStatement':{const cd=jx(n.test);const wb=n.body.type==='BlockStatement'?n.body.body.map(x=>js(x,ind+'\t')).join('\n'):js(n.body,ind+'\t');return ind+'[1..w].on(_ => (\n'+ind+'\t!('+cd+') ? true ! false\n'+wb+'\n'+ind+'))'}
    case'ForOfStatement':{
      const vn = uName(n.left.declarations ? n.left.declarations[0].id.name : jx(n.left));
      const iter = jx(n.right);
      const b = n.body.type==='BlockStatement' ? n.body.body.map(x=>js(x,ind+'\t')).join('\n') : js(n.body,ind+'\t');
      return ind+iter+'.on('+vn+' => (\n'+b+'\n'+ind+'))'}
    case'ForInStatement':{
      const vn2 = n.left.declarations ? n.left.declarations[0].id.name : jx(n.left);
      const obj2 = jx(n.right);
      return ind+obj2+'.on(('+vn2+', _val) => (\n'+ind+'\t// for...in body\n'+ind+'))'}
    case'ForStatement':{let st='1',en='nn',vn='idx';if(n.init&&n.init.declarations){const d=n.init.declarations[0];vn=uName(d.id.name);st=jx(d.init)}if(n.test&&n.test.right)en=jx(n.test.right);const inc=n.test&&(n.test.operator==='<='||n.test.operator==='>=');const dt=inc?'..':'...';const b=n.body.type==='BlockStatement'?n.body.body.map(x=>js(x,ind+'\t')).join('\n'):js(n.body,ind+'\t');return ind+'['+st+dt+en+'].on('+vn+' => (\n'+b+'\n'+ind+'))'}
case'ClassDeclaration':{const nm=uName(n.id.name);const sup=n.superClass?' : '+jx(n.superClass):'';let flds=[],mths=[];for(const m of n.body.body){if(m.kind==='constructor'){for(const s of m.value.body.body)if(s.type==='ExpressionStatement'&&s.expression.type==='AssignmentExpression'&&s.expression.left.type==='MemberExpression'&&jx(s.expression.left.object)==='t'){const fn=s.expression.left.property.name;const tp=inferType(s.expression.right)||'I';const zero=(tp==='S'?'""':tp==='L'?'false':tp==='N'?'0.0':'0');flds.push(ind+'\t'+fn+': '+tp+' +M = '+zero)}}else if(m.kind==='method'){const mn=m.key.name;const ps=m.value.params.map(p=>uName(p.name||jx(p))+': I').join(', ');const mb=m.value.body.body.map(s=>js(s,ind+'\t\t')).join('\n');const rt=grt(m.value.body.body);mths.push(ind+'\tf '+mn+'('+ps+')'+(rt?' -> '+rt:'')+'\n'+mb)}}return'd '+nm+sup+'\n'+flds.join('\n')+(flds.length&&mths.length?'\n':'')+mths.join('\n')}
case'SwitchStatement':{
      const disc = jx(n.discriminant);
      let out2 = '';
      for (const c of n.cases) {
        if (c.test) {
          // case val: → disc == val ? r => ... (if body is return)
          const body = c.consequent.filter(s=>s.type!=='BreakStatement');
          if (body.length===1 && body[0].type==='ReturnStatement') {
            out2 += ind+disc+' == '+jx(c.test)+' ? r => '+(body[0].argument?jx(body[0].argument):'none')+'\n';
          } else {
            out2 += ind+'// case '+jx(c.test)+':\n';
            body.forEach(s => { out2 += js(s, ind+'\t')+'\n'; });
          }
        } else {
          // default:
          const body = c.consequent.filter(s=>s.type!=='BreakStatement');
          body.forEach(s => { out2 += js(s, ind)+'\n'; });
        }
      }
      return out2.trimEnd()}
    case'BlockStatement':case'BlockStatement':return n.body.map(s=>js(s,ind)).join('\n');
case'ThrowStatement':return ind+'x '+jx(n.argument);
    case'TryStatement':{
      // try { body } catch(e) { handler } → body x.on((err: Error) => handler)
      const tryBody = (n.block.body||[]).map(s=>js(s,ind)).join('\n');
      if(!n.handler) return tryBody;
      let p = n.handler.param ? n.handler.param.name : 'err';
      if (p === 'e') p = 'err';
      if (p.length === 1) p = p + p;
      const catchBody = (n.handler.body.body||[]).map(s=>js(s,ind+'\t\t')).join('\n');
      // Single-statement try → inline x.on()
      const tryStmts = (n.block.body||[]).filter(s=>s.type!=='EmptyStatement');
      if(tryStmts.length===1){
        const s=tryStmts[0];
        // return expr → r => expr x.on(...)
        if(s.type==='ReturnStatement'&&s.argument){
          const val=jx(s.argument);
          // Single-line catch body → extract the value
          const catchStmts=(n.handler.body.body||[]).filter(s2=>s2.type!=='EmptyStatement');
          if(catchStmts.length===1&&catchStmts[0].type==='ReturnStatement'){
            const fb=jx(catchStmts[0].argument);
            return ind+'r => '+val+' x.on(\n'+ind+'\t('+p+': Error) => '+fb+'\n'+ind+')';
          }
          return ind+'r => '+val+' x.on(\n'+ind+'\t('+p+': Error) => (\n'+catchBody+'\n'+ind+'\t)\n'+ind+')';
        }
        // assignment: x = expr → x = expr x.on(...)
        if(s.type==='ExpressionStatement'&&s.expression.type==='AssignmentExpression'){
          const lhs=jx(s.expression.left), rhs=jx(s.expression.right);
          const catchStmts=(n.handler.body.body||[]).filter(s2=>s2.type!=='EmptyStatement');
          if(catchStmts.length===1&&catchStmts[0].type==='ExpressionStatement'&&
             catchStmts[0].expression.type==='AssignmentExpression'&&
             jx(catchStmts[0].expression.left)===lhs){
            const fb=jx(catchStmts[0].expression.right);
            return ind+lhs+' = '+rhs+' x.on(\n'+ind+'\t('+p+': Error) => '+fb+'\n'+ind+')';
          }
        }
        if(s.type==='VariableDeclaration'&&s.declarations.length===1){
          const d=s.declarations[0], nm=jx(d.id), val=jx(d.init);
          return ind+nm+' = '+val+' x.on(\n'+ind+'\t('+p+': Error) => (\n'+catchBody+'\n'+ind+'\t)\n'+ind+')';
        }
      }
      // Multi-statement → wrap in block x.on(...)
      return ind+'(\n'+tryBody+'\n'+ind+') x.on(\n'+ind+'\t('+p+': Error) => (\n'+catchBody+'\n'+ind+'\t)\n'+ind+')';
    }
    case'BreakStatement':return '';
    case'ContinueStatement':return ind+'// continue';
    case'DoWhileStatement':{const db=n.body.type==='BlockStatement'?n.body.body.map(x=>js(x,ind+'\t')).join('\n'):js(n.body,ind+'\t');return ind+'// do-while\n'+db+'\n'+ind+jx(n.test)+' ? (\n'+db+'\n'+ind+')'}
    case'DebuggerStatement':return ind+'// debugger';
    case'LabeledStatement':return ind+'// label: '+n.label.name+'\n'+js(n.body,ind);
    case'EmptyStatement':return '';
    default:return ind+jx(n)}}


// Scan function body to infer parameter types from usage
function scanBodyForTypes(stmts, types, msgs) {
  function scanExpr(node, paramNames) {
    if (!node) return;
    // param used in comparison → likely I or N
    if (node.type === 'BinaryExpression') {
      const op = node.operator;
      if (['<','>','<=','>='].includes(op)) {
        tagType(node.left, 'I', paramNames, types);
        tagType(node.right, 'I', paramNames, types);
      }
      if (['+'].includes(op)) {
        // + is ambiguous: I, N, or S
        const lt = inferType(node.left), rt = inferType(node.right);
        if (lt === 'S' || rt === 'S') {
          tagType(node.left, 'S', paramNames, types);
          tagType(node.right, 'S', paramNames, types);
          // Warn about implicit string conversion
          if (lt && lt !== 'S' && node.left.type === 'Identifier' && paramNames.has(node.left.name))
            msgs.push({line: node.left.start, text: node.left.name + ': implicit conversion to S in + (add .string())', type: 'warn'});
          if (rt && rt !== 'S' && node.right.type === 'Identifier' && paramNames.has(node.right.name))
            msgs.push({line: node.right.start, text: node.right.name + ': implicit conversion to S in + (add .string())', type: 'warn'});
        } else if (lt === 'N' || rt === 'N') {
          tagType(node.left, 'N', paramNames, types);
          tagType(node.right, 'N', paramNames, types);
        } else {
          tagType(node.left, 'I', paramNames, types);
          tagType(node.right, 'I', paramNames, types);
        }
      }
      if (['-','*','/','%'].includes(op)) {
        tagType(node.left, 'I|N', paramNames, types);
        tagType(node.right, 'I|N', paramNames, types);
      }
      if (['===','!==','==','!='].includes(op)) {
        // equality — don't constrain type unless compared to literal
        if (node.right.type === 'Literal' && node.right.value === null)
          tagType(node.left, '+N', paramNames, types); // nullable
        if (node.right.type === 'Literal' && node.right.value === 0)
          tagType(node.left, 'I', paramNames, types);
      }
      scanExpr(node.left, paramNames);
      scanExpr(node.right, paramNames);
    }
    if (node.type === 'MemberExpression' && !node.computed) {
      const prop = node.property.name;
      if (prop === 'length') { /* length is ambiguous (string or list) — don't tag Any */ }
      scanExpr(node.object, paramNames);
      if (['strip','trim','trimStart','trimEnd','toLowerCase','toUpperCase','lower','upper','split','replace','charAt','substring','substr','startsWith','endsWith','padStart','padEnd','repeat'].indexOf(prop) >= 0)
        tagType(node.object, 'S', paramNames, types);
      if (prop === 'push' || prop === 'pop' || prop === 'map' || prop === 'filter' || prop === 'reduce' || prop === 'forEach' || prop === 'find' || prop === 'some' || prop === 'every')
        tagType(node.object, '[I] +R', paramNames, types);
    }
    if (node.type === 'CallExpression') {
      scanExpr(node.callee, paramNames);
      node.arguments.forEach(a => scanExpr(a, paramNames));
    }
    if (node.type === 'ConditionalExpression') {
      scanExpr(node.test, paramNames);
      scanExpr(node.consequent, paramNames);
      scanExpr(node.alternate, paramNames);
    }
    if (node.type === 'UnaryExpression') scanExpr(node.argument, paramNames);
  }
  function tagType(node, typ, paramNames, types) {
    if (!node || node.type !== 'Identifier') return;
    if (!paramNames.has(node.name)) return;
    const existing = types[node.name];
    if (!existing) { types[node.name] = typ; return; }
    // Merge: if same, keep; if different, union
    if (existing === typ) return;
    if (existing.includes(typ) || typ.includes(existing)) return;
    // Simple: I and N → N (widen)
    if ((existing === 'I' && typ === 'N') || (existing === 'N' && typ === 'I')) { types[node.name] = 'N'; return; }
    // Otherwise keep existing (first guess wins)
  }
  // Collect param names from the enclosing function
  const paramNames = new Set();
  // We don't have direct access to params here, so we just tag anything
  // that's used — the caller filters to actual param names
  stmts.forEach(s => {
    if (s.type === 'ReturnStatement') scanExpr(s.argument, paramNames);
    if (s.type === 'ExpressionStatement') scanExpr(s.expression, paramNames);
    if (s.type === 'IfStatement') { scanExpr(s.test, paramNames); }
    if (s.type === 'VariableDeclaration') s.declarations.forEach(d => scanExpr(d.init, paramNames));
  });
  // Actually we need ALL identifiers to be scannable
  // Re-scan with all idents as param candidates
  function collectIdents(node, set) {
    if (!node) return;
    if (node.type === 'Identifier') set.add(node.name);
    for (const k of Object.keys(node)) {
      const v = node[k];
      if (v && typeof v === 'object') {
        if (Array.isArray(v)) v.forEach(c => { if (c && c.type) collectIdents(c, set); });
        else if (v.type) collectIdents(v, set);
      }
    }
  }
  stmts.forEach(s => collectIdents(s, paramNames));
  stmts.forEach(s => {
    if (s.type === 'ReturnStatement') scanExpr(s.argument, paramNames);
    if (s.type === 'ExpressionStatement') scanExpr(s.expression, paramNames);
    if (s.type === 'IfStatement') { scanExpr(s.test, paramNames); if (s.consequent) (s.consequent.body||[s.consequent]).forEach(x => { if(x.type==='ReturnStatement') scanExpr(x.argument, paramNames); }); }
    if (s.type === 'VariableDeclaration') s.declarations.forEach(d => scanExpr(d.init, paramNames));
  });
}

function _tsParamTypes(src){var map={};var T2U={string:'S',number:'I',boolean:'L'};var re=/function\s+\w+\s*\(([^)]*)\)/g,sg;while((sg=re.exec(src))){sg[1].split(',').forEach(function(p){var m=p.trim().match(/^(\w+)(\?)?\s*:\s*(\w+)/);if(m){var ut=T2U[m[3]];if(ut)map[m[1]]=ut+(m[2]?' +N':'')}})}return map}
function convertJS(code){const msgs=[];try{let ast;try{ast=acorn.parse(code,{ecmaVersion:2022,sourceType:'module'})}catch(e1){ast=acorn.parse(code,{ecmaVersion:2022,sourceType:'script'})}const out=ast.body.map(n=>js(n,'')).join('\n\n');return{code:out.trim(),messages:msgs}}catch(e){msgs.push({line:e.loc?.line||0,text:'Parse error: '+e.message,type:'err'});return{code:'// Parse error\n// '+e.message,messages:msgs}}}

var _renames=[];
function _renOutStr(expr,names){if(!names||!names.length)return expr;let out='',i=0;while(i<expr.length){const ch=expr[i];if(ch==='"'||ch==="'"){let j=i+1;while(j<expr.length&&expr[j]!==ch){if(expr[j]==='\\')j++;j++}out+=expr.slice(i,j+1);i=j+1}else{let j=i;while(j<expr.length&&expr[j]!=='"'&&expr[j]!=="'")j++;let seg=expr.slice(i,j);for(const nm of names){seg=seg.replace(new RegExp('(^|[^.\\w])'+nm+'(?![\\w])','g'),(m,pre)=>pre+nm+nm)}out+=seg;i=j}}return out}
function px(e){if(!e)return'none';e=e.trim();e=e.replace(/\bawait\s+/g,'');var _rm=e.match(/^range\((.+)\)$/);if(_rm){var _a=_rm[1].split(',').map(function(x){return x.trim()});if(_a.length===1)return '[1..'+px(_a[0])+']';if(_a.length>=2)return '['+px(_a[0])+'..'+px(_a[1])+']'}var _lc=e.match(/^\[\s*(.+?)\s+for\s+(\w+)\s+in\s+(.+?)(?:\s+if\s+(.+?))?\s*\]$/);if(_lc){var _ex=_lc[1],_v=uName(_lc[2]),_it=px(_lc[3].trim()),_cd=_lc[4];var _rn=function(str){return str.replace(new RegExp('\\b'+_lc[2]+'\\b','g'),_v)};var _o=_it;if(_cd)_o+='.filter('+_v+' => '+px(_rn(_cd))+')';_o+='.map('+_v+' => '+px(_rn(_ex))+')';return _o}e=e.replace(/\bNone\b/g,'none')
    // 0-based → 1-based array indexing for U
    e=e.replace(/\[(\d+)\]/g, function(_,n){ return '['+(parseInt(n)+1)+']' })
    // Variable indices: arr[i] → arr[i + 1] (but not map["key"])
    e=e.replace(/\[([a-zA-Z_]\w*)\]/g, function(_,v){ return '['+v+' + 1]' }).replace(/\bTrue\b/g,'true').replace(/\bFalse\b/g,'false');e=e.replace(/\*\*/g,'^');e=e.replace(/\band\b/g,'&').replace(/\bor\b/g,'|').replace(/\bnot\b/g,'!');e=e.replace(/\bis\s+none\b/gi,'== none').replace(/\bis\s+not\s+none\b/gi,'!= none');e=e.replace(/\bself\./g,'t.');e=e.replace(/\blen\(([^)]+)\)/g,'$1.len')
    e=e.replace(/\bisinstance\(([^,]+),\s*(\w+)\)/g,'$1 :: $2')
    e=e.replace(/\bhasattr\(([^,]+),\s*['"]([^'"]+)['"]\)/g,'$1.has("$2")')
    e=e.replace(/\bgetattr\(([^,]+),\s*['"]([^'"]+)['"](,\s*[^)]+)?\)/g,'$1.get("$2")')
    e=e.replace(/\bsetattr\(([^,]+),\s*['"]([^'"]+)['"],\s*([^)]+)\)/g,'$1.set("$2", $3)')
    e=e.replace(/\bstr\(([^)]+)\)/g,'$1.__string__()')
    e=e.replace(/\bint\(([^)]+)\)/g,'$1.__int__()')
    e=e.replace(/\bfloat\(([^)]+)\)/g,'$1.__float__()')
    e=e.replace(/\bbool\(([^)]+)\)/g,'$1.__bool__()')
    e=e.replace(/\bprint\(([^)]*)\)/g,'log($1)')
    e=e.replace(/\bjson\.dumps\(([^)]+)\)/g,'json.encode($1)')
    e=e.replace(/\bjson\.loads\(([^)]+)\)/g,'json.decode($1)')
    e=e.replace(/\bjson\.load\(([^)]+)\)/g,'json.decode($1.read())')
    e=e.replace(/\bjson\.dump\(([^,]+),\s*([^)]+)\)/g,'$2.write(json.encode($1))')
    e=e.replace(/\bos\.path\.join\(/g,'filesystem.join(')
    e=e.replace(/\bos\.path\.exists\(/g,'filesystem.exists(')
    e=e.replace(/\bos\.makedirs\(/g,'filesystem.mkdir(')
    e=e.replace(/\bhashlib\.(\w+)\(([^)]*)\)/g,'Crypto.$1($2)')
    e=e.replace(/\b(\w+)\.append\(/g,'$1.push(')
    e=e.replace(/\b(\w+)\.extend\(/g,'$1.concat(')
    e=e.replace(/\b(\w+)\.items\(\)/g,'$1.entries()')
    e=e.replace(/\b(\w+)\.keys\(\)/g,'$1.keys()')
    e=e.replace(/\b(\w+)\.values\(\)/g,'$1.values()')
    e=e.replace(/\b(\w+)\.strip\(\)/g,'$1.trim()')
    e=e.replace(/\b(\w+)\.lower\(\)/g,'$1.lower()')
    e=e.replace(/\b(\w+)\.upper\(\)/g,'$1.upper()')
    e=e.replace(/\b(\w+)\.startswith\(([^)]+)\)/g,'$1.starts_with($2)')
    e=e.replace(/\b(\w+)\.endswith\(([^)]+)\)/g,'$1.ends_with($2)')
    e=e.replace(/\b(\w+)\.replace\(/g,'$1.replace(')
    e=e.replace(/\b(\w+)\.split\(/g,'$1.split(')
    e=e.replace(/\b(\w+)\.join\(([^)]+)\)/g,'$2.join($1)')
    e=e.replace(/\bnot\s+/g,'!')
    e=e.replace(/\s+and\s+/g,' ? ')
    e=e.replace(/\s+or\s+/g,' ?? ')
    e=e.replace(/\bNone\b/g,'none')
    e=e.replace(/\bTrue\b/g,'true')
    e=e.replace(/\bFalse\b/g,'false');e=e.replace(/\bprint\(/g,'log(');
    // f-string: f"...{expr}..." → "...{{expr}}..."
    e=e.replace(/\bf"([^"]*)"/g, function(m, inner) {
      return '"' + inner.replace(/\{([^}]+)\}/g, '{{$1}}') + '"';
    });
    e=e.replace(/\bf'([^']*)'/g, function(m, inner) {
      return '"' + inner.replace(/\{([^}]+)\}/g, '{{$1}}') + '"';
    });
    // isinstance(x, Type) → x :: Type
    e=e.replace(/isinstance\(([^,]+),\s*(str|int|float|bool|list)\)/g, (m, obj, typ) => {
      const tm = {str:'S',int:'I',float:'N',bool:'L',list:'[I]'};
      return obj.trim() + ' :: ' + (tm[typ]||typ);
    });
    // str(x) → S(x), int(x) → I(x), float(x) → N(x)
    e=e.replace(/\bstr\(([^)]+)\)/g,'S($1)');
    e=e.replace(/\bint\(([^)]+)\)/g,'I($1)');
    e=e.replace(/\bfloat\(([^)]+)\)/g,'N($1)');e=e.replace(/\.lower\(\)/g,'.lower()').replace(/\.upper\(\)/g,'.upper()');e=e.replace(/\.strip\(\)/g,'.strip()');e=e.replace(/\.append\(/g,'.push(');
    e=e.replace(/\.index\(/g,'.index(');
    e=e.replace(/\.count\(/g,'.count(');
    e=e.replace(/\.startswith\(/g,'.startswith(');
    e=e.replace(/\.endswith\(/g,'.endswith(');
    e=e.replace(/\.find\(/g,'.find(');
    e=e.replace(/\.extend\(/g,'// extend → use [...a, ...b]');
    e=e.replace(/\.pop\(\)/g,'.pop()');
    e=e.replace(/\.reverse\(\)/g,'.reverse()');
    e=e.replace(/\.sort\(\)/g,'.sort()');e=e.replace(/\brange\((\d+),\s*(\d+)\)/g,'[$1...$2]');e=e.replace(/\brange\((\d+)\)/g,'[1...$1]');const tm=e.match(/^(.+?)\s+if\s+(.+?)\s+else\s+(.+)$/);if(tm)e=px(tm[2])+' ? '+px(tm[1])+' ! '+px(tm[3]);e=e.replace(/\blambda\s+([^:]+):\s*/g,function(mt,pp){pp.split(',').forEach(function(p){p=p.trim();if(p.length===1&&/[A-Za-z]/.test(p)&&_renames.indexOf(p)<0)_renames.push(p)});return pp+' => '});const mm=e.match(/^list\(map\((.+?),\s*(.+)\)\)$/);if(mm)e=px(mm[2])+'.map('+px(mm[1])+')';e=_renOutStr(e,_renames);return e}
function pyT(t){
    if(!t) return 'I';
    // Generic types
    t = t.replace(/List\[(\w+)\]/g, function(_,inner){ return '['+pyT(inner)+']'; });
    t = t.replace(/Dict\[(\w+),\s*(\w+)\]/g, function(_,k,v){ return '{'+pyT(k)+':'+pyT(v)+'}'; });
    t = t.replace(/Optional\[(\w+)\]/g, function(_,inner){ return pyT(inner)+' +N'; });
    t = t.replace(/Tuple\[([^\]]+)\]/g, function(_,inner){ return '('+inner.split(',').map(function(x){return pyT(x.trim())}).join(', ')+')'; });
    t = t.replace(/Set\[(\w+)\]/g, function(_,inner){ return '{'+pyT(inner)+'}'; });return{int:'I',float:'N',str:'S',bool:'L',bytes:'B',None:'none',list:'[I] +R',dict:'{S:I} +R',List:'[I] +R',Dict:'{S:I} +R',Optional:'+N',Any:'I|N|S'}[t]||t}

function convertPy(code){const msgs=[],lines=code.split('\n'),out=[];let i=0;function gi(l){return l.match(/^(\s*)/)[1].length}
const _stk=[];const _mut={};const _seen={};const _declared={};const _callTypes={};(function(){var _defs={};lines.forEach(function(ln){var dm=ln.match(/^\s*def\s+(\w+)\s*\(([^)]*)\)/);if(dm){_defs[dm[1]]=dm[2].split(',').map(function(p){return p.trim().replace(/[:=].*/,'').trim()}).filter(function(p){return p&&p!=='self'})}});lines.forEach(function(ln){Object.keys(_defs).forEach(function(fn){var cm=ln.match(new RegExp('\\b'+fn+'\\s*\\(([^)]*)\\)'));if(cm&&!/^\s*def\s/.test(ln)){var args=cm[1].split(',').map(function(a){return a.trim()});args.forEach(function(a,ix){var pn=_defs[fn][ix];if(!pn)return;var t=null;if(/^["']/.test(a))t='S';else if(/^\d+\.\d+/.test(a))t='N';else if(/^\d+$/.test(a))t='I';else if(/^(True|False)$/.test(a))t='L';if(t){_callTypes[fn]=_callTypes[fn]||{};_callTypes[fn][pn]=t}})}})})}());lines.forEach(function(ln){var am=ln.trim().match(/^(\w+)\s*([:+\-*\/]?=)[^=]/);if(am){var vn=am[1],op=am[2];if(op!=='='&&op!==':='){_mut[vn]=true}else{if(_seen[vn])_mut[vn]=true;_seen[vn]=true}}});function _tabsFor(nd){return '\t'.repeat(Math.max(0,Math.floor(nd/4)))}function _closeTo(nd){while(_stk.length&&_stk[_stk.length-1].indent>=nd){const b=_stk.pop();out.push(_tabsFor(b.indent)+b.closer)}}
function _zeroFor(t){return t==='S'?'""':t==='L'?'false':t==='N'?'0.0':'0'}
function inferPyType(v){v=(v||'').trim();if(/^["']/.test(v))return 'S';if(/^(true|false|True|False)$/.test(v))return 'L';if(/^\d+\.\d+/.test(v))return 'N';if(/^\d+$/.test(v))return 'I';return 'I'}
function _inferRet(di,dind){for(var k=di+1;k<lines.length;k++){var bl=lines[k];var bs=bl.trimStart();if(!bs)continue;if(gi(bl)<=dind)break;var rm=bs.match(/^return\s+(.+)$/);if(rm){var rv=rm[1].trim();if(/^f?["']/.test(rv))return 'S';if(/^(True|False)$/.test(rv))return 'L';if(/^-?\d+\.\d+/.test(rv))return 'N';if(/^-?\d+$/.test(rv))return 'I';return null}}return null}
while(i<lines.length){const raw=lines[i],s=raw.trimStart(),ind=gi(raw),tabs='\t'.repeat(Math.max(1,Math.floor(ind/4))||0).slice(0,ind>0?undefined:0);
if(!s||s.startsWith('#')){out.push(s.startsWith('#')?tabs+'//'+s.slice(1):'');i++;continue}
_closeTo(ind);
if(/\*\*?\w+/.test(s)&&/[(,]\s*\*/.test(s)){out.push(tabs+'// [not translatable to U] Python *args/**kwargs has no direct U equivalent — '+s.trim());msgs.push({line:i+1,text:'Python *args/**kwargs (variadic) has no direct U equivalent; left as a comment.',type:'warn'});i++;continue}
if(s.startsWith('@')){out.push(tabs+'// [decorator] '+s.trim()+' — in U this maps to the z prefix (z f name); applied manually');msgs.push({line:i+1,text:'Python decorator maps to U\'s z prefix (compile-time/AOP); emitted as a note.',type:'warn'});i++;continue}
if(s.startsWith('from ')||s.startsWith('import ')){out.push(tabs+'// '+s);i++;continue}
let m=s.match(/^(async\s+)?def\s+(\w+)\s*\(([^)]*)\)\s*(?:->\s*(\w+))?\s*:/);
if(m){if(m[2]==='__init__'){var _ci=gi(raw);var _fields=[];var _k=i+1;while(_k<lines.length){var _bl=lines[_k];var _bs=_bl.trimStart();if(!_bs){_k++;continue}if(gi(_bl)<=_ci)break;var _fm=_bs.match(/^self\.(\w+)\s*=\s*(.+)$/);if(_fm){var _ft=inferPyType?inferPyType(_fm[2]):'I';_fields.push(tabs+_fm[1]+': '+(_ft||'I')+' +M = '+_zeroFor(_ft||'I'))}_k++}_fields.forEach(function(fl){out.push(fl)});i=_k;continue}const isA=!!m[1],nm=uName(m[2]),rp=m[3],rh=m[4];
const ps=rp?rp.split(',').map(p=>{p=p.trim();if(p==='self'||p.startsWith('*'))return null;const dm=p.match(/^(\w+)(?:\s*:\s*([\w\[\], ]+))?\s*=\s*(.+)$/);if(dm){const tp=dm[2]?pyT(dm[2].trim()):inferPyType(dm[3]);return uName(dm[1])+': '+tp+' = '+px(dm[3])}const tm=p.match(/^(\w+)\s*:\s*([\w\[\], ]+)$/);if(tm)return uName(tm[1])+': '+pyT(tm[2].trim());const bare=p.match(/^(\w+)$/);if(bare){var _ct=(_callTypes[m[2]]&&_callTypes[m[2]][bare[1]])||'I';return uName(bare[1])+': '+_ct}return p}).filter(Boolean).join(', '):'';
if(rp){_renames=rp.split(',').map(function(p){p=p.trim().replace(/[:=].*$/,'').trim().replace(/^\*+/,'');return p}).filter(function(p){return p.length===1&&/[A-Za-z]/.test(p)})}else{_renames=[]}
if(nm==='// constructor'){out.push(tabs+'// constructor')}else{var _rh=rh?pyT(rh):_inferRet(i,gi(raw));out.push(tabs+(isA?'f+A ':'f ')+nm+'('+ps+')'+(_rh?' -> '+_rh:''))}i++;continue}
m=s.match(/^class\s+(\w+)(?:\((\w+)\))?\s*:/);if(m){out.push(tabs+'d '+m[1]+(m[2]&&m[2]!=='object'?' : '+m[2]:''));i++;continue}
m=s.match(/^if\s+(.+):/);if(m&&i+1<lines.length){const nx=lines[i+1]?.trimStart();if(nx&&nx.startsWith('return ')&&!(lines[i+2]?.trimStart().startsWith('el'))){out.push(tabs+px(m[1])+' ? r => '+px(nx.slice(7)));i+=2;continue}}
if(s.startsWith('return ')){out.push(tabs+'r => '+px(s.slice(7)));i++;continue}
    // try: → collect try body, attach x.on() from except
    if(s==='try:'){var _tryLines=[];var _tryInd=ind;i++;while(i<lines.length){var _tl=lines[i];var _ts=_tl.trimStart();if(!_ts){i++;continue}if(gi(_tl)<=_tryInd)break;_tryLines.push(tabs+'\t'+px(_ts));i++}var _excepts=[];while(i<lines.length){var _el=lines[i];var _es=_el.trimStart();var _em2=_es.match(/^except\s+(\w+)(?:\s+as\s+(\w+))?:/);if(!_em2)break;var _etype=_em2[1]||'Error';var _ename=(_em2[2]&&_em2[2]!=='e')?_em2[2]:'err';i++;var _catchLines=[];while(i<lines.length){var _cl=lines[i];var _cs=_cl.trimStart();if(!_cs){i++;continue}if(gi(_cl)<=_tryInd)break;_catchLines.push(tabs+'\t\t'+px(_cs));i++}_excepts.push({type:_etype,name:_ename,body:_catchLines})}if(_tryLines.length===1&&_tryLines[0].includes('r =>')){var _expr=_tryLines[0].trim().replace(/^r => /,'');var _h=_excepts.map(function(ex){if(ex.body.length===1&&ex.body[0].includes('r =>')){return tabs+'\t('+ex.name+': '+ex.type+') => '+ex.body[0].trim().replace(/^r => /,'')}return tabs+'\t('+ex.name+': '+ex.type+') => (\n'+ex.body.join('\n')+'\n'+tabs+'\t)'});out.push(tabs+'r => '+_expr+' x.on(\n'+_h.join(',\n')+'\n'+tabs+')')}else{var _h2=_excepts.map(function(ex){return tabs+'\t('+ex.name+': '+ex.type+') => (\n'+ex.body.join('\n')+'\n'+tabs+'\t)'});out.push(tabs+'(\n'+_tryLines.join('\n')+'\n'+tabs+') x.on(\n'+_h2.join(',\n')+'\n'+tabs+')')}continue}
    // except without preceding try
    m=s.match(/^except\s+(\w+)(?:\s+as\s+(\w+))?:/);
    if(m){i++;continue}
    // raise X → x X
    if(s.startsWith('raise ')){out.push(tabs+'x '+px(s.slice(6)));i++;continue}
// with open(...) as f → f = open(...) (U manages resources via RAII)
    m=s.match(/^with\s+(.+?)\s+as\s+(\w+)\s*:/);
    if(m){out.push(tabs+uName(m[2])+' = '+px(m[1]));_stk.push({indent:ind,closer:'// end with'});i++;continue}
    if(s==='return'){out.push(tabs+'r => none');i++;continue}
m=s.match(/^self\.(\w+)\s*=\s*(.+)$/);if(m){out.push(tabs+'t.'+m[1]+' = '+px(m[2]));i++;continue}
var _em=s.match(/^for\s+(\w+)\s*,\s*(\w+)\s+in\s+enumerate\((.+?)\)\s*:/);if(_em){var _idx=_em[1],_val=_em[2],_it=_em[3];[_idx,_val].forEach(function(v){if(v.length===1&&/[A-Za-z]/.test(v)&&_renames.indexOf(v)<0)_renames.push(v)});out.push(tabs+px(_it)+'.on(('+uName(_val)+', '+uName(_idx)+') => (');_stk.push({indent:ind,closer:'))'});i++;continue}var _mm=s.match(/^for\s+(\w+)\s*,\s*(\w+)\s+in\s+(.+):/);if(_mm){var _a=_mm[1],_b=_mm[2],_z=_mm[3];[_a,_b].forEach(function(v){if(v.length===1&&/[A-Za-z]/.test(v)&&_renames.indexOf(v)<0)_renames.push(v)});out.push(tabs+px(_z)+'.on(('+uName(_a)+', '+uName(_b)+') => (');_stk.push({indent:ind,closer:'))'});i++;continue}m=s.match(/^for\s+(\w+)\s+in\s+(.+):/);if(m){var _lv=m[1];if(_lv.length===1&&/[A-Za-z]/.test(_lv)&&_renames.indexOf(_lv)<0)_renames.push(_lv);out.push(tabs+px(m[2])+'.on('+uName(_lv)+' => (');_stk.push({indent:ind,closer:'))'});i++;continue}
m=s.match(/^(\w+)\s*([:+\-*\/]?=)\s*(.+)$/);if(m){var _vn=m[1];var _decl=(_mut[_vn]&&!_declared[_vn]);if(_decl)_declared[_vn]=true;var _pfx=_decl?(': I +M '):' ';if(m[2]==='='||m[2]===':=')out.push(tabs+_vn+_pfx+'= '+px(m[3]));else{const o=m[2].slice(0,-1);out.push(tabs+_vn+_pfx+'= '+_vn+' '+o+' '+px(m[3]))}i++;continue}
out.push(tabs+px(s));i++}
_closeTo(-1);
    // Scan for implicit conversions
    const uCode = out.join('\n');
    // Warn about + with mixed types (could be string concat)
    const lines2 = uCode.split('\n');
    lines2.forEach((l, i) => {
      if (l.includes('.__string__()') === false && /\+ "/.test(l)) {
        // string concat with non-string — might need .string()
      }
    });
    return{code:uCode.trim(),messages:msgs}}

function doTranspile(){const code=srcE.getValue(),con=document.getElementById('console');con.innerHTML='';if(!code.trim()){outE.setValue('');document.getElementById('status-text').textContent='Ready';document.getElementById('stats').textContent='';return}
const t0=performance.now();let srcCode = code;
  // Strip TypeScript annotations before parsing
  var _tsTypes = (lang === 'typescript') ? _tsParamTypes(srcCode) : {};
  if (lang === 'typescript') {
    srcCode = srcCode
      .replace(/(\w)\?(\s*[:),])/g, '$1$2')
      .replace(/:\s*(Record|Map|Set|Array|Promise|ReadonlyArray)\s*<[^>]*>/g, '')
      .replace(/:\s*\{[^{}]*:[^{}]*\}\s*(?=[),=])/g, '')
      .replace(/\)\s*:\s*\[[^\]]*\]/g, ')')
      .replace(/\)\s*:\s*[A-Za-z_][\w.<>\[\]| ]*/g, ')')
      .replace(/^(\s*[A-Za-z_]\w*)\s*:\s*[A-Za-z_][\w.<>\[\]| ]*(\s*[;=])/gm, '$1$2')
      .replace(/^(\s*[A-Za-z_]\w*)\s*:\s*[A-Za-z_][\w.<>\[\]| ]*\s*$/gm, '$1')
      .replace(/:\s*(string|number|boolean|void|any|unknown|never|null|undefined|[A-Z][\w.<>\[\]]*)(\s*\|\s*[A-Za-z_][\w.<>\[\]]*)*(\[\])?\s*(?=[,)])/g, '')
      .replace(/<[A-Za-z_][\w, ]*>/g, '')
      .replace(/\bas\s+[A-Za-z_]\w*/g, '')
      .replace(/\s*\|\s*(null|undefined)/g, '')
      .replace(/^interface\s+\w+\s*\{[^}]*\}/gm, (m) => {
        // interface → d with abstract methods (f name(params)! -> ret)
        const name = m.match(/interface\s+(\w+)/)[1];
        const body = m.slice(m.indexOf('{')+1, m.lastIndexOf('}'));
        let u = 'd ' + name;
        body.split(/[;\n]/).forEach(line => {
          line = line.trim(); if(!line) return;
          const mm = line.match(/^(\w+)\s*\(([^)]*)\)\s*(?::\s*([\w\[\]]+))?$/);
          if(mm){ const ret = mm[3] && mm[3]!=='void' ? ' -> '+mm[3] : '';
                  u += '\n\tf ' + mm[1] + '(' + mm[2].trim() + ')!' + ret; }
          else {
            // a property line like `readonly host` (types already stripped)
            const pm = line.replace(/^readonly\s+/,'').match(/^(\w+)$/);
            if(pm) u += '\n\t' + pm[1] + ': I +M = 0';
          }
        });
        return u;
      })
      .replace(/^enum\s+(\w+)\s*\{([^}]*)\}/gm, (m, name, body) => {
        // enum → d with static fields
        const vals = body.split(',').map(v => v.trim()).filter(Boolean);
        let u = 'd ' + name;
        vals.forEach(v => {
          const [k, val] = v.split('=').map(x => x.trim());
          u += '\n\t' + k + ': I +G = ' + (val || '0');
        });
        return u;
      });
  }
  // If TS stripping already produced pure U (interface/enum → d ...), and there's
  // no residual JS to parse, pass it through instead of re-parsing as JavaScript.
  let res;
  if (lang === 'typescript' && /^d\s/m.test(srcCode) &&
      !/\b(function|class|const|let|var|return|=>)\b/.test(srcCode)) {
    res = { code: srcCode.trim(), messages: [] };
  } else {
    res = (lang==='python') ? convertPy(srcCode) : convertJS(srcCode);
  }
  if (lang === 'typescript' && res && res.code) {
    res.code = res.code.replace(/\b(\w+): (I|S|N|L)\b/g, function(mt, pn, ty){
      var uName2 = pn.length===1 ? pn+pn : pn;
      // match against both raw and renamed param name
      var key = Object.keys(_tsTypes).filter(function(k){return k===pn || (k.length===1 && k+k===pn)})[0];
      return key ? (pn + ': ' + _tsTypes[key]) : mt;
    });
  }const dt=(performance.now()-t0).toFixed(1);outE.setValue(res.code);
res.messages.forEach(m=>{const d=document.createElement('div');d.className='console-line '+(m.type||'');d.innerHTML=(m.line?'<span class="ln">L'+m.line+'</span>':'')+m.text;if(m.line)d.onclick=()=>srcE.setCursor(m.line-1);con.appendChild(d)});
document.getElementById('stats').textContent=code.split('\n').length+'\u2192'+res.code.split('\n').length+' lines \u00b7 '+dt+'ms';document.getElementById('status-text').textContent=res.messages.some(m=>m.type==='err')?'Errors':'Transpiled'}

window.addEventListener('DOMContentLoaded',()=>{
var _srcEl=document.getElementById('src');
  if(_srcEl && _srcEl.tagName==='TEXTAREA' && typeof CodeMirror!=='undefined' && CodeMirror.fromTextArea)
  srcE=CodeMirror.fromTextArea(_srcEl,{mode:'javascript',theme:'material-darker',lineNumbers:true,tabSize:2,indentWithTabs:false});
var _outEl=document.getElementById('out');
  if(_outEl && typeof CodeMirror!=='undefined' && CodeMirror.fromTextArea)
  outE=CodeMirror.fromTextArea(_outEl,{mode:'u-lang',theme:'material-darker',lineNumbers:true,readOnly:true,tabSize:4});
if(srcE&&srcE.on)srcE.on('change',()=>{clearTimeout(dbt);dbt=setTimeout(doTranspile,300)});
if(srcE){popEx();srcE.setValue(EX.javascript[0][1]);}});

/* TypeScript support lived inside doTranspile(), which needs the original
   page's DOM. Exposed here so any caller can translate TS: strip the
   annotations, then hand the result to the JavaScript path. Without this a TS
   sample reached acorn with its types intact and came back "Parse error". */
function convertTS(code) {
  var srcCode = String(code || '');
  var types = (typeof _tsParamTypes === 'function') ? _tsParamTypes(srcCode) : {};
  srcCode = srcCode
    .replace(/(\w)\?(\s*[:),])/g, '$1$2')
    .replace(/:\s*(Record|Map|Set|Array|Promise|ReadonlyArray)\s*<[^>]*>/g, '')
    .replace(/:\s*\{[^{}]*:[^{}]*\}\s*(?=[),=])/g, '')
    .replace(/\)\s*:\s*\[[^\]]*\]/g, ')')
    .replace(/\)\s*:\s*[A-Za-z_][\w.<>\[\]| ]*/g, ')')
    .replace(/^(\s*[A-Za-z_]\w*)\s*:\s*[A-Za-z_][\w.<>\[\]| ]*(\s*[;=])/gm, '$1$2')
    .replace(/:\s*[A-Za-z_][\w.<>\[\]| ]*(?=\s*[,)])/g, '')
    .replace(/\bexport\s+/g, '')
    .replace(/\binterface\s+\w+\s*\{[^}]*\}/g, '')
    .replace(/\btype\s+\w+\s*=\s*[^;]+;/g, '');
  var res = convertJS(srcCode);
  /* put the declared parameter types back, so `first: number` becomes
     `first: N` rather than being inferred */
  if (res && res.code && types) {
    Object.keys(types).forEach(function (name) {
      res.code = res.code.replace(
        new RegExp('\\b' + name + ': [A-Z]\\b'), name + ': ' + types[name]);
    });
  }
  return res;
}
if (typeof window !== 'undefined') window.convertTS = convertTS;
