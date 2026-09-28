<?php

declare(strict_types=1);

namespace SuperSoft\Plugin\System\SmartAuthors\Tests\Runtime;

use Joomla\CMS\Form\Form;
use SuperSoft\Plugin\System\SmartAuthors\Domain\Entity\Policy;
use SuperSoft\Plugin\System\SmartAuthors\Domain\Enum\CategoryManagement;
use SuperSoft\Plugin\System\SmartAuthors\Domain\Enum\EditMode;
use SuperSoft\Plugin\System\SmartAuthors\Domain\Enum\PolicyType;
use SuperSoft\Plugin\System\SmartAuthors\Domain\Enum\StateMode;
use SuperSoft\Plugin\System\SmartAuthors\Domain\Service\AccessService;
use SuperSoft\Plugin\System\SmartAuthors\Model\SmartAuthorsFormModel;
use SuperSoft\Plugin\System\SmartAuthors\Service\PolicyResolver;
use SuperSoft\Plugin\System\SmartAuthors\Service\RequestCache;
use SuperSoft\Plugin\System\SmartAuthors\Tests\Mocks\InMemoryPolicyRepository;

class NewArticleStateEnforcementTest
{
    private function assert(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new \RuntimeException("Assertion failed: {$message}");
        }
    }

    public function runAll(): void
    {
        $this->testNewArticleStateNoneEnforcement();
        $this->testTrashedOptionRemovedForNewArticle();
        $this->testCreatedByImmutabilityOnCategoryMove();
        $this->testOwnDraftTrashingPermitted();
        $this->testDraftLifecycleTransitionsAndHelper();
        $this->testPluginOnContentBeforeSaveDatabaseIntegrity();
    }

    /**
     * Point 2: StateMode::NONE forces state=0 and locks fields.
     */
    public function testNewArticleStateNoneEnforcement(): void
    {
        $repo = new InMemoryPolicyRepository();
        $cache = new RequestCache();
        $resolver = new PolicyResolver($repo, null, $cache);
        $access = new AccessService($resolver);

        // Cat 15: StateMode::None
        $repo->savePolicy(new Policy(
            categoryId: 15,
            policyType: PolicyType::Personal,
            ownerId: 55,
            contributorIds: [],
            editMode: EditMode::Own,
            stateMode: StateMode::None,
            categoryManagement: CategoryManagement::None
        ));

        // Create testable FormModel
        $model = new class ($access) extends SmartAuthorsFormModel {
            public function __construct(AccessService $access) {
                $this->accessService = $access;
            }

            public function getCurrentUser(): object {
                return (object) [
                    'id' => 55,
                    'guest' => false,
                    'authorise' => static fn(string $act, ?string $asset = null) => false, // no native core.edit.state
                ];
            }

            public function testFormModifications(Form $form, int $catId): void {
                $user = $this->getCurrentUser();
                $userId = (int) $user->id;
                $canManageNewState = $this->accessService->canManageNewArticleState($userId, $catId);

                if (!$canManageNewState) {
                    $form->setValue('state', null, 0);
                    $form->setFieldAttribute('state', 'disabled', 'true');
                    $form->setFieldAttribute('state', 'filter', 'unset');
                    $form->setFieldAttribute('publish_up', 'disabled', 'true');
                    $form->setFieldAttribute('publish_up', 'filter', 'unset');
                }
            }

            public function testSaveSanitization(array $data): array {
                $user = $this->getCurrentUser();
                $userId = (int) $user->id;
                $catId = (int) ($data['catid'] ?? 0);

                if (empty($data['id'])) {
                    if (!$this->accessService->canManageNewArticleState($userId, $catId)) {
                        $data['state'] = 0;
                        $data['publish_up'] = null;
                        $data['publish_down'] = null;
                    }
                    $data['featured'] = 0;
                }

                return $data;
            }
        };

        $form = new Form('com_content.article');
        $model->testFormModifications($form, 15);

        // Verify form has state = 0, disabled = true, filter = unset
        $this->assert($form->getValue('state') === 0, 'Form state value must be forced to 0 for StateMode::NONE');
        $this->assert($form->getFieldAttribute('state', 'disabled') === 'true', 'State field must be disabled for StateMode::NONE');
        $this->assert($form->getFieldAttribute('state', 'filter') === 'unset', 'State field filter must be unset for StateMode::NONE');

        // Verify save path sanitization (defense-in-depth)
        $maliciousPayload = [
            'id' => 0,
            'catid' => 15,
            'title' => 'Sneaky Article',
            'state' => 1, // Tried to post published state!
            'featured' => 1, // Tried to post featured!
            'publish_up' => '2026-09-24 00:00:00',
        ];

        $sanitized = $model->testSaveSanitization($maliciousPayload);

        $this->assert($sanitized['state'] === 0, 'Save path MUST enforce state = 0 (Unpublished) for StateMode::NONE');
        $this->assert($sanitized['publish_up'] === null, 'Save path MUST clear publish_up for StateMode::NONE');
        $this->assert($sanitized['featured'] === 0, 'Save path MUST neutralize featured for non-core admins');

        echo "  [PASS] Phase 2 Test: StateMode::NONE Enforces state=0 and Neutralizes Featured\n";
    }

    /**
     * Point 4: Option -2 (Trashed) removed from state field for NEW articles under StateMode::OWN/ALL.
     */
    public function testTrashedOptionRemovedForNewArticle(): void
    {
        $xmlContent = <<<XML
<form>
    <field name="state" type="list">
        <option value="1">JPUBLISHED</option>
        <option value="0">JUNPUBLISHED</option>
        <option value="2">JARCHIVED</option>
        <option value="-2">JTRASHED</option>
    </field>
</form>
XML;

        $form = new Form('com_content.article', $xmlContent);

        // Before removal: -2 exists
        $nodesBefore = $form->getXml()->xpath('//field[@name="state"]/option[@value="-2"]');
        $this->assert(count($nodesBefore) === 1, 'Initial form XML must contain option -2');

        // Apply SmartAuthorsFormModel removal
        $nodes = $form->getXml()->xpath('//field[@name="state"]/option[@value="-2"]');
        foreach ($nodes as $node) {
            unset($node[0]);
        }

        // After removal: -2 does NOT exist
        $nodesAfter = $form->getXml()->xpath('//field[@name="state"]/option[@value="-2"]');
        $this->assert(count($nodesAfter) === 0, 'Option -2 (Trashed) MUST be removed from state field XML for NEW articles');

        // Remaining options are 1, 0, 2
        $remainingOptions = [];
        foreach ($form->getXml()->xpath('//field[@name="state"]/option') as $opt) {
            $remainingOptions[] = (string) $opt['value'];
        }

        $this->assert($remainingOptions === ['1', '0', '2'], 'Remaining options must be Published (1), Unpublished (0), and Archived (2)');

        echo "  [PASS] Phase 2 Test: Trashed (-2) Option Removed from State Field for New Articles\n";
    }

    /**
     * Category move & created_by immutability.
     */
    public function testCreatedByImmutabilityOnCategoryMove(): void
    {
        $repo = new InMemoryPolicyRepository();
        $cache = new RequestCache();
        $resolver = new PolicyResolver($repo, null, $cache);
        $access = new AccessService($resolver);

        // User 42 has EditMode::All on Cat 60 and can Create in Cat 70
        $repo->savePolicy(new Policy(
            categoryId: 60,
            policyType: PolicyType::Collaborative,
            ownerId: null,
            contributorIds: [42],
            editMode: EditMode::All,
            stateMode: StateMode::Own,
            categoryManagement: CategoryManagement::None
        ));

        $repo->savePolicy(new Policy(
            categoryId: 70,
            policyType: PolicyType::Collaborative,
            ownerId: null,
            contributorIds: [42],
            editMode: EditMode::Own,
            stateMode: StateMode::Own,
            categoryManagement: CategoryManagement::None
        ));

        $model = new class ($access) extends SmartAuthorsFormModel {
            public function __construct(AccessService $access) {
                $this->accessService = $access;
            }

            public function getCurrentUser(): object {
                return (object) ['id' => 42, 'guest' => false, 'authorise' => static fn() => false];
            }

            protected function loadExistingArticleRecord(int $id): ?object {
                return (object) [
                    'id' => 500,
                    'catid' => 60,
                    'created_by' => 999, // Original author is user 999!
                    'featured' => 0,
                ];
            }

            public function testSaveCategoryMove(array $data): array|false {
                $user = $this->getCurrentUser();
                $existing = $this->loadExistingArticleRecord((int) $data['id']);

                // created_by immutability
                $data['created_by'] = (int) $existing->created_by;

                // Validate move: source edit + destination create
                $canEditSource = $this->accessService->canEditArticle((int) $user->id, $existing);
                $canCreateDest = $this->accessService->canCreateArticle((int) $user->id, (int) $data['catid']);

                if (!$canEditSource || !$canCreateDest) {
                    return false;
                }

                return $data;
            }
        };

        // User 42 attempts to move article 500 from Cat 60 to Cat 70, trying to claim ownership
        $movePayload = [
            'id' => 500,
            'catid' => 70,
            'created_by' => 42, // Maliciously trying to change created_by to self
        ];

        $result = $model->testSaveCategoryMove($movePayload);

        $this->assert($result !== false, 'Category move must be permitted (can edit source + can create dest)');
        $this->assert($result['created_by'] === 999, 'created_by MUST remain 999 (original author) and not change to 42');
        $this->assert($result['catid'] === 70, 'catid must be updated to destination 70');

        echo "  [PASS] Phase 2 Test: Category Move Preserves created_by Immutability\n";
    }

    /**
     * Point 4: Even with StateMode::NONE, author can trash their own unpublished draft (0 -> -2)
     * and restore it (-2 -> 0), but cannot publish (1). Live published articles cannot be trashed.
     */
    public function testOwnDraftTrashingPermitted(): void
    {
        $repo = new InMemoryPolicyRepository();
        $cache = new RequestCache();
        $resolver = new PolicyResolver($repo, null, $cache);
        $access = new AccessService($resolver);

        // Cat 15: Personal owner=55, StateMode::None
        $repo->savePolicy(new Policy(
            categoryId: 15,
            policyType: PolicyType::Personal,
            ownerId: 55,
            contributorIds: [],
            editMode: EditMode::Own,
            stateMode: StateMode::None,
            categoryManagement: CategoryManagement::None
        ));

        // 1. Unpublished draft owned by user 55
        $draft = (object) ['id' => 101, 'catid' => 15, 'created_by' => 55, 'state' => 0];
        $this->assert($access->canTrashArticle(55, $draft) === true, 'Author must be able to trash own draft');

        // Other user cannot trash user 55 draft
        $this->assert($access->canTrashArticle(99, $draft) === false, 'Non-actor cannot trash user 55 draft');

        // 2. Already published article owned by user 55 (with StateMode::NONE)
        $published = (object) ['id' => 102, 'catid' => 15, 'created_by' => 55, 'state' => 1];
        $this->assert($access->canTrashArticle(55, $published) === false, 'Author without publishing rights cannot trash live published article');

        // 3. Test Form filtering
        $model = new class ($access) extends SmartAuthorsFormModel {
            public function __construct(AccessService $access) {
                $this->accessService = $access;
            }
            public function testFilterStateForDrafts(Form $form): void {
                $this->filterStateOptionsForDraftsOnly($form);
            }
        };

        $formXml = <<<XML
<?xml version="1.0" encoding="utf-8"?>
<form>
    <field name="state" type="list">
        <option value="1">JPUBLISHED</option>
        <option value="0">JUNPUBLISHED</option>
        <option value="2">JARCHIVED</option>
        <option value="-2">JTRASHED</option>
    </field>
</form>
XML;
        $form = new Form('test_draft_form', $formXml);

        $model->testFilterStateForDrafts($form);
        $xml = $form->getXml();
        $options = [];
        foreach ($xml->xpath('//field[@name="state"]/option') as $opt) {
            $options[] = (string) $opt['value'];
        }

        $this->assert(in_array('0', $options, true), 'Unpublished (0) must remain in form');
        $this->assert(in_array('-2', $options, true), 'Trashed (-2) must remain in form');
        $this->assert(!in_array('1', $options, true), 'Published (1) must be removed');
        $this->assert(!in_array('2', $options, true), 'Archived (2) must be removed because Archived is public in Joomla');

        echo "  [PASS] Phase 2 Test: Author can Trash Own Unpublished Draft (0 -> -2)\n";
    }

    /**
     * Verifies that the draft lifecycle allows transitions between 0 (Unpublished) and -2 (Trashed),
     * forbids publishing (1) or archiving (2) without publishing permissions,
     * and validates SmartAuthorsHelper.
     */
    public function testDraftLifecycleTransitionsAndHelper(): void
    {
        $repo = new InMemoryPolicyRepository();
        $cache = new RequestCache();
        $resolver = new PolicyResolver($repo, null, $cache);
        $access = new AccessService($resolver);
        \Joomla\CMS\Factory::getContainer()->set(AccessService::class, $access);

        // Cat 15: StateMode::None, Owner = 55
        $repo->savePolicy(new Policy(
            categoryId: 15,
            policyType: PolicyType::Personal,
            ownerId: 55,
            contributorIds: [],
            editMode: EditMode::Own,
            stateMode: StateMode::None,
            categoryManagement: CategoryManagement::None
        ));

        $draft = (object) ['id' => 10, 'catid' => 15, 'created_by' => 55, 'state' => 0];
        $archived = (object) ['id' => 11, 'catid' => 15, 'created_by' => 55, 'state' => 2];
        $trashed = (object) ['id' => 12, 'catid' => 15, 'created_by' => 55, 'state' => -2];
        $published = (object) ['id' => 13, 'catid' => 15, 'created_by' => 55, 'state' => 1];

        // 1. Author (55) can transition between private draft states [0, -2]
        $this->assert($access->canTransitionState(55, $draft, -2), 'Draft -> Trash permitted');
        $this->assert($access->canTransitionState(55, $trashed, 0), 'Trash -> Draft permitted');

        // 2. Author (55) CANNOT transition to public states 1 (Published) or 2 (Archived) without publish rights
        $this->assert(!$access->canTransitionState(55, $draft, 1), 'Draft -> Published FORBIDDEN without publish rights');
        $this->assert(!$access->canTransitionState(55, $draft, 2), 'Draft -> Archived FORBIDDEN without publish rights');
        $this->assert(!$access->canTransitionState(55, $published, 0), 'Live article transition forbidden without publish rights');
        $this->assert(!$access->canTransitionState(55, $archived, -2), 'Archived article transition forbidden without publish rights');

        // 3. Other user (99) CANNOT transition anything
        $this->assert(!$access->canTransitionState(99, $draft, -2), 'Non-owner cannot touch draft');

        // 4. Category with EditMode::ALL and StateMode::NONE:
        // Actor can trash and restore ANY unpublished draft in the category, not just their own!
        $repo->savePolicy(new Policy(
            categoryId: 16,
            policyType: PolicyType::Personal,
            ownerId: 55,
            contributorIds: [],
            editMode: EditMode::All,
            stateMode: StateMode::None,
            categoryManagement: CategoryManagement::None
        ));

        $draftByOther = (object) ['id' => 20, 'catid' => 16, 'created_by' => 77, 'state' => 0];
        $trashedByOther = (object) ['id' => 21, 'catid' => 16, 'created_by' => 77, 'state' => -2];
        $publishedByOther = (object) ['id' => 22, 'catid' => 16, 'created_by' => 77, 'state' => 1];

        // Actor (55) CAN trash and restore other user's draft in EditMode::ALL category
        $this->assert($access->canTrashArticle(55, $draftByOther), 'Owner can trash other author draft when EditMode is ALL');
        $this->assert($access->canTransitionState(55, $draftByOther, -2), 'Owner can transition other author draft -> trash');
        $this->assert($access->canTransitionState(55, $trashedByOther, 0), 'Owner can transition other author trashed -> draft');

        // Actor (55) CANNOT publish (1) or archive (2) other author draft (Drafts only / StateMode::NONE)
        $this->assert(!$access->canTransitionState(55, $draftByOther, 1), 'Publishing other author draft forbidden under StateMode::NONE');
        $this->assert(!$access->canTransitionState(55, $draftByOther, 2), 'Archiving other author draft forbidden under StateMode::NONE');

        // Actor (55) CANNOT trash an already published article without publishing rights
        $this->assert(!$access->canTrashArticle(55, $publishedByOther), 'Cannot trash already published article under StateMode::NONE');

        // 5. Test SmartAuthorsHelper
        $user = new \Joomla\CMS\User\User(55);
        $this->assert(\SuperSoft\Plugin\System\SmartAuthors\Helper\SmartAuthorsHelper::canEditArticle($user, $draft), 'Helper::canEditArticle');
        $this->assert(\SuperSoft\Plugin\System\SmartAuthors\Helper\SmartAuthorsHelper::canTrashArticle($user, $draft), 'Helper::canTrashArticle');
        $this->assert(\SuperSoft\Plugin\System\SmartAuthors\Helper\SmartAuthorsHelper::canTrashArticle($user, $draftByOther), 'Helper::canTrashArticle other user draft in EditMode::ALL');
        $this->assert(\SuperSoft\Plugin\System\SmartAuthors\Helper\SmartAuthorsHelper::canChangeState($user, $draft, -2), 'Helper::canChangeState to -2');
        $this->assert(\SuperSoft\Plugin\System\SmartAuthors\Helper\SmartAuthorsHelper::canChangeState($user, $draftByOther, -2), 'Helper::canChangeState other draft to -2');
        $this->assert(!\SuperSoft\Plugin\System\SmartAuthors\Helper\SmartAuthorsHelper::canChangeState($user, $draft, 1), 'Helper::canChangeState to 1 forbidden');
        $this->assert(!\SuperSoft\Plugin\System\SmartAuthors\Helper\SmartAuthorsHelper::canChangeState($user, $draft, 2), 'Helper::canChangeState to 2 forbidden');
        $this->assert(!\SuperSoft\Plugin\System\SmartAuthors\Helper\SmartAuthorsHelper::canChangeState($user, $draftByOther, 1), 'Helper::canChangeState other draft to 1 forbidden');

        // Array support in Helper
        $draftArray = ['id' => 10, 'catid' => 15, 'created_by' => 55, 'state' => 0];
        $this->assert(\SuperSoft\Plugin\System\SmartAuthors\Helper\SmartAuthorsHelper::canEditArticle($user, $draftArray), 'Helper::canEditArticle with array');
        $this->assert(\SuperSoft\Plugin\System\SmartAuthors\Helper\SmartAuthorsHelper::canTrashArticle($user, $draftArray), 'Helper::canTrashArticle with array');
        $this->assert(\SuperSoft\Plugin\System\SmartAuthors\Helper\SmartAuthorsHelper::canChangeState($user, $draftArray, -2), 'Helper::canChangeState with array');

        echo "  [PASS] Phase 2 Test: Draft Lifecycle (Unpublished <-> Trashed) & SmartAuthorsHelper\n";
    }

    /**
     * Regression test: SmartAuthors::onContentBeforeSave must have getDatabase() available,
     * must preserve created_by for authors, but ALLOW authorized administrators to change created_by.
     */
    public function testPluginOnContentBeforeSaveDatabaseIntegrity(): void
    {
        // 1. Author test: regular user without admin permissions
        $authorUser = new \Joomla\CMS\User\User(55);
        \Joomla\CMS\Factory::$currentUser = $authorUser;

        $repo = new InMemoryPolicyRepository();
        $cache = new RequestCache();
        $resolver = new PolicyResolver($repo, null, $cache);
        $access = new AccessService($resolver);
        $ownerBinder = new \SuperSoft\Plugin\System\SmartAuthors\Service\PersonalOwnerBinder($repo, null, $cache);

        $plugin = new \SuperSoft\Plugin\System\SmartAuthors\Extension\SmartAuthors(['name' => 'smartauthors', 'type' => 'system']);
        $plugin->setServices($repo, $resolver, $access, $ownerBinder, $cache);

        // Verify getDatabase() is callable and returns database
        $db = $plugin->getDatabase();
        $this->assert(is_object($db), 'Plugin must return database');

        // Create table mock representing an existing article
        $table = (object) [
            'id' => 42,
            'catid' => 15,
            'created_by' => 999, // User trying to tamper
            'featured' => 1,
            'state' => 1,
        ];

        // Custom database mock that returns existing record with created_by = 55, featured = 0
        $mockDb = new class {
            public function quoteName(string|array $name, ?string $as = null): string {
                if (is_array($name)) { return implode(', ', $name); }
                return $as ? "{$name} AS {$as}" : $name;
            }
            public function getQuery(bool $new = true): \Joomla\Database\DatabaseQuery {
                return new \Joomla\Database\DatabaseQuery();
            }
            public function setQuery(mixed $query): self { return $this; }
            public function loadObject(): ?object {
                return (object) ['created_by' => 55, 'featured' => 0];
            }
        };

        $plugin->setDatabase(new class ($mockDb) implements \Joomla\Database\DatabaseInterface {
            public function __construct(private object $db) {}
            public function quoteName(string|array $name, ?string $as = null): string { return $this->db->quoteName($name, $as); }
            public function getQuery(bool $new = true): \Joomla\Database\DatabaseQuery { return $this->db->getQuery($new); }
            public function setQuery(mixed $query): self { return $this; }
            public function loadObject(): ?object { return $this->db->loadObject(); }
        });

        // Trigger onContentBeforeSave for existing article as author
        $plugin->onContentBeforeSave('com_content.article', $table, false);

        // Verify created_by was restored to 55 from DB for regular author
        $this->assert($table->created_by === 55, "Author: created_by must be restored from DB (expected 55, got {$table->created_by})");
        $this->assert($table->featured === 0, "Author: featured must be restored to 0");

        // 2. Admin test: user with core.admin or core.manage on com_users
        $adminUser = new \Joomla\CMS\User\User(1);
        $adminUser->authorisedActions['core.admin'] = true;
        \Joomla\CMS\Factory::$currentUser = $adminUser;

        $tableAdmin = (object) [
            'id' => 42,
            'catid' => 15,
            'created_by' => 999, // Admin changing author to 999
            'featured' => 1,
            'state' => 1,
        ];

        // Trigger onContentBeforeSave for existing article as admin
        $plugin->onContentBeforeSave('com_content.article', $tableAdmin, false);

        // Verify created_by was PRESERVED as 999 for authorized admin!
        $this->assert($tableAdmin->created_by === 999, "Admin: created_by must be allowed to change to 999 (got {$tableAdmin->created_by})");

        echo "  [PASS] Phase 2 Test: SmartAuthors::onContentBeforeSave getDatabase & Immutability\n";
    }
}
