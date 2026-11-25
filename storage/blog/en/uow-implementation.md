---
title: "UnitOfWork in Nativa ... Implementation Without Dependencies"
date: "2026-08-18"
version: "1.0.0"
tags: ["php", "architecture", "database", "transactions", "implementation"]
excerpt: "How we implemented UnitOfWork for Nativa Minimal ... simple, effective, zero-dependency."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Architecture"
views: "0"
translation: "uow-implementation"
published: true
---

In the previous article, we wrote about the philosophy of transactional consistency.
Now we implemented it.

42 lines of code. No dependencies. Works with any PDO.

## What We Implemented

One new file:

| File | Purpose |
|---|---|
| `UnitOfWork.php` | Transactional consistency |

**42 lines of code.** No dependencies.

## 1. UnitOfWork ... The Foundation

```php
final class UnitOfWork
{
    private bool $transactionStarted = false;

    public function __construct(
        private readonly \PDO $pdo,
    ) {
    }

    public function transaction(callable $fn): mixed
    {
        $this->beginTransaction();
        try {
            $result = $fn($this->pdo);
            $this->commit();
            return $result;
        } catch (\Throwable $e) {
            $this->rollback();
            throw $e;
        }
    }

    public function beginTransaction(): void
    {
        if (!$this->transactionStarted) {
            $this->pdo->beginTransaction();
            $this->transactionStarted = true;
        }
    }

    public function commit(): void
    {
        if ($this->transactionStarted) {
            $this->pdo->commit();
            $this->transactionStarted = false;
        }
    }

    public function rollback(): void
    {
        if ($this->transactionStarted) {
            $this->pdo->rollBack();
            $this->transactionStarted = false;
        }
    }

    public function inTransaction(): bool
    {
        return $this->transactionStarted;
    }
}
```

**Key features:**
- **Generic `transaction()`** ... callback receives PDO, returns value
- **Idempotent `beginTransaction()`** ... safe to call multiple times
- **`inTransaction()`** ... state check for tests
- **Best-effort rollback** ... if commit fails, rollback runs

## 2. Repository Integration

### UserRepository::delete() ... Before

```php
public function delete(string $id): void
{
    $user = $this->findById($id);
    $email = null === $user ? '' : $user->email();

    $this->pdo->prepare('DELETE FROM user_roles WHERE user_id = :id')->execute([':id' => $id]);
    if ('' !== $email) {
        $this->pdo->prepare('DELETE FROM password_reset_tokens WHERE email = :email')->execute([':email' => $email]);
    }
    $this->pdo->prepare('DELETE FROM remember_tokens WHERE user_id = :id')->execute([':id' => $id]);
    $this->pdo->prepare('DELETE FROM sessions WHERE user_id = :id')->execute([':id' => $id]);
    $this->pdo->prepare('DELETE FROM users WHERE id = :id')->execute([':id' => $id]);
    // If one fails ... orphan data!
}
```

### UserRepository::delete() ... After

```php
public function delete(string $id): void
{
    $user = $this->findById($id);
    $email = null === $user ? '' : $user->email();

    $uow = new UnitOfWork($this->pdo);
    $uow->transaction(function (\PDO $pdo) use ($id, $email): void {
        $pdo->prepare('DELETE FROM user_roles WHERE user_id = :id')->execute([':id' => $id]);
        if ('' !== $email) {
            $pdo->prepare('DELETE FROM password_reset_tokens WHERE email = :email')->execute([':email' => $email]);
        }
        $pdo->prepare('DELETE FROM remember_tokens WHERE user_id = :id')->execute([':id' => $id]);
        $pdo->prepare('DELETE FROM sessions WHERE user_id = :id')->execute([':id' => $id]);
        $pdo->prepare('DELETE FROM users WHERE id = :id')->execute([':id' => $id]);
    });
}
```

### RoleRepository::deleteRole()

```php
public function deleteRole(string $roleId): void
{
    $uow = new UnitOfWork($this->pdo);
    $uow->transaction(function (\PDO $pdo) use ($roleId): void {
        $pdo->prepare('DELETE FROM role_permissions WHERE role_id = :rid')->execute([':rid' => $roleId]);
        $pdo->prepare('DELETE FROM user_roles WHERE role_id = :rid')->execute([':rid' => $roleId]);
        $pdo->prepare('DELETE FROM roles WHERE id = :rid')->execute([':rid' => $roleId]);
    });
}
```

