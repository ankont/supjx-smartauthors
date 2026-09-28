<?php

declare(strict_types=1);

namespace SuperSoft\Plugin\System\SmartAuthors\Tests\Mocks;

defined('_JEXEC') or die;

use SuperSoft\Plugin\System\SmartAuthors\Domain\Entity\Policy;
use SuperSoft\Plugin\System\SmartAuthors\Domain\Enum\PolicyType;
use SuperSoft\Plugin\System\SmartAuthors\Repository\PolicyRepository;
use SuperSoft\Plugin\System\SmartAuthors\Service\RequestCache;

final class InMemoryPolicyRepository extends PolicyRepository
{
    /** @var array<int, Policy> */
    private array $policies = [];

    /** @var array<int, list<int>> */
    private array $contributors = [];

    public function __construct(?RequestCache $cache = null)
    {
        // Don't call parent constructor needing real DatabaseInterface
    }

    public function loadPolicy(int $categoryId): ?Policy
    {
        return $this->policies[$categoryId] ?? null;
    }

    public function savePolicy(Policy $policy): void
    {
        $categoryId = $policy->getCategoryId();
        $this->policies[$categoryId] = $policy;

        if ($policy->isPersonal()) {
            unset($this->contributors[$categoryId]);
        } else {
            $this->contributors[$categoryId] = $policy->getContributorIds();
        }
    }

    public function deletePolicy(int $categoryId): void
    {
        unset($this->policies[$categoryId]);
        unset($this->contributors[$categoryId]);
    }

    public function loadOwner(int $categoryId): ?int
    {
        return ($this->policies[$categoryId] ?? null)?->getOwnerId();
    }

    public function saveOwner(int $categoryId, ?int $ownerId): void
    {
        if (isset($this->policies[$categoryId])) {
            $p = $this->policies[$categoryId];
            if ($p->isPersonal()) {
                $this->policies[$categoryId] = new Policy(
                    categoryId: $p->getCategoryId(),
                    policyType: $p->getPolicyType(),
                    ownerId: $ownerId,
                    contributorIds: [],
                    editMode: $p->getEditMode(),
                    stateMode: $p->getStateMode(),
                    categoryManagement: $p->getCategoryManagement()
                );
            }
        }
    }

    public function loadContributors(int $categoryId): array
    {
        return $this->contributors[$categoryId] ?? [];
    }

    public function saveContributors(int $categoryId, array $contributorIds): void
    {
        $this->contributors[$categoryId] = array_values(array_unique(array_filter($contributorIds, static fn($id) => $id > 0)));
    }

    public function deleteContributors(int $categoryId): void
    {
        unset($this->contributors[$categoryId]);
    }

    public function findPoliciesByContributor(int $userId): array
    {
        $matching = [];
        foreach ($this->contributors as $catId => $users) {
            if (\in_array($userId, $users, true)) {
                $matching[] = $catId;
            }
        }
        return $matching;
    }

    public function findPersonalPoliciesByOwner(int $userId): array
    {
        $matching = [];
        foreach ($this->policies as $catId => $policy) {
            if ($policy->isPersonal() && $policy->getOwnerId() === $userId) {
                $matching[] = $catId;
            }
        }
        return $matching;
    }

    public function findUnboundPersonalPolicies(): array
    {
        $matching = [];
        foreach ($this->policies as $catId => $policy) {
            if ($policy->isPersonal() && $policy->getOwnerId() === null) {
                $matching[] = $catId;
            }
        }
        return $matching;
    }
}
