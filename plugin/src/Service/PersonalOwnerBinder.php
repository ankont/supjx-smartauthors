<?php

declare(strict_types=1);

namespace SuperSoft\Plugin\System\SmartAuthors\Service;

defined('_JEXEC') or die;

use Closure;
use Joomla\CMS\Application\ApplicationHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Table\Category as CategoryTable;
use Joomla\CMS\Table\Table;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use SuperSoft\Plugin\System\SmartAuthors\Repository\PolicyRepository;

class PersonalOwnerBinder
{
    /** @var bool Static recursion guard flag */
    private static bool $isSyncing = false;

    /** @var array<int, string> Tracks previous username per user ID before save */
    private array $previousUsernames = [];

    /** @var (Closure(): Table)|null Optional factory for Table instance (for testing/mocking) */
    private ?Closure $tableFactory = null;

    public function __construct(
        private readonly PolicyRepository $repository,
        private readonly ?DatabaseInterface $db = null,
        private readonly ?RequestCache $cache = null,
    ) {
    }

    public function setTableFactory(Closure $factory): void
    {
        $this->tableFactory = $factory;
    }

    /**
     * Record a user's current username before save to detect real changes.
     */
    public function recordOldUsername(int $userId, string $username): void
    {
        if ($userId > 0 && $username !== '') {
            $this->previousUsernames[$userId] = $username;
        }
    }

    /**
     * Reconciles an unbound Personal category by matching category.alias == user.username.
     * Note: Searches existing users regardless of whether they are blocked (identity binding).
     *
     * @param int $categoryId
     * @param string|null $categoryAlias
     * @return int|null Bound user ID or null
     */
    public function reconcileUnboundCategory(int $categoryId, ?string $categoryAlias = null): ?int
    {
        if ($categoryId <= 0) {
            return null;
        }

        $policy = $this->repository->loadPolicy($categoryId);
        if ($policy === null || !$policy->isPersonal() || $policy->getOwnerId() !== null) {
            return null;
        }

        $alias = $categoryAlias;
        if ($alias === null || $alias === '') {
            $alias = $this->getCategoryAlias($categoryId);
        }

        if ($alias === null || $alias === '') {
            return null;
        }

        $userId = $this->findUserIdByUsername($alias);
        if ($userId !== null && $userId > 0) {
            $this->repository->saveOwner($categoryId, $userId);
            $this->cache?->invalidateCategory($categoryId);
            return $userId;
        }

        return null;
    }

    /**
     * When a new user is created, checks for unbound Personal policies whose category alias matches username.
     *
     * @param int $userId
     * @param string $username
     * @return list<int> Category IDs that were bound
     */
    public function reconcileNewUser(int $userId, string $username): array
    {
        if ($userId <= 0 || $username === '') {
            return [];
        }

        $unboundCategoryIds = $this->repository->findUnboundPersonalPolicies();
        $bound = [];

        foreach ($unboundCategoryIds as $categoryId) {
            $alias = $this->getCategoryAlias($categoryId);
            if ($alias !== null && $alias === $username) {
                $this->repository->saveOwner($categoryId, $userId);
                $this->cache?->invalidateCategory($categoryId);
                $bound[] = $categoryId;
            }
        }

        return $bound;
    }

    /**
     * Self-healing reconciliation on user login.
     * Only binds UNBOUND Personal policies matching category.alias == username.
     * Does NOT alter the alias of already bound categories.
     */
    public function reconcileOnLogin(int $userId, string $username): void
    {
        if ($userId <= 0 || $username === '') {
            return;
        }

        $this->reconcileNewUser($userId, $username);
    }

    /**
     * Handles detected username changes in the user save lifecycle.
     * Synchronizes category alias using the Joomla Category Table API.
     *
     * @param int $userId
     * @param string $newUsername
     */
    public function onUsernameChanged(int $userId, string $newUsername): void
    {
        if ($userId <= 0 || $newUsername === '') {
            return;
        }

        // Recursion guard
        if (self::$isSyncing) {
            return;
        }

        self::$isSyncing = true;

        try {
            $oldUsername = $this->previousUsernames[$userId] ?? null;

            // Only proceed if there was a real username change or old username was not tracked
            if ($oldUsername !== null && $oldUsername === $newUsername) {
                return;
            }

            // Find all Personal categories owned by this user
            $categoryIds = $this->repository->findPersonalPoliciesByOwner($userId);
            if (empty($categoryIds)) {
                return;
            }

            foreach ($categoryIds as $categoryId) {
                $this->synchronizeCategoryAlias($categoryId, $userId, $newUsername);
            }
        } finally {
            self::$isSyncing = false;
            unset($this->previousUsernames[$userId]);
        }
    }

