<?php

declare(strict_types=1);

namespace SuperSoft\Plugin\System\SmartAuthors\Service;

defined('_JEXEC') or die;

use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use SuperSoft\Plugin\System\SmartAuthors\Domain\Entity\EffectivePolicy;
use SuperSoft\Plugin\System\SmartAuthors\Repository\PolicyRepository;

class PolicyResolver
{
    /** @var array<int, int> In-memory cache of category_id => parent_id */
    private array $parentMap = [];

    public function __construct(
        private readonly PolicyRepository $repository,
        private readonly ?DatabaseInterface $db = null,
        private readonly ?RequestCache $cache = null,
    ) {
    }

    /**
     * Resolves the effective SmartAuthors policy for a category.
     * Ascends the Joomla category tree until a Custom policy is found.
     *
     * @param int $categoryId
     * @return EffectivePolicy|null
     */
    public function resolve(int $categoryId): ?EffectivePolicy
    {
        if ($categoryId <= 1) {
            return null;
        }

        if ($this->cache?->hasEffectivePolicy($categoryId)) {
            return $this->cache->getEffectivePolicy($categoryId);
        }

        // 1. Check if category itself has a Custom policy
        $ownPolicy = $this->repository->loadPolicy($categoryId);
        if ($ownPolicy !== null) {
            $effective = EffectivePolicy::fromCustomPolicy($ownPolicy, $categoryId);
            $this->cache?->setEffectivePolicy($categoryId, $effective);
            return $effective;
        }

        // 2. Ascend parent tree
        $currentId = $categoryId;
        $visited = [$currentId => true];

        while (true) {
            $parentId = $this->getParentCategoryId($currentId);

            // Joomla root is id 1, parent_id 0
            if ($parentId === null || $parentId <= 1 || isset($visited[$parentId])) {
                break;
            }

            $visited[$parentId] = true;
            $currentId = $parentId;

            $ancestorPolicy = $this->repository->loadPolicy($currentId);
            if ($ancestorPolicy !== null) {
                $effective = EffectivePolicy::fromCustomPolicy($ancestorPolicy, $categoryId);
                $this->cache?->setEffectivePolicy($categoryId, $effective);
                return $effective;
            }
        }

        $this->cache?->setEffectivePolicy($categoryId, null);
        return null;
    }

    /**
     * Get parent category ID from database or local map.
     */
    public function getParentCategoryId(int $categoryId): ?int
    {
        if ($categoryId <= 1) {
            return null;
        }

        if (isset($this->parentMap[$categoryId])) {
            return $this->parentMap[$categoryId];
        }

        if ($this->db === null) {
            return null;
        }

        $query = $this->db->getQuery(true)
            ->select($this->db->quoteName('parent_id'))
            ->from($this->db->quoteName('#__categories'))
            ->where($this->db->quoteName('id') . ' = :id')
            ->bind(':id', $categoryId, ParameterType::INTEGER);

        $this->db->setQuery($query);
        $result = $this->db->loadResult();

        if ($result === null) {
            return null;
        }

        $parentId = (int) $result;
        $this->parentMap[$categoryId] = $parentId;

        return $parentId;
    }

    /**
     * Allows pre-registering category hierarchy (useful for tests or bulk loading).
     */
    public function setCategoryParent(int $categoryId, int $parentId): void
    {
        $this->parentMap[$categoryId] = $parentId;
    }

    /**
     * Returns all content category IDs where the given user is an effective actor
     * (either directly via custom policy or via inheritance from a managed root).
     *
     * @param int $userId
     * @return array<int>
     */
    public function getEffectiveActorCategoryIds(int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }

        if ($this->cache?->hasActorCategories($userId)) {
            return $this->cache->getActorCategories($userId) ?? [];
        }

        if ($this->db === null) {
            return [];
        }

        $query = $this->db->getQuery(true)
            ->select($this->db->quoteName('id'))
            ->from($this->db->quoteName('#__categories'))
            ->where($this->db->quoteName('extension') . ' = ' . $this->db->quote('com_content'))
            ->where($this->db->quoteName('published') . ' = 1');

        $this->db->setQuery($query);
        $categoryIds = array_map('intval', $this->db->loadColumn() ?: []);

        $actorCategoryIds = [];
        foreach ($categoryIds as $catId) {
            $effective = $this->resolve($catId);
            if ($effective !== null && $effective->isActor($userId)) {
                $actorCategoryIds[] = $catId;
            }
        }

        $this->cache?->setActorCategories($userId, $actorCategoryIds);

        return $actorCategoryIds;
    }
}
