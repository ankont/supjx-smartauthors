<?php

declare(strict_types=1);

namespace SuperSoft\Plugin\System\SmartAuthors\Tests\Domain;

defined('_JEXEC') or die;

use SuperSoft\Plugin\System\SmartAuthors\Domain\Entity\Policy;
use SuperSoft\Plugin\System\SmartAuthors\Domain\Enum\PolicyType;
use SuperSoft\Plugin\System\SmartAuthors\Service\RequestCache;
use SuperSoft\Plugin\System\SmartAuthors\Tests\Mocks\InMemoryPolicyRepository;
use SuperSoft\Plugin\System\SmartAuthors\Tests\Mocks\MockCategoryTable;
use SuperSoft\Plugin\System\SmartAuthors\Tests\Mocks\TestablePersonalOwnerBinder;

class PersonalOwnerBinderTest
{
    public function runAll(): void
    {
        $this->testUnboundPersonalCategoryUserExists();
        $this->testUnboundPersonalCategoryUserDoesNotExistYet();
        $this->testPersonalOwnerUsernameChange();
        $this->testAliasSyncFailureCollision();
        $this->testCollaborativeUsernameChangeNoop();
        $this->testDescendantDoesNotAutoBind();
        $this->testPersonalToCollaborativeTransition();
        $this->testCollaborativeToPersonalTransition();
    }

    /**
     * Acceptance Test J: UNBOUND PERSONAL CATEGORY - USER EXISTS
     * Also tests USER CORRECTION 4: searches existing user regardless of whether blocked!
     */
    public function testUnboundPersonalCategoryUserExists(): void
    {
        $cache = new RequestCache();
        $repo = new InMemoryPolicyRepository($cache);
        $binder = new TestablePersonalOwnerBinder($repo, $cache);

        MockCategoryTable::resetRegistry();
        TestablePersonalOwnerBinder::resetUsers();

        // Category 20, alias = akontarinis, Personal, owner_id = NULL
        MockCategoryTable::seed(20, ['alias' => 'akontarinis']);
        $repo->savePolicy(new Policy(categoryId: 20, policyType: PolicyType::Personal, ownerId: null));

        // Seed user 5 with username 'akontarinis', even if blocked = 1
        TestablePersonalOwnerBinder::seedUser(5, 'akontarinis', block: 1);

        $boundId = $binder->reconcileUnboundCategory(20);

        assert($boundId === 5, 'User 5 should have been bound to category 20');
        assert($repo->loadOwner(20) === 5, 'Persisted owner_id should be 5');

        echo "  [PASS] Acceptance Test J: Unbound Personal Category - User Exists (including blocked user)\n";
    }

    /**
     * Acceptance Test K: UNBOUND PERSONAL CATEGORY - USER DOES NOT EXIST YET
     */
    public function testUnboundPersonalCategoryUserDoesNotExistYet(): void
    {
        $cache = new RequestCache();
        $repo = new InMemoryPolicyRepository($cache);
        $binder = new TestablePersonalOwnerBinder($repo, $cache);

        MockCategoryTable::resetRegistry();
        TestablePersonalOwnerBinder::resetUsers();

        MockCategoryTable::seed(25, ['alias' => 'futureteacher']);
        $repo->savePolicy(new Policy(categoryId: 25, policyType: PolicyType::Personal, ownerId: null));

        // First attempt: no user exists yet
        $binder->reconcileUnboundCategory(25);
        assert($repo->loadOwner(25) === null, 'Owner must remain null when user does not exist');

        // Later: user "futureteacher" is created
        TestablePersonalOwnerBinder::seedUser(42, 'futureteacher');
        $boundCategories = $binder->reconcileNewUser(42, 'futureteacher');

        assert(\in_array(25, $boundCategories, true), 'Category 25 should be in bound categories list');
        assert($repo->loadOwner(25) === 42, 'Owner should now be 42');

        echo "  [PASS] Acceptance Test K: Unbound Personal Category - User Created Later\n";
    }

