/**
 * mo-code-highlight.js — dependency-free syntax highlighter + copy buttons.
 *
 * Mono has no Frontend package and no build step (its stylesheet is a
 * vendored snapshot), so it cannot consume the shared code-highlight.ts the
 * built themes bundle. This file is a faithful 1:1 port of that tokenizer
 * (same rules, same order, same .tok-* spans): PHP, TS/JS, YAML, Bash, SQL,
 * JSON, Rust, HTML, XML. Loaded inline by MonoTheme only on pages whose
 * content carries a fenced block, through InlineJs::file like the admin
 * shell scripts.
 *
 * ES2015-safe: no `?.`, `??` or other Chrome 70-unsupported syntax.
 * Idempotent: blocks already carrying .tok-* spans are skipped, and a second
 * run never adds a second copy button.
 */
(function () {
  'use strict';

  var PHP_RULES = [
    [/^\/\/[^\n]*/, 'comment'], [/^\/\*[\s\S]*?\*\//, 'comment'], [/^#[^\n]*/, 'comment'],
    [/^'(?:[^'\\]|\\.)*'/, 'string'], [/^"(?:[^"\\]|\\.)*"/, 'string'],
    [/^\$\w+/, 'variable'],
    [/^(?:abstract|and|array|as|break|callable|case|catch|class|clone|const|continue|declare|default|die|do|echo|else|elseif|empty|enddeclare|endfor|endforeach|endif|endswitch|endwhile|eval|exit|extends|final|finally|fn|for|foreach|function|global|goto|if|implements|include|include_once|instanceof|insteadof|interface|isset|list|match|namespace|new|or|print|private|protected|public|readonly|require|require_once|return|static|switch|throw|trait|try|unset|use|var|while|xor|yield)\b/, 'keyword'],
    [/^(?:int|float|string|bool|array|object|callable|iterable|null|void|never|self|parent|static)\b/, 'type'],
    [/^(?:true|false|null)\b/, 'keyword'], [/^\d+\.?\d*/, 'number'],
    [/^->|::|\?->|\?\?/, 'operator'], [/^[a-zA-Z_]\w*(?=\s*\()/, 'function'],
    [/^[A-Z]\w*/, 'type'], [/^[a-zA-Z_]\w*/, 'property'],
    [/^[{}()\[\];,.:]/, 'punctuation'], [/^[ \t]+/, '']
  ];

  var TS_RULES = [
    [/^\/\/[^\n]*/, 'comment'], [/^\/\*[\s\S]*?\*\//, 'comment'],
    [/^'(?:[^'\\]|\\.)*'/, 'string'], [/^"(?:[^"\\]|\\.)*"/, 'string'], [/^`(?:[^`\\]|\\.)*`/, 'string'],
    [/^(?:import|export|from|default|const|let|var|function|class|extends|implements|interface|type|enum|namespace|module|declare|abstract|as|async|await|new|return|if|else|for|while|do|switch|case|break|continue|throw|try|catch|finally|typeof|instanceof|in|of|yield|this|super|static|public|private|protected|readonly|get|set|infer|keyof|never|unknown|any|void|null|undefined|true|false)\b/, 'keyword'],
    [/^\d+\.?\d*/, 'number'], [/^[a-zA-Z_]\w*(?=\s*[<(])/, 'function'],
    [/^[A-Z]\w*/, 'type'], [/^[a-zA-Z_]\w*/, 'property'],
    [/^[{}()\[\];,.:!?=<>+\-*/%&|^~]+/, 'operator'], [/^[ \t]+/, '']
  ];

  var YAML_RULES = [
    [/^#[^\n]*/, 'comment'], [/^'(?:[^'\\]|\\.)*'/, 'string'], [/^"(?:[^"\\]|\\.)*"/, 'string'],
    [/^\d+\.?\d*/, 'number'], [/^(?:true|false|null|yes|no|on|off)\b/, 'keyword'],
    [/^[a-zA-Z_][\w.-]*(?=\s*:)/, 'property'], [/^-[ \t]+/, 'punctuation'],
    [/^[{}()\[\],&*?|>!]/, 'punctuation'], [/^:[ \t]+/, 'punctuation'],
    [/^[ \t]+/, ''], [/^[^\s#'"{}()\[\],&*?|>!:-]+/, 'string']
  ];

  var BASH_RULES = [
    [/^#[^\n]*/, 'comment'], [/^"(?:[^"\\]|\\.)*"/, 'string'], [/^'(?:[^'\\]|\\.)*'/, 'string'],
    [/^\$\{[^}]+\}/, 'variable'], [/^\$\w+/, 'variable'],
    [/^(?:if|then|else|elif|fi|for|while|do|done|case|esac|function|return|exit|local|export|source|alias|unalias|set|unset|shift|declare|typeset|readonly|eval|exec|trap|wait|kill|bg|fg|jobs|cd|pwd|echo|printf|read|test|let|true|false|break|continue|in|select)\b/, 'keyword'],
    [/^\d+/, 'number'], [/^\$\(/, 'punctuation'], [/^[a-zA-Z_]\w*/, 'function'],
    [/^[|&;<>]+/, 'operator'], [/^[ \t]+/, ''], [/^[^\s#'"';|&<>]+/, 'string']
  ];

  var SQL_RULES = [
    [/^--[^\n]*/, 'comment'], [/^\/\*[\s\S]*?\*\//, 'comment'], [/^'(?:[^'\\]|\\.)*'/, 'string'],
    [/^\d+\.?\d*/, 'number'],
    [/^(?:SELECT|FROM|WHERE|INSERT|INTO|VALUES|UPDATE|SET|DELETE|CREATE|TABLE|DROP|ALTER|INDEX|JOIN|LEFT|RIGHT|INNER|OUTER|ON|AND|OR|NOT|IN|EXISTS|BETWEEN|LIKE|IS|NULL|AS|ORDER|BY|GROUP|HAVING|LIMIT|OFFSET|UNION|ALL|DISTINCT|COUNT|SUM|AVG|MIN|MAX|CASE|WHEN|THEN|ELSE|END|BEGIN|COMMIT|ROLLBACK|GRANT|REVOKE|PRIMARY|KEY|FOREIGN|REFERENCES|CONSTRAINT|DEFAULT|AUTO_INCREMENT|INTEGER|VARCHAR|TEXT|BOOLEAN|DATE|TIMESTAMP)\b/i, 'keyword'],
    [/^[a-zA-Z_]\w*/, 'property'], [new RegExp('^[{}()\\[\\];,.:*/+=\x3c\x3e\x27-]+'), 'operator'], [/^[ \t]+/, '']
  ];

  var JSON_RULES = [
    [/^"(?:[^"\\]|\\.)*"(?=\s*:)/, 'property'], [/^"(?:[^"\\]|\\.)*"/, 'string'], [/^-?\d+\.?\d*(?:[eE][+-]?\d+)?/, 'number'], [/^(?:true|false|null)\b/, 'keyword'], [/^[{}()\[\]:,]/, 'punctuation'], [/^[ \t]+/, '']
  ];

  var RUST_RULES = [
    [/^\/\/[^\n]*/, 'comment'], [/^\/\*[\s\S]*?\*\//, 'comment'],
    [/^'(?:[^'\\\n]|\\.)*'/, 'string'], [/^"(?:[^"\\]|\\.)*"/, 'string'], [/^b"(?:[^"\\]|\\.)*"/, 'string'], [/^r#*["'].*?["']#*/, 'string'],
    [/^''[\w]+/, 'keyword'],
    [/^\b(?:as|async|await|break|const|continue|crate|dyn|else|enum|extern|fn|for|if|impl|in|let|loop|match|mod|move|mut|pub|ref|return|self|Self|static|struct|super|trait|type|unsafe|use|where|while|yield)\b/, 'keyword'],
    [/^\b(?:bool|char|f32|f64|i8|i16|i32|i64|i128|isize|str|u8|u16|u32|u64|u128|usize|String|Vec|Option|Result|Box|Rc|Arc|HashMap|HashSet|BTreeMap)\b/, 'type'],
    [/^\b(?:true|false)\b/, 'keyword'],
    [/^\b(?:println!|eprintln!|format!|vec!|assert!|assert_eq!|assert_ne!|debug_assert!|debug_assert_eq!|todo!|unimplemented!|unreachable!|panic!|include_str!|include_bytes!|cfg!|env!|option_env!|concat!|stringify!|module_path!|file!|line!|column!|const_assert!)\b/, 'function'],
    [/^\d+\.?\d*(?:f32|f64|i8|i16|i32|i64|i128|u8|u16|u32|u64|u128|isize|usize)?\b/, 'number'],
    [/^\b0[xX][0-9a-fA-F_]+\b/, 'number'], [/^\b0[bB][01_]+\b/, 'number'], [/^\b0[oO][0-7_]+\b/, 'number'],
    [/^->|=>|::|\.\.|\.\.=|&&=|\|\||=|\+=|-=|\*=|\/=|%=|&=|\|=|\^=|<<=|>>=|!\?\?\?|\.\.\.?|\bfn\b/, 'operator'],
    [/^\bfn\s+\w+/, 'function'],
    [/^\b(?:pub\s+)?(?:fn|struct|enum|impl|trait|type|const|static)\s+\w+/, 'function'],
    [/^[A-Z]\w*/, 'type'], [/^[a-zA-Z_]\w*/, 'property'],
    [/^[{}()\[\];,.:]/, 'punctuation'], [/^[ \t]+/, '']
  ];

  var HTML_RULES = [
    [new RegExp('^<!--[\\s\\S]*?-->'), 'comment'],
    [new RegExp('^<!DOCTYPE[\\s\\S]{0,60}', 'i'), 'keyword'],
    [new RegExp('^<\\/?[a-zA-Z][\\w-]*'), 'tag'],
    [new RegExp('^[a-zA-Z][\\w-]*(?=\\s*=)'), 'property'],
    [new RegExp('^='), 'punctuation'],
    [new RegExp('^"[^"]*"'), 'string'], [new RegExp("^'[^']*'"), 'string'],
    [new RegExp('^&[a-zA-Z]+;'), 'number'], [new RegExp('^[ \\t]+'), ''],
    [new RegExp('^[^<]+'), '']
  ];

  var XML_RULES = [
    [new RegExp('^<!--[\\s\\S]*?-->'), 'comment'],
    [new RegExp('^<\\?[\\s\\S]{0,200}'), 'keyword'],
    [new RegExp('^<\\/?[a-zA-Z][\\w:-]*'), 'tag'],
    [new RegExp('^[a-zA-Z][\\w:-]*(?=\\s*=)'), 'property'],
    [new RegExp('^='), 'punctuation'],
    [new RegExp('^"[^"]*"'), 'string'], [new RegExp("^'[^']*'"), 'string'],
    [new RegExp('^&[a-zA-Z]+;'), 'number'], [new RegExp('^[ \\t]+'), ''],
    [new RegExp('^[^<]+'), '']
  ];

  var RULES_MAP = {
    php: PHP_RULES, ts: TS_RULES, typescript: TS_RULES, js: TS_RULES, javascript: TS_RULES,
    yaml: YAML_RULES, yml: YAML_RULES, bash: BASH_RULES, sh: BASH_RULES, shell: BASH_RULES,
    sql: SQL_RULES, json: JSON_RULES, html: HTML_RULES, xml: XML_RULES, rust: RUST_RULES
  };

  function tokenize(code, rules) {
    var tokens = [];
    var remaining = code;
    var i, m, matched;
    while (remaining.length > 0) {
      matched = false;
      for (i = 0; i < rules.length; i++) {
        m = remaining.match(rules[i][0]);
        if (null !== m && 0 === m.index) {
          tokens.push({ type: rules[i][1], value: m[0] });
          remaining = remaining.slice(m[0].length);
          matched = true;
          break;
        }
      }
      if (!matched) { tokens.push({ type: '', value: remaining[0] }); remaining = remaining.slice(1); }
    }
    return tokens;
  }

  function esc(text) {
    return text.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

  function tokensToHtml(tokens) {
    var html = '';
    var i;
    for (i = 0; i < tokens.length; i++) {
      html += '' !== tokens[i].type
        ? '<span class="tok-' + tokens[i].type + '">' + esc(tokens[i].value) + '</span>'
        : esc(tokens[i].value);
    }
    return html;
  }

  function tokenizeHtmlWithScript(code) {
    var tokens = [];
    var parts = code.split(/(<\/?(?:script|style)[^>]*>)/gi);
    var inScript = false;
    var inStyle = false;
    var i, part;
    for (i = 0; i < parts.length; i++) {
      part = parts[i];
      if (/^<script[\s>]/i.test(part)) { tokens.push({ type: 'tag', value: part }); inScript = true; }
      else if (/^<\/script[\s>]/i.test(part)) { tokens.push({ type: 'tag', value: part }); inScript = false; }
      else if (/^<style[\s>]/i.test(part)) { tokens.push({ type: 'tag', value: part }); inStyle = true; }
      else if (/^<\/style[\s>]/i.test(part)) { tokens.push({ type: 'tag', value: part }); inStyle = false; }
      else if (inScript) { tokens = tokens.concat(tokenize(part, TS_RULES)); }
      else if (inStyle) { tokens = tokens.concat(tokenize(part, TS_RULES)); }
      else if (part.length > 0) { tokens = tokens.concat(tokenize(part, HTML_RULES)); }
    }
    return tokens;
  }

  function highlightCode(code, lang) {
    if ('html' === lang || 'xml' === lang) return tokensToHtml(tokenizeHtmlWithScript(code));
    var rules = RULES_MAP[lang];
    if (!rules) return esc(code);
    return tokensToHtml(tokenize(code, rules));
  }

  function showCopied(btn) {
    btn.textContent = 'Copied!';
    if (btn.classList) btn.classList.add('copied');
    setTimeout(function () {
      btn.textContent = 'Copy';
      if (btn.classList) btn.classList.remove('copied');
    }, 2000);
  }

  function copyFallback(code, btn) {
    var ta = document.createElement('textarea');
    ta.value = code.textContent || '';
    ta.style.position = 'fixed';
    ta.style.opacity = '0';
    document.body.appendChild(ta);
    ta.select();
    try { document.execCommand('copy'); } catch (e) { /* clipboard unavailable — the button still confirms */ }
    document.body.removeChild(ta);
    showCopied(btn);
  }

  function copyButton(code) {
    var btn = document.createElement('button');
    btn.className = 'copy-btn';
    btn.type = 'button';
    btn.textContent = 'Copy';
    btn.addEventListener('click', function () {
      var text = code.textContent || '';
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(function () { showCopied(btn); }, function () { copyFallback(code, btn); });
      } else {
        copyFallback(code, btn);
      }
    });
    return btn;
  }

  function langOf(code) {
    var m = /(?:^|\s)language-([^\s]+)/.exec(code.className || '');
    return m ? m[1] : '';
  }

  var NO_BADGE = ' plain plaintext text console terminal output log ';

  function langBadge(lang) {
    var badge = document.createElement('span');
    badge.className = 'mo-code-badge';
    badge.textContent = lang.toUpperCase();
    return badge;
  }

  function showBadge(lang) {
    return '' !== lang && NO_BADGE.indexOf(' ' + lang.toLowerCase() + ' ') < 0;
  }

  function initMoCodeHighlight() {
    var blocks = document.querySelectorAll('pre code[class^="language-"]');
    var i, code, lang;
    for (i = 0; i < blocks.length; i++) {
      code = blocks[i];
      if (code.getAttribute('data-highlighted')) continue;
      lang = code.className.replace('language-', '');
      code.innerHTML = highlightCode(code.textContent || '', lang);
      code.setAttribute('data-highlighted', 'true');
    }
    var pres = document.querySelectorAll('pre');
    var pre, badgeLang;
    for (i = 0; i < pres.length; i++) {
      pre = pres[i];
      code = pre.querySelector('code');
      if (!code) continue;
      badgeLang = langOf(code);
      if (showBadge(badgeLang) && !pre.querySelector('.mo-code-badge')) {
        pre.insertBefore(langBadge(badgeLang), pre.firstChild);
        pre.classList.add('has-badge');
      }
      if (pre.querySelector('.copy-btn')) continue;
      pre.appendChild(copyButton(code));
    }
  }

  initMoCodeHighlight();
}
)();
