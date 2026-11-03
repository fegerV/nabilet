-- ============================================================ 025 — tenant ownership
-- Мультитенантность (док. SECURITY-GAPS-REMEDIATION §3.2, класс A): у строки
-- каждого из этих 40 контекстов есть ровно один владелец-организация.
-- Колонка обязательна (NOT NULL) — «строка без арендатора» считается ошибкой
-- данных, а не легальным состоянием; ссылка каскадная.
-- Таблицы, носившие organization_id изначально (organizations, venues, events,
-- orders, promo_codes, webhooks, api_keys, pages, ticket_templates,
-- media_assets, storefront_settings, ab_experiments и др.), объявляют колонку
-- в своих CREATE-блоках и здесь не повторяются.
ALTER TABLE sessions
  ADD COLUMN organization_id BIGINT UNSIGNED NOT NULL AFTER id,
  ADD KEY idx_sessions_org (organization_id),
  ADD CONSTRAINT fk_sessions_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE;
ALTER TABLE inventory_items
  ADD COLUMN organization_id BIGINT UNSIGNED NOT NULL AFTER id,
  ADD KEY idx_inventory_items_org (organization_id),
  ADD CONSTRAINT fk_inventory_items_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE;
ALTER TABLE carts
  ADD COLUMN organization_id BIGINT UNSIGNED NOT NULL AFTER id,
  ADD KEY idx_carts_org (organization_id),
  ADD CONSTRAINT fk_carts_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE;
ALTER TABLE cart_items
  ADD COLUMN organization_id BIGINT UNSIGNED NOT NULL AFTER id,
  ADD KEY idx_cart_items_org (organization_id),
  ADD CONSTRAINT fk_cart_items_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE;
ALTER TABLE seat_holds
  ADD COLUMN organization_id BIGINT UNSIGNED NOT NULL AFTER id,
  ADD KEY idx_seat_holds_org (organization_id),
  ADD CONSTRAINT fk_seat_holds_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE;
ALTER TABLE order_items
  ADD COLUMN organization_id BIGINT UNSIGNED NOT NULL AFTER id,
  ADD KEY idx_order_items_org (organization_id),
  ADD CONSTRAINT fk_order_items_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE;
ALTER TABLE payments
  ADD COLUMN organization_id BIGINT UNSIGNED NOT NULL AFTER id,
  ADD KEY idx_payments_org (organization_id),
  ADD CONSTRAINT fk_payments_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE;
ALTER TABLE payment_transactions
  ADD COLUMN organization_id BIGINT UNSIGNED NOT NULL AFTER id,
  ADD KEY idx_payment_transactions_org (organization_id),
  ADD CONSTRAINT fk_payment_transactions_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE;
ALTER TABLE refunds
  ADD COLUMN organization_id BIGINT UNSIGNED NOT NULL AFTER id,
  ADD KEY idx_refunds_org (organization_id),
  ADD CONSTRAINT fk_refunds_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE;
ALTER TABLE tickets
  ADD COLUMN organization_id BIGINT UNSIGNED NOT NULL AFTER id,
  ADD KEY idx_tickets_org (organization_id),
  ADD CONSTRAINT fk_tickets_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE;
ALTER TABLE ticket_scans
  ADD COLUMN organization_id BIGINT UNSIGNED NOT NULL AFTER id,
  ADD KEY idx_ticket_scans_org (organization_id),
  ADD CONSTRAINT fk_ticket_scans_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE;
ALTER TABLE halls
  ADD COLUMN organization_id BIGINT UNSIGNED NOT NULL AFTER id,
  ADD KEY idx_halls_org (organization_id),
  ADD CONSTRAINT fk_halls_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE;
ALTER TABLE hall_schema_versions
  ADD COLUMN organization_id BIGINT UNSIGNED NOT NULL AFTER id,
  ADD KEY idx_hall_schema_versions_org (organization_id),
  ADD CONSTRAINT fk_hall_schema_versions_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE;
ALTER TABLE sectors
  ADD COLUMN organization_id BIGINT UNSIGNED NOT NULL AFTER id,
  ADD KEY idx_sectors_org (organization_id),
  ADD CONSTRAINT fk_sectors_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE;
ALTER TABLE hall_rows
  ADD COLUMN organization_id BIGINT UNSIGNED NOT NULL AFTER id,
  ADD KEY idx_hall_rows_org (organization_id),
  ADD CONSTRAINT fk_hall_rows_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE;
ALTER TABLE seats
  ADD COLUMN organization_id BIGINT UNSIGNED NOT NULL AFTER id,
  ADD KEY idx_seats_org (organization_id),
  ADD CONSTRAINT fk_seats_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE;
ALTER TABLE hall_tables
  ADD COLUMN organization_id BIGINT UNSIGNED NOT NULL AFTER id,
  ADD KEY idx_hall_tables_org (organization_id),
  ADD CONSTRAINT fk_hall_tables_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE;
ALTER TABLE standing_zones
  ADD COLUMN organization_id BIGINT UNSIGNED NOT NULL AFTER id,
  ADD KEY idx_standing_zones_org (organization_id),
  ADD CONSTRAINT fk_standing_zones_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE;
ALTER TABLE event_translations
  ADD COLUMN organization_id BIGINT UNSIGNED NOT NULL AFTER id,
  ADD KEY idx_event_translations_org (organization_id),
  ADD CONSTRAINT fk_event_translations_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE;
