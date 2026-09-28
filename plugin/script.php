<?php

declare(strict_types=1);

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Installer\InstallerAdapter;
use Joomla\Database\DatabaseInterface;

class PlgSystemSmartauthorsInstallerScript
{
    /**
     * Called on extension installation.
     */
    public function install(InstallerAdapter $parent): bool
    {
        return $this->ensureSchemaTables();
    }

    /**
     * Called on extension update.
     */
    public function update(InstallerAdapter $parent): bool
    {
        return $this->ensureSchemaTables();
    }

    /**
     * Called after installation, update, or discovery.
     */
    public function postflight(string $type, InstallerAdapter $parent): bool
    {
        return $this->ensureSchemaTables();
    }

    /**
     * Called on extension uninstallation.
     */
    public function uninstall(InstallerAdapter $parent): bool
    {
        return true;
    }

    /**
     * Guarantees that #__smartauthors_policies and #__smartauthors_contributors exist.
     */
    private function ensureSchemaTables(): bool
    {
        try {
            $db = Factory::getContainer()->get(DatabaseInterface::class);

            $queries = [
                "CREATE TABLE IF NOT EXISTS `#__smartauthors_policies` (
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
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

                "CREATE TABLE IF NOT EXISTS `#__smartauthors_contributors` (
                  `category_id` INT UNSIGNED NOT NULL,
                  `user_id` INT UNSIGNED NOT NULL,
                  `created` DATETIME NOT NULL,
                  PRIMARY KEY (`category_id`, `user_id`),
                  KEY `idx_user_id` (`user_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;"
            ];

            foreach ($queries as $query) {
                $db->setQuery($query)->execute();
            }

            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
