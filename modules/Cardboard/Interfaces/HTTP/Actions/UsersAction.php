<?php

declare(strict_types = 1);

namespace Modules\Cardboard\Interfaces\HTTP\Actions;

use Modules\Audit\Interfaces\HTTP\AuditServices;
use Modules\Blog\Application\Services\AuthorAvatar;
use Modules\Cardboard\Interfaces\HTTP\AdminLayout;
use Modules\Cardboard\Interfaces\HTTP\CardboardServices;
use Modules\Core\Application\Services\CacheInvalidator;
use Modules\Core\Application\Services\View\ViewRenderer;
use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Infrastructure\Http\Response;
use Modules\Core\Infrastructure\Storage\LocalStorageProvider;
use Modules\Core\Interfaces\HTTP\Attributes\Route;
use Modules\Media\Interfaces\HTTP\MediaUploadPayload;
use Modules\Outbox\Interfaces\HTTP\SecurityEventServices;
use Modules\Security\Application\Services\PasswordHasher;
use Modules\Users\Domain\Entities\User;
use Modules\Users\Infrastructure\Persistence\Repositories\UserRepository;
use Modules\Users\Interfaces\HTTP\UsersTableRenderer;

/**
 * Users CRUD — task 9.2.
 *
 * Routes:
 * - GET  /mark/users             list
 * - GET  /mark/users/create      create form
 * - POST /mark/users/create      create submit
 * - GET  /mark/users/{id}/edit   edit form
 * - POST /mark/users/{id}/edit   edit submit
 * - POST /mark/users/{id}/delete delete
 *
 * Avatar upload (phase 2): both forms accept an image file stored via
 * LocalStorageProvider; the relative path persists into users.avatar and the
 * edit form can also remove it.
 */
#[Route(path: '/mark/users', method: 'GET', name: 'mark.users')]
#[Route(path: '/mark/users/create', method: 'GET', name: 'mark.users.create', handlerMethod: 'createForm')]
#[Route(path: '/mark/users/create', method: 'POST', name: 'mark.users.create.submit', handlerMethod: 'createSubmit')]
#[Route(path: '/mark/users/{id}/edit', method: 'GET', name: 'mark.users.edit', handlerMethod: 'editForm')]
#[Route(path: '/mark/users/{id}/edit', method: 'POST', name: 'mark.users.edit.submit', handlerMethod: 'editSubmit')]
#[Route(path: '/mark/users/{id}/delete', method: 'POST|DELETE', name: 'mark.users.delete', handlerMethod: 'delete')]
final class UsersAction
{
    public function handle(Request $request): Response
    {
        $search = is_string($request->query('q')) ? $request->query('q') : '';
        $users = CardboardServices::users()->all();

        // Filter by search query.
        if ('' !== $search) {
            $users = $this->filterUsers($users, $search);
        }

        $escapedSearch = AdminLayout::e($search);
        $searchInput = '<div class="m-filter-bar">'
            . '<input type="search" name="q" value="' . $escapedSearch . '" class="m-input--filter"'
            . ' hx-get="/mark/users"'
            . ' hx-trigger="keyup changed delay:300ms, search"'
            . ' hx-target="#users-content"'
            . ' hx-swap="outerHTML"'
            . ' hx-indicator="#users-skeleton"'
            . ' placeholder="Search users...">'
            . '</div>';

        $grid = UsersTableRenderer::render($users);

        // HTMX: return partial HTML.
        if (AdminLayout::isHtmx($request)) {
            return Response::html($grid);
        }

        $toast = '';
        $toastMessage = is_string($request->query('toast')) ? $request->query('toast') : '';
        if ('' !== $toastMessage) {
            $toast = AdminLayout::toast(urldecode($toastMessage));
        }

        $content = $searchInput . $grid . $toast;

        return Response::html(AdminLayout::render(
            'Users',
            $content,
            'users',
            [['label' => 'New user', 'href' => '/mark/users/create']],
        ));
    }

    /**
     * Filter users by name or email.
     *
     * @param list<User> $users
     *
     * @return list<User>
     */
    private function filterUsers(array $users, string $search): array
    {
        $query = strtolower($search);

        return array_values(array_filter(
            $users,
            static fn (User $user): bool => str_contains(strtolower($user->name()), $query)
                || str_contains(strtolower($user->email()), $query),
        ));
    }

    public function createForm(Request $request): Response
    {
        return Response::html($this->form(
            'New user',
            '/mark/users/create',
            'Create user',
            [],
            [],
            [],
            null,
            $request,
        ));
    }

