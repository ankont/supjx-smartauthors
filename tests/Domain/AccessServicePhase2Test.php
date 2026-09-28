<?php

declare(strict_types=1);

namespace SuperSoft\Plugin\System\SmartAuthors\Tests\Domain;

use SuperSoft\Plugin\System\SmartAuthors\Domain\Entity\Policy;
use SuperSoft\Plugin\System\SmartAuthors\Domain\Enum\CategoryManagement;
use SuperSoft\Plugin\System\SmartAuthors\Domain\Enum\EditMode;
use SuperSoft\Plugin\System\SmartAuthors\Domain\Enum\PolicyType;
use SuperSoft\Plugin\System\SmartAuthors\Domain\Enum\StateMode;
use SuperSoft\Plugin\System\SmartAuthors\Domain\Service\AccessService;
use SuperSoft\Plugin\System\SmartAuthors\Service\PolicyResolver;
use SuperSoft\Plugin\System\SmartAuthors\Service\RequestCache;
use SuperSoft\Plugin\System\SmartAuthors\Tests\Mocks\InMemoryPolicyRepository;

class AccessServicePhase2Test
{
    private function assert(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new \RuntimeException("Assertion failed: {$message}");
        }
    }

    public function runAll(): void
    {
        $this->testNewArticleStatePermissions();
        $this->testActorCategoryIdsResolution();
        $this->testEditorialVisibilityVsEditPermission();
    }

    /**
     * Point 2: New article state permissions per StateMode.
     */
    public function testNewArticleStatePermissions(): void
    {
        $repo = new InMemoryPolicyRepository();
        $cache = new RequestCache();
        $resolver = new PolicyResolver($repo, null, $cache);
        $access = new AccessService($resolver);

        // Cat 10: StateMode::None
        $repo->savePolicy(new Policy(
            categoryId: 10,
            policyType: PolicyType::Personal,
            ownerId: 42,
            contributorIds: [],
            editMode: EditMode::Own,
            stateMode: StateMode::None,
            categoryManagement: CategoryManagement::None
        ));

        // Cat 20: StateMode::Own
        $repo->savePolicy(new Policy(
            categoryId: 20,
            policyType: PolicyType::Personal,
            ownerId: 42,
            contributorIds: [],
            editMode: EditMode::Own,
            stateMode: StateMode::Own,
            categoryManagement: CategoryManagement::None
        ));

        // Cat 30: StateMode::All
        $repo->savePolicy(new Policy(
            categoryId: 30,
            policyType: PolicyType::Collaborative,
            ownerId: null,
            contributorIds: [42, 99],
            editMode: EditMode::All,
            stateMode: StateMode::All,
            categoryManagement: CategoryManagement::None
        ));

        // Test 1: StateMode::None denies state choice on new article
        $this->assert(!$access->canManageNewArticleState(42, 10), 'StateMode::None must deny new article state management');

        // Test 2: StateMode::Own allows state choice on new article
        $this->assert($access->canManageNewArticleState(42, 20), 'StateMode::Own must allow new article state management');

        // Test 3: StateMode::All allows state choice on new article
        $this->assert($access->canManageNewArticleState(42, 30), 'StateMode::All must allow new article state management');

        // Test 4: Non-actor is always denied
        $this->assert(!$access->canManageNewArticleState(100, 20), 'Non-actor must be denied new article state management');
        $this->assert(!$access->canManageNewArticleState(100, 30), 'Non-actor must be denied new article state management');

        echo "  [PASS] Phase 2 Test: New Article State Permissions (StateMode None/Own/All)\n";
    }

    /**
     * Point 1: Editorial Visibility != Edit Permission.
     * An effective actor sees unpublished articles, but cannot edit them if EditMode=Own and article belongs to another contributor.
     */
    public function testEditorialVisibilityVsEditPermission(): void
    {
        $repo = new InMemoryPolicyRepository();
        $cache = new RequestCache();
        $resolver = new PolicyResolver($repo, null, $cache);
        $access = new AccessService($resolver);

        // Collaborative Policy on Category 50:
        // Contributors: Andreas (ID: 10), Maria (ID: 20)
        // EditMode: Own, StateMode: None
        $repo->savePolicy(new Policy(
            categoryId: 50,
            policyType: PolicyType::Collaborative,
            ownerId: null,
            contributorIds: [10, 20],
            editMode: EditMode::Own,
            stateMode: StateMode::None,
            categoryManagement: CategoryManagement::None
        ));

        // Maria's article in Category 50
        $mariasArticle = (object) [
            'id' => 101,
            'catid' => 50,
            'created_by' => 20,
            'state' => 0, // Unpublished
        ];

        // 1. Andreas is an effective actor in Category 50 -> HAS EDITORIAL VISIBILITY!
        $this->assert($access->isEffectiveActor(10, 50), 'Andreas must be an effective actor in Category 50 (editorial visibility granted)');

        // 2. Andreas CANNOT edit Maria's article (EditMode is Own, created_by is Maria)
        $this->assert(!$access->canEditArticle(10, $mariasArticle), 'Andreas must NOT be able to edit Maria\'s article');

        // 3. Andreas CANNOT change state of Maria's article (StateMode is None)
        $this->assert(!$access->canManageArticleState(10, $mariasArticle), 'Andreas must NOT be able to change state of Maria\'s article');

        // 4. Maria CAN edit her own article
        $this->assert($access->canEditArticle(20, $mariasArticle), 'Maria must be able to edit her own article');

        // 5. Maria CANNOT change state either (StateMode is None)
        $this->assert(!$access->canManageArticleState(20, $mariasArticle), 'Maria must NOT be able to change state under StateMode::None');

        // 6. Non-contributor Kostas (ID: 30) is NOT an effective actor -> NO EDITORIAL VISIBILITY
        $this->assert(!$access->isEffectiveActor(30, 50), 'Kostas must NOT be an effective actor in Category 50');
        $this->assert(!$access->canEditArticle(30, $mariasArticle), 'Kostas must NOT be able to edit article');

        echo "  [PASS] Phase 2 Test: Editorial Visibility != Edit Permission (Collaborative Own/None)\n";
    }

    /**
     * Test getEffectiveActorCategoryIds across direct and inherited categories.
     */
    public function testActorCategoryIdsResolution(): void
    {
        $repo = new InMemoryPolicyRepository();
        $cache = new RequestCache();
        $resolver = new PolicyResolver($repo, null, $cache);

        // Pre-register category hierarchy
        // Root: 1
        // Cat 100 (Personal Owner: 7)
        //   -> Cat 101 (Inherit from 100)
        //   -> Cat 102 (Custom Override: Collaborative Contributors [8, 9])
        $resolver->setCategoryParent(100, 1);
        $resolver->setCategoryParent(101, 100);
        $resolver->setCategoryParent(102, 100);

        $repo->savePolicy(new Policy(
            categoryId: 100,
            policyType: PolicyType::Personal,
            ownerId: 7,
            contributorIds: [],
            editMode: EditMode::Own,
            stateMode: StateMode::Own,
            categoryManagement: CategoryManagement::Subtree
        ));

        $repo->savePolicy(new Policy(
            categoryId: 102,
            policyType: PolicyType::Collaborative,
            ownerId: null,
            contributorIds: [8, 9],
            editMode: EditMode::All,
            stateMode: StateMode::All,
            categoryManagement: CategoryManagement::None
        ));

        // User 7 is actor in 100 and 101 (inherited), but NOT 102 (overridden by 8, 9)
        $access = new AccessService($resolver);
        $this->assert($access->isEffectiveActor(7, 100), 'User 7 must be actor in Cat 100');
        $this->assert($access->isEffectiveActor(7, 101), 'User 7 must be actor in Cat 101 via inheritance');
        $this->assert(!$access->isEffectiveActor(7, 102), 'User 7 must NOT be actor in Cat 102 (overridden)');

        // User 8 is actor ONLY in 102
        $this->assert(!$access->isEffectiveActor(8, 100), 'User 8 must not be actor in Cat 100');
        $this->assert(!$access->isEffectiveActor(8, 101), 'User 8 must not be actor in Cat 101');
        $this->assert($access->isEffectiveActor(8, 102), 'User 8 must be actor in Cat 102');

        echo "  [PASS] Phase 2 Test: Effective Actor Category Inheritance & Override\n";
    }
}
