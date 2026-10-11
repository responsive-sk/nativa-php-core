<?php

declare(strict_types = 1);

namespace Modules\Blog\Interfaces\CLI\Command;

use Modules\Blog\Application\Services\BlogArticlesService;
use Modules\Blog\Application\Services\CoverGenerator;
use Modules\Blog\Application\Services\CoverPathResolver;
use Modules\Core\Application\Services\MarkdownRenderer;
use Modules\Core\Infrastructure\Paths\AppPaths;
use Modules\Core\Interfaces\HTTP\ContentServices;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * bin/cms generate-images — generate preview images for blog articles.
 *
 * Thin CLI shell over Modules\Blog\Application\Services\CoverGenerator (the
 * command owns only flag parsing, file discovery and raster conversion; all
 * rendering lives in the Blog module). Covers are byte-deterministic: the
 * same article always renders the same SVG. Without --template/--color the
 * identity is auto-resolved (series front matter → slug hash → table).
 */
#[AsCommand(name: 'generate-images', description: 'Generate preview images for blog articles')]
final class GenerateImagesCommand extends Command
{
    private readonly CoverGenerator $covers;
    private readonly CoverPathResolver $paths;

    public function __construct(?CoverGenerator $covers = null, ?CoverPathResolver $paths = null)
    {
        parent::__construct();
        $this->covers = $covers ?? new CoverGenerator();
        $this->paths = $paths ?? new CoverPathResolver();
    }

    protected function configure(): void
    {
        $this
            ->addOption('slug', 's', InputOption::VALUE_REQUIRED, 'Generate image for a specific article slug only')
            // Relative to the storage root — AppPaths::storage() joins the
            // base storage/ dir, so the physical target is storage/blog-images.
            ->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Output directory', 'blog-images')
            ->addOption('template', 't', InputOption::VALUE_REQUIRED, 'Template (code, grid, wave, circuit, gradient, noise, abstract, rust) — omit for auto identity (series → slug hash)', '')
            ->addOption('color', 'c', InputOption::VALUE_REQUIRED, 'Color scheme (blue, green, orange, purple, cyan) — omit for auto identity', '')
            ->addOption('code-lines', null, InputOption::VALUE_REQUIRED, 'Max code-evidence lines on the cover (0 disables the snippet)', (string) CoverGenerator::DEFAULT_CODE_LINES)
            ->addOption('format', 'f', InputOption::VALUE_REQUIRED, 'Output format: svg, webp, png, or both (svg + webp)', 'svg')
            ->addOption('quality', null, InputOption::VALUE_REQUIRED, 'WebP/PNG quality (1-100)', '85');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Empty flag means "no override" — pass null so the auto identity
        // (series front matter → slug hash) can take over.
        $template = '' !== ($rawTemplate = $this->optionalOption($input, 'template')) ? $rawTemplate : null;
        $color = '' !== ($rawColor = $this->optionalOption($input, 'color')) ? $rawColor : null;
        $format = $this->optionString($input, 'format', 'svg');
        $quality = $this->optionInt($input, 'quality', 85);
        $codeLines = $this->optionInt($input, 'code-lines', CoverGenerator::DEFAULT_CODE_LINES);

        if (null === $this->validateOptions($template, $color, $format, $quality, $codeLines, $output)) {
            return Command::FAILURE;
        }

        $outputDir = $this->optionString($input, 'output', 'storage/blog-images');
        $outputPath = $this->prepareOutputPath($outputDir);
        $generated = $this->processArticles($input, $output, $template, $color, $format, $quality, $codeLines, $outputPath);

        $output->writeln('');
        $output->writeln('<info>Generated ' . $generated . ' image(s) in ' . $outputDir . '/</info>');

        return Command::SUCCESS;
    }

    /**
     * Option value that may legitimately be empty (auto identity) — returns
     * '' when the flag is absent.
     */
    private function optionalOption(InputInterface $input, string $name): string
    {
        $value = $input->getOption($name);

        return is_string($value) ? $value : '';
    }

    private function optionString(InputInterface $input, string $name, string $default = ''): string
    {
        $value = $input->getOption($name);

        return is_string($value) && '' !== $value ? $value : $default;
    }

    private function optionInt(InputInterface $input, string $name, int $default = 0): int
    {
        $value = $input->getOption($name);

        return is_string($value) && '' !== $value ? (int) $value : $default;
    }

