SET NAMES utf8mb4;
SET time_zone = '+00:00';
SET FOREIGN_KEY_CHECKS = 0;


-- ============================================================ 011 — checkout fixes (D5, B2, A6)
-- Mirrors the applied Laravel migrations:
--   database/migrations/2026_09_29_000100_add_cart_token_and_order_cart_id.php
--   database/migrations/2026_09_29_000200_add_order_customer_name.php
--   database/migrations/2026_09_29_000300_add_qr_payload_to_tickets.php
--   database/migrations/2026_09_30_000100_add_session_event_to_orders.php
--
--   1. carts.cart_token + orders.cart_id  — D5 «корзина на сеанс»: корзина
--      ключуется парой (cart_token, session_id), заказ помнит корзину, из
--      которой вырос (чинит validateHoldsForOrder).
--   2. orders.customer_name               — B2: витрина собирала имя покупателя,
--      но писать его было некуда.
--   3. tickets.qr_payload                 — A6: QR-картинка кодирует подписанный
--      пэйлоад QrSigner («NB1.<id>.<token>.<sig>»), qr_token_hash остаётся
--      якорем проверки на чек-ине.
--   4. orders.session_id / event_id       — A6: выпуск билетов требует их,
--      резолвятся из корзины (carts.session_id → sessions.event_id).

ALTER TABLE carts
  ADD COLUMN cart_token VARCHAR(64) NULL AFTER user_id;

-- Uniqueness only among ACTIVE carts (D5). The previous
-- uq_carts_token_session_status key allowed one cart per (token, session) in
-- EVERY status, so the second purchase in the same session raised 1062 on the
-- conversion to `converted`. Partial indexes do not exist in MySQL, hence the
-- generated key. See migrations.sql for the full rationale.
ALTER TABLE carts
  ADD COLUMN active_cart_key VARCHAR(128)
    GENERATED ALWAYS AS (
      IF(status = 'active', CONCAT(COALESCE(cart_token, ''), '-', session_id), NULL)
    ) VIRTUAL,
  ADD UNIQUE KEY uq_carts_active (active_cart_key);

ALTER TABLE orders
  ADD COLUMN session_id BIGINT UNSIGNED NULL AFTER user_id,
  ADD COLUMN event_id BIGINT UNSIGNED NULL AFTER session_id,
  ADD COLUMN customer_name VARCHAR(255) NULL AFTER customer_email,
  ADD COLUMN cart_id BIGINT UNSIGNED NULL AFTER promo_code_id,
  ADD KEY idx_orders_session (session_id),
  ADD KEY idx_orders_event (event_id),
  ADD KEY idx_orders_cart (cart_id);

ALTER TABLE tickets
  ADD COLUMN qr_payload VARCHAR(255) NULL AFTER qr_token_hash;