    public function createSubmit(Request $request): Response
    {
        $name = is_string($request->body('name')) ? $request->body('name') : '';
        $email = is_string($request->body('email')) ? $request->body('email') : '';
        $password = is_string($request->body('password')) ? $request->body('password') : '';
        $roleId = is_string($request->body('role')) ? $request->body('role') : '';

        $errors = $this->createErrors($name, $email, $password, $roleId);
        if ([] !== $errors) {
            return $this->createFormResponse($errors, $name, $email, $request);
        }

        $users = CardboardServices::users();

        if ($users->emailExists($email)) {
            return $this->createFormResponse(['A user with this email already exists.'], $name, $email, $request);
        }

        // Avatar upload — attempted only after field validation and email
        // uniqueness passed; on error nothing is stored (path is null).
        $upload = $this->avatarUpload();
        if ('' !== $upload['error']) {
            return $this->createFormResponse([$upload['error']], $name, $email, $request);
        }

        $hasher = new PasswordHasher();
        $user = $users->insert([
            'id'       => bin2hex(random_bytes(16)),
            'name'     => $name,
            'email'    => $email,
            'password' => $hasher->hash($password),
            'avatar'   => $upload['path'],
        ]);

        if ('' !== $roleId) {
            $users->assignRole($user->id(), $roleId);
        }

        CacheInvalidator::flush();
        AuditServices::log('users.create', 'user:' . $user->id(), 'Created user ' . $email, $request->getClientIp());
        SecurityEventServices::userChanged($user->id(), SecurityEventServices::OP_CREATE, $request->getClientIp());

        if (AdminLayout::isHtmx($request)) {
            $response = Response::html('');
            $response->setHeader('HX-Redirect', '/mark/users?toast=User+created+successfully');

            return $response;
        }

        return Response::redirect('/mark/users');
    }

    public function editForm(Request $request): Response
    {
        $id = $request->routeParam('id');
        $user = null === $id ? null : CardboardServices::users()->findById($id);

        if (null === $user) {
            return Response::redirect('/mark/users');
        }

        return Response::html($this->form(
            'Edit user — ' . $user->name(),
            '/mark/users/' . AdminLayout::e($user->id()) . '/edit',
            'Save changes',
            [],
            ['name' => $user->name(), 'email' => $user->email()],
            CardboardServices::roles()->roleIdsForUser($user->id()),
            $user,
            $request,
        ));
    }

    public function editSubmit(Request $request): Response
    {
        $id = $request->routeParam('id');
        $users = CardboardServices::users();
        $user = null === $id ? null : $users->findById($id);

        if (null === $user) {
            return Response::redirect('/mark/users');
        }

        $name = is_string($request->body('name')) ? $request->body('name') : '';
        $email = is_string($request->body('email')) ? $request->body('email') : '';
        $password = is_string($request->body('password')) ? $request->body('password') : '';
        $roleId = is_string($request->body('role')) ? $request->body('role') : '';
        $isActive = in_array($request->body('is_active'), ['on', '1', 'true', true], true);

        $errors = $this->editErrors($user, $name, $email, $roleId, $users);
        if ([] !== $errors) {
            return $this->editFormResponse($user, $errors, $name, $email, $request);
        }

        // Avatar change (upload, removal, or none) — a storage error keeps
        // the DB untouched (the orphaned file is deleted below).
        $state = $this->editAvatarState($errors, $request);
        if ([] !== $state['errors']) {
            if (null !== $state['newAvatar']) {
                (new LocalStorageProvider())->delete($state['newAvatar']);
            }

            return $this->editFormResponse($user, $state['errors'], $name, $email, $request);
        }

        $users->update($user->id(), $name, $email, $isActive);
        $this->applyAvatarChange($user, $state['newAvatar'], $state['removeAvatar']);
        $this->syncRole($user->id(), $roleId);

        // Optional password change
        if ('' !== $password) {
            $users->updatePassword($user->id(), (new PasswordHasher())->hash($password));
        }

        CacheInvalidator::flush();
        AuditServices::log('users.update', 'user:' . $user->id(), 'Updated user ' . $email, $request->getClientIp());
        SecurityEventServices::userChanged($user->id(), SecurityEventServices::OP_UPDATE, $request->getClientIp());

        if (AdminLayout::isHtmx($request)) {
            $response = Response::html('');
            $response->setHeader('HX-Redirect', '/mark/users?toast=User+saved+successfully');

            return $response;
        }

        return Response::redirect('/mark/users');
    }

    public function delete(Request $request): Response
    {
        $id = $request->routeParam('id');
        $users = CardboardServices::users();

        if (null !== $id) {
            $user = $users->findById($id);

            // Never allow deleting yourself.
            if (null !== $user && $user->id() !== CardboardServices::auth()->currentUserId()) {
                $users->delete($id);
                CacheInvalidator::flush();
                AuditServices::log('users.delete', 'user:' . $user->id(), 'Deleted user ' . $user->email(), $request->getClientIp());
                SecurityEventServices::userChanged($user->id(), SecurityEventServices::OP_DELETE, $request->getClientIp());
            }
        }

        if (AdminLayout::isHtmx($request)) {
            return Response::html('');
        }

        return Response::redirect('/mark/users');
    }

