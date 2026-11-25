---
title: "Zend engine and opcodes ... how PHP actually runs"
date: "2026-08-15"
version: "1.0.0"
tags: ["php", "zend", "engine", "performance"]
excerpt: "What the Zend engine is, how PHP turns your source code into opcodes and why the whole interpreter is written in C ... the first part of a series about why PHP is really a set of precompiled C chunks."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Architecture"
views: "0"
translation: "zend-engine-a-opcody"
published: true
---

When you write a PHP script and send a request, what actually happens? Most
developers would say "it just runs". But between your source code and the
response there is a whole chain of steps handled by the **Zend engine** ...
and that's exactly where the answer begins to the question of why PHP is
fast when you use it right. This is the first part of a series about how PHP
really works and why it is, at its core, a set of precompiled C chunks.

## What the Zend engine is

The Zend engine is the **heart of PHP** ... the part that compiles and
executes your script. When we say "PHP 8.5", we're really talking about the
PHP interpreter whose core is the Zend engine. Everything else (the standard
library, extensions, bindings) plugs into it.

Important: the Zend engine is **not a classic compiler**. It doesn't
produce an `.exe` or machine code ahead of time. It's a combination of two
things:

1. **A compiler** ... turns the source code into **opcodes** (bytecode that
   resembles assembly).
2. **A virtual machine (Zend VM)** ... executes those opcodes.

That's the same model you know from Java (bytecode + JVM) or .NET
(IL + CLR). PHP just does this work on every request ... unless you have
OPcache, which we'll cover in the third part of the series.

## How PHP processes a script

The chain looks like this:

```
source → lexing → parsing → compilation → opcodes → execution (Zend VM)
```

### 1. Lexing ... from characters to tokens

The lexer reads the source code and splits it into **tokens**. For example:

```php
<?php
echo "Hi";
```

becomes something like `T_OPEN_TAG`, `T_ECHO`, `T_CONSTANT_ENCAPSED_STRING`,
`;` ... each token is already categorized: "this is the echo keyword",
"this is a string", "this is a semicolon".

### 2. Parsing ... from tokens to a tree

The parser takes the tokens and builds an **AST** (abstract syntax tree) ...
a tree structure that captures the logic of your code: `echo` is a node
with one child ... the string "Hi".

### 3. Compilation ... from tree to opcodes

The compiler walks the AST and produces **opcodes** ... simple instructions
for the Zend VM. For our example:

```text
line     op                          return
-----    --------------------------  ------
   2     ECHO                        "Hi"
   2     RETURN                      1
```

An opcode is an atomic instruction: "print this string", "return a value",
"add two variables", "call this function". It's the lowest level at which
PHP represents your code ... and it's completely independent of the syntax
you wrote it in.

### 4. Execution ... the Zend VM runs the opcodes

The Zend VM takes the opcodes one by one and executes each through a
dedicated **handler**. And here comes the key point: those handlers are
written in C.

## Opcodes are precompiled code

The moment a script is compiled into opcodes, nobody needs the source
anymore. The Zend VM works exclusively with opcodes ... and if OPcache keeps
them in memory, the next request doesn't have to do lexing, parsing or
compilation. The script is "precompiled" in exactly the sense that Astro
pre-renders pages into finished HTML: the work is done once, then it's just
delivered.

If you want to see the opcodes with your own eyes, the VLD extension
(Vulcan Logic Disassembler) does the job:

```bash
php -dvld.active=1 script.php
```

It's not part of PHP ... you install it separately ... but it shows you exactly
what the Zend engine made of your code.

## And it's all in C

The Zend engine is written in C. Its hash tables, zval (the variable
value), string handling (`zend_string`), the execution loop ... all C.
When your opcode `ECHO "Hi"` runs, a C function executes it. When you call
`str_replace`, `json_encode` or PDO ... you're calling a C function. Even
the execution of your own code (the Zend VM) is C code.

That is literally what we talk about in the article about the philosophy of
our stack ([“PHP C chunks” is not a metaphor](/blog/filozofia-super-rychleho-php)):
PHP is a thin syntax layer over a huge library of C functions. The Zend
engine is the proof ... not a slogan.

## Summary

- **Zend engine** = compiler + virtual machine (Zend VM), the heart of PHP
- The chain: source → tokens (lexing) → AST (parsing) → **opcodes**
  (compilation) → execution
- **Opcode** = an atomic instruction for the Zend VM (`ECHO`, `RETURN`,
  function calls…)
- Opcodes are "precompiled" code ... no source needed, OPcache keeps them
  in memory
- **The Zend engine is written in C** ... every opcode, every built-in
  function, every running piece of code is eventually C
- The VLD extension shows the opcodes: `php -dvld.active=1 script.php`

In the next part of the series we'll go through the history ... how PHP and
the Zend engine went from one person's tool to today's PHP 8.5.

## Sources

- [PHP Manual](https://www.php.net/manual/) — official PHP documentation
- [PHP-FIG — PSR Standards](https://www.php-fig.org/) — PHP standards
- [Nativa Minimal Documentation](/docs/getting-started) — official getting started guide
- [OWASP Cheat Sheet Series](https://cheatsheetseries.owasp.org/) — security best practices
- [W3C — Web Standards](https://www.w3.org/standards/) — web standards
