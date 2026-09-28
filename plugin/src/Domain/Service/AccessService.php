<?php

declare(strict_types=1);

namespace SuperSoft\Plugin\System\SmartAuthors\Domain\Service;

defined('_JEXEC') or die;

use SuperSoft\Plugin\System\SmartAuthors\Domain\Entity\EffectivePolicy;
use SuperSoft\Plugin\System\SmartAuthors\Domain\Enum\StateMode;
use SuperSoft\Plugin\System\SmartAuthors\Service\PolicyResolver;

class AccessService
{
    public function __construct(
        private readonly PolicyResolver $resolver,
    ) {
    }

    /**
     * Checks if a user is an effective actor (Owner for Personal, Contributor for Collaborative)
     * for the given category under its effective policy.
     */
    public function isEffectiveActor(int $userId, int $categoryId): bool
    {
        if ($userId <= 0 || $categoryId <= 0) {
            return false;
        }

        $policy = $this->resolver->resolve($categoryId);
        if ($policy === null) {
            return false;
        }

        return $policy->isActor($userId);
    }

    /**
     * Checks if the user has contextual permission to create an article in this category.
     */
    public function canCreateArticle(int $userId, int $categoryId): bool
    {
        return $this->isEffectiveActor($userId, $categoryId);
    }

    /**
     * Checks if the user has contextual permission to edit the given article.
     *
     * @param int $userId
     * @param object|array<string, mixed> $article
     */
    public function canEditArticle(int $userId, object|array $article): bool
    {
        if ($userId <= 0) {
            return false;
        }

        $catid = (int) (is_array($article) ? ($article['catid'] ?? 0) : ($article->catid ?? 0));
        $createdBy = (int) (is_array($article) ? ($article['created_by'] ?? 0) : ($article->created_by ?? 0));

        if ($catid <= 0) {
            return false;
        }

        $policy = $this->resolver->resolve($catid);
        if ($policy === null || !$policy->isActor($userId)) {
            return false;
        }

        if ($policy->getEditMode()->allowsAll()) {
            return true;
        }

        return $policy->getEditMode()->allowsOwn() && $createdBy === $userId;
    }

    /**
     * Checks contextual state management (publish/unpublish/trash/restore).
     * Note: Does not grant Featured or permanent deletion.
     *
     * @param int $userId
     * @param object|array<string, mixed> $article
     */
    public function canManageArticleState(int $userId, object|array $article): bool
    {
        if ($userId <= 0) {
            return false;
        }

        $catid = (int) (is_array($article) ? ($article['catid'] ?? 0) : ($article->catid ?? 0));
        $createdBy = (int) (is_array($article) ? ($article['created_by'] ?? 0) : ($article->created_by ?? 0));

        if ($catid <= 0) {
            return false;
        }

        $policy = $this->resolver->resolve($catid);
        if ($policy === null || !$policy->isActor($userId)) {
            return false;
        }

        if ($policy->getStateMode()->isNone()) {
            return false;
        }

        if ($policy->getStateMode()->allowsAll()) {
            return true;
        }

        return $policy->getStateMode()->allowsOwn() && $createdBy === $userId;
    }

    /**
     * Checks if the actor can trash or restore a draft article.
     *
     * - If canManageArticleState is true, trashing is allowed.
     * - If StateMode::NONE, an actor with edit permission can manage draft states (0 Unpublished, -2 Trashed).
     *   When EditMode is ALL, this applies to any unpublished draft in the category.
     *   When EditMode is OWN, this applies only to their own drafts.
     *
     * @param int $userId
     * @param object|array<string, mixed> $article
     */
    public function canTrashArticle(int $userId, object|array $article): bool
    {
        if ($this->canManageArticleState($userId, $article)) {
            return true;
        }

        $state = (int) (is_array($article) ? ($article['state'] ?? 0) : ($article->state ?? 0));

        return in_array($state, [0, -2], true)
            && $this->canEditArticle($userId, $article);
    }