    /**
     * Acceptance Test L: PERSONAL OWNER USERNAME CHANGE
     */
    public function testPersonalOwnerUsernameChange(): void
    {
        $cache = new RequestCache();
        $repo = new InMemoryPolicyRepository($cache);
        $binder = new TestablePersonalOwnerBinder($repo, $cache);

        MockCategoryTable::resetRegistry();
        TestablePersonalOwnerBinder::resetUsers();

        MockCategoryTable::seed(20, ['alias' => 'akontarinis', 'parent_id' => 1, 'path' => 'akontarinis']);
        $repo->savePolicy(new Policy(categoryId: 20, policyType: PolicyType::Personal, ownerId: 5));
        TestablePersonalOwnerBinder::seedUser(5, 'akontarinis');

        // Username changes akontarinis -> andreas
        $binder->recordOldUsername(5, 'akontarinis');
        TestablePersonalOwnerBinder::$users[5]['username'] = 'andreas';
        $binder->onUsernameChanged(5, 'andreas');

        // Expected: owner binding remains (20 -> 5)
        assert($repo->loadOwner(20) === 5, 'Owner binding must remain user 5');

        // Expected: category alias becomes andreas
        $category = MockCategoryTable::$registry[20];
        assert($category['alias'] === 'andreas', "Category alias should be updated to 'andreas', got {$category['alias']}");

        echo "  [PASS] Acceptance Test L: Personal Owner Username Change\n";
    }

    /**
     * Acceptance Test M: ALIAS SYNC FAILURE (COLLISION)
     */
    public function testAliasSyncFailureCollision(): void
    {
        $cache = new RequestCache();
        $repo = new InMemoryPolicyRepository($cache);
        $binder = new TestablePersonalOwnerBinder($repo, $cache);

        MockCategoryTable::resetRegistry();
        TestablePersonalOwnerBinder::resetUsers();

        // Sibling category 19 already has alias 'andreas' under parent 1
        MockCategoryTable::seed(19, ['alias' => 'andreas', 'parent_id' => 1]);

        // Category 20 belongs to user 5 with alias 'akontarinis' under parent 1
        MockCategoryTable::seed(20, ['alias' => 'akontarinis', 'parent_id' => 1]);
        $repo->savePolicy(new Policy(categoryId: 20, policyType: PolicyType::Personal, ownerId: 5));

        // User 5 attempts to change username to 'andreas' (collides with sibling 19)
        $binder->recordOldUsername(5, 'akontarinis');
        $binder->onUsernameChanged(5, 'andreas');

        // Expected: owner binding remains 5
        assert($repo->loadOwner(20) === 5, 'Owner binding must remain intact');

        // Expected: category 20 remains valid with original alias 'akontarinis'
        $cat20 = MockCategoryTable::$registry[20];
        assert($cat20['alias'] === 'akontarinis', 'Category alias must not change on collision');

        echo "  [PASS] Acceptance Test M: Alias Sync Failure Handled Predictably\n";
    }

    /**
     * Acceptance Test N: COLLABORATIVE USERNAME CHANGE
     */
    public function testCollaborativeUsernameChangeNoop(): void
    {
        $cache = new RequestCache();
        $repo = new InMemoryPolicyRepository($cache);
        $binder = new TestablePersonalOwnerBinder($repo, $cache);

        MockCategoryTable::resetRegistry();
        TestablePersonalOwnerBinder::resetUsers();

        // Category 30 has Collaborative policy with contributors [5, 6]
        MockCategoryTable::seed(30, ['alias' => 'erasmus-plus']);
        $repo->savePolicy(new Policy(categoryId: 30, policyType: PolicyType::Collaborative, contributorIds: [5, 6]));

        TestablePersonalOwnerBinder::seedUser(5, 'user5');
        TestablePersonalOwnerBinder::seedUser(6, 'user6');

        // User 5 changes username
        $binder->recordOldUsername(5, 'user5');
        $binder->onUsernameChanged(5, 'new-user5');

        // Category alias MUST NOT change
        assert(MockCategoryTable::$registry[30]['alias'] === 'erasmus-plus', 'Collaborative category alias must not change when contributor changes username');

        echo "  [PASS] Acceptance Test N: Collaborative Username Change Has No Effect on Category Alias\n";
    }

