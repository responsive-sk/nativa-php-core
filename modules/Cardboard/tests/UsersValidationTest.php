<?php

declare(strict_types = 1);

namespace Modules\Cardboard\Tests;

use Modules\Cardboard\Interfaces\HTTP\Actions\UsersAction;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * UsersValidationTest — the admin user form's legacy error strings survive
 * the Validation-module refactor, in the same order.
 *
 * `UsersAction::validate()` is private and the HTTP stack around it is
 * heavy, so the test reaches it via reflection with a constructor-less
 * instance (the router instantiates actions without arguments, and the
 * method only touches its own parameters).
 *
 * Like every module test it drives the INSTALLED copy and skips when the
 * module (or its Validation dependency) is not installed.
 */
final class UsersValidationTest extends TestCase
{
    /** @var callable(string, string, string, bool): list<string> */
    private $validate;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Cardboard', 'Validation'] as $module) {
            if (!is_dir(APP_BASE_PATH . '/src/Modules/' . $module)) {
                $this->markTestSkipped($module . ' is not installed.');
            }
        }

        $method = new ReflectionMethod(UsersAction::class, 'validate');
        $action = (new \ReflectionClass(UsersAction::class))->newInstanceWithoutConstructor();

        $this->validate = static fn (string $name, string $email, string $password, bool $required): array =>
            /** @var list<string> */
            $method->invoke($action, $name, $email, $password, $required);
    }

    #[Test]
    public function validCreatePasses(): void
    {
        $this->assertSame([], ($this->validate)('Eva', 'eva@example.test', 'long-enough', true));
    }

    #[Test]
    public function invalidCreateReportsLegacyMessagesInOrder(): void
    {
        $this->assertSame(
            [
                'Name is required.',
                'A valid email is required.',
                'Password is required.',
            ],
            ($this->validate)('', 'not-an-email', '', true),
        );
    }

    #[Test]
    public function shortPasswordReportsLegacyMessage(): void
    {
        $this->assertSame(
            ['Password must be at least 8 characters.'],
            ($this->validate)('Eva', 'eva@example.test', 'short', true),
        );
    }

    #[Test]
    public function editModeLeavesBlankPasswordAlone(): void
    {
        $this->assertSame([], ($this->validate)('Eva', 'eva@example.test', '', false));
    }

    #[Test]
    public function blankNamesAreRejected(): void
    {
        // Tightening vs the old `=== ''` check (documented on the method):
        // a whitespace-only name is not a name.
        $this->assertSame(['Name is required.'], ($this->validate)('   ', 'eva@example.test', 'long-enough', true));
    }
}
