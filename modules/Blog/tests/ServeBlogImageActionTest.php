<?php

declare(strict_types = 1);

namespace Modules\Blog\Tests;

use Modules\Blog\Interfaces\HTTP\Actions\ServeBlogImageAction;
use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Infrastructure\Paths\AppPaths;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * ServeBlogImageActionTest - /storage/blog-images/{path*} serves page images.
 *
 * The route began as SVG-only because its first producer was the generated
 * article cover. Page editors also place photographs under
 * `blog-images/pages/`, and every one of those was a file on disk whose URL
 * answered 404. The gallery had been broken since the images were seeded.
 *
 * The whitelist still matters: an extension only identifies the expected
 * MIME, and the bytes have to match it. A `.jpg` carrying HTML is a 404, not a
 * file download with an image name.
 */
final class ServeBlogImageActionTest extends TestCase
{
    /** @var list<string> files this test created, removed in tearDown */
    private array $created = [];

    /** @var list<string> directories this test created, removed in tearDown */
    private array $createdDirs = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (!is_dir(APP_BASE_PATH . '/src/Modules/Blog')) {
            $this->markTestSkipped('Blog is not installed - the blog image route ships with this module.');
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->created as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        foreach (array_reverse($this->createdDirs) as $dir) {
            if (is_dir($dir)) {
                @rmdir($dir);
            }
        }

        $this->created = [];
        $this->createdDirs = [];

        parent::tearDown();
    }

    #[Test]
    public function aJpegPageImageIsServedWithItsDetectedType(): void
    {
        $bytes = base64_decode(
            '/9j/4AAQSkZJRgABAQEAYABgAAD//gA+Q1JFQVRPUjogZ2QtanBlZyB2MS4wICh1c2luZyBJSkcgSlBFRyB2ODApLCBkZWZhdWx0IHF1YWxpdHkK/9sAQwAIBgYHBgUIBwcHCQkICgwUDQwLCwwZEhMPFB0aHx4dGhwcICQuJyAiLCMcHCg3KSwwMTQ0NB8nOT04MjwuMzQy/9sAQwEJCQkMCwwYDQ0YMiEcITIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIy/8AAEQgAAQABAwEiAAIRAQMRAf/EAB8AAAEFAQEBAQEBAAAAAAAAAAABAgMEBQYHCAkKC//EALUQAAIBAwMCBAMFBQQEAAABfQECAwAEEQUSITFBBhNRYQcicRQygZGhCCNCscEVUtHwJDNicoIJChYXGBkaJSYnKCkqNDU2Nzg5OkNERUZHSElKU1RVVldYWVpjZGVmZ2hpanN0dXZ3eHl6g4SFhoeIiYqSk5SVlpeYmZqio6Slpqeoqaqys7S1tre4ubrCw8TFxsfIycrS09TV1tfY2drh4uPk5ebn6Onq8fLz9PX29/j5+v/EAB8BAAMBAQEBAQEBAQEAAAAAAAABAgMEBQYHCAkKC//EALURAAIBAgQEAwQHBQQEAAECdwABAgMRBAUhMQYSQVEHYXETIjKBCBRCkaGxwQkjM1LwFWJy0QoWJDThJfEXGBkaJicoKSo1Njc4OTpDREVGR0hJSlNUVVZXWFlaY2RlZmdoaWpzdHV2d3h5eoKDhIWGh4iJipKTlJWWl5iZmqKjpKWmp6ipqrKztLW2t7i5usLDxMXGx8jJytLT1NXW19jZ2uLj5OXm5+jp6vLz9PX29/j5+v/aAAwDAQACEQMRAD8A+f6KKKAP/9k=',
            true,
        );
        self::assertIsString($bytes);

        $relative = $this->write('jpg', $bytes);

        $response = $this->handle($relative);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('image/jpeg', $response->getHeader('Content-Type'));
        $this->assertSame('public, max-age=31536000, immutable', $response->getHeader('Cache-Control'));
        $this->assertSame($bytes, $response->getContent());
    }

    #[Test]
    public function aRasterFileMustMatchTheImageItsExtensionPromises(): void
    {
        $relative = $this->write('jpg', '<html><script>alert(1)</script></html>');

        $this->assertSame(404, $this->handle($relative)->getStatusCode());
    }

    #[Test]
    public function aFileWithAnUnsupportedExtensionIsNotFound(): void
    {
        $relative = $this->write('txt', 'not an image');

        $this->assertSame(404, $this->handle($relative)->getStatusCode());
    }

    private function handle(string $relative): \Modules\Core\Infrastructure\Http\Response
    {
        $request = Request::create('GET', '/storage/blog-images/' . $relative);
        $request->setRouteParams(['path' => $relative]);

        return (new ServeBlogImageAction())->handle($request);
    }

    private function write(string $extension, string $bytes): string
    {
        $relative = 'tests/serve-' . bin2hex(random_bytes(8)) . '.' . $extension;
        $full = AppPaths::instance()->storage('blog-images' . DIRECTORY_SEPARATOR . $relative);
        $dir = dirname($full);

        if (!is_dir($dir)) {
            mkdir($dir, 0o775, true);
            $this->createdDirs[] = $dir;
        }

        file_put_contents($full, $bytes);
        $this->created[] = $full;

        return $relative;
    }
}