ALTER TABLE venue_translations
  ADD COLUMN organization_id BIGINT UNSIGNED NOT NULL AFTER id,
  ADD KEY idx_venue_translations_org (organization_id),
  ADD CONSTRAINT fk_venue_translations_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE;
ALTER TABLE page_translations
  ADD COLUMN organization_id BIGINT UNSIGNED NOT NULL AFTER id,
  ADD KEY idx_page_translations_org (organization_id),
  ADD CONSTRAINT fk_page_translations_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE;
ALTER TABLE promo_code_redemptions
  ADD COLUMN organization_id BIGINT UNSIGNED NOT NULL AFTER id,
  ADD KEY idx_promo_code_redemptions_org (organization_id),
  ADD CONSTRAINT fk_promo_code_redemptions_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE;
ALTER TABLE event_dates
  ADD COLUMN organization_id BIGINT UNSIGNED NOT NULL AFTER id,
  ADD KEY idx_event_dates_org (organization_id),
  ADD CONSTRAINT fk_event_dates_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE;
ALTER TABLE event_speakers
  ADD COLUMN organization_id BIGINT UNSIGNED NOT NULL AFTER id,
  ADD KEY idx_event_speakers_org (organization_id),
  ADD CONSTRAINT fk_event_speakers_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE;
ALTER TABLE event_sponsors
  ADD COLUMN organization_id BIGINT UNSIGNED NOT NULL AFTER id,
  ADD KEY idx_event_sponsors_org (organization_id),
  ADD CONSTRAINT fk_event_sponsors_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE;
ALTER TABLE event_faqs
  ADD COLUMN organization_id BIGINT UNSIGNED NOT NULL AFTER id,
  ADD KEY idx_event_faqs_org (organization_id),
  ADD CONSTRAINT fk_event_faqs_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE;
ALTER TABLE event_artists
  ADD COLUMN organization_id BIGINT UNSIGNED NOT NULL AFTER id,
  ADD KEY idx_event_artists_org (organization_id),
  ADD CONSTRAINT fk_event_artists_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE;
ALTER TABLE event_schedule_items
  ADD COLUMN organization_id BIGINT UNSIGNED NOT NULL AFTER id,
  ADD KEY idx_event_schedule_items_org (organization_id),
  ADD CONSTRAINT fk_event_schedule_items_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE;
ALTER TABLE artist_event
  ADD COLUMN organization_id BIGINT UNSIGNED NOT NULL AFTER id,
  ADD KEY idx_artist_event_org (organization_id),
  ADD CONSTRAINT fk_artist_event_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE;
ALTER TABLE ab_variants
  ADD COLUMN organization_id BIGINT UNSIGNED NOT NULL AFTER id,
  ADD KEY idx_ab_variants_org (organization_id),
  ADD CONSTRAINT fk_ab_variants_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE;
ALTER TABLE ab_assignments
  ADD COLUMN organization_id BIGINT UNSIGNED NOT NULL AFTER id,
  ADD KEY idx_ab_assignments_org (organization_id),
  ADD CONSTRAINT fk_ab_assignments_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE;
ALTER TABLE ab_metrics
  ADD COLUMN organization_id BIGINT UNSIGNED NOT NULL AFTER id,
  ADD KEY idx_ab_metrics_org (organization_id),
  ADD CONSTRAINT fk_ab_metrics_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE;
ALTER TABLE webhook_deliveries
  ADD COLUMN organization_id BIGINT UNSIGNED NOT NULL AFTER id,
  ADD KEY idx_webhook_deliveries_org (organization_id),
  ADD CONSTRAINT fk_webhook_deliveries_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE;
ALTER TABLE event_favorites
  ADD COLUMN organization_id BIGINT UNSIGNED NOT NULL AFTER id,
  ADD KEY idx_event_favorites_org (organization_id),
  ADD CONSTRAINT fk_event_favorites_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE;
ALTER TABLE event_follows
  ADD COLUMN organization_id BIGINT UNSIGNED NOT NULL AFTER id,
  ADD KEY idx_event_follows_org (organization_id),
  ADD CONSTRAINT fk_event_follows_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE;
ALTER TABLE follow_notifications
  ADD COLUMN organization_id BIGINT UNSIGNED NOT NULL AFTER id,
  ADD KEY idx_follow_notifications_org (organization_id),
  ADD CONSTRAINT fk_follow_notifications_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE;
ALTER TABLE raffles
  ADD COLUMN organization_id BIGINT UNSIGNED NOT NULL AFTER id,
  ADD KEY idx_raffles_org (organization_id),
  ADD CONSTRAINT fk_raffles_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE;
ALTER TABLE raffle_prizes
  ADD COLUMN organization_id BIGINT UNSIGNED NOT NULL AFTER id,
  ADD KEY idx_raffle_prizes_org (organization_id),
  ADD CONSTRAINT fk_raffle_prizes_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE;
ALTER TABLE raffle_entries
  ADD COLUMN organization_id BIGINT UNSIGNED NOT NULL AFTER id,
  ADD KEY idx_raffle_entries_org (organization_id),
  ADD CONSTRAINT fk_raffle_entries_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE;
ALTER TABLE raffle_winners
  ADD COLUMN organization_id BIGINT UNSIGNED NOT NULL AFTER id,
  ADD KEY idx_raffle_winners_org (organization_id),
  ADD CONSTRAINT fk_raffle_winners_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE;

