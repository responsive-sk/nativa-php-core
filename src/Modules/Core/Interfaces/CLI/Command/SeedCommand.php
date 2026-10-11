<?php

declare(strict_types = 1);

namespace Modules\Core\Interfaces\CLI\Command;

use Modules\Core\Interfaces\CLI\Support\DatabaseMigrator;
use Modules\Database\Infrastructure\Persistence\DatabaseConnectionManager;
use Modules\Security\Application\Services\PasswordHasher;
use Modules\Users\Domain\Permissions;
use Modules\Users\Infrastructure\Persistence\Repositories\RoleRepository;
use Modules\Users\Infrastructure\Persistence\Repositories\UserRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * bin/cms seed — create demo data (admin role + admin user + permissions).
 *
 * The admin role is granted all seeded permissions. The admin user is created
 * deterministically: password from --admin-password, else a documented
 * development default.
 */
#[AsCommand(name: 'seed', description: 'Seed demo data (admin role + admin user + permissions)')]
final class SeedCommand extends Command
{
    public const string DEFAULT_ADMIN_PASSWORD = 'admin1234';

    public const string DEFAULT_ADMIN_EMAIL = 'admin@example.test';

    /**
     * The seeded blog byline that doubles as a loginable author account.
     *
     * It is deliberately one of the two `author_email` values already in
     * `storage/blog` front matter: the account then OWNS the seeded articles,
     * so the browser check can prove the ownership rule against real content
     * instead of a fixture it had to create first.
     */
    public const string DEFAULT_AUTHOR_EMAIL = 'nativa.dev@example.test';

    public const string DEFAULT_AUTHOR_PASSWORD = 'author1234';

