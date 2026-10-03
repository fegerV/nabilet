# NABILET Core v1 — State Diagrams

## 1. Hold

```mermaid
stateDiagram-v2
    [*] --> active: create hold
    active --> converted: order paid
    active --> released: user/cart release
    active --> expired: timeout
    converted --> [*]
    released --> [*]
    expired --> [*]
```

### Invariants

- `active` reserve quantity in InventoryItem.
- `released/expired` restore quantity exactly once.
- `converted` must not restore quantity.
- Hold cleanup is idempotent.

---

## 2. Order

```mermaid
stateDiagram-v2
    [*] --> pending
    pending --> awaiting_payment: checkout created
    pending --> cancelled: cancel
    pending --> expired: ttl

    awaiting_payment --> paid: payment.succeeded
    awaiting_payment --> payment_failed: payment failed
    awaiting_payment --> cancelled: cancel
    awaiting_payment --> expired: ttl

    payment_failed --> awaiting_payment: retry payment
    payment_failed --> cancelled: cancel

    paid --> partially_refunded: partial refund
    paid --> refunded: full refund

    partially_refunded --> refunded: remaining refund

    cancelled --> [*]
    expired --> [*]
    refunded --> [*]
```

### Invariants

- Only `paid` can issue tickets.
- `cancelled/expired` cannot become `paid`.
- Refund transitions only from paid/refundable states.

---

## 3. Payment

```mermaid
stateDiagram-v2
    [*] --> pending
    pending --> waiting_for_capture: provider response
    pending --> succeeded: immediate capture
    pending --> failed: provider failed
    pending --> canceled: canceled

    waiting_for_capture --> succeeded: capture
    waiting_for_capture --> canceled: cancel

    succeeded --> [*]
    failed --> [*]
    canceled --> [*]
```

### MVP

Use one-stage payment:

`pending -> succeeded|failed|canceled`.

### Invariants

- Final provider state must be reconciled server-side.
- `payment.succeeded` can trigger order payment exactly once.
- Duplicate provider webhook is no-op after deduplication.

---

## 4. Ticket

```mermaid
stateDiagram-v2
    [*] --> issued: issue after payment
    issued --> used: successful check-in
    issued --> cancelled: admin cancellation
    issued --> refunded: refund
    issued --> expired: event/session expired policy

    used --> [*]
    cancelled --> [*]
    refunded --> [*]
    expired --> [*]
```

### Invariants

- `used` is terminal.
- `used` ticket cannot be reactivated.
- QR validation is independent from display metadata.

---

## 5. Check-in attempt

Check-in попытка — не отдельное состояние Ticket, а результат атомарной операции.

```mermaid
stateDiagram-v2
    [*] --> received: scan
    received --> validated: signature/token valid
    received --> invalid_signature: bad signature
    received --> unknown_ticket: not found

    validated --> session_verified: event/session valid
    validated --> wrong_session: wrong session
    validated --> wrong_event: wrong event

    session_verified --> available: ticket issued
    session_verified --> already_used: ticket used
    session_verified --> cancelled: cancelled/refunded/expired

    available --> used: atomic check-in
    used --> success

    invalid_signature --> [*]
    unknown_ticket --> [*]
    wrong_session --> [*]
    wrong_event --> [*]
    already_used --> [*]
    cancelled --> [*]
    success --> [*]
```

### Concurrent check-in

```text
Request A: lock ticket -> issued -> used -> success
Request B: waits lock -> sees used -> already_used
```

---

## 6. Composite business flow

```mermaid
sequenceDiagram
    participant U as User
    participant API as NABILET API
    participant DB as MySQL
    participant YK as YooKassa
    participant N as Notification
    participant C as Checker

    U->>API: select seat
    API->>DB: transaction + lock inventory
    DB-->>API: hold created
    API-->>U: cart + hold

    U->>API: checkout
    API->>DB: create order
    API->>YK: create payment + idempotency key
    YK-->>API: payment URL
    API-->>U: redirect

    YK->>API: payment.succeeded webhook
    API->>DB: dedupe + mark paid
    API->>DB: issue tickets
    API->>N: send email/Telegram

    C->>API: validate/use QR
    API->>DB: lock ticket
    DB-->>API: issued
    API->>DB: used + scan
    API-->>C: VALID
```