    /**
     * Render the user form.
     *
     * @param list<string>          $errors
     * @param array<string, string> $values
     * @param list<string>          $selectedRoleIds
     */
    private function form(
        string $title,
        string $action,
        string $submitLabel,
        array $errors,
        array $values = [],
        array $selectedRoleIds = [],
        ?User $user = null,
        ?Request $request = null,
    ): string {
        $errorHtml = AdminLayout::messages($errors, 'err');
        $roles = CardboardServices::roles()->allRoles();
        $roleOptions = '';

        foreach ($roles as $role) {
            $roleId = AdminLayout::e((string) $role['id']);
            $roleName = AdminLayout::e((string) $role['name']);
            $selected = in_array((string) $role['id'], $selectedRoleIds, true) ? ' selected' : '';
            $roleOptions .= "<option value=\"{$roleId}\"{$selected}>{$roleName}</option>";
        }

        $name = AdminLayout::e($values['name'] ?? '');
        $email = AdminLayout::e($values['email'] ?? '');
        $activeChecked = (null === $user || $user->isActive()) ? ' checked' : '';
        $csrf = AdminLayout::csrfField();
        $passwordHint = null !== $user
            ? '<p class="m-muted m-hint">Leave blank to keep the current password.</p>'
            : '';

        // Avatar preview + removal checkbox (edit only). Mirrors the public
        // rendering: a stored file that no longer exists falls back to the
        // placeholder instead of a broken image.
        $avatar = null !== $user ? $user->avatar() : null;
        $avatarPreview = null !== $avatar
            ? '<p class="m-hint">Current avatar:</p>'
                . $this->avatarPreviewImg($avatar, $user?->name() ?? '')
            : '';
        $removeAvatar = null !== $avatar
            ? '<div class="m-check"><input type="checkbox" id="remove_avatar" name="remove_avatar" value="1"><label for="remove_avatar">Remove avatar</label></div>'
            : '';

        $htmx = null !== $request && AdminLayout::isHtmx($request);
        $hxPost = $htmx ? ' hx-post="' . AdminLayout::e($action) . '" hx-indicator="#user-spinner"' : '';
        $spinner = '<span class="htmx-indicator" id="user-spinner">⏳</span> ';

        $content = ViewRenderer::render('Modules/Cardboard/Interfaces/HTTP/templates/users-form.phtml', ['action' => $action, 'activeChecked' => $activeChecked, 'avatarPreview' => $avatarPreview, 'csrf' => $csrf, 'email' => $email, 'errorHtml' => $errorHtml, 'hxPost' => $hxPost, 'name' => $name, 'passwordHint' => $passwordHint, 'removeAvatar' => $removeAvatar, 'roleOptions' => $roleOptions, 'spinner' => $spinner, 'submitLabel' => $submitLabel])
            . ViewRenderer::render('Modules/Cardboard/Interfaces/HTTP/templates/avatar-guard.phtml', []);

        return AdminLayout::render($title, $content, 'users');
    }

    /**
     * @return list<string>
     */
    private function createErrors(string $name, string $email, string $password, string $roleId): array
    {
        $errors = $this->validate($name, $email, $password, true);

        // F-7: reject unknown role ids at submit time — assignRole() would
        // otherwise violate the user_roles foreign key after the insert.
        if ('' !== $roleId && !CardboardServices::roles()->roleExists($roleId)) {
            $errors[] = 'The selected role no longer exists.';
        }

        return $errors;
    }

    /**
     * @return list<string>
     */
    private function editErrors(User $user, string $name, string $email, string $roleId, UserRepository $users): array
    {
        $errors = $this->validate($name, $email, '', false);

        // Email uniqueness (ignoring self)
        if ([] === $errors && strtolower($email) !== strtolower($user->email())) {
            if ($users->emailExists($email)) {
                $errors[] = 'A user with this email already exists.';
            }
        }

        // F-7: reject unknown role ids at submit time — the role sync below
        // would otherwise violate the user_roles foreign key mid-way.
        if ('' !== $roleId && !CardboardServices::roles()->roleExists($roleId)) {
            $errors[] = 'The selected role no longer exists.';
        }

        return $errors;
    }

    /**
     * Re-render the create form with errors.
     *
     * @param list<string> $errors
     */
    private function createFormResponse(array $errors, string $name, string $email, ?Request $request = null): Response
    {
        return Response::html($this->form(
            'New user',
            '/mark/users/create',
            'Create user',
            $errors,
            ['name' => $name, 'email' => $email],
            [],
            null,
            $request,
        ));
    }

