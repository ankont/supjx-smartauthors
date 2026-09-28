<?php

declare(strict_types=1);

namespace SuperSoft\Plugin\System\SmartAuthors\Repository;

defined('_JEXEC') or die;

use DateTimeImmutable;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use SuperSoft\Plugin\System\SmartAuthors\Domain\Entity\Policy;
use SuperSoft\Plugin\System\SmartAuthors\Domain\Enum\CategoryManagement;
use SuperSoft\Plugin\System\SmartAuthors\Domain\Enum\EditMode;
use SuperSoft\Plugin\System\SmartAuthors\Domain\Enum\PolicyType;
use SuperSoft\Plugin\System\SmartAuthors\Domain\Enum\StateMode;
use SuperSoft\Plugin\System\SmartAuthors\Service\RequestCache;

class PolicyRepository
{
    public function __construct(
        private readonly DatabaseInterface $db,
        private readonly ?RequestCache $cache = null,
    ) {
    }

    /**
     * Executes a database operation with self-healing table creation on error 1146.
     *
     * @template T
     * @param callable(): T $operation
     * @return T
     */
    private function executeWithSchemaRetry(callable $operation): mixed
    {
        try {
            return $operation();
        } catch (\Throwable $e) {
            if ($this->isMissingTableError($e)) {
                $this->ensureSchemaTables();
                return $operation();
            }
            throw $e;
        }
    }

    private function isMissingTableError(\Throwable $e): bool
    {
        $code = (int) $e->getCode();
        $message = $e->getMessage();

        return $code === 1146 || str_contains($message, "doesn't exist") || str_contains($message, "does not exist");
    }

