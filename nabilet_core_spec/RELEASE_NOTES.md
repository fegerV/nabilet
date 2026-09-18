# NABILET Core v1 specification bundle — notes

## Included

- Full technical specification and ER model.
- Consolidated MySQL 8.4+ SQL schema.
- Split SQL migrations in execution order.
- OpenAPI 3.1 API contract with 68 paths / 71 reusable schemas.
- Mermaid state diagrams for Hold, Order, Payment, Ticket, Check-in and purchase sequence.

## Important implementation decisions

1. `inventory_items` is the sellable inventory layer between physical hall seats and orders/tickets.
2. Published hall schema versions are immutable.
3. `ticket_index` allows one OrderItem to produce multiple standing tickets.
4. Payment success is finalized server-side from provider state/webhook.
5. Critical mutations use idempotency.
6. Online check-in uses row locking; offline check-in sync is conflict-aware.
7. Guest purchase is supported through a checkout token.

## Verification

- OpenAPI YAML parsed successfully with PyYAML.
- Every templated OpenAPI path has a matching path parameter.
- SQL tables: 56.
- Split migration files: 8.