    /**
     * Acceptance Test O: DESCENDANT DOES NOT AUTO-BIND
     */
    public function testDescendantDoesNotAutoBind(): void
    {
        $cache = new RequestCache();
        $repo = new InMemoryPolicyRepository($cache);
        $binder = new TestablePersonalOwnerBinder($repo, $cache);

        MockCategoryTable::resetRegistry();
        TestablePersonalOwnerBinder::resetUsers();

        // Source Category 20: Personal Custom, owner 5
        MockCategoryTable::seed(20, ['alias' => 'akontarinis', 'parent_id' => 1]);
        $repo->savePolicy(new Policy(categoryId: 20, policyType: PolicyType::Personal, ownerId: 5));

        // Child Category 21: Inherit (no policy row in repo), alias 'ib-computer-science'
        MockCategoryTable::seed(21, ['alias' => 'ib-computer-science', 'parent_id' => 20]);

        // User with username 'ib-computer-science' exists
        TestablePersonalOwnerBinder::seedUser(77, 'ib-computer-science');

        // Running reconciliation on category 21
        $binder->reconcileUnboundCategory(21);

        // Child 21 must NOT create a policy or bind owner 77
        assert($repo->loadPolicy(21) === null, 'Descendant category 21 must not have a custom policy created');
        assert($repo->loadOwner(21) === null, 'Descendant category 21 must not bind an owner');

        echo "  [PASS] Acceptance Test O: Descendant Does Not Auto-Bind\n";
    }

    /**
     * Acceptance Test P: PERSONAL -> COLLABORATIVE
     */
    public function testPersonalToCollaborativeTransition(): void
    {
        $repo = new InMemoryPolicyRepository();

        // Existing Personal policy with owner 5
        $repo->savePolicy(new Policy(categoryId: 40, policyType: PolicyType::Personal, ownerId: 5));
        assert($repo->loadPolicy(40)->isPersonal() === true);
        assert($repo->loadPolicy(40)->getOwnerId() === 5);

        // Transition to Collaborative with contributors [10, 11]
        $collab = new Policy(categoryId: 40, policyType: PolicyType::Collaborative, contributorIds: [10, 11]);
        $repo->savePolicy($collab);

        $saved = $repo->loadPolicy(40);
        assert($saved->isCollaborative() === true);
        assert($saved->getOwnerId() === null, 'Owner must be null in collaborative policy');
        assert($saved->getContributorIds() === [10, 11]);

        echo "  [PASS] Acceptance Test P: Personal -> Collaborative Transition\n";
    }

    /**
     * Acceptance Test Q: COLLABORATIVE -> PERSONAL
     */
    public function testCollaborativeToPersonalTransition(): void
    {
        $repo = new InMemoryPolicyRepository();

        // Existing Collaborative with contributors [10, 11]
        $repo->savePolicy(new Policy(categoryId: 50, policyType: PolicyType::Collaborative, contributorIds: [10, 11]));
        assert($repo->loadContributors(50) === [10, 11]);

        // Transition to Personal (unbound or with explicit owner, never guessing from contributors)
        $personal = new Policy(categoryId: 50, policyType: PolicyType::Personal, ownerId: null);
        $repo->savePolicy($personal);

        $saved = $repo->loadPolicy(50);
        assert($saved->isPersonal() === true);
        assert($saved->getOwnerId() === null);
        assert($saved->getContributorIds() === [], 'Contributors must be empty in personal policy');
        assert($repo->loadContributors(50) === [], 'Contributors table must have zero rows for this category');

        echo "  [PASS] Acceptance Test Q: Collaborative -> Personal Transition (Clean Invariant)\n";
    }
}
