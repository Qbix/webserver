(function (root, factory) {
	if (typeof module === "object" && module.exports) {
		module.exports = factory();
	} else if (typeof define === "function" && define.amd) {
		define([], factory);
	} else {
		root.Handlebars = factory();
	}
}(typeof self !== "undefined" ? self : this, function () {
	"use strict";

	// ---------------- SafeString ----------------
	function SafeString(str){this.string=String(str);}
	SafeString.prototype.toString=function(){return this.string;}

	// ---------------- Utilities ----------------
	function has(o,k){return Object.prototype.hasOwnProperty.call(o,k);}
	function assign(t,s){for(var k in s)if(has(s,k))t[k]=s[k];return t;}
	function createFrame(d){var o={};if(d&&typeof d==="object")assign(o,d);return o;}

	function escapeExpression(i){
		if(i==null)return "";
		if(i instanceof SafeString)return i.toString();
		var s=String(i);
		// NOTE: only minimal escaping, unlike full Handlebars which supports SafeString + HTML entities
		return s.replace(/[&<>"']/g,c=>({"&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;","'":"&#39;"}[c]));
	}

	// ---------------- Path + Eval ----------------
	function getPath(base, dotted){
		if(base==null)return;
		if(!dotted)return base;
		var segs=dotted.split("."),cur=base;
		for(var i=0;i<segs.length;i++){
			var key=segs[i];
			// NOTE: only foo.[bar] syntax supported, not full Handlebars bracket rules
			if(key[0]==="["&&key[key.length-1]=="]"){key=key.slice(1,-1);}
			cur=cur!=null?cur[key]:undefined;
		}
		return cur;
	}
	function resolvePath(path,ctx,stack,data){
		if(path==="this"||path===".")return ctx;
		if(path==="@index")return data&&data["@index"];
		if(path==="@key")return data&&data["@key"];
		if(path==="@first")return data&&data["@first"];
		if(path==="@last")return data&&data["@last"];
		if(path.indexOf("@root.")===0)return getPath(data.root,path.slice(6));
		if(path==="@root")return data.root;
		var up=0;while(path.indexOf("../")===0){up++;path=path.slice(3);}
		var base=ctx;
		if(up>0){var idx=stack.length-1-up;if(idx<0)idx=0;base=stack[idx];}
		return getPath(base,path);
	}

	// Tokenizing args, supports newlines (#20)
	function tokenizeArgs(s){
		var out=[],cur="",q=null,esc=false,depth=0;
		for(var i=0;i<s.length;i++){
			var ch=s[i];
			if(q){
				if(esc){cur+=ch;esc=false;continue;}
				if(ch==="\\"){cur+=ch;esc=true;continue;}
				cur+=ch;if(ch===q)q=null;continue;
			}
			if(ch==="'"||ch==='"'){cur+=ch;q=ch;continue;}
			if(ch==="("){depth++;cur+=ch;continue;}
			if(ch===")"){if(depth>0)depth--;cur+=ch;continue;}
			if(/\s/.test(ch)&&depth===0){if(cur){out.push(cur);cur="";}continue;}
			cur+=ch;
		}
		if(cur)out.push(cur);return out;
	}
	function firstToken(s){var t=tokenizeArgs(s);return t.length?t[0]:"";}
	function isSubExpr(t){return t[0]==="("&&t[t.length-1]===")";}

	// Sub-expressions (#2) simplified recursive evaluation
	function evalMaybePath(token,ctx,stack,data){
		if(isSubExpr(token)){
			var inner=token.slice(1,-1).trim();
			var name=firstToken(inner);
			if(helpers[name]){
				var parsed=parseArgs(inner.slice(name.length).trim(),ctx,stack,data);
				var opts={hash:parsed.hash,data:data,fn:()=>"",inverse:()=>""};
				return helpers[name].apply(ctx,parsed.args.concat(opts));
			}
			return evalMaybePath(inner,ctx,stack,data);
		}
		if((token[0]==="'"&&token[token.length-1]==="'")||(token[0]==='"'&&token[token.length-1]==='"'))
			return token.slice(1,-1);
		return resolvePath(token,ctx,stack,data);
	}

	function parseArgs(expr,ctx,stack,data){
		var toks=tokenizeArgs(expr),args=[],hash={};
		for(var i=0;i<toks.length;i++){
			var p=toks[i],eq=p.indexOf("=");
			if(eq>0){var k=p.slice(0,eq),v=p.slice(eq+1);hash[k]=evalMaybePath(v,ctx,stack,data);}
			else args.push(evalMaybePath(p,ctx,stack,data));
		}
		return{args:args,hash:hash};
	}

	// ---------------- Compiler ----------------
	function compile(tpl){
		var uid=0,code="var out='';var __stack=[ctx];\n";
		var parts=tpl.split(/(\{\{\{\{[\s\S]+?\}\}\}\}|\{\{\{[\s\S]+?\}\}\}|\{\{[\s\S]+?\}\})/);
		var uidStack=[];
		for(var t=0;t<parts.length;t++){
			var tok=parts[t];if(!tok)continue;

			// Raw block {{{{raw}}}}…{{{{/raw}}}} (#5,#10)
			if(tok.startsWith("{{{{raw}}}}")){
				var end=tpl.indexOf("{{{{/raw}}}}",tpl.indexOf(tok));
				var inner=tpl.slice(tpl.indexOf(tok)+10,end);
				code+="out+="+JSON.stringify(inner)+";\n";t+=2;continue;
			}

			// Triple mustache
			if(tok.startsWith("{{{")&&tok.endsWith("}}}")){
				var raw=tok.slice(3,-3).trim();
				code+="var v=evalMaybePath("+JSON.stringify(raw)+",ctx,__stack,data);out+=(v==null?'':v);\n";
				continue;
			}

			// Double mustache
			if(tok.startsWith("{{")&&tok.endsWith("}}")){
				var expr=tok.slice(2,-2).trim();
				if(expr[0]==="!"||expr.startsWith("--"))continue; // comments (#4)

				// Block start
				if(expr[0]==="#"){
					var inner=expr.slice(1).trim(),name=firstToken(inner),rest=inner.slice(name.length).trim();
					// Block params (#1,#16)
					var m=/\s+as\s+\|([^|]+)\|/.exec(rest),blockParams=null;
					if(m){blockParams=m[1].trim().split(/\s+/);rest=rest.replace(m[0],"");}
					var id=++uid;
					uidStack.push(id);
					code+="var parsed"+id+"=parseArgs("+JSON.stringify(rest)+",ctx,__stack,data);";
					code+="var outerCtx"+id+"=ctx;var opts"+id+"={hash:parsed"+id+".hash,data:data,fn:function(sub,o){var out='';var d=createFrame(data);if(o&&o.data)assign(d,o.data);var data=d;ctx=sub||outerCtx"+id+";__stack.push(ctx);";
					// NOTE: Block param binding simplified, not full lexical scoping
					if(blockParams){for(var b=0;b<blockParams.length;b++){code+="ctx["+JSON.stringify(blockParams[b])+"]=sub;";}}
					continue;
				}

				if(expr==="else"){code+="return out;},inverse:function(sub,o){var out='';ctx=sub||ctx;__stack.push(ctx);\n";continue;}

				if(expr[0]==="/"){
					var end=expr.slice(1).trim(),last=uidStack.pop();
					code+="return out;}};if(!opts"+last+".inverse)opts"+last+".inverse=function(){return '';};var r=(helpers["+JSON.stringify(end)+"]||helpers.blockHelperMissing).apply(ctx,parsed"+last+".args.concat(opts"+last+"));out+=(r||'');ctx=outerCtx"+last+";__stack.pop();\n";
					continue;
				}

				// Partial block (#6)
				if(expr.startsWith("#>")){
					var p=expr.slice(2).trim();
					// NOTE: only passes fn, not inverse
					code+="if(partials["+JSON.stringify(p)+"])out+=partials["+JSON.stringify(p)+"](ctx,partials,helpers,data,evalMaybePath,parseArgs,escapeExpression,createFrame,assign,firstToken,{fn:function(){return '';}});\n";
					continue;
				}

				// Partials (#7,#8)
				if(expr[0]===">"){
					var rest=expr.slice(1).trim(),toks=tokenizeArgs(rest),pName=toks.shift(),dyn=isSubExpr(pName);
					var ctxExpr=toks.join(" "),id=++uid;
					code+="var pName"+id+"="+(dyn?"evalMaybePath("+JSON.stringify(pName)+",ctx,__stack,data)":"'"+pName+"'")+";";
					code+="var pArgs=parseArgs("+JSON.stringify(ctxExpr)+",ctx,__stack,data);";
					code+="var pCtx=ctx;if(Object.keys(pArgs.hash).length) pCtx=assign({},ctx),assign(pCtx,pArgs.hash);";
					// NOTE: No fallback to helpers if partial not found (#19 diff)
					code+="if(partials[pName"+id+"])out+=partials[pName"+id+"](pCtx,partials,helpers,data,evalMaybePath,parseArgs,escapeExpression,createFrame,assign,firstToken);\n";
					continue;
				}

				// Inline helper or variable
				var tok=firstToken(expr);
				code+="if(helpers["+JSON.stringify(tok)+"]) {var p=parseArgs("+JSON.stringify(expr.slice(tok.length).trim())+",ctx,__stack,data);var opts={hash:p.hash,data:data,fn:function(){return ''},inverse:function(){return ''}};out+=(helpers["+JSON.stringify(tok)+"]||helpers.helperMissing).apply(ctx,p.args.concat(opts))||'';} else {var v=evalMaybePath("+JSON.stringify(expr)+",ctx,__stack,data);out+=(v==null?'':escapeExpression(v));}\n";
				continue;
			}
			// Literal text
			code+="out+="+JSON.stringify(tok)+";\n";
		}
		code+="return out;";return new Function("ctx","partials","helpers","data","evalMaybePath","parseArgs","escapeExpression","createFrame","assign","firstToken",code);
	}

	// ---------------- Built-ins ----------------
	var helpers=Object.create(null),partials=Object.create(null);
	helpers["if"]=function(c,o){return c?o.fn(this,{data:o.data}):o.inverse(this,{data:o.data});};
	helpers.unless=function(c,o){return !c?o.fn(this,{data:o.data}):o.inverse(this,{data:o.data});};
	helpers.each=function(l,o){
		if(!l||(Array.isArray(l)&&!l.length))return o.inverse(this);
		var out="",i=0,f;
		if(Array.isArray(l)){
			for(i=0;i<l.length;i++){f=createFrame(o.data);f["@index"]=i;f["@first"]=i===0;f["@last"]=i===l.length-1;out+=o.fn(l[i],{data:f});}
		}else{
			var keys=Object.keys(l);
			for(i=0;i<keys.length;i++){var k=keys[i];f=createFrame(o.data);f["@key"]=k;f["@index"]=i;f["@first"]=i===0;f["@last"]=i===keys.length-1;out+=o.fn(l[k],{data:f});}
		}
		return out;
	};
	helpers["with"]=(v,o)=>v?o.fn(v):o.inverse(this);
	helpers.log=m=>{if(console&&console.log)console.log(m);};
	helpers.lookup=(o,k)=>o?o[k]:"";
	helpers.helperMissing=()=>"";helpers.blockHelperMissing=(c,o)=>c?o.fn(c):o.inverse(c);

	function wrap(fn){return function(ctx,r){var d=(r&&r.data)||{};if(d.root===undefined)d.root=ctx;return fn(ctx||{},partials,helpers,d,evalMaybePath,parseArgs,escapeExpression,createFrame,assign,firstToken);} }

	// ---------------- Public API ----------------
	return{
		compile:(tpl,opts)=>wrap(compile(String(tpl),opts)),
		registerHelper:(n,f)=>{if(typeof n==="object"){for(var k in n)if(has(n,k))helpers[k]=n[k];}else helpers[n]=f;},
		registerPartial:(n,v)=>{if(typeof n==="object"){for(var k in n)if(has(n,k))partials[k]=typeof v==="function"?v:compile(String(v));}else partials[n]=typeof v==="function"?v:compile(String(v));},
		helpers:helpers,partials:partials,
		escapeExpression:escapeExpression,createFrame:createFrame,
		SafeString:SafeString
	};
}));