    /**
     * Re-render the edit form with errors.
     *
     * @param list<string> $errors
     */
    private function editFormResponse(User $user, array $errors, string $name, string $email, ?Request $request = null): Response
    {
        return Response::html($this->form(
            'Edit user — ' . $user->name(),
            '/mark/users/' . AdminLayout::e($user->id()) . '/edit',
            'Save changes',
            $errors,
            ['name' => $name, 'email' => $email],
            CardboardServices::roles()->roleIdsForUser($user->id()),
            $user,
            $request,
        ));
    }

    /**
     * Admin avatar preview — the stored file when it exists, else the same
     * initial-letter placeholder the public pages render (no broken image).
     */
    private function avatarPreviewImg(string $avatar, string $name): string
    {
        $storage = new LocalStorageProvider();
        $src = $storage->exists($avatar)
            ? $storage->url($avatar)
            : (new AuthorAvatar($storage))->placeholderDataUri($name);

        return '<img src="' . AdminLayout::e($src) . '" alt="" width="48" height="48" class="m-avatar">';
    }

    /**
     * Store the submitted avatar file, if any.
     *
     * @return array{path: ?string, error: string}
     */
    private function avatarUpload(): array
    {
        // MediaUploadPayload::filesFor() prefers the real multipart entry and
        // falls back to a synthetic entry built from the base64 "avatar_b64"
        // POST field (the form's guard script encodes the picked file) — on
        // shared hosting the multipart path can be unusable (broken
        // upload_tmp_dir / upload_max_filesize) while the urlencoded path
        // works.
        $file = MediaUploadPayload::filesFor('avatar')['avatar'] ?? null;

        if (!is_array($file) || UPLOAD_ERR_NO_FILE === ($file['error'] ?? UPLOAD_ERR_NO_FILE)) {
            return ['path' => null, 'error' => ''];
        }

        try {
            $result = (new LocalStorageProvider())->store($file);
        } catch (\RuntimeException $e) {
            return ['path' => null, 'error' => $e->getMessage()];
        }

        // The provider whitelist also admits pdf/txt/md — avatars are images.
        if (!str_starts_with($result['mime_type'], 'image/')) {
            (new LocalStorageProvider())->delete($result['path']);

            return ['path' => null, 'error' => 'Avatar must be an image file.'];
        }

        return ['path' => $result['path'], 'error' => ''];
    }

    /**
     * Avatar change state for the edit submit: upload error appended to the
     * error list (nothing stored on error), resolved new path, removal flag.
     *
     * @param list<string> $errors
     *
     * @return array{errors: list<string>, newAvatar: ?string, removeAvatar: bool}
     */
    private function editAvatarState(array $errors, Request $request): array
    {
        $removeAvatar = in_array($request->body('remove_avatar'), ['on', '1', 'true', true], true);
        $upload = $this->avatarUpload();
        $newAvatar = $upload['path'];

        if ('' !== $upload['error']) {
            $errors[] = $upload['error'];
        }

        return ['errors' => $errors, 'newAvatar' => $newAvatar, 'removeAvatar' => $removeAvatar];
    }

    /**
     * Apply an avatar change from the edit form: a new upload, a removal, or
     * nothing. Old files are deleted best-effort after the DB write.
     */
    private function applyAvatarChange(User $user, ?string $newAvatar, bool $removeAvatar): void
    {
        if (null !== $newAvatar) {
            $old = $user->avatar();
            CardboardServices::users()->updateAvatar($user->id(), $newAvatar);

            if (null !== $old && $old !== $newAvatar) {
                (new LocalStorageProvider())->delete($old);
            }

            return;
        }

        if ($removeAvatar && null !== $user->avatar()) {
            CardboardServices::users()->updateAvatar($user->id(), null);
            (new LocalStorageProvider())->delete($user->avatar());
        }
    }

    /**
     * Replace the user's role with the chosen one (no-op when none chosen).
     */
    private function syncRole(string $userId, string $roleId): void
    {
        if ('' === $roleId) {
            return;
        }

        $roles = CardboardServices::roles();
        $users = CardboardServices::users();

        foreach ($roles->roleIdsForUser($userId) as $existingRoleId) {
            $users->removeRole($userId, $existingRoleId);
        }
        $users->assignRole($userId, $roleId);
    }

    /**
     * @return list<string>
     */
    private function validate(string $name, string $email, string $password, bool $passwordRequired): array
    {
        $errors = [];

        if ('' === $name) {
            $errors[] = 'Name is required.';
        }

        if ('' === $email || false === filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'A valid email is required.';
        }

        if ($passwordRequired && '' === $password) {
            $errors[] = 'Password is required.';
        }

        if ('' !== $password && strlen($password) < 8) {
            $errors[] = 'Password must be at least 8 characters.';
        }

        return $errors;
    }
}
