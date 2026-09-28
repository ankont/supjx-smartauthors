<?php

declare(strict_types=1);

namespace SuperSoft\Plugin\System\SmartAuthors\Tests\Domain;

defined('_JEXEC') or die;

use SuperSoft\Plugin\System\SmartAuthors\Domain\Entity\Policy;
use SuperSoft\Plugin\System\SmartAuthors\Domain\Enum\PolicyType;
use SuperSoft\Plugin\System\SmartAuthors\Service\PolicyResolver;
use SuperSoft\Plugin\System\SmartAuthors\Service\RequestCache;
use SuperSoft\Plugin\System\SmartAuthors\Tests\Mocks\InMemoryPolicyRepository;

class PolicyResolverTest
{
    public function runAll(): void
    {
        $this->testPolicyInheritance();
        $this->testPolicyOverride();
        $this->testReturnToInherit();
        $this->testNoPolicyInAncestry();
    }

    /**
     * Acceptance Test A: POLICY INHERITANCE
     * Category 10 = Custom Collaborative, Contributor user 5
     * Category 11 child of 10
     * Category 12 child of 11
     * resolve(12): sourceCategoryId = 10, user 5 = effective actor
     */
    public function testPolicyInheritance(): void
    {
        $cache = new RequestCache();
        $repo = new InMemoryPolicyRepository($cache);
        $resolver = new PolicyResolver($repo, null, $cache);

        // Setup tree: 12 -> 11 -> 10 -> 1 (root)
        $resolver->setCategoryParent(12, 11);
        $resolver->setCategoryParent(11, 10);
        $resolver->setCategoryParent(10, 1);

        // Category 10 has Custom Collaborative policy with contributor user 5
        $policy10 = new Policy(
            categoryId: 10,
            policyType: PolicyType::Collaborative,
            contributorIds: [5]
        );
        $repo->savePolicy($policy10);

        $effective = $resolver->resolve(12);

        assert($effective !== null, 'Effective policy for category 12 should not be null');
        assert($effective->getSourceCategoryId() === 10, 'Source category ID should be 10');
        assert($effective->getManagedRootId() === 10, 'Managed root ID should be 10');
        assert($effective->isInherited() === true, 'Policy should be marked as inherited');
        assert($effective->isActor(5) === true, 'User 5 should be an effective actor');
        assert($effective->isActor(6) === false, 'User 6 should not be an effective actor');

        echo "  [PASS] Acceptance Test A: Policy Inheritance\n";
    }

    /**
     * Acceptance Test B: POLICY OVERRIDE
     * Category 11 acquires Custom policy.
     * resolve(12): sourceCategoryId = 11, not 10.
     */
    public function testPolicyOverride(): void
    {
        $cache = new RequestCache();
        $repo = new InMemoryPolicyRepository($cache);
        $resolver = new PolicyResolver($repo, null, $cache);

        $resolver->setCategoryParent(12, 11);
        $resolver->setCategoryParent(11, 10);
        $resolver->setCategoryParent(10, 1);

        $policy10 = new Policy(
            categoryId: 10,
            policyType: PolicyType::Collaborative,
            contributorIds: [5]
        );
        $repo->savePolicy($policy10);

        // Category 11 overrides with its own Custom policy (contributor 8)
        $policy11 = new Policy(
            categoryId: 11,
            policyType: PolicyType::Collaborative,
            contributorIds: [8]
        );
        $repo->savePolicy($policy11);

        $effective = $resolver->resolve(12);

        assert($effective !== null, 'Effective policy for category 12 should not be null');
        assert($effective->getSourceCategoryId() === 11, 'Source category ID should be 11, not 10');
        assert($effective->isActor(8) === true, 'User 8 should be an effective actor');
        assert($effective->isActor(5) === false, 'User 5 should not be an effective actor after override');

        echo "  [PASS] Acceptance Test B: Policy Override\n";
    }

    /**
     * Acceptance Test C: RETURN TO INHERIT
     * Category 11 policy is deleted / becomes Inherit.
     * resolve(12): sourceCategoryId = 10.
     */
    public function testReturnToInherit(): void
    {
        $cache = new RequestCache();
        $repo = new InMemoryPolicyRepository($cache);
        $resolver = new PolicyResolver($repo, null, $cache);

        $resolver->setCategoryParent(12, 11);
        $resolver->setCategoryParent(11, 10);
        $resolver->setCategoryParent(10, 1);

        $policy10 = new Policy(
            categoryId: 10,
            policyType: PolicyType::Collaborative,
            contributorIds: [5]
        );
        $repo->savePolicy($policy10);

        $policy11 = new Policy(
            categoryId: 11,
            policyType: PolicyType::Collaborative,
            contributorIds: [8]
        );
        $repo->savePolicy($policy11);

        // Delete policy on 11 (returns to Inherit)
        $repo->deletePolicy(11);

        $effective = $resolver->resolve(12);

        assert($effective !== null, 'Effective policy for category 12 should not be null');
        assert($effective->getSourceCategoryId() === 10, 'Source category ID should return to 10');
        assert($effective->isActor(5) === true, 'User 5 should again be an effective actor');
        assert($effective->isActor(8) === false, 'User 8 should no longer be an effective actor');

        echo "  [PASS] Acceptance Test C: Return to Inherit\n";
    }

    /**
     * Acceptance Test R: NO POLICY
     * No Custom policy in ancestry.
     * resolve(category) = null, SmartAuthors contextual permissions = false.
     */
    public function testNoPolicyInAncestry(): void
    {
        $cache = new RequestCache();
        $repo = new InMemoryPolicyRepository($cache);
        $resolver = new PolicyResolver($repo, null, $cache);

        $resolver->setCategoryParent(50, 40);
        $resolver->setCategoryParent(40, 1);

        $effective = $resolver->resolve(50);
        assert($effective === null, 'Effective policy should be null when no policy in ancestry');

        echo "  [PASS] Acceptance Test R: No Policy in Ancestry\n";
    }
}