    private function validateOptions(?string $template, ?string $color, string $format, int $quality, int $codeLines, OutputInterface $output): ?bool
    {
        if (null !== $template && !isset(CoverGenerator::TEMPLATES[$template])) {
            $output->writeln('<error>Invalid template: ' . $template . '</error>');
            $output->writeln('Available templates: ' . implode(', ', array_keys(CoverGenerator::TEMPLATES)));

            return null;
        }

        if (null !== $color && !isset(CoverGenerator::PALETTES[$color])) {
            $output->writeln('<error>Invalid color: ' . $color . '</error>');
            $output->writeln('Available colors: ' . implode(', ', array_keys(CoverGenerator::PALETTES)));

            return null;
        }

        $validFormats = ['svg', 'webp', 'png', 'both'];
        if (!in_array($format, $validFormats, true)) {
            $output->writeln('<error>Invalid format: ' . $format . '</error>');
            $output->writeln('Available formats: ' . implode(', ', $validFormats));

            return null;
        }

        if ($quality < 1 || $quality > 100) {
            $output->writeln('<error>Quality must be between 1 and 100</error>');

            return null;
        }

        if ($codeLines < 0 || $codeLines > 20) {
            $output->writeln('<error>Code lines must be between 0 and 20</error>');

            return null;
        }

        // Raster export needs a converter — point at the package when missing.
        if (in_array($format, ['webp', 'png', 'both'], true) && !$this->hasConversionTool()) {
            $output->writeln('<warning>rsvg-convert not found. Installing librsvg2-bin...</warning>');
            $output->writeln('<info>Run: sudo apt-get install librsvg2-bin</info>');

            return null;
        }

        return true;
    }

    private function prepareOutputPath(string $outputDir): string
    {
        $outputPath = AppPaths::instance()->storage(ltrim($outputDir, '/'));

        if (!is_dir($outputPath)) {
            mkdir($outputPath, 0o775, true);
        }

        return $outputPath;
    }

    private function processArticles(InputInterface $input, OutputInterface $output, ?string $template, ?string $color, string $format, int $quality, int $codeLines, string $outputPath): int
    {
        $slug = $this->optionString($input, 'slug');
        // Articles live in one directory per locale: the default locale
        // (settings.locale) in the family root, every other locale in
        // blog/{lang}. The mapper owns that mapping — no glob guessing.
        $files = $this->articleFiles();
        sort($files);
        $generated = 0;

        foreach ($files as $file) {
            $results = $this->generateForArticle((string) $file, $slug, $template, $color, $format, $quality, $codeLines, $outputPath);
            foreach ($results as $result) {
                $output->writeln($result);
                $generated++;
            }
        }

        return $generated;
    }

    /**
     * Generate one article's cover (all requested formats).
     *
     * @return list<string> console messages
     */
    private function generateForArticle(string $file, string $slugFilter, ?string $template, ?string $color, string $format, int $quality, int $codeLines, string $outputPath): array
    {
        $fileSlug = basename($file, '.md');

        if ('' !== $slugFilter && $fileSlug !== $slugFilter) {
            return [];
        }

        if (1 !== preg_match('/^[a-z0-9][a-z0-9_-]*$/i', $fileSlug)) {
            return [];
        }

        $source = file_get_contents($file);
        if (!is_string($source)) {
            return [];
        }

        $fm = (new MarkdownRenderer())->extractFrontMatter($source);
        $locale = $this->paths->localeFor($file);

        $svg = $this->covers->generate($fileSlug, $fm['meta'], $fm['body'], $locale, $template, $color, $codeLines);

        return $this->writeOutputs($fileSlug, $svg, $format, $quality, $outputPath, $locale);
    }

    /**
     * Write the SVG and any requested raster variants.
     *
     * @return list<string>
     */
    private function writeOutputs(string $fileSlug, string $svg, string $format, int $quality, string $outputPath, string $locale = 'sk'): array
    {
        $results = [];

        if (in_array($format, ['svg', 'both'], true)) {
            $path = $this->coverSvgPath($fileSlug, $locale);
            $results[] = $this->writeFile($path, $svg, $this->coverSvgLabel($path));
        }

        if (in_array($format, ['webp', 'both'], true)) {
            $webpFile = $outputPath . DIRECTORY_SEPARATOR . $fileSlug . '.webp';
            $results[] = $this->convertToWebp($svg, $webpFile, $quality)
                ? '<info>Generated: ' . $fileSlug . '.webp</info>'
                : '<error>Failed to generate: ' . $fileSlug . '.webp</error>';
        }

        if ('png' === $format) {
            $pngFile = $outputPath . DIRECTORY_SEPARATOR . $fileSlug . '.png';
            $results[] = $this->convertToPng($svg, $pngFile)
                ? '<info>Generated: ' . $fileSlug . '.png</info>'
                : '<error>Failed to generate: ' . $fileSlug . '.png</error>';
        }

        return $results;
    }

