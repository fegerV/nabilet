# План реализации NABILET Core API

## Статус аудита (на 2026-01-01)

### Критические проблемы (CRITICAL)
1. **C-01**: API endpoints не реализованы — только OpenAPI документация
2. **C-02**: Eloquent Models отсутствуют полностью  
3. **C-03**: Риск multi-tenancy leak — organization_id нет в tickets/payments

### Высокие проблемы (HIGH)
1. **H-01**: Нет Repository/Service слоя
2. **H-02**: Foreign Keys не определены в миграциях
3. **H-03**: Генерация Inventory из Schema не реализована

### Средние проблемы (MEDIUM)
1. **M-01**: SEO URL/redirections не реализованы
2. **M-02**: Нет integration tests
3. **M-03**: Immutability schema — только триггер БД

---

## Этап 1: Eloquent Models (Приоритет: CRITICAL)

### 1.1 Identity Module (9 таблиц)
- [x] Organization
- [x] Role
- [ ] Permission
- [ ] RolePermission (pivot)
- [ ] User
- [ ] UserOrganization (pivot)
- [ ] UserRole
- [ ] UserSession
- [ ] LoginLog

### 1.2 Content Module (10 таблиц)
- [ ] EventCategory
- [ ] Event
- [ ] EventTranslation
- [ ] VenueTranslation
- [ ] PageTranslation
- [ ] Page
- [ ] SeoMeta
- [ ] Redirect
- [ ] MediaAsset
- [ ] MediaLink

### 1.3 Venues & Schemas Module (8 таблиц)
- [ ] Venue
- [ ] Hall
- [ ] HallSchemaVersion
- [ ] Sector
- [ ] HallRow
- [ ] Seat
- [ ] HallTable
- [ ] StandingZone

### 1.4 Sales Module (9 таблиц)
- [ ] Session
- [ ] InventoryItem
- [ ] Cart
- [ ] CartItem
- [ ] SeatHold
- [ ] Order
- [ ] OrderItem
- [ ] PromoCode
- [ ] PromoCodeRedemption

### 1.5 Payments & Tickets Module (8 таблиц)
- [ ] Payment
- [ ] PaymentTransaction
- [ ] Refund
- [ ] TicketTemplate
- [ ] Ticket
- [ ] CheckinDevice
- [ ] TicketScan
- [ ] OfflineBundle

### 1.6 Notifications & Privacy Module (4 таблицы)
- [ ] NotificationTemplate
- [ ] Notification
- [ ] Consent
- [ ] PrivacyRequest

### 1.7 Analytics Module (7 таблиц)
- [ ] AnalyticsEvent
- [ ] AbExperiment
- [ ] AbVariant
- [ ] AbAssignment
- [ ] AbMetric
- [ ] HeatmapEvent
- [ ] EmbedDomain

### 1.8 Integrations & System Module (9 таблиц)
- [ ] Webhook
- [ ] WebhookDelivery
- [ ] WebhookEvent
- [ ] ApiKey
- [ ] IdempotencyKey
- [ ] IpRule
- [ ] Module
- [ ] Setting
- [ ] AuditLog

**Итого: 64 модели**

---

## Этап 2: Foreign Keys в миграциях (Приоритет: CRITICAL)

Создать новую миграцию для добавления всех foreign key constraints:

```php
// 2026_09_20_000900_add_foreign_keys.php
Schema::table('role_permissions', function(Blueprint $table) {
    $table->foreign('role_id')->references('id')->on('roles')->cascadeOnDelete();
    $table->foreign('permission_id')->references('id')->on('permissions')->cascadeOnDelete();
});
// ... и так далее для всех связей
```

---

## Этап 3: Repository Layer (Приоритет: HIGH)

Для каждого модуля создать репозитории:

### Auth Module
- [ ] UserRepository
- [ ] OrganizationRepository
- [ ] RoleRepository

### Events Module
- [ ] EventRepository
- [ ] VenueRepository
- [ ] HallRepository

### Sessions Module
- [ ] SessionRepository
- [ ] InventoryRepository

### Orders Module
- [ ] OrderRepository
- [ ] CartRepository

### Payments Module
- [ ] PaymentRepository
- [ ] RefundRepository

### Tickets Module
- [ ] TicketRepository
- [ ] CheckinDeviceRepository

