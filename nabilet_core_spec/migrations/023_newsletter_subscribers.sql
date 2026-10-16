-- Подписчики на рассылку афиши: email уникален, отписка — по токену,
-- факт согласия дублируется в consents с тем же anonymous_id (152-ФЗ §9).
CREATE TABLE IF NOT EXISTS newsletter_subscribers (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  email VARCHAR(255) NOT NULL,
  unsubscribe_token VARCHAR(64) NOT NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'subscribed',
  anonymous_id CHAR(36) NOT NULL,
  policy_version VARCHAR(50) NULL,
  created_at DATETIME(6) NOT NULL,
  updated_at DATETIME(6) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_newsletter_email (email),
  UNIQUE KEY uq_newsletter_unsub_token (unsubscribe_token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
