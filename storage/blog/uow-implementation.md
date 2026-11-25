---
title: "UnitOfWork v Nativa ... Implementácia bez závislostí"
date: "2026-08-18"
version: "1.0.1"
tags: ["php", "architecture", "database", "transactions", "implementation"]
excerpt: "Ako sme implementovali UnitOfWork pre Nativa Minimal ... jednoduchý, efektívny, zero-dependency."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Architecture"
views: "0"
translation: "uow-implementation"
published: true
---

V minulom článku sme písali o filozofii transakčnej konzistencie.
Teraz sme to implementovali.

V minulom článku sme písali o filozofii transactional consistency. Teraz sme to implementovali. Jeden súbor. 42 riadkov. Žiadne závislosti.

## Čo sme implementovali

Jeden nový súbor:

| Subor | Účel |
|---|---|
| `UnitOfWork.php` | Transakčná konzistencia |

**42 riadkov kódu.** Žiadne závislosti.

## 1. UnitOfWork ... základ

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

**Kľúčové vlastnosti:**
- **Generický `transaction()`** ... callback dostane PDO, vráti hodnotu
- **Idempotentné `beginTransaction()`** ... bezpečné volať viackrát
- **`inTransaction()`** ... kontrola stavu pre testy
- **Best-effort rollback** ... ak commit zlyhá, rollback sa spustí

## 2. Integrácia do repository

### UserRepository::delete() ... pred

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
    // Ak jedno zlyhá ... orphan data!
}
```

### UserRepository::delete() ... po

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

## 3. syncPermissions ... atomický diff+apply

Namiesto manuálneho foreach cez current/wanted:

### Pred ... non-atomic

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
// Ak jedno zlyhá ... inconsistent state!
```

### Po ... atomic sync

```php
$roles = CardboardServices::roles();
$roles->syncPermissions($id, $wanted);
```

**Implementácia syncPermissions:**

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

**Výhody:**
- **Early return** ... ak nie je čo meniť, žiadna transakcia
- **Diff+apply** ... len zmeny, nie celý rebuild
- **Atomic** ... buď všetky zmeny, alebo žiadne

## 4. Integrácia do admin akcií

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

Jeden riadok namiesto foreach loopu.

## Ako to zapadá do existujúceho stacku

### Pred implementáciou

```
Middleware: #[AsMiddleware] + MiddlewareRegistry (auto-discovery)
Migrátory: #[AsMigrator] + DatabaseMigrator (auto-discovery)
Témy: ThemeRegistry + auto-discovery
Module: ModuleInterface (formálny contract)
Disposal: DisposalRegistry (cleanup callbacks)
UnitOfWork: žiadny
```

### Po implementácii

```
Middleware: #[AsMiddleware] + MiddlewareRegistry (auto-discovery)
Migrátory: #[AsMigrator] + DatabaseMigrator (auto-discovery)
Témy: ThemeRegistry + auto-discovery
Module: ModuleInterface (formálny contract)
Disposal: DisposalRegistry (cleanup callbacks)
UnitOfWork: transakčná konzistencia
Testy: 443 testov, 1343 asercií
```

## Integrované v

| Repository | Metóda | Počet operácií |
|---|---|---|
| `UserRepository` | `delete()` | 5 DELETEs |
| `RoleRepository` | `deleteRole()` | 3 DELETEs |
| `RoleRepository` | `syncPermissions()` | Diff + apply |

## Philosophy

> **Nepotrebujeme ORM, ktorý generuje transakcie.
> Potrebujeme pattern, ktorý funguje s čistým PDO.**

42 riadkov kódu. Žiadne závislosti. Funguje s akýmkoľvek PDO.

---

→ [UnitOfWork filozofia](/blog/uow-philosophy): prečo transakcie nie sú nudné
→ [Outbox pattern](/blog/outbox-pattern): Domain Events bez porušenia ACID
→ [ACID pre deti](/blog/acid-pre-deti): prečo transakcie nie sú nudné

## Zdroje

- [PHP Manual](https://www.php.net/manual/): oficiálna PHP dokumentácia
- [PHP-FIG, PSR Standards](https://www.php-fig.org/): PHP štandardy
- [Nativa Minimal Documentation](/docs/getting-started): oficiálny návod
- [OWASP Cheat Sheet Series](https://cheatsheetseries.owasp.org/): bezpečnostné best practices
- [W3C, Web Standards](https://www.w3.org/standards/): web štandardy