---

## Этап 4: Service Layer (Приоритет: HIGH)

### AuthService
- register()
- login()
- logout()
- forgotPassword()
- resetPassword()
- verifyEmail()

### EventService
- listEvents()
- getEvent()
- createEvent()
- updateEvent()
- deleteEvent()

### CheckoutService
- createCart()
- addToCart()
- removeFromCart()
- checkout()

### PaymentService
- initiatePayment()
- handleWebhook()
- processRefund()

### TicketService
- issueTickets()
- getTicket()
- cancelTicket()
- generateQR()
- generatePDF()

### CheckinService
- validateTicket()
- useTicket()
- syncOfflineScans()

---

## Этап 5: Controllers (Приоритет: CRITICAL)

### Public API Controllers
- [ ] AuthController (6 методов)
- [ ] EventController (2 метода)
- [ ] SessionController (2 метода)
- [ ] CartController (4 метода)
- [ ] OrderController (3 метода)
- [ ] PaymentController (2 метода)
- [ ] TicketController (4 метода)
- [ ] CheckinController (3 метода)
- [ ] MeController (4 метода)

### Admin API Controllers
- [ ] Admin/EventController (2 метода)
- [ ] Admin/VenueController (2 метода)
- [ ] Admin/HallController (2 метода)
- [ ] Admin/SchemaController (4 метода)
- [ ] Admin/SessionController (2 метода)
- [ ] Admin/InventoryController (2 метода)
- [ ] Admin/OrderController (2 метода)
- [ ] Admin/TicketController (2 метода)
- [ ] Admin/CheckinController (3 метода)
- [ ] Admin/WebhookController (2 метода)
- [ ] Admin/AnalyticsController (2 метода)

### Embed API Controllers
- [ ] Embed/EventController (2 метода)
- [ ] Embed/CartController (2 метода)
- [ ] Embed/OrderController (2 метода)

### Integration Controllers
- [ ] WebhookController (YooKassa)
- [ ] TelegramController

**Итого: ~35 контроллеров**

---

## Этап 6: Routes (Приоритет: CRITICAL)

Зарегистрировать все маршруты из OpenAPI:

```php
// routes/api.php
Route::prefix('v1')->group(function () {
    // Public routes
    Route::post('auth/register', [AuthController::class, 'register']);
    Route::post('auth/login', [AuthController::class, 'login']);
    // ...
    
    // Protected routes
    Route::middleware(['auth:sanctum'])->group(function () {
        Route::post('auth/logout', [AuthController::class, 'logout']);
        // ...
    });
    
    // Admin routes
    Route::prefix('admin')->middleware(['auth:sanctum', 'role:admin'])->group(function () {
        // ...
    });
});
```

---

## Этап 7: Multi-tenancy Fix (Приоритet: CRITICAL)

Добавить denormalization:

```php
// Новая миграция
Schema::table('tickets', function(Blueprint $table) {
    $table->unsignedBigInteger('organization_id')->nullable()->after('order_id');
    $table->index(['organization_id', 'status'], 'idx_tickets_org_status');
});

// Заполнить данные
DB::update('UPDATE tickets t JOIN orders o ON t.order_id = o.id SET t.organization_id = o.organization_id');
```

---

## Этап 8: SEO/URL Services (Приоритет: MEDIUM)

- [ ] SlugGenerator
- [ ] RedirectService
- [ ] CanonicalUrlService

---

## Этап 9: Integration Tests (Приоритет: MEDIUM)

- [ ] ConcurrentBookingTest
- [ ] DuplicatePaymentWebhookTest
- [ ] DuplicateOrderCreationTest
- [ ] HoldExpirationTest
- [ ] RefundTest
- [ ] IDORTest

---

## Timeline

| Этап | Дней | Приоритет |
|------|------|-----------|
| 1. Models | 3 | CRITICAL |
| 2. Foreign Keys | 1 | CRITICAL |
| 3. Repositories | 2 | HIGH |
| 4. Services | 3 | HIGH |
| 5. Controllers | 4 | CRITICAL |
| 6. Routes | 1 | CRITICAL |
| 7. Multi-tenancy | 1 | CRITICAL |
| 8. SEO | 1 | MEDIUM |
| 9. Tests | 3 | MEDIUM |

**Всего: ~19 дней**