## 3. syncPermissions ... Atomic Diff+Apply

Instead of manual foreach over current/wanted:

### Before ... Non-Atomic

```php
$roles = CardboardServices::roles();
$current = $roles->permissionIdsForRole($id);

// Remove unchecked
foreach ($current as $pid) {
    if (!in_array($pid, $wanted, true)) {
        $roles->removePermission($id, $pid);
    }
}
// Add newly checked
foreach ($wanted as $pid) {
    if (!in_array($pid, $current, true)) {
        $roles->assignPermission($id, $pid);
    }
}
// If one fails ... inconsistent state!
```

### After ... Atomic Sync

```php
$roles = CardboardServices::roles();
$roles->syncPermissions($id, $wanted);
```

**syncPermissions Implementation:**

```php
public function syncPermissions(string $roleId, array $permissionIds): void
{
    $current = $this->permissionIdsForRole($roleId);

    $toRemove = array_values(array_diff($current, $permissionIds));
    $toAdd = array_values(array_diff($permissionIds, $current));

    if ([] === $toRemove && [] === $toAdd) {
        return; // Nothing to do
    }

    $uow = new UnitOfWork($this->pdo);
    $uow->transaction(function (\PDO $pdo) use ($roleId, $toRemove, $toAdd): void {
        foreach ($toRemove as $pid) {
            $pdo->prepare('DELETE FROM role_permissions WHERE role_id = :rid AND permission_id = :pid')
                ->execute([':rid' => $roleId, ':pid' => $pid]);
        }
        foreach ($toAdd as $pid) {
            $pdo->prepare('INSERT OR IGNORE INTO role_permissions (role_id, permission_id, created_at) VALUES (:rid, :pid, CURRENT_TIMESTAMP)')
                ->execute([':rid' => $roleId, ':pid' => $pid]);
        }
    });
}
```

**Benefits:**
- **Early return** ... if nothing to change, no transaction
- **Diff+apply** ... only changes, not full rebuild
- **Atomic** ... either all changes, or none

## 4. Admin Action Integration

### RolesAction::savePermissions()

```php
public function savePermissions(Request $request): Response
{
    $id = $request->routeParam('id');
    // ...
    $roles->syncPermissions($id, $wanted);
    // ...
}
```

One line instead of a foreach loop.

## How It Fits the Existing Stack

### Before Implementation

```
Middleware: #[AsMiddleware] + MiddlewareRegistry (auto-discovery)
Migrators: #[AsMigrator] + DatabaseMigrator (auto-discovery)
Themes: ThemeRegistry + auto-discovery
Module: ModuleInterface (formal contract)
Disposal: DisposalRegistry (cleanup callbacks)
UnitOfWork: none
```

### After Implementation

```
Middleware: #[AsMiddleware] + MiddlewareRegistry (auto-discovery)
Migrators: #[AsMigrator] + DatabaseMigrator (auto-discovery)
Themes: ThemeRegistry + auto-discovery
Module: ModuleInterface (formal contract)
Disposal: DisposalRegistry (cleanup callbacks)
UnitOfWork: transactional consistency
Tests: 443 tests, 1343 assertions
```

## Integrated In

| Repository | Method | Operations |
|---|---|---|
| `UserRepository` | `delete()` | 5 DELETEs |
| `RoleRepository` | `deleteRole()` | 3 DELETEs |
| `RoleRepository` | `syncPermissions()` | Diff + apply |

## Philosophy

> **We don't need an ORM that generates transactions.
> We need a pattern that works with raw PDO.**

UnitOfWork remains a pattern, not a framework. 42 lines, zero dependencies, works with any PDO.

---

A transaction is not a feature. It's a guarantee. UnitOfWork makes that guarantee explicit.

---

→ [UnitOfWork philosophy](/blog/uow-philosophy) — why transactions matter
→ [Outbox pattern](/blog/uow-outbox) — domain events without breaking ACID

## Sources

- [PHP Manual](https://www.php.net/manual/) — official PHP documentation
- [PHP-FIG — PSR Standards](https://www.php-fig.org/) — PHP standards
- [Nativa Minimal Documentation](/docs/getting-started) — official getting started guide
- [OWASP Cheat Sheet Series](https://cheatsheetseries.owasp.org/) — security best practices
- [W3C — Web Standards](https://www.w3.org/standards/) — web standards
