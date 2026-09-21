# Repository Pattern Implementation

## Current State

The project uses a **Partial Repository Pattern** where:
- Repositories exist for data access logic
- Services use repositories for data operations
- Controllers should NOT directly access models

## Implemented Repositories

| Module | Repository | Service Uses It? | Controller Bypasses? |
|--------|-----------|------------------|---------------------|
| Tickets | `TicketRepository` | ✅ Yes | ❌ No |
| Core/Organizations | `OrganizationRepository` | ✅ Yes | ❌ No |
| Core/Users | `UserRepository` | ✅ Yes | ❌ No |
| Inventory | `InventoryItemRepository` | ✅ Yes | ❌ No |
| Venues/Halls | `HallRepository` | ✅ Yes | ❌ No |
| Events | `EventRepository` | ✅ Yes | ❌ No |
| Payments | `PaymentRepository` | ✅ Yes | ❌ No |
| Orders | `OrderRepository` | ✅ Yes | ❌ No |

## Architecture Decision

### KEEP Repository Pattern With Rules:

1. **Services MUST use Repositories** for all data access
2. **Controllers MUST use Services** (never direct repository or model access)
3. **Middleware SHOULD use Repositories** when available (via DI)
4. **No static model calls** (`Model::find()`, `Model::where()`) outside repositories

### Exceptions Allowed:

- Simple read-only queries in views/blades
- Eager loading configuration in repositories only
- Model events and observers (use model directly)

## Fixed Violations

### Before This Audit:
- `CheckOrganizationAccess` middleware used `Organization::where()` directly
- `OrganizationController` used `User::findOrFail()` directly

### After This Audit:
- `CheckOrganizationAccess` now injects `OrganizationRepository`
- `OrganizationController` now injects `UserRepository`

## Future Guidelines

When adding new modules:

```php
// ✅ CORRECT: Repository usage
class UserService {
    public function __construct(
        protected UserRepository $users
    ) {}
    
    public function find(int $id): ?User {
        return $this->users->find($id);
    }
}

// ❌ WRONG: Direct model access in service
class UserService {
    public function find(int $id): ?User {
        return User::find($id); // Don't do this
    }
}
```

## Interface Consideration (Future)

Currently repositories are concrete classes. Consider adding interfaces if:
- You need to swap implementations (e.g., cache decorator)
- You need to mock repositories in tests
- You have multiple data sources

Example future structure:
```
Repositories/
  Interfaces/
    UserRepositoryInterface.php
  Eloquent/
    UserRepository.php (implements interface)
```