    /**
     * Write the SVG to its collision-proof path and make sure the directory
     * exists.
     *
     * The rule itself — flat for a unique slug, `blog-images/{locale}/` for
     * a slug two locales share — belongs to CoverPathResolver, where a test
     * can reach it. This method only creates the directory.
     */
    private function coverSvgPath(string $fileSlug, string $locale): string
    {
        $path = $this->paths->pathFor($fileSlug, $locale);

        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0o775, true);
        }

        return $path;
    }

    /**
     * Every article markdown file across all locales.
     *
     * @return list<string>
     */
    private function articleFiles(): array
    {
        $files = [];

        foreach (ContentServices::mapper()->locales() as $locale) {
            $dir = AppPaths::instance()->storage(BlogArticlesService::directoryFor($locale));
            foreach (glob($dir . DIRECTORY_SEPARATOR . '*.md') ?: [] as $file) {
                $files[] = (string) $file;
            }
        }

        return $files;
    }

    private function coverSvgLabel(string $path): string
    {
        $root = AppPaths::instance()->storage('blog-images') . DIRECTORY_SEPARATOR;

        return str_starts_with($path, $root) ? substr($path, strlen($root)) : basename($path);
    }

    private function writeFile(string $path, string $content, string $label): string
    {
        return file_put_contents($path, $content) !== false
            ? '<info>Generated: ' . $label . '</info>'
            : '<error>Failed to generate: ' . $label . '</error>';
    }

    /**
     * Unique temp path under storage/cache for raster conversion intermediates.
     *
     * sys_get_temp_dir() is deliberately avoided: shared hostings may
     * forbid it via open_basedir, while the app's storage tree is always
     * inside the allowlist.
     */
    private function tempPath(string $extension): string
    {
        $dir = AppPaths::instance()->storage('cache' . DIRECTORY_SEPARATOR . 'image-conversion');

        try {
            AppPaths::instance()->ensureDir($dir);
        } catch (\RuntimeException) {
            // Fall through — file_put_contents below will fail loudly.
        }

        return $dir . DIRECTORY_SEPARATOR . bin2hex(random_bytes(8)) . '.' . $extension;
    }

    private function hasConversionTool(): bool
    {
        $output = [];
        exec('which rsvg-convert 2>/dev/null', $output, $exitCode);

        return 0 === $exitCode;
    }

    private function convertToWebp(string $svg, string $outputFile, int $quality): bool
    {
        // Use Imagick for SVG → WebP conversion
        if (extension_loaded('imagick')) {
            return $this->convertViaImagick($svg, $outputFile, 'webp', $quality);
        }

        // Fallback: rsvg-convert to PNG, then convert to WebP
        $tempPng = $this->tempPath('png');
        $this->convertToPng($svg, $tempPng);

        $cmd = sprintf(
            'convert %s -quality %d %s 2>&1',
            escapeshellarg($tempPng),
            $quality,
            escapeshellarg($outputFile),
        );

        exec($cmd, $output, $exitCode);
        @unlink($tempPng);

        return 0 === $exitCode && is_file($outputFile);
    }

    private function convertViaImagick(string $svg, string $outputFile, string $format, int $quality): bool
    {
        $imagick = new \Imagick();
        $imagick->readImageBlob($svg);
        $imagick->setImageFormat($format);
        $imagick->setImageCompressionQuality($quality);
        $imagick->resizeImage(CoverGenerator::WIDTH, CoverGenerator::HEIGHT, \Imagick::FILTER_LANCZOS, 1);

        $result = $imagick->writeImage($outputFile);
        $imagick->destroy();

        return $result;
    }

    private function convertToPng(string $svg, string $outputFile): bool
    {
        // Use Imagick if available
        if (extension_loaded('imagick')) {
            return $this->convertViaImagick($svg, $outputFile, 'png', 100);
        }

        // Fallback: rsvg-convert
        $tempSvg = $this->tempPath('svg');
        file_put_contents($tempSvg, $svg);

        $cmd = sprintf(
            'rsvg-convert -w %d -h %d --format png %s -o %s 2>&1',
            CoverGenerator::WIDTH,
            CoverGenerator::HEIGHT,
            escapeshellarg($tempSvg),
            escapeshellarg($outputFile),
        );

        exec($cmd, $output, $exitCode);
        @unlink($tempSvg);

        return 0 === $exitCode && is_file($outputFile);
    }
}
