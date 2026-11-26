-- 027 — снимок шаблона билета на момент выпуска.
--
-- Оформление билета фиксируется при выпуске: последующая правка/удаление
-- шаблона не меняет уже проданные билеты. Динамические данные (дата, цена)
-- продолжают читаться из заказа/сеанса.
ALTER TABLE order_items
  ADD COLUMN template_snapshot_json JSON NULL AFTER seat_snapshot_json;
ALTER TABLE tickets
  ADD COLUMN template_snapshot_json JSON NULL AFTER qr_payload;