    protected function configure(): void
    {
        $this->addOption(
            'admin-password',
            null,
            InputOption::VALUE_REQUIRED,
            sprintf('Deterministic admin password (default: %s)', self::DEFAULT_ADMIN_PASSWORD),
        );
        $this->addOption(
            'admin-email',
            null,
            InputOption::VALUE_REQUIRED,
            sprintf('Admin email (default: %s)', self::DEFAULT_ADMIN_EMAIL),
        );
        $this->addOption(
            'author-email',
            null,
            InputOption::VALUE_REQUIRED,
            sprintf(
                'Which seeded blog byline becomes the loginable author (default: %s)',
                self::DEFAULT_AUTHOR_EMAIL,
            ),
        );
        $this->addOption(
            'author-password',
            null,
            InputOption::VALUE_REQUIRED,
            sprintf('Deterministic author password (default: %s)', self::DEFAULT_AUTHOR_PASSWORD),
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $pdo = DatabaseConnectionManager::instance()->connection('users');

        // Ensure schema exists before seeding (safe on fresh clones).
        (new DatabaseMigrator())->run($output);

        $hasher = new PasswordHasher();
        $users = new UserRepository($pdo);
        $roles = new RoleRepository($pdo);

        $adminPassword = $this->adminPassword($input);
        $adminEmail = $this->adminEmail($input);

        $roleIds = $this->seedRbac($output, $roles);
        $this->seedAdminUser($output, $users, $hasher, $adminEmail, $adminPassword, $roleIds['admin']);
        $authorEmail = $this->authorEmail($input);
        $this->seedAuthorUsers($output, $users, $hasher, $roleIds['author'], $authorEmail, $this->authorPassword($input));

        $output->writeln(sprintf(
            '<comment>  Admin credentials — email: %s | password: %s</comment>',
            $adminEmail,
            $adminPassword,
        ));
        $output->writeln(sprintf(
            '<comment>  Author credentials — email: %s | password: %s</comment>',
            $authorEmail,
            $this->authorPassword($input),
        ));
        $output->writeln('<info>Seed complete.</info>');

        return Command::SUCCESS;
    }

    private function adminPassword(InputInterface $input): string
    {
        $password = $input->getOption('admin-password');

        return is_string($password) && '' !== $password ? $password : self::DEFAULT_ADMIN_PASSWORD;
    }

    private function adminEmail(InputInterface $input): string
    {
        $email = $input->getOption('admin-email');

        return is_string($email) && '' !== $email ? strtolower($email) : self::DEFAULT_ADMIN_EMAIL;
    }

    /**
     * Which seeded byline becomes the loginable author account.
     *
     * The email is passed through as given (not lowercased like the admin
     * one): it has to match the `author_email` already written in the article
     * front matter byte for byte, because that is what ownership compares.
     */
    private function authorEmail(InputInterface $input): string
    {
        $email = $input->getOption('author-email');

        return is_string($email) && '' !== $email ? $email : self::DEFAULT_AUTHOR_EMAIL;
    }

    private function authorPassword(InputInterface $input): string
    {
        $password = $input->getOption('author-password');

        return is_string($password) && '' !== $password ? $password : self::DEFAULT_AUTHOR_PASSWORD;
    }

    /**
     * Seed RBAC: permissions, the all-powerful admin role, the author role.
     *
     * @return array{admin: string, author: string} role ids by name
     */
    private function seedRbac(OutputInterface $output, RoleRepository $roles): array
    {
        // Names come from Permissions, and the admin role is handed every one
        // of them. The gate (`AdminRoutePolicy`) reads the same constants, so a
        // rename cannot leave the seed granting a name nothing checks for.
        $permissions = [
            [Permissions::USERS,    'Manage users',                     'users'],
            [Permissions::ROLES,    'Manage roles and permissions',     'roles'],
            [Permissions::SETTINGS, 'Manage site settings',             'settings'],
            [Permissions::CONTENT,  'Manage content',                   'content'],
            [Permissions::MARK_ACCESS, 'Access the admin panel',         'mark'],
        ];

        /** @var array<string, string> $permissionIds name => id, keyed so the
         *  role grants below can ask BY NAME — a missing key then means the
         *  permission failed to seed, which must be loud rather than a silently
         *  under-privileged role. */
        $permissionIds = [];
        foreach ($permissions as [$name, $desc, $group]) {
            $permissionIds[$name] = $roles->ensurePermission(bin2hex(random_bytes(16)), $name, $desc, $group);
        }

        $adminRoleId = $roles->ensureRole(
            bin2hex(random_bytes(16)),
            'admin',
            'Administrator with full access',
        );
        foreach (Permissions::all() as $name) {
            if (!isset($permissionIds[$name])) {
                throw new \RuntimeException(sprintf('Cannot grant "%s" to the admin role: it was not seeded.', $name));
            }

            $roles->assignPermission($adminRoleId, $permissionIds[$name]);
        }

        $authorRoleId = $this->seedAuthorRole($output, $roles, $permissionIds);

        $output->writeln(sprintf(
            '<info>  ✓ RBAC: role "admin" seeded with all %d permissions</info>',
            count($permissionIds),
        ));

        return ['admin' => $adminRoleId, 'author' => $authorRoleId];
    }

    /**
     * The `author` role: the admin panel plus content, and nothing that can
     * reshape access.
     *
     * Without it the permission graph is unreachable — `AdminRoutePolicy`
     * would work and there would be no shipped way to be anything but an
     * administrator, so the gate would be correct and still useless.
     *
     * @param array<string, string> $permissionIds
     *
     * @return string the author role id
     */
    private function seedAuthorRole(OutputInterface $output, RoleRepository $roles, array $permissionIds): string
    {
        $authorRoleId = $roles->ensureRole(
            bin2hex(random_bytes(16)),
            'author',
            'Writes articles and pages; no access management',
        );

        foreach ([Permissions::MARK_ACCESS, Permissions::CONTENT] as $name) {
            if (!isset($permissionIds[$name])) {
                throw new \RuntimeException(sprintf('Cannot grant "%s" to the author role: it was not seeded.', $name));
            }

            $roles->assignPermission($authorRoleId, $permissionIds[$name]);
        }

        $output->writeln('<info>  ✓ RBAC: role "author" seeded (mark.access, content.manage)</info>');

        return $authorRoleId;
    }

    /**
     * Create the admin user idempotently (by email).
     */
    private function seedAdminUser(
        OutputInterface $output,
        UserRepository $users,
        PasswordHasher $hasher,
        string $adminEmail,
        string $adminPassword,
        string $adminRoleId,
    ): void {
        $adminUser = $users->findByEmail($adminEmail);

        if (null === $adminUser) {
            $adminUser = $users->insert([
                'id'       => bin2hex(random_bytes(16)),
                'name'     => 'Administrator',
                'email'    => $adminEmail,
                'password' => $hasher->hash($adminPassword),
            ]);
            $users->assignRole($adminUser->id(), $adminRoleId);
            $output->writeln(sprintf('<info>  ✓ Created admin user: %s</info>', $adminEmail));
        } else {
            $users->assignRole($adminUser->id(), $adminRoleId);
            $output->writeln(sprintf('<info>  ✓ Admin user exists (role ensured): %s</info>', $adminEmail));
        }
    }

    /**
     * Seed the blog author profiles referenced by storage/blog front matter
     * (author_email keys) so the public author pages and avatar links resolve.
     * Idempotent by email — safe on re-runs.
     *
     * The byline named by `$loginEmail` gets the `author` role AND a
     * deterministic password, so it is a real login account: the browser
     * check signs in as it and edits the articles it already owns. Every
     * other byline stays a display profile with a random password, so it
     * cannot be used to authenticate.
     */
    private function seedAuthorUsers(
        OutputInterface $output,
        UserRepository $users,
        PasswordHasher $hasher,
        string $authorRoleId,
        string $loginEmail,
        string $loginPassword,
    ): void {
        foreach (self::authorProfiles() as $email => $name) {
            $isLogin = $email === $loginEmail;
            $existing = $users->findByEmail($email);

            if (null !== $existing) {
                // The password is re-set on every seed, unlike the admin one:
                // an earlier seed may have created this account with a random
                // password, and a check that can no longer log in fails for a
                // reason that has nothing to do with what it is checking.
                if ($isLogin) {
                    $users->updatePassword($existing->id(), $hasher->hash($loginPassword));
                    $users->assignRole($existing->id(), $authorRoleId);
                }
                $output->writeln(sprintf('<info>  ✓ Author user exists: %s</info>', $email));

                continue;
            }

            $user = $users->insert([
                'id'       => bin2hex(random_bytes(16)),
                'name'     => $name,
                'email'    => $email,
                // Display-only bylines get a random password: they are profiles
                // referenced by front matter, not accounts anyone signs in as.
                'password' => $hasher->hash($isLogin ? $loginPassword : bin2hex(random_bytes(24))),
            ]);

            if ($isLogin) {
                $users->assignRole($user->id(), $authorRoleId);
            }
            $output->writeln(sprintf('<info>  ✓ Created author user: %s (%s)</info>', $name, $email));
        }
    }

    /**
     * The bylines the shipped articles are attributed to.
     *
     * @return array<string, string> email => display name
     */
    private static function authorProfiles(): array
    {
        return [
            self::DEFAULT_AUTHOR_EMAIL => 'Nativa Dev',
            'evan.mcdan@example.test'  => 'Evan McDan',
        ];
    }
}