    /**
     * Creates database tables if they do not exist.
     */
    public function ensureSchemaTables(): void
    {
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
            try {
                $this->db->setQuery($query)->execute();
            } catch (\Throwable) {
                // Ignore errors if table already being created concurrently
            }
        }
    }

    /**
     * Load Custom policy for a specific category, or null if it inherits.
     */
    public function loadPolicy(int $categoryId): ?Policy
    {
        if ($categoryId <= 0) {
            return null;
        }

        if ($this->cache?->hasPolicy($categoryId)) {
            return $this->cache->getPolicy($categoryId);
        }

        $query = $this->db->getQuery(true)
            ->select([
                $this->db->quoteName('category_id'),
                $this->db->quoteName('policy_type'),
                $this->db->quoteName('owner_id'),
                $this->db->quoteName('edit_mode'),
                $this->db->quoteName('state_mode'),
                $this->db->quoteName('category_management'),
            ])
            ->from($this->db->quoteName('#__smartauthors_policies'))
            ->where($this->db->quoteName('category_id') . ' = :categoryId')
            ->bind(':categoryId', $categoryId, ParameterType::INTEGER);

        $row = $this->executeWithSchemaRetry(function () use ($query) {
            $this->db->setQuery($query);
            return $this->db->loadAssoc();
        });

        if (!$row) {
            $this->cache?->setPolicy($categoryId, null);
            return null;
        }

        $policyType = PolicyType::fromString((string) $row['policy_type']);
        $ownerId = $row['owner_id'] !== null ? (int) $row['owner_id'] : null;
        $contributorIds = [];

        if ($policyType->isCollaborative()) {
            $contributorIds = $this->loadContributors($categoryId);
            $ownerId = null;
        }

        $policy = new Policy(
            categoryId: (int) $row['category_id'],
            policyType: $policyType,
            ownerId: $ownerId,
            contributorIds: $contributorIds,
            editMode: EditMode::fromString((string) $row['edit_mode']),
            stateMode: StateMode::fromString((string) $row['state_mode']),
            categoryManagement: CategoryManagement::fromString((string) $row['category_management']),
        );

        $this->cache?->setPolicy($categoryId, $policy);

        return $policy;
    }

    /**
     * Save or update a Custom policy and synchronize contributors.
     */
    public function savePolicy(Policy $policy): void
    {
        $categoryId = $policy->getCategoryId();
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        $existing = $this->loadPolicy($categoryId);

        $policyTypeStr = $policy->getPolicyType()->value;
        $editModeStr = $policy->getEditMode()->value;
        $stateModeStr = $policy->getStateMode()->value;
        $categoryMgmtStr = $policy->getCategoryManagement()->value;

        if ($existing === null) {
            $ownerId = $policy->isPersonal() ? $policy->getOwnerId() : null;

            $query = $this->db->getQuery(true)
                ->insert($this->db->quoteName('#__smartauthors_policies'))
                ->columns([
                    $this->db->quoteName('category_id'),
                    $this->db->quoteName('policy_type'),
                    $this->db->quoteName('owner_id'),
                    $this->db->quoteName('edit_mode'),
                    $this->db->quoteName('state_mode'),
                    $this->db->quoteName('category_management'),
                    $this->db->quoteName('created'),
                    $this->db->quoteName('modified'),
                ])
                ->values(
                    implode(',', [
                        ':categoryId',
                        ':policyType',
                        $ownerId === null ? 'NULL' : ':ownerId',
                        ':editMode',
                        ':stateMode',
                        ':categoryMgmt',
                        ':created',
                        ':modified',
                    ])
                )
                ->bind(':categoryId', $categoryId, ParameterType::INTEGER)
                ->bind(':policyType', $policyTypeStr, ParameterType::STRING)
                ->bind(':editMode', $editModeStr, ParameterType::STRING)
                ->bind(':stateMode', $stateModeStr, ParameterType::STRING)
                ->bind(':categoryMgmt', $categoryMgmtStr, ParameterType::STRING)
                ->bind(':created', $now, ParameterType::STRING)
                ->bind(':modified', $now, ParameterType::STRING);

            if ($ownerId !== null) {
                $query->bind(':ownerId', $ownerId, ParameterType::INTEGER);
            }

            $this->executeWithSchemaRetry(function () use ($query) {
                $this->db->setQuery($query)->execute();
            });
        } else {
            $ownerId = $policy->isPersonal() ? $policy->getOwnerId() : null;

            $query = $this->db->getQuery(true)
                ->update($this->db->quoteName('#__smartauthors_policies'))
                ->set([
                    $this->db->quoteName('policy_type') . ' = :policyType',
                    $this->db->quoteName('owner_id') . ' = ' . ($ownerId === null ? 'NULL' : ':ownerId'),
                    $this->db->quoteName('edit_mode') . ' = :editMode',
                    $this->db->quoteName('state_mode') . ' = :stateMode',
                    $this->db->quoteName('category_management') . ' = :categoryMgmt',
                    $this->db->quoteName('modified') . ' = :modified',
                ])
                ->where($this->db->quoteName('category_id') . ' = :categoryId')
                ->bind(':categoryId', $categoryId, ParameterType::INTEGER)
                ->bind(':policyType', $policyTypeStr, ParameterType::STRING)
                ->bind(':editMode', $editModeStr, ParameterType::STRING)
                ->bind(':stateMode', $stateModeStr, ParameterType::STRING)
                ->bind(':categoryMgmt', $categoryMgmtStr, ParameterType::STRING)
                ->bind(':modified', $now, ParameterType::STRING);

            if ($ownerId !== null) {
                $query->bind(':ownerId', $ownerId, ParameterType::INTEGER);
            }

            $this->executeWithSchemaRetry(function () use ($query) {
                $this->db->setQuery($query)->execute();
            });
        }

        // Contributors synchronization according to invariants
        if ($policy->isPersonal()) {
            // Invariant: Personal policy must have zero contributors
            $this->deleteContributors($categoryId);
        } else {
            // Collaborative: save contributors
            $this->saveContributors($categoryId, $policy->getContributorIds());
        }

        $this->cache?->invalidateCategory($categoryId);
    }

    /**
     * Delete Custom policy and its contributors for a category (converts category to Inherit).
     */
    public function deletePolicy(int $categoryId): void
    {
        if ($categoryId <= 0) {
            return;
        }

        $this->deleteContributors($categoryId);

        $query = $this->db->getQuery(true)
            ->delete($this->db->quoteName('#__smartauthors_policies'))
            ->where($this->db->quoteName('category_id') . ' = :categoryId')
            ->bind(':categoryId', $categoryId, ParameterType::INTEGER);

        $this->executeWithSchemaRetry(function () use ($query) {
            $this->db->setQuery($query)->execute();
        });

        $this->cache?->invalidateCategory($categoryId);
    }

    public function loadOwner(int $categoryId): ?int
    {
        $policy = $this->loadPolicy($categoryId);
        return $policy?->getOwnerId();
    }

    public function saveOwner(int $categoryId, ?int $ownerId): void
    {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        $query = $this->db->getQuery(true)
            ->update($this->db->quoteName('#__smartauthors_policies'))
            ->set([
                $this->db->quoteName('owner_id') . ' = ' . ($ownerId === null ? 'NULL' : ':ownerId'),
                $this->db->quoteName('modified') . ' = :modified',
            ])
            ->where($this->db->quoteName('category_id') . ' = :categoryId')
            ->where($this->db->quoteName('policy_type') . ' = :policyType')
            ->bind(':categoryId', $categoryId, ParameterType::INTEGER)
            ->bind(':policyType', PolicyType::Personal->value, ParameterType::STRING)
            ->bind(':modified', $now, ParameterType::STRING);

        if ($ownerId !== null) {
            $query->bind(':ownerId', $ownerId, ParameterType::INTEGER);
        }

        $this->executeWithSchemaRetry(function () use ($query) {
            $this->db->setQuery($query)->execute();
        });

        $this->cache?->invalidateCategory($categoryId);
    }

    /**
     * @return list<int>
     */
    public function loadContributors(int $categoryId): array
    {
        if ($categoryId <= 0) {
            return [];
        }

        $query = $this->db->getQuery(true)
            ->select($this->db->quoteName('user_id'))
            ->from($this->db->quoteName('#__smartauthors_contributors'))
            ->where($this->db->quoteName('category_id') . ' = :categoryId')
            ->order($this->db->quoteName('user_id') . ' ASC')
            ->bind(':categoryId', $categoryId, ParameterType::INTEGER);

        $results = $this->executeWithSchemaRetry(function () use ($query) {
            $this->db->setQuery($query);
            return $this->db->loadColumn();
        });

        return array_map('intval', $results ?: []);
    }

    /**
     * @param int $categoryId
     * @param list<int> $contributorIds
     */
    public function saveContributors(int $categoryId, array $contributorIds): void
    {
        $this->deleteContributors($categoryId);

        $uniqueIds = array_values(array_unique(array_filter($contributorIds, static fn(int $id) => $id > 0)));

        if (empty($uniqueIds)) {
            return;
        }

        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        foreach ($uniqueIds as $userId) {
            $query = $this->db->getQuery(true)
                ->insert($this->db->quoteName('#__smartauthors_contributors'))
                ->columns([
                    $this->db->quoteName('category_id'),
                    $this->db->quoteName('user_id'),
                    $this->db->quoteName('created'),
                ])
                ->values(implode(',', [':categoryId', ':userId', ':created']))
                ->bind(':categoryId', $categoryId, ParameterType::INTEGER)
                ->bind(':userId', $userId, ParameterType::INTEGER)
                ->bind(':created', $now, ParameterType::STRING);

            $this->executeWithSchemaRetry(function () use ($query) {
                $this->db->setQuery($query)->execute();
            });
        }

        $this->cache?->invalidateCategory($categoryId);
    }

    public function deleteContributors(int $categoryId): void
    {
        $query = $this->db->getQuery(true)
            ->delete($this->db->quoteName('#__smartauthors_contributors'))
            ->where($this->db->quoteName('category_id') . ' = :categoryId')
            ->bind(':categoryId', $categoryId, ParameterType::INTEGER);

        $this->executeWithSchemaRetry(function () use ($query) {
            $this->db->setQuery($query)->execute();
        });
    }

    /**
     * @return list<int>
     */
    public function findPoliciesByContributor(int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }

        $query = $this->db->getQuery(true)
            ->select($this->db->quoteName('category_id'))
            ->from($this->db->quoteName('#__smartauthors_contributors'))
            ->where($this->db->quoteName('user_id') . ' = :userId')
            ->bind(':userId', $userId, ParameterType::INTEGER);

        $results = $this->executeWithSchemaRetry(function () use ($query) {
            $this->db->setQuery($query);
            return $this->db->loadColumn();
        });

        return array_map('intval', $results ?: []);
    }

    /**
     * @return list<int>
     */
    public function findPersonalPoliciesByOwner(int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }

        if ($cached = $this->cache?->getOwnerCategories($userId)) {
            return $cached;
        }

        $query = $this->db->getQuery(true)
            ->select($this->db->quoteName('category_id'))
            ->from($this->db->quoteName('#__smartauthors_policies'))
            ->where($this->db->quoteName('policy_type') . ' = :policyType')
            ->where($this->db->quoteName('owner_id') . ' = :ownerId')
            ->bind(':policyType', PolicyType::Personal->value, ParameterType::STRING)
            ->bind(':ownerId', $userId, ParameterType::INTEGER);

        $results = $this->executeWithSchemaRetry(function () use ($query) {
            $this->db->setQuery($query);
            return array_map('intval', $this->db->loadColumn() ?: []);
        });

        $this->cache?->setOwnerCategories($userId, $results);

        return $results;
    }

    /**
     * Find category IDs of unbound personal policies.
     *
     * @return list<int>
     */
    public function findUnboundPersonalPolicies(): array
    {
        $query = $this->db->getQuery(true)
            ->select($this->db->quoteName('category_id'))
            ->from($this->db->quoteName('#__smartauthors_policies'))
            ->where($this->db->quoteName('policy_type') . ' = :policyType')
            ->where($this->db->quoteName('owner_id') . ' IS NULL')
            ->bind(':policyType', PolicyType::Personal->value, ParameterType::STRING);

        $results = $this->executeWithSchemaRetry(function () use ($query) {
            $this->db->setQuery($query);
            return $this->db->loadColumn();
        });

        return array_map('intval', $results ?: []);
    }
}
