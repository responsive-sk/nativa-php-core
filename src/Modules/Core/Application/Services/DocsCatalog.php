<?php

declare(strict_types = 1);

namespace Modules\Core\Application\Services;

use Modules\Core\Infrastructure\Paths\AppPaths;

/**
 * DocsCatalog — the publication boundary of the /docs surface.
 *
 * The repository's `docs/` directory is not a documentation set, it is a mixed
 * bag, and the difference is measured rather than stylistic: 22 top-level
 * markdown files, of which 13 are guides a reader may open, sitting next to six
 * `security-*` audit / pentest / checklist reports, an internal PR summary, a
 * rhythm-score measurement table, an admin-template map, the ADRs, the Lekalo
 * model documentation with 425 772 B of generated trace manifests, and 37 files
 * of dex-era reference code that are not even in git (`.gitignore:65`).
 *
 * So the unit of publication is an ENTRY, not a file extension and not a
 * directory:
 *
 * - a request path is resolved THROUGH this map, so nothing outside it can be
 *   served whatever a working tree happens to contain;
 * - the map is an allow-list because a deny-list rots: the next `security-*`
 *   file dropped into `docs/` would be published by default;
 * - the excluded families are named right here so the reason travels with the
 *   code that would otherwise publish them.
 *
 * Excluded on purpose, family by family: `security-audit-*`,
 * `security-pentest-*`, `security-checklist-*` (point-in-time reports about
 * defects that were found and fixed), `PR-summary-*` (internal review
 * artefact), `rhythm-score-matrix` (internal measurement table behind the
 * article-rhythm advisor), `admin-templates` (admin markup map for
 * contributors), `lekalo/**` and its trace manifests, and `dex/**`.
 *
 * The architecture decision records under `adr/` ARE published: they are the
 * decisions the articles argue about, they link each other with plain `.md`
 * targets (which `rewriteLinks()` turns into `/docs/adr/...`), and a reader
 * following a series on themes needs the ADR to check the claim.
 */
