<?php

declare(strict_types=1);

use PhpCsFixer\Config;
use PhpCsFixer\Finder;

/**
 * The kernel's code style — `composer cs-fix` / `composer cs-fix-dry`.
 *
 * Same rule set as the showcase — which is measured green there (0 of 385
 * files) — scoped to the code THIS repo owns and may restyle:
 *
 *   - `src/Modules/Core` + `src/Modules/Database` (the kernel slice),
 *     `src/Themes`, `bin`, `public`, `tests`. That is the same scope
 *     `phpstan.neon` analyses, and the scope `bin/build/extract-kernel.sh
 *     --check` compares. Upstream's finder covered all of `src/`; this tree's
 *     `src/Modules/*` also holds INSTALLED module slices, which are operator
 *     state (gitignored, present only where someone ran `bin/cms module:add`).
 *     Scanning those would make the gate's verdict depend on what is installed:
 *     green on a fresh clone, red on a workstation.
 *   - `modules/` is NOT scanned, deliberately. That directory is the committed
 *     module catalogue, imported verbatim from the showcase and re-imported
 *     whenever a module is updated: restyling it would turn every future
 *     re-import into a diff to hand-merge, for zero behavioural gain.
 *   - `Frontend/` holds no PHP.
 *
 * Without this file `composer cs-fix-dry` used to CREATE a config and append
 * to .gitignore as a side effect of a "dry run" — a gate that mutates the tree
 * it is supposed to be checking.
 */
$finder = Finder::create()
    ->in([
        __DIR__ . '/src/Modules/Core',
        __DIR__ . '/src/Modules/Database',
        __DIR__ . '/src/Themes',
        __DIR__ . '/bin',
        __DIR__ . '/public',
        __DIR__ . '/tests',
    ])
    ->name('*.php')
    ->ignoreDotFiles(true)
    ->ignoreVCS(true);

return (new Config())
    ->setRiskyAllowed(true)
    ->setFinder($finder)
    ->setCacheFile('.php-cs-fixer.cache')
    ->setRules([
        '@PSR12' => true,

        // Code Quality
        'array_syntax' => ['syntax' => 'short'],
        'blank_line_after_namespace' => true,
        'blank_line_before_statement' => [
            'statements' => ['return', 'throw', 'try', 'yield', 'break'],
        ],
        'concat_space' => ['spacing' => 'one'],
        'declare_equal_normalize' => ['space' => 'single'],
        'declare_strict_types' => true,
        'fully_qualified_strict_types' => [
            'import_symbols' => true,
            'leading_backslash_in_global_namespace' => true,
        ],
        'heredoc_to_nowdoc' => true,
        'increment_style' => ['style' => 'post'],
        'list_syntax' => ['syntax' => 'short'],
        'method_argument_space' => [
            'on_multiline' => 'ensure_fully_multiline',
            'keep_multiple_spaces_after_comma' => true,
        ],
        'no_unused_imports' => true,
        'no_useless_else' => true,
        'no_useless_return' => true,
        'ordered_imports' => [
            'sort_algorithm' => 'alpha',
            'imports_order' => ['class', 'function', 'const'],
        ],
        'phpdoc_align' => [
            'align' => 'vertical',
            'tags' => ['param', 'property', 'return', 'throws', 'type', 'var'],
        ],
        'phpdoc_indent' => true,
        'phpdoc_order' => true,
        'phpdoc_scalar' => true,
        'phpdoc_separation' => true,
        'phpdoc_summary' => true,
        'phpdoc_trim' => true,
        'phpdoc_types' => true,
        'phpdoc_types_order' => [
            'null_adjustment' => 'always_last',
            'sort_algorithm' => 'none',
        ],
        'return_type_declaration' => ['space_before' => 'none'],
        'single_quote' => true,
        'strict_comparison' => true,
        'trailing_comma_in_multiline' => [
            'elements' => ['arrays', 'arguments', 'parameters'],
        ],
        'unary_operator_spaces' => true,
        'void_return' => true,
    ]);
