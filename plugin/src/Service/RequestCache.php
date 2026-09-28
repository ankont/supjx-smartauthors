<?php

declare(strict_types=1);

namespace SuperSoft\Plugin\System\SmartAuthors\Service;

defined('_JEXEC') or die;

use SuperSoft\Plugin\System\SmartAuthors\Domain\Entity\EffectivePolicy;
use SuperSoft\Plugin\System\SmartAuthors\Domain\Entity\Policy;

final class RequestCache
{
    /** @var array<int, Policy|null> */
    private array $policies = [];

    /** @var array<int, EffectivePolicy|null> */
    private array $effectivePolicies = [];

    /** @var array<int, list<int>> */
    private array $ownerCategories = [];

    /** @var array<int, list<int>> */
    private array $actorCategories = [];

    /** @var array<int, list<int>> */
    private array $categoryAncestors = [];

    public function hasPolicy(int $categoryId): bool
    {
        return \array_key_exists($categoryId, $this->policies);
    }

    public function getPolicy(int $categoryId): ?Policy
    {
        return $this->policies[$categoryId] ?? null;
    }

    public function setPolicy(int $categoryId, ?Policy $policy): void
    {
        $this->policies[$categoryId] = $policy;
    }

    public function hasEffectivePolicy(int $categoryId): bool
    {
        return \array_key_exists($categoryId, $this->effectivePolicies);
    }

    public function getEffectivePolicy(int $categoryId): ?EffectivePolicy
    {
        return $this->effectivePolicies[$categoryId] ?? null;
    }

    public function setEffectivePolicy(int $categoryId, ?EffectivePolicy $policy): void
    {
        $this->effectivePolicies[$categoryId] = $policy;
    }

    /**
     * @return list<int>|null
     */
    public function getOwnerCategories(int $userId): ?array
    {
        return $this->ownerCategories[$userId] ?? null;
    }

    /**
     * @param int $userId
     * @param list<int> $categories
     */
    public function setOwnerCategories(int $userId, array $categories): void
    {
        $this->ownerCategories[$userId] = $categories;
    }

    public function hasActorCategories(int $userId): bool
    {
        return \array_key_exists($userId, $this->actorCategories);
    }

    /**
     * @return list<int>|null
     */
    public function getActorCategories(int $userId): ?array
    {
        return $this->actorCategories[$userId] ?? null;
    }

    /**
     * @param int $userId
     * @param list<int> $categories
     */
    public function setActorCategories(int $userId, array $categories): void
    {
        $this->actorCategories[$userId] = $categories;
    }

    /**
     * @return list<int>|null
     */
    public function getCategoryAncestors(int $categoryId): ?array
    {
        return $this->categoryAncestors[$categoryId] ?? null;
    }

    /**
     * @param int $categoryId
     * @param list<int> $ancestorIds
     */
    public function setCategoryAncestors(int $categoryId, array $ancestorIds): void
    {
        $this->categoryAncestors[$categoryId] = $ancestorIds;
    }

    public function invalidateCategory(int $categoryId): void
    {
        unset($this->policies[$categoryId]);
        unset($this->effectivePolicies[$categoryId]);
        unset($this->categoryAncestors[$categoryId]);

        // When a category policy changes, descendants' effective policies may change, so clear effective policies
        $this->effectivePolicies = [];
        $this->ownerCategories = [];
        $this->actorCategories = [];
    }

    public function invalidateAll(): void
    {
        $this->policies = [];
        $this->effectivePolicies = [];
        $this->ownerCategories = [];
        $this->actorCategories = [];
        $this->categoryAncestors = [];
    }
}