    /**
     * Checks if the actor can transition the article to a specific target state.
     *
     * - Public states: 1 (Published) and 2 (Archived - publicly accessible via /archive or direct URL)
     *   require full state management permission (canManageArticleState).
     * - Private draft states: 0 (Unpublished) and -2 (Trashed) can be transitioned between
     *   by any actor who has edit permission for this article (e.g. category manager when EditMode is ALL).
     *
     * @param int $userId
     * @param object|array<string, mixed> $article
     * @param int $targetState
     */
    public function canTransitionState(int $userId, object|array $article, int $targetState): bool
    {
        if ($this->canManageArticleState($userId, $article)) {
            return true;
        }

        $currentState = (int) (is_array($article) ? ($article['state'] ?? 0) : ($article->state ?? 0));

        return in_array($currentState, [0, -2], true)
            && in_array($targetState, [0, -2], true)
            && $this->canEditArticle($userId, $article);
    }

    /**
     * Checks if the actor can manage state and scheduling when creating a NEW article.
     *
     * StateMode::NONE => false (new article is forced to unpublished state=0, fields locked)
     * StateMode::OWN  => true  (actor can choose initial state/scheduling since article is their own)
     * StateMode::ALL  => true  (actor can choose initial state/scheduling)
     */
    public function canManageNewArticleState(int $userId, int $categoryId): bool
    {
        if ($userId <= 0 || $categoryId <= 0) {
            return false;
        }

        $policy = $this->resolver->resolve($categoryId);
        if ($policy === null || !$policy->isActor($userId)) {
            return false;
        }

        return match ($policy->getStateMode()) {
            StateMode::Own, StateMode::All => true,
            StateMode::None                => false,
        };
    }

    /**
     * Checks if the user can manage this category (descendant of Managed Root).
     */
    public function canManageCategory(int $userId, int $categoryId): bool
    {
        if ($userId <= 0 || $categoryId <= 0) {
            return false;
        }

        $policy = $this->resolver->resolve($categoryId);
        if ($policy === null || !$policy->isActor($userId)) {
            return false;
        }

        return $policy->getCategoryManagement()->allowsSubtree();
    }

    /**
     * Checks if the user can create a child category under parentCategoryId.
     */
    public function canCreateChildCategory(int $userId, int $parentCategoryId): bool
    {
        return $this->canManageCategory($userId, $parentCategoryId);
    }

    /**
     * Checks if the user can move categoryId to destinationParentId.
     *
     * Subtree security invariants:
     * - Source category cannot be the Managed Root itself.
     * - Source category must be in the managed subtree.
     * - Destination parent category must also be within the SAME managed subtree.
     * - User must be effective actor and category_management == subtree.
     */
    public function canMoveCategory(int $userId, int $categoryId, int $destinationParentId): bool
    {
        if ($userId <= 0 || $categoryId <= 0 || $destinationParentId <= 0) {
            return false;
        }

        $sourcePolicy = $this->resolver->resolve($categoryId);
        if ($sourcePolicy === null || !$sourcePolicy->isActor($userId)) {
            return false;
        }

        if (!$sourcePolicy->getCategoryManagement()->allowsSubtree()) {
            return false;
        }

        // Subtree security invariant: CANNOT move Managed Root itself via SmartAuthors
        if ($categoryId === $sourcePolicy->getManagedRootId()) {
            return false;
        }

        // Destination parent must resolve to the SAME Managed Root
        $destPolicy = $this->resolver->resolve($destinationParentId);
        if ($destPolicy === null) {
            return false;
        }

        return $destPolicy->getManagedRootId() === $sourcePolicy->getManagedRootId();
    }

    /**
     * Returns the Managed Root category ID for the given category, if any.
     */
    public function getManagedRoot(int $categoryId): ?int
    {
        return $this->resolver->resolve($categoryId)?->getManagedRootId();
    }

    /**
     * Returns the resolved effective policy for inspection.
     */
    public function getEffectivePolicy(int $categoryId): ?EffectivePolicy
    {
        return $this->resolver->resolve($categoryId);
    }

    /**
     * Returns all content category IDs where the user is an effective actor.
     *
     * @return array<int>
     */
    public function getEffectiveActorCategoryIds(int $userId): array
    {
        return $this->resolver->getEffectiveActorCategoryIds($userId);
    }

    /**
     * Checks if the user is an effective actor with create permissions in at least one category.
     */
    public function canCreateInAnyCategory(int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }

        return !empty($this->getEffectiveActorCategoryIds($userId));
    }
}

