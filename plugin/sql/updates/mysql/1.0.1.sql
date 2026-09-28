-- SuperSoftJx SmartAuthors - Schema Update 1.0.1
CREATE TABLE IF NOT EXISTS `#__smartauthors_policies` (
  `category_id` INT UNSIGNED NOT NULL,
  `policy_type` VARCHAR(20) NOT NULL,
  `owner_id` INT UNSIGNED NULL DEFAULT NULL,
  `edit_mode` VARCHAR(10) NOT NULL DEFAULT 'own',
  `state_mode` VARCHAR(10) NOT NULL DEFAULT 'none',
  `category_management` VARCHAR(10) NOT NULL DEFAULT 'none',
  `created` DATETIME NOT NULL,
  `modified` DATETIME NOT NULL,
  PRIMARY KEY (`category_id`),
  KEY `idx_owner_id` (`owner_id`),
  KEY `idx_policy_type` (`policy_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `#__smartauthors_contributors` (
  `category_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `created` DATETIME NOT NULL,
  PRIMARY KEY (`category_id`, `user_id`),
  KEY `idx_user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
