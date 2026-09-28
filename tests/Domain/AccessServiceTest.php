<?php

declare(strict_types=1);

namespace SuperSoft\Plugin\System\SmartAuthors\Tests\Domain;

defined('_JEXEC') or die;

use SuperSoft\Plugin\System\SmartAuthors\Domain\Entity\Policy;
use SuperSoft\Plugin\System\SmartAuthors\Domain\Enum\CategoryManagement;
use SuperSoft\Plugin\System\SmartAuthors\Domain\Enum\EditMode;
use SuperSoft\Plugin\System\SmartAuthors\Domain\Enum\PolicyType;
use SuperSoft\Plugin\System\SmartAuthors\Domain\Enum\StateMode;
use SuperSoft\Plugin\System\SmartAuthors\Domain\Service\AccessService;
use SuperSoft\Plugin\System\SmartAuthors\Service\PolicyResolver;
use SuperSoft\Plugin\System\SmartAuthors\Service\RequestCache;
use SuperSoft\Plugin\System\SmartAuthors\Tests\Mocks\InMemoryPolicyRepository;

class AccessServiceTest
{
    public function runAll(): void
    {
        $this->testCollaborativeContributors();
        $this->testPersonalOwner();
        $this->testEditOwn();
        $this->testEditAll();
        $this->testStateModes();
        $this->testCategorySubtreeManagement();
    }

    /**
     * Acceptance Test D: COLLABORATIVE CONTRIBUTORS
     */
    public function testCollaborativeContributors(): void
    {
        $cache = new RequestCache();
        $repo = new InMemoryPolicyRepository($cache);
        $resolver = new PolicyResolver($repo, null, $cache);
        $access = new AccessService($resolver);

        $resolver->setCategoryParent(12, 11);
        $resolver->setCategoryParent(11, 10);
        $resolver->setCategoryParent(10, 1);

        $policy = new Policy(
            categoryId: 10,
            policyType: PolicyType::Collaborative,
            contributorIds: [5, 6]
        );
        $repo->savePolicy($policy);

        assert($access->isEffectiveActor(5, 12) === true, 'User 5 should be effective actor in 12');
        assert($access->isEffectiveActor(6, 12) === true, 'User 6 should be effective actor in 12');
        assert($access->isEffectiveActor(7, 12) === false, 'User 7 should not be effective actor in 12');

        echo "  [PASS] Acceptance Test D: Collaborative Contributors\n";
    }

    /**
     * Acceptance Test E: PERSONAL OWNER
     */
    public function testPersonalOwner(): void
    {
        $cache = new RequestCache();
        $repo = new InMemoryPolicyRepository($cache);
        $resolver = new PolicyResolver($repo, null, $cache);
        $access = new AccessService($resolver);

        $resolver->setCategoryParent(21, 20);
        $resolver->setCategoryParent(20, 1);

        $policy = new Policy(
            categoryId: 20,
            policyType: PolicyType::Personal,
            ownerId: 5
        );
        $repo->savePolicy($policy);

        assert($access->isEffectiveActor(5, 20) === true, 'User 5 should be effective actor for managed root 20');
        assert($access->isEffectiveActor(5, 21) === true, 'User 5 should be effective actor for child 21');
        assert($access->isEffectiveActor(6, 21) === false, 'User 6 should not be effective actor for child 21');

        echo "  [PASS] Acceptance Test E: Personal Owner\n";
    }

    /**
     * Acceptance Test F: EDIT OWN
     */
    public function testEditOwn(): void
    {
        $cache = new RequestCache();
        $repo = new InMemoryPolicyRepository($cache);
        $resolver = new PolicyResolver($repo, null, $cache);
        $access = new AccessService($resolver);

        $resolver->setCategoryParent(31, 30);
        $resolver->setCategoryParent(30, 1);

        $policy = new Policy(
            categoryId: 30,
            policyType: PolicyType::Collaborative,
            contributorIds: [5],
            editMode: EditMode::Own
        );
        $repo->savePolicy($policy);

        $ownArticle = (object) ['catid' => 31, 'created_by' => 5];
        $otherArticle = (object) ['catid' => 31, 'created_by' => 7];

        assert($access->canEditArticle(5, $ownArticle) === true, 'User 5 should be able to edit own article');
        assert($access->canEditArticle(5, $otherArticle) === false, 'User 5 should not be able to edit article by user 7');
        assert($access->canEditArticle(7, $otherArticle) === false, 'User 7 is not an effective actor so cannot edit via SmartAuthors');

        echo "  [PASS] Acceptance Test F: Edit Own\n";
    }