    /**
     * Synchronizes a category's alias to the new username using Category Table API.
     */
    private function synchronizeCategoryAlias(int $categoryId, int $ownerId, string $newUsername): void
    {
        $table = $this->createCategoryTable();
        if ($table === null || !$table->load($categoryId)) {
            return;
        }

        // 1. Normalize alias using Joomla URL safe string helper
        $language = isset($table->language) ? (string) $table->language : '*';
        $normalizedAlias = class_exists(ApplicationHelper::class)
            ? ApplicationHelper::stringURLSafe($newUsername, $language)
            : strtolower(preg_replace('/[^a-zA-Z0-9_-]/', '-', $newUsername));

        // Validation check: cannot be empty or only dashes
        if (trim(str_replace('-', '', $normalizedAlias)) === '') {
            $this->logWarning("SmartAuthors: Category alias synchronization for category #{$categoryId} to '{$newUsername}' failed (invalid alias). Owner binding #{$ownerId} remains authoritative.");
            return;
        }

        // No-op if alias is already equal
        if ($table->alias === $normalizedAlias) {
            return;
        }

        // 2. Uniqueness check among siblings in same parent and extension
        $parentId = (int) $table->parent_id;
        $extension = (string) $table->extension;

        if ($this->hasSiblingAliasConflict($categoryId, $parentId, $extension, $normalizedAlias)) {
            $this->logWarning("SmartAuthors: Category alias synchronization for category #{$categoryId} to '{$normalizedAlias}' failed (alias collision in parent #{$parentId}). Owner binding #{$ownerId} remains authoritative.");
            return;
        }

        // 3. Store alias using Table API
        $table->alias = $normalizedAlias;

        if (!$table->store()) {
            $error = method_exists($table, 'getError') ? $table->getError() : 'Store error';
            $this->logWarning("SmartAuthors: Category table store failed for category #{$categoryId}: {$error}. Owner binding #{$ownerId} remains authoritative.");
            return;
        }

        // 4. Rebuild path for node and recursively for descendants without altering tree structure
        if (method_exists($table, 'rebuildPath')) {
            $table->rebuildPath($table->id);
        }

        if (method_exists($table, 'rebuild') && isset($table->lft, $table->level, $table->path)) {
            $table->load($table->id);
            $table->rebuild($table->id, (int) $table->lft, (int) $table->level, (string) $table->path);
        }

        $this->cache?->invalidateCategory($categoryId);
    }

    /**
     * Checks if another sibling category with the same parent and extension already uses the alias.
     */
    protected function hasSiblingAliasConflict(int $categoryId, int $parentId, string $extension, string $alias): bool
    {
        if ($this->db === null) {
            return false;
        }

        $query = $this->db->getQuery(true)
            ->select($this->db->quoteName('id'))
            ->from($this->db->quoteName('#__categories'))
            ->where($this->db->quoteName('alias') . ' = :alias')
            ->where($this->db->quoteName('parent_id') . ' = :parentId')
            ->where($this->db->quoteName('extension') . ' = :extension')
            ->where($this->db->quoteName('id') . ' != :id')
            ->bind(':alias', $alias, ParameterType::STRING)
            ->bind(':parentId', $parentId, ParameterType::INTEGER)
            ->bind(':extension', $extension, ParameterType::STRING)
            ->bind(':id', $categoryId, ParameterType::INTEGER);

        $this->db->setQuery($query);
        $result = $this->db->loadResult();

        return $result !== null;
    }

    protected function getCategoryAlias(int $categoryId): ?string
    {
        if ($categoryId <= 0 || $this->db === null) {
            return null;
        }

        $query = $this->db->getQuery(true)
            ->select($this->db->quoteName('alias'))
            ->from($this->db->quoteName('#__categories'))
            ->where($this->db->quoteName('id') . ' = :id')
            ->bind(':id', $categoryId, ParameterType::INTEGER);

        $this->db->setQuery($query);
        $result = $this->db->loadResult();

        return $result !== null ? (string) $result : null;
    }

    /**
     * Look up Joomla user ID by username regardless of block status.
     */
    protected function findUserIdByUsername(string $username): ?int
    {
        if ($username === '' || $this->db === null) {
            return null;
        }

        $query = $this->db->getQuery(true)
            ->select($this->db->quoteName('id'))
            ->from($this->db->quoteName('#__users'))
            ->where($this->db->quoteName('username') . ' = :username')
            ->bind(':username', $username, ParameterType::STRING);

        $this->db->setQuery($query);
        $result = $this->db->loadResult();

        return $result !== null ? (int) $result : null;
    }

    private function createCategoryTable(): ?Table
    {
        if ($this->tableFactory !== null) {
            return ($this->tableFactory)();
        }

        if (class_exists(CategoryTable::class) && $this->db !== null) {
            return new CategoryTable($this->db);
        }

        if (class_exists(Table::class) && $this->db !== null) {
            return Table::getInstance('Category', 'Joomla\\CMS\\Table\\', ['dbo' => $this->db]);
        }

        return null;
    }

    private function logWarning(string $message): void
    {
        if (class_exists(Log::class)) {
            try {
                Log::add($message, Log::WARNING, 'plg_system_smartauthors');
            } catch (\Throwable) {
                // Ignore logger errors
            }
        }
    }
}
