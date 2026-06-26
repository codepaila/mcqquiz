-- Adds Telegram username to the payment_settings table. The student-facing
-- post-purchase "pending" modal renders a "Send on Telegram" button when this
-- is set (and a "Send on WhatsApp" button when whatsapp_number is set).
ALTER TABLE payment_settings
  ADD COLUMN telegram_username VARCHAR(60) NULL AFTER whatsapp_number;

-- ============================================================
-- Contact Messages table (added for contact form feature)
-- ============================================================
CREATE TABLE IF NOT EXISTS `contact_messages` (
  `id`             CHAR(24)      NOT NULL,
  `name`           VARCHAR(100)  NOT NULL,
  `email`          VARCHAR(180)  NOT NULL,
  `phone`          VARCHAR(30)   NOT NULL DEFAULT '',
  `subject`        VARCHAR(100)  NOT NULL,
  `message`        TEXT          NOT NULL,
  `status`         ENUM('UNREAD','READ','REPLIED','ARCHIVED','DELETED')
                                 NOT NULL DEFAULT 'UNREAD',
  `reply_text`     TEXT                   DEFAULT NULL,
  `replied_at`     DATETIME               DEFAULT NULL,
  `replied_by_id`  CHAR(24)               DEFAULT NULL,
  `ip_address`     VARCHAR(45)   NOT NULL DEFAULT '',
  `user_agent`     VARCHAR(255)  NOT NULL DEFAULT '',
  `created_at`     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_status`     (`status`),
  KEY `idx_email`      (`email`),
  KEY `idx_created_at` (`created_at`),
  CONSTRAINT `fk_contact_replied_by`
    FOREIGN KEY (`replied_by_id`) REFERENCES `users` (`id`)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `contact_settings` (
  `id` INT NOT NULL DEFAULT 1,
  `whatsapp_url` VARCHAR(500) NULL,
  `whatsapp_label` VARCHAR(100) NULL DEFAULT 'Chat with us instantly',
  `telegram_url` VARCHAR(500) NULL,
  `telegram_label` VARCHAR(100) NULL DEFAULT 'Join our community',
  `instagram_url` VARCHAR(500) NULL,
  `instagram_label` VARCHAR(100) NULL DEFAULT 'Follow updates & tips',
  `email_address` VARCHAR(180) NULL,
  `email_label` VARCHAR(180) NULL DEFAULT 'support@quiznosis.com',
  `urgent_title` VARCHAR(100) NULL DEFAULT 'Immediate Help',
  `urgent_text` TEXT NULL,
  `urgent_btn_label` VARCHAR(100) NULL DEFAULT 'Chat on WhatsApp',
  `response_time` VARCHAR(200) NULL DEFAULT 'Typically responds within a few hours',
  `show_whatsapp` TINYINT(1) NOT NULL DEFAULT 1,
  `show_telegram` TINYINT(1) NOT NULL DEFAULT 1,
  `show_instagram` TINYINT(1) NOT NULL DEFAULT 1,
  `show_email` TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `contact_settings` (id, whatsapp_url, telegram_url, instagram_url, email_address)
VALUES (1,'https://wa.me/message/6KL7YKWGT227O1','https://t.me/quiznosis','https://www.instagram.com/quiz_nosis','support@quiznosis.com');
