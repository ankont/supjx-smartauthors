<?php

declare(strict_types=1);

namespace SuperSoft\Plugin\System\SmartAuthors\Domain\Entity;

defined('_JEXEC') or die;

use SuperSoft\Plugin\System\SmartAuthors\Domain\Enum\CategoryManagement;
use SuperSoft\Plugin\System\SmartAuthors\Domain\Enum\EditMode;
use SuperSoft\Plugin\System\SmartAuthors\Domain\Enum\PolicyType;
use SuperSoft\Plugin\System\SmartAuthors\Domain\Enum\StateMode;

final class EffectivePolicy
{
    /**
     * @param int $targetCategoryId
     * @param int $managedRootId
     * @param bool $isInherited
     * @param PolicyType $policyType
     * @param int|null $ownerId
     * @param list<int> $contributorIds
     * @param EditMode $editMode
     * @param StateMode $stateMode
     * @param CategoryManagement $categoryManagement
     */
    public function __construct(
        private readonly int $targetCategoryId,
        private readonly int $managedRootId,
        private readonly bool $isInherited,
        private readonly PolicyType $policyType,
        private readonly ?int $ownerId = null,
        private readonly array $contributorIds = [],
        private readonly EditMode $editMode = EditMode::Own,
        private readonly StateMode $stateMode = StateMode::None,
        private readonly CategoryManagement $categoryManagement = CategoryManagement::None,
    ) {
    }

    public static function fromCustomPolicy(Policy $policy, int $targetCategoryId): self
    {
        return new self(
            targetCategoryId: $targetCategoryId,
            managedRootId: $policy->getCategoryId(),
            isInherited: $policy->getCategoryId() !== $targetCategoryId,
            policyType: $policy->getPolicyType(),
            ownerId: $policy->getOwnerId(),
            contributorIds: $policy->getContributorIds(),
            editMode: $policy->getEditMode(),
            stateMode: $policy->getStateMode(),
            categoryManagement: $policy->getCategoryManagement(),
        );
    }

    public function getTargetCategoryId(): int
    {
        return $this->targetCategoryId;
    }

    public function getManagedRootId(): int
    {
        return $this->managedRootId;
    }

    public function getSourceCategoryId(): int
    {
        return $this->managedRootId;
    }

    public function isInherited(): bool
    {
        return $this->isInherited;
    }

    public function getPolicyType(): PolicyType
    {
        return $this->policyType;
    }

    public function isPersonal(): bool
    {
        return $this->policyType->isPersonal();
    }

    public function isCollaborative(): bool
    {
        return $this->policyType->isCollaborative();
    }

    public function getOwnerId(): ?int
    {
        return $this->ownerId;
    }

    /**
     * @return list<int>
     */
    public function getContributorIds(): array
    {
        return $this->contributorIds;
    }

    public function getEditMode(): EditMode
    {
        return $this->editMode;
    }

    public function getStateMode(): StateMode
    {
        return $this->stateMode;
    }

    public function getCategoryManagement(): CategoryManagement
    {
        return $this->categoryManagement;
    }

    /**
     * Checks if the given user is an effective actor in this policy.
     */
    public function isActor(int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }

        if ($this->policyType->isPersonal()) {
            return $this->ownerId !== null && $this->ownerId === $userId;
        }

        return \in_array($userId, $this->contributorIds, true);
    }
}
