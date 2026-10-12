<?php

declare(strict_types=1);

namespace App\Models;

use Nabilet\Modules\Core\Users\Models\User as CanonicalUser;

/**
 * The legacy name for the user model — a SUBCLASS, not a second model.
 *
 * WHAT WAS WRONG
 *   Two classes over the `users` table. This one had its own `$fillable`
 *   (13 fields), a `name` accessor, `canAccessAdmin()`, `roles()`,
 *   `hasRole()` — and none of the other 19 relations; the module one had every
 *   relation, `extends Authenticatable` with `HasApiTokens`, and neither of
 *   those three methods. `config/auth.php` pointed the web guard here and the
 *   api guard there, so the same row came back as different classes depending
 *   on the entry point — `instanceof`, `roles()` and `userSessions()` were
 *   missing exactly where they were needed (`docs/REVIEW-spec-bundle.md`
 *   §3.25, «раздвоение моделей User»).
 *
 *   The duplication also cost a real bug: `Order::user()` resolves to the
 *   module model, so `$order->user?->name` was always null and
 *   `OrderNotificationData::customerName()` silently fell back to «Покупатель».
 *
 * THE DECISION
 *   One implementation owns the domain: `Nabilet\Modules\Core\Users\Models\User`
 *   — table, fillable, casts, all relations, `name`, `canAccessAdmin()`,
 *   `roles()`, `hasRole()`, the `UserStatus`-based checks. Both auth providers
 *   point at it (config/auth.php). This class stays only so the name keeps
 *   resolving: `vendor/composer`'s optimized classmap maps it, and
 *   `AUTH_MODEL` (the framework default) names it. It declares NOTHING of its
 *   own, so behaviour cannot drift again — an inherited method cannot disagree
 *   with the canonical one.
 *
 *   `tests/Feature/Core/UserModelUnificationTest.php` is the guard: it fails if
 *   the two providers stop pointing at one class, or if this file grows code.
 */
class User extends CanonicalUser
{
}