final readonly class DocsCatalog
{
    /**
     * Every guide is English, and that is a property of the CONTENT, not of the
     * visitor's locale: the surface declares its own language so a screen
     * reader does not read English prose with Slovak phonetics.
     */
    public const string LANGUAGE = 'en';

    /** Longest description the index renders for one guide. */
    private const int DESCRIPTION_LIMIT = 180;

    /**
     * Published entries, keyed by their docs-relative path without `.md`.
     *
     * Keyed by path rather than flattened into an invented slug so a nested
     * document (`adr/0003-…`, `lekalo/scan/…`) costs one line, not a rename.
     *
     * @var array<string, string> key => file name, relative to docs/
     */
    private const array PUBLISHED = [
        'getting-started' => 'getting-started.md',
        'adding-modules' => 'adding-modules.md',
        'theme-layout' => 'theme-layout.md',
        'template-map' => 'template-map.md',
        'design-blocks' => 'design-blocks.md',
        'blog-covers' => 'blog-covers.md',
        'blog-images-guide' => 'blog-images-guide.md',
        'blog-voice' => 'blog-voice.md',
        'forms' => 'forms.md',
        'questions' => 'questions.md',
        'security-patterns' => 'security-patterns.md',
        'bin-layout' => 'bin-layout.md',
        'lekalo-model' => 'lekalo-model.md',
        'adr/0001-theme-architecture-for-2-themes' => 'adr/0001-theme-architecture-for-2-themes.md',
        'adr/0002-third-wired-theme-erik' => 'adr/0002-third-wired-theme-erik.md',
        'adr/0003-theme-module-contract' => 'adr/0003-theme-module-contract.md',
    ];

    private string $docsDir;

    /**
     * @param string|null $docsDir Directory holding the guides — the repository
     *                             `docs/` by default. The seam exists so a test
     *                             can prove what a deployment without the
     *                             directory renders, without touching the tree.
     */
    public function __construct(?string $docsDir = null)
    {
        $this->docsDir = $docsDir ?? AppPaths::instance()->join('docs');
    }

    /** @return list<string> Published keys, in the order the index renders them. */
    public function keys(): array
    {
        return array_keys(self::PUBLISHED);
    }

    public function has(string $key): bool
    {
        return isset(self::PUBLISHED[$key]);
    }

    /**
     * The index payload — one row per published guide whose file exists.
     *
     * A missing file is skipped rather than faked: the alternative is a card
     * that 404s, which reads as a broken site instead of an absent deployment.
     *
     * @return list<array{key: string, title: string, description: string}>
     */
    public function entries(): array
    {
        $entries = [];

        foreach ($this->keys() as $key) {
            $entry = $this->entry($key);
            if (null !== $entry) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    /** @return array{key: string, title: string, description: string}|null */
    public function entry(string $key): ?array
    {
        $source = $this->source($key);

        if (null === $source) {
            return null;
        }

        [$title, $body] = $this->split($source);

        return [
            'key' => $key,
            'title' => '' !== $title ? $this->plainText($title) : $this->label($key),
            'description' => $this->describe($body),
        ];
    }

    /**
     * The guide as the page needs it: its title, and a body with the H1 removed
     * (the layout renders the title itself) and its links pointed at the
     * surface that serves them.
     *
     * @return array{title: string, body: string}|null
     */
    public function read(string $key): ?array
    {
        $source = $this->source($key);

        if (null === $source) {
            return null;
        }

        [$title, $body] = $this->split($source);

        return [
            'title' => '' !== $title ? $this->plainText($title) : $this->label($key),
            'body' => $this->rewriteLinks($key, $body),
        ];
    }

    /**
     * Absolute path of a published entry, or null.
     *
     * Null covers three different situations on purpose — not published, an
     * unsafe key, and absent from this deployment — because all three answer
     * the same 404 and a caller that could tell them apart would leak which
     * files exist on the disk.
     */
    public function filePath(string $key): ?string
    {
        if (!$this->isSafeKey($key) || !$this->has($key)) {
            return null;
        }

        $real = realpath($this->docsDir . DIRECTORY_SEPARATOR . self::PUBLISHED[$key]);
        $base = realpath($this->docsDir);

        if (false === $real || false === $base || !is_file($real)
            || !str_starts_with($real, $base . DIRECTORY_SEPARATOR)
        ) {
            return null;
        }

        return $real;
    }

    /**
     * A key may only ever produce one entry of PUBLISHED and no path segment:
     * no `..`, no leading dot, no backslash, no URL escape, no leading slash.
     *
     * The map lookup would already refuse an unknown key — this guard is what
     * makes "refused without reading the file" a property of the code rather
     * than a coincidence of the map, so a future nested key cannot escape both.
     */
    public function isSafeKey(string $key): bool
    {
        return 1 === preg_match('#^[a-z0-9][a-z0-9/._-]*$#', $key)
            && !str_contains($key, '..')
            && !str_contains($key, '\\');
    }

    /**
     * Point the links that this surface can serve at this surface.
     *
     * Only a relative `.md` target that resolves to a published entry is
     * rewritten. Everything else — an external URL, an absolute path, a
     * repo-relative link that leaves `docs/`, a link to a document nobody
     * published — comes back exactly as its author wrote it: a rewrite that
     * guesses turns a link that 404s today into a link that 404s somewhere new,
     * which is worse than a link that plainly names a file.
     */
    public function rewriteLinks(string $sourceKey, string $markdown): string
    {
        return (string) preg_replace_callback(
            '/\]\(\s*([^)\s]+)\s*\)/',
            function (array $matches) use ($sourceKey): string {
                $target = $this->rewriteTarget($sourceKey, (string) $matches[1]);

                return '](' . ($target ?? (string) $matches[1]) . ')';
            },
            $markdown,
        );
    }

    /** The `/docs/…` URL for a target, or null when this surface cannot serve it. */
    private function rewriteTarget(string $sourceKey, string $target): ?string
    {
        if (str_starts_with($target, '/') || str_contains($target, ':')) {
            return null;
        }

        $parts = explode('#', $target, 2);
        $path = $parts[0];
        $fragment = $parts[1] ?? '';

        if (!str_ends_with($path, '.md')) {
            return null;
        }

        $key = $this->resolveKey($sourceKey, substr($path, 0, -\strlen('.md')));

        if (null === $key) {
            return null;
        }

        return '/docs/' . $key . ('' !== $fragment ? '#' . $fragment : '');
    }

    /**
     * Resolve a docs-relative target against the document that wrote it.
     * Leaving `docs/` resolves to nothing — that is the point of the walk.
     */
    private function resolveKey(string $sourceKey, string $relative): ?string
    {
        $segments = [];

        if (str_contains($sourceKey, '/')) {
            /** @var list<string> $segments */
            $segments = explode('/', substr($sourceKey, 0, (int) strrpos($sourceKey, '/')));
        }

        foreach (explode('/', $relative) as $segment) {
            if ('' === $segment || '.' === $segment) {
                continue;
            }

            if ('..' === $segment) {
                if ([] === $segments) {
                    return null;
                }

                array_pop($segments);

                continue;
            }

            $segments[] = $segment;
        }

        $key = implode('/', $segments);

        // `docs/adding-modules.md`, written from the repository root's point of
        // view, is the same document as `adding-modules.md` written next to it.
        if (str_starts_with($key, 'docs/')) {
            $key = substr($key, \strlen('docs/'));
        }

        return $this->has($key) ? $key : null;
    }

    /** Raw contents of a published, safe, existing guide — null otherwise. */
    private function source(string $key): ?string
    {
        $file = $this->filePath($key);

        if (null === $file) {
            return null;
        }

        $contents = @file_get_contents($file);

        return false === $contents ? null : $contents;
    }

    /**
     * Split a guide into its title and the rest.
     *
     * The H1 is cut out BY POSITION rather than by pattern: a title carrying
     * regex metacharacters or inline markup must not be able to decide what the
     * body is.
     *
     * @return array{string, string}
     */
    private function split(string $markdown): array
    {
        $matched = preg_match('/^#\s+(.+?)\s*$/m', $markdown, $matches, PREG_OFFSET_CAPTURE);

        if (1 !== $matched) {
            return ['', $markdown];
        }

        $heading = (string) $matches[0][0];
        $offset = (int) $matches[0][1];
        $body = substr($markdown, 0, $offset) . substr($markdown, $offset + \strlen($heading));

        return [trim((string) $matches[1][0]), ltrim($body, "\n")];
    }

    /** One plain line of prose from the body, for the index card. */
    private function describe(string $body): string
    {
        foreach (explode("\n", $body) as $line) {
            $text = trim($line);

            if (!$this->isProse($text)) {
                continue;
            }

            return $this->shorten($this->plainText($text));
        }

        return '';
    }

    /** Markdown that is a structure, a quotation or empty — not an opening line. */
    private function isProse(string $text): bool
    {
        if ('' === $text) {
            return false;
        }

        foreach (['#', '```', '>', '|', '-', '*', '<', '!'] as $marker) {
            if (str_starts_with($text, $marker)) {
                return false;
            }
        }

        return 1 !== preg_match('/^\d+\./', $text);
    }

    /**
     * One line of plain text: inline markup resolved and whitespace collapsed.
     *
     * The H1 is rendered as ESCAPED text (never through the Markdown renderer),
     * so a title written with backticks would otherwise show them literally —
     * `The \`bin/\` layout` — on the card and in the heading.
     */
    private function plainText(string $line): string
    {
        $text = (string) preg_replace('/\[([^\]]+)\]\([^)]+\)/', '$1', $line);
        $text = str_replace(['**', '__', '`'], '', $text);

        return trim((string) preg_replace('/\s+/', ' ', $text));
    }

    /** A description is plain text that has to fit in a card. */
    private function shorten(string $text): string
    {
        if (mb_strlen($text) <= self::DESCRIPTION_LIMIT) {
            return $text;
        }

        return rtrim(mb_substr($text, 0, self::DESCRIPTION_LIMIT - 1)) . '…';
    }

    /** A readable name for a key whose file carries no H1. */
    private function label(string $key): string
    {
        return ucfirst(str_replace(['-', '/'], [' ', ' '], $key));
    }
}