    /**
     * Acceptance Test G: EDIT ALL
     */
    public function testEditAll(): void
    {
        $cache = new RequestCache();
        $repo = new InMemoryPolicyRepository($cache);
        $resolver = new PolicyResolver($repo, null, $cache);
        $access = new AccessService($resolver);

        $resolver->setCategoryParent(31, 30);
        $resolver->setCategoryParent(30, 1);

        $policy = new Policy(
            categoryId: 30,
            policyType: PolicyType::Personal,
            ownerId: 5,
            editMode: EditMode::All
        );
        $repo->savePolicy($policy);

        $anyArticle = (object) ['catid' => 31, 'created_by' => 99];

        assert($access->canEditArticle(5, $anyArticle) === true, 'User 5 (edit_mode=all) should be able to edit any article in subtree');
        assert($access->canEditArticle(99, $anyArticle) === false, 'User 99 is not an effective actor in personal policy');

        echo "  [PASS] Acceptance Test G: Edit All\n";
    }

    /**
     * Acceptance Test H: STATE
     */
    public function testStateModes(): void
    {
        $cache = new RequestCache();
        $repo = new InMemoryPolicyRepository($cache);
        $resolver = new PolicyResolver($repo, null, $cache);
        $access = new AccessService($resolver);

        $resolver->setCategoryParent(41, 40);
        $resolver->setCategoryParent(40, 1);

        $ownArticle = (object) ['catid' => 41, 'created_by' => 5];
        $otherArticle = (object) ['catid' => 41, 'created_by' => 7];

        // 1. None
        $repo->savePolicy(new Policy(categoryId: 40, policyType: PolicyType::Personal, ownerId: 5, stateMode: StateMode::None));
        $cache->invalidateAll();
        assert($access->canManageArticleState(5, $ownArticle) === false, 'State mode None should disallow state management');
        assert($access->canManageArticleState(5, $otherArticle) === false, 'State mode None should disallow state management');

        // 2. Own
        $repo->savePolicy(new Policy(categoryId: 40, policyType: PolicyType::Personal, ownerId: 5, stateMode: StateMode::Own));
        $cache->invalidateAll();
        assert($access->canManageArticleState(5, $ownArticle) === true, 'State mode Own should allow own article');
        assert($access->canManageArticleState(5, $otherArticle) === false, 'State mode Own should disallow other article');

        // 3. All
        $repo->savePolicy(new Policy(categoryId: 40, policyType: PolicyType::Personal, ownerId: 5, stateMode: StateMode::All));
        $cache->invalidateAll();
        assert($access->canManageArticleState(5, $ownArticle) === true, 'State mode All should allow own article');
        assert($access->canManageArticleState(5, $otherArticle) === true, 'State mode All should allow other article');

        echo "  [PASS] Acceptance Test H: State Modes\n";
    }

    /**
     * Acceptance Test I: CATEGORY SUBTREE
     */
    public function testCategorySubtreeManagement(): void
    {
        $cache = new RequestCache();
        $repo = new InMemoryPolicyRepository($cache);
        $resolver = new PolicyResolver($repo, null, $cache);
        $access = new AccessService($resolver);

        // Subtree 10: 10 (managed root) -> 11 (descendant A) -> 12 (descendant B)
        $resolver->setCategoryParent(12, 11);
        $resolver->setCategoryParent(11, 10);
        $resolver->setCategoryParent(10, 1);

        // Unrelated subtree 90 -> 1
        $resolver->setCategoryParent(90, 1);

        $policy = new Policy(
            categoryId: 10,
            policyType: PolicyType::Personal,
            ownerId: 5,
            categoryManagement: CategoryManagement::Subtree
        );
        $repo->savePolicy($policy);

        // canCreateChildCategory
        assert($access->canCreateChildCategory(5, 11) === true, 'Actor can create child under descendant');
        assert($access->canCreateChildCategory(5, 90) === false, 'Actor cannot create child under unrelated category');

        // canMoveCategory:
        // canMove(descendant A, descendant B) = true
        assert($access->canMoveCategory(5, 12, 10) === true, 'Can move descendant to another node in same subtree');
        assert($access->canMoveCategory(5, 12, 11) === true, 'Can move descendant within same subtree');

        // canMove(descendant A, unrelated) = false
        assert($access->canMoveCategory(5, 12, 90) === false, 'Cannot move descendant outside managed subtree');

        // canMove(managed root, descendant) = false
        assert($access->canMoveCategory(5, 10, 11) === false, 'Managed Root itself CANNOT be moved via SmartAuthors');

        echo "  [PASS] Acceptance Test I: Category Subtree Management\n";
    }
}
