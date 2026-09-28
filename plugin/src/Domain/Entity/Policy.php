<?php

declare(strict_types=1);

namespace SuperSoft\Plugin\System\SmartAuthors\Domain\Entity;

defined('_JEXEC') or die;

use InvalidArgumentException;
use SuperSoft\Plugin\System\SmartAuthors\Domain\Enum\CategoryManagement;
use SuperSoft\Plugin\System\SmartAuthors\Domain\Enum\EditMode;
use SuperSoft\Plugin\System\SmartAuthors\Domain\Enum\PolicyType;
use SuperSoft\Plugin\System\SmartAuthors\Domain\Enum\StateMode;

final class Policy
{
    /**
     * @param int $categoryId
     * @param PolicyType $policyType
     * @param int|null $ownerId
     * @param list<int> $contributorIds
     * @param EditMode $editMode
     * @param StateMode $stateMode
     * @param CategoryManagement $categoryManagement
     */
    public function __construct(
        private readonly int $categoryId,
        private readonly PolicyType $policyType,
        private readonly ?int $ownerId = null,
        private readonly array $contributorIds = [],
        private readonly EditMode $editMode = EditMode::Own,
        private readonly StateMode $stateMode = StateMode::None,
        private readonly CategoryManagement $categoryManagement = CategoryManagement::None,
    ) {
        $this->assertInvariants();
    }

    private function assertInvariants(): void
    {
        if ($this->categoryId <= 0) {
            throw new InvalidArgumentException("Category ID must be a positive integer, got {$this->categoryId}");
        }

        if ($this->policyType->isPersonal()) {
            if (!empty($this->contributorIds)) {
                throw new InvalidArgumentException('Personal policy cannot have contributors.');
            }
            if ($this->ownerId !== null && $this->ownerId <= 0) {
                throw new InvalidArgumentException("Owner ID must be a positive integer or null, got {$this->ownerId}");
            }
        } elseif ($this->policyType->isCollaborative()) {
            if ($this->ownerId !== null) {
                throw new InvalidArgumentException('Collaborative policy cannot have an owner_id.');
            }
            foreach ($this->contributorIds as $id) {
                if ($id <= 0) {
                    throw new InvalidArgumentException("Contributor ID must be a positive integer, got {$id}");
                }
            }
        }
    }

    public function getCategoryId(): int
    {
        return $this->categoryId;
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

    public function isBound(): bool
    {
        return $this->isPersonal() && $this->ownerId !== null;
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
}
