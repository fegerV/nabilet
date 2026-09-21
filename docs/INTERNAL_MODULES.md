# Internal Modules Documentation

## Modules Without Public Routes

The following modules are registered via ServiceProviders but do not expose public API routes.
They are designed for internal use only.

### Core Infrastructure Modules

| Module | Provider | Purpose |
|--------|----------|---------|
| `Auth` | `AuthServiceProvider` | Authentication logic, guards, middleware |
| `Security` | `SecurityServiceProvider` | Security policies, encryption, rate limiting |
| `System` | `SystemServiceProvider` | System configuration, health checks |
| `Installer` | `InstallerServiceProvider` | Installation wizard, setup routines |

### Support Modules

| Module | Provider | Purpose |
|--------|----------|---------|
| `Analytics` | `AnalyticsServiceProvider` | Data collection, metrics aggregation |
| `AbTesting` | `AbTestingServiceProvider` | A/B test configuration, variant selection |
| `Ai` | `AiServiceProvider` | AI integration, prompt handling |
| `Backups` | `BackupsServiceProvider` | Backup scheduling, storage management |
| `Content` | `ContentServiceProvider` | CMS content management |
| `Embed` | `EmbedServiceProvider` | Embeddable widget generation |
| `HallSchemas` | `HallSchemasServiceProvider` | Hall layout definitions |
| `Heatmaps` | `HeatmapsServiceProvider` | Heatmap data collection |
| `Localization` | `LocalizationServiceProvider` | i18n/l10n support |
| `Media` | `MediaServiceProvider` | Media asset management |
| `Notifications` | `NotificationsServiceProvider` | Notification dispatch |
| `Pricing` | `PricingServiceProvider` | Pricing rules engine |
| `Privacy` | `PrivacyServiceProvider` | GDPR/privacy compliance |
| `Seo` | `SeoServiceProvider` | SEO metadata management |
| `Telegram` | `TelegramServiceProvider` | Telegram bot integration |
| `Users` | `UsersServiceProvider` | User management (internal) |

### Special Cases

| Module | Provider | Notes |
|--------|----------|-------|
| `Checkin` | `CheckinServiceProvider` | May have routes in separate file |
| `Core` | `CoreServiceProvider` | Base services, shared utilities |
| `Nabilet` (root) | `NabiletServiceProvider` | Application bootstrap |

## Usage Guidelines

1. **Do not add public routes** to these modules unless there is a clear API requirement.
2. **Use dependency injection** to access services from these modules.
3. **Document any new internal module** in this file when created.
4. **Keep business logic** in Services, not Controllers.

## Architecture Decision

These modules follow the **Internal Service Pattern**:
- No public HTTP endpoints
- Accessed via service containers
- Used by other modules through interfaces
- May have background jobs, events, listeners
