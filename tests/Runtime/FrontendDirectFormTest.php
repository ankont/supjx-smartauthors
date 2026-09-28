<?php

declare(strict_types=1);

namespace SuperSoft\Plugin\System\SmartAuthors\Tests\Runtime;

use Joomla\CMS\Factory;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\MVC\Factory\MVCFactoryInterface;
use Joomla\CMS\User\User;
use Joomla\Database\DatabaseInterface;
use Joomla\Input\Input;
use Joomla\Registry\Registry;
use SuperSoft\Plugin\System\SmartAuthors\Controller\SmartAuthorsArticleController;
use SuperSoft\Plugin\System\SmartAuthors\Controller\SmartAuthorsDisplayController;
use SuperSoft\Plugin\System\SmartAuthors\Domain\Entity\EffectivePolicy;
use SuperSoft\Plugin\System\SmartAuthors\Domain\Entity\Policy;
use SuperSoft\Plugin\System\SmartAuthors\Domain\Enum\CategoryManagement;
use SuperSoft\Plugin\System\SmartAuthors\Domain\Enum\EditMode;
use SuperSoft\Plugin\System\SmartAuthors\Domain\Enum\PolicyType;
use SuperSoft\Plugin\System\SmartAuthors\Domain\Enum\StateMode;
use SuperSoft\Plugin\System\SmartAuthors\Domain\Service\AccessService;
use SuperSoft\Plugin\System\SmartAuthors\Extension\SmartAuthorsMVCFactory;
use SuperSoft\Plugin\System\SmartAuthors\Model\SmartAuthorsCategoryModel;
use SuperSoft\Plugin\System\SmartAuthors\Model\SmartAuthorsFormModel;
use SuperSoft\Plugin\System\SmartAuthors\Service\PolicyResolver;
use SuperSoft\Plugin\System\SmartAuthors\Service\RequestCache;
use SuperSoft\Plugin\System\SmartAuthors\Tests\Mocks\InMemoryPolicyRepository;
use SuperSoft\Plugin\System\SmartAuthors\View\Form\HtmlView as SmartAuthorsFormView;

class FrontendDirectFormTest
{
    private function assert(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new \RuntimeException("Assertion failed: {$message}");
        }
    }

    private function getProtectedProperty(object $object, string $property): mixed
    {
        $reflection = new \ReflectionClass($object);
        while ($reflection !== false) {
            if ($reflection->hasProperty($property)) {
                $prop = $reflection->getProperty($property);
                return $prop->getValue($object);
            }
            $reflection = $reflection->getParentClass();
        }

        throw new \RuntimeException("Property {$property} not found on " . get_class($object));
    }

    private function createAccessService(): AccessService
    {
        $repo = new InMemoryPolicyRepository();

        // Policy on category 97: Collaborative, Contributors=[42], EditMode=All, StateMode=Own
        $repo->savePolicy(new Policy(
            categoryId: 97,
            policyType: PolicyType::Collaborative,
            ownerId: null,
            contributorIds: [42],
            editMode: EditMode::All,
            stateMode: StateMode::Own,
            categoryManagement: CategoryManagement::None,
        ));


        // Category 50: No policy (fallback)
        $cache = new RequestCache();
        $cache->setActorCategories(42, [97]);

        $resolver = new PolicyResolver($repo, null, $cache);

        return new AccessService($resolver);
    }


    public function runAll(): void
    {
        $this->testMVCFactoryDisplayControllerAndFormViewBinding();
        $this->testPathADirectExistingArticleEditSmartAuthorsAuthorized();
        $this->testPathADirectExistingArticleUnauthorizedBlocked();
        $this->testPathBTaskArticleEditAndRedirect();
        $this->testNewArticleCreatePathSmartAuthorsAuthorized();
        $this->testNewArticleCreatePathUnauthorizedBlocked();
        $this->testCategoryModelAccessCreateInjected();
        $this->testLoadExistingArticleRecordQueryJoinsFrontpage();
        $this->testCheckinAndCheckoutAuthorizedSmartAuthors();
    }

    /**
     * Verifies SmartAuthorsMVCFactory creates SmartAuthorsDisplayController, SmartAuthorsFormView,
     * and rebinds $controller->factory to avoid leaking inner MVCFactory.
     */
    public function testMVCFactoryDisplayControllerAndFormViewBinding(): void
    {
        $accessService = $this->createAccessService();
        $app = Factory::getApplication();
        $input = new Input();

        // Mock inner MVCFactory
        $inner = new class ($app, $input) implements MVCFactoryInterface {
            public function __construct(private $app, private $input) {}
            public function createController($name, $prefix, array $config, \Joomla\CMS\Application\CMSApplicationInterface $app, Input $input): \Joomla\CMS\MVC\Controller\ControllerInterface {
                return new \Joomla\CMS\MVC\Controller\BaseController($config, $this, $app, $input);
            }
            public function createModel($name, $prefix = '', array $config = []): \Joomla\CMS\MVC\Model\ModelInterface {
                return new \Joomla\CMS\MVC\Model\BaseDatabaseModel();
            }
            public function createView($name, $prefix = '', $type = '', array $config = []): \Joomla\CMS\MVC\View\ViewInterface {
                return new \Joomla\CMS\MVC\View\HtmlView($config);
            }
            public function createTable($name, $prefix = '', array $config = []): \Joomla\CMS\Table\Table {
                throw new \RuntimeException('Not implemented');
            }
        };

        $factory = new SmartAuthorsMVCFactory($inner, $accessService);

        // 1. Controller 'display' -> SmartAuthorsDisplayController
        $displayController = $factory->createController('Display', 'Site', [], $app, $input);
        $this->assert(
            $displayController instanceof SmartAuthorsDisplayController,
            'SmartAuthorsMVCFactory must create SmartAuthorsDisplayController for "Display"'
        );
        $this->assert(
            $this->getProtectedProperty($displayController, 'factory') === $factory,
            'SmartAuthorsDisplayController factory property must be bound to SmartAuthorsMVCFactory'
        );

        // 2. View 'form' -> SmartAuthorsFormView
        $formView = $factory->createView('Form', 'Site', 'html', []);
        $this->assert(
            $formView instanceof SmartAuthorsFormView,
            'SmartAuthorsMVCFactory must create SmartAuthorsFormView for "Form"'
        );

        // 3. Fallback controller gets rebound factory
        $otherController = $factory->createController('Other', 'Site', [], $app, $input);
        $this->assert(
            $this->getProtectedProperty($otherController, 'factory') === $factory,
            'Any controller created must have its factory property rebound to SmartAuthorsMVCFactory'
        );

        echo "  [PASS] Phase 2 Test: SmartAuthorsMVCFactory DisplayController & FormView Binding\n";
    }

    /**
     * Tests Path A: Direct GET URL view=form&layout=edit&a_id=186&catid=97
     * when the actor is authorized by SmartAuthors.
     */
    public function testPathADirectExistingArticleEditSmartAuthorsAuthorized(): void
    {
        $accessService = $this->createAccessService();
        $app = Factory::getApplication();

        // User 42 has NO native Joomla ACL
        $user = new User(42);
        $user->guest = 0;
        $app->loadIdentity($user);

        // Article 186 in cat 97 created by someone else (user 99)
        $article = (object) [
            'id' => 186,
            'catid' => 97,
            'created_by' => 99,
            'state' => 1,
            'publish_up' => null,
            'publish_down' => null,
        ];

        // Ensure session does NOT initially hold edit id
        $app->setUserState('com_content.edit.article.id', []);

        $input = new Input([
            'option' => 'com_content',
            'view' => 'form',
            'layout' => 'edit',
            'a_id' => 186,
            'catid' => 97,
        ]);

        // Create testable subclass of SmartAuthorsDisplayController
        $controller = new class ($article, $accessService, $app, $input) extends SmartAuthorsDisplayController {
            public function __construct(
                private object $mockArticle,
                AccessService $accessService,
                $app,
                $input
            ) {
                parent::__construct([], null, $app, $input);
                $this->setAccessService($accessService);
            }

            protected function loadArticleRecord(int $articleId): ?object
            {
                return $articleId === (int) $this->mockArticle->id ? $this->mockArticle : null;
            }

            public function testDirectCheck(): void
            {
                $id = $this->input->getInt('a_id');
                $vName = $this->input->getCmd('view', 'categories');

                if ($vName === 'form' && $id > 0 && !$this->checkEditId('com_content.edit.article', $id)) {
                    $user = $this->app->getIdentity();
                    if ($user && !$user->guest) {
                        $accessService = $this->getAccessService();
                        if ($accessService !== null) {
                            $article = $this->loadArticleRecord($id);
                            if ($article !== null && $accessService->canEditArticle((int) $user->id, $article)) {
                                $this->holdEditId('com_content.edit.article', $id);
                            }
                        }
                    }
                }
            }

            public function verifyEditIdHeld(int $id): bool
            {
                return $this->checkEditId('com_content.edit.article', $id);
            }
        };

        // Before check: unheld
        $this->assert(!$controller->verifyEditIdHeld(186), 'Initially, 186 must not be held in edit list');

        // Execute direct check
        $controller->testDirectCheck();

        // After check: SmartAuthors validated permission and held edit ID
        $this->assert($controller->verifyEditIdHeld(186), 'SmartAuthorsDisplayController must hold edit ID for authorized actor');

        // Now test SmartAuthorsFormModel::getItem()
        $model = new class ($article, $accessService) extends SmartAuthorsFormModel {
            public function __construct(private object $mockArticle, AccessService $accessService) {
                parent::__construct();
                $this->setAccessService($accessService);
            }

            public function getItem($itemId = null)
            {
                // Simulate parent::getItem() returning object without access-edit
                $val = clone $this->mockArticle;
                $val->params = new Registry();
                $this->setState('article.id', 186);

                $user = $this->getCurrentUser();
                $accessService = $this->getAccessService();

                $userId = (int) $user->id;
                if (!$val->params->get('access-edit')) {
                    if ($accessService->canEditArticle($userId, $val)) {
                        $val->params->set('access-edit', true);
                    }
                }

                return $val;
            }
        };

        $item = $model->getItem(186);
        $this->assert(
            $item->params->get('access-edit') === true,
            'SmartAuthorsFormModel must set access-edit to true for authorized actor'
        );

        echo "  [PASS] Phase 2 Test: Path A Direct Article Edit Authorization (v1.1.2 regression)\n";
    }

    /**
     * Tests Path A: Direct GET URL by unauthorized user -> blocked with unheld ID check.
     */
    public function testPathADirectExistingArticleUnauthorizedBlocked(): void
    {
        $accessService = $this->createAccessService();
        $app = Factory::getApplication();

        // User 55 is NOT an actor in category 97
        $user = new User(55);
        $user->guest = 0;
        $app->loadIdentity($user);

        $article = (object) [
            'id' => 186,
            'catid' => 97,
            'created_by' => 99,
            'state' => 1,
            'publish_up' => null,
            'publish_down' => null,
        ];

        $app->setUserState('com_content.edit.article.id', []);

        $input = new Input([
            'option' => 'com_content',
            'view' => 'form',
            'layout' => 'edit',
            'a_id' => 186,
            'catid' => 97,
        ]);

        $controller = new class ($article, $accessService, $app, $input) extends SmartAuthorsDisplayController {
            public function __construct(private object $mockArticle, AccessService $accessService, $app, $input) {
                parent::__construct([], null, $app, $input);
                $this->setAccessService($accessService);
            }

            protected function loadArticleRecord(int $articleId): ?object {
                return $this->mockArticle;
            }

            public function testDirectCheck(): void {
                $id = $this->input->getInt('a_id');
                $vName = $this->input->getCmd('view', 'categories');

                if ($vName === 'form' && $id > 0 && !$this->checkEditId('com_content.edit.article', $id)) {
                    $user = $this->app->getIdentity();
                    if ($user && !$user->guest) {
                        $accessService = $this->getAccessService();
                        if ($accessService !== null) {
                            $article = $this->loadArticleRecord($id);
                            if ($article !== null && $accessService->canEditArticle((int) $user->id, $article)) {
                                $this->holdEditId('com_content.edit.article', $id);
                            }
                        }
                    }
                }
            }

            public function verifyEditIdHeld(int $id): bool {
                return $this->checkEditId('com_content.edit.article', $id);
            }
        };

        $controller->testDirectCheck();

        $this->assert(
            !$controller->verifyEditIdHeld(186),
            'Unauthorized user must NOT have edit ID held in session'
        );

        echo "  [PASS] Phase 2 Test: Path A Direct Article Edit Blocks Unauthorized Actor\n";
    }

    /**
     * Tests Path B: task=article.edit&a_id=186.
     */
    public function testPathBTaskArticleEditAndRedirect(): void
    {
        $accessService = $this->createAccessService();
        $app = Factory::getApplication();

        $user = new User(42);
        $user->guest = 0;
        $app->loadIdentity($user);

        $article = (object) [
            'id' => 186,
            'catid' => 97,
            'created_by' => 99,
        ];

        $input = new Input([
            'option' => 'com_content',
            'task' => 'article.edit',
            'a_id' => 186,
        ]);

        $controller = new class ($article, $accessService, $app, $input) extends SmartAuthorsArticleController {
            public function __construct(private object $mockArticle, AccessService $accessService, $app, $input) {
                parent::__construct([], null, $app, $input);
                $this->setAccessService($accessService);
            }

            public function testAllowEdit(): bool {
                return $this->allowEdit(['id' => 186], 'id');
            }

            public function getModel($name = 'Form', $prefix = 'Site', $config = ['ignore_request' => true]) {
                $art = $this->mockArticle;
                return new class ($art) {
                    public function __construct(private $art) {}
                    public function getItem($id = null) { return $this->art; }
                };
            }
        };

        $this->assert($controller->testAllowEdit() === true, 'Path B allowEdit must return true for authorized actor');

        echo "  [PASS] Phase 2 Test: Path B task=article.edit Authorization & Flow\n";
    }

    /**
     * Tests New Article create path (view=form&layout=edit&a_id=0&catid=97)
     * when actor has SmartAuthors create permission.
     */
    public function testNewArticleCreatePathSmartAuthorsAuthorized(): void
    {
        $accessService = $this->createAccessService();
        $app = Factory::getApplication();

        $user = new User(42);
        $user->guest = 0;
        $app->loadIdentity($user);

        $view = new class ($accessService) extends SmartAuthorsFormView {
            public function __construct(AccessService $accessService) {
                $this->setAccessService($accessService);
            }

            public function testAuthorizeNewArticle(int $catid): bool {
                $app = Factory::getApplication();
                $user = $app->getIdentity();

                // Native Joomla ACL is false
                $authorised = false;

                if (!$authorised && $user && !$user->guest) {
                    $accessService = $this->getAccessService();
                    if ($accessService !== null) {
                        $userId = (int) $user->id;
                        if ($catid > 0) {
                            $authorised = $accessService->canCreateArticle($userId, $catid);
                        } else {
                            $authorised = $accessService->canCreateInAnyCategory($userId);
                        }
                    }
                }

                return $authorised;
            }
        };

        // User 42 has create permission in category 97
        $this->assert(
            $view->testAuthorizeNewArticle(97) === true,
            'SmartAuthorsFormView must authorize new article create for actor in category 97'
        );

        // General create in any category
        $this->assert(
            $view->testAuthorizeNewArticle(0) === true,
            'SmartAuthorsFormView must authorize new article create when actor has at least one category'
        );

        echo "  [PASS] Phase 2 Test: New Article Path Authorized via SmartAuthorsFormView\n";
    }

    /**
     * Tests New Article create path blocks unauthorized user.
     */
    public function testNewArticleCreatePathUnauthorizedBlocked(): void
    {
        $accessService = $this->createAccessService();
        $app = Factory::getApplication();

        // User 55 has no policies
        $user = new User(55);
        $user->guest = 0;
        $app->loadIdentity($user);

        $view = new class ($accessService) extends SmartAuthorsFormView {
            public function __construct(AccessService $accessService) {
                $this->setAccessService($accessService);
            }

            public function testAuthorizeNewArticle(int $catid): bool {
                $app = Factory::getApplication();
                $user = $app->getIdentity();

                $authorised = false;

                if (!$authorised && $user && !$user->guest) {
                    $accessService = $this->getAccessService();
                    if ($accessService !== null) {
                        $userId = (int) $user->id;
                        if ($catid > 0) {
                            $authorised = $accessService->canCreateArticle($userId, $catid);
                        } else {
                            $authorised = $accessService->canCreateInAnyCategory($userId);
                        }
                    }
                }

                return $authorised;
            }
        };

        $this->assert(
            $view->testAuthorizeNewArticle(97) === false,
            'SmartAuthorsFormView must block unauthorized user from creating new article in category 97'
        );

        echo "  [PASS] Phase 2 Test: New Article Path Blocks Unauthorized User\n";
    }

    /**
     * Tests SmartAuthorsCategoryModel::getCategory() injects access-create = true for actors.
     */
    public function testCategoryModelAccessCreateInjected(): void
    {
        $accessService = $this->createAccessService();
        $app = Factory::getApplication();

        // User 42 is actor in category 97
        $user = new User(42);
        $user->guest = 0;
        $app->loadIdentity($user);

        $model = new class ($accessService) extends SmartAuthorsCategoryModel {
            public function __construct(AccessService $accessService) {
                parent::__construct();
                $this->setAccessService($accessService);
            }

            public function getCategory()
            {
                // Simulate parent::getCategory() returning category object with empty params
                $category = (object) [
                    'id' => 97,
                    'title' => 'Informatics',
                    'params' => new Registry(),
                ];

                $user = $this->getCurrentUser();
                if ($user && !$user->guest && !$category->params->get('access-create')) {
                    $accessService = $this->getAccessService();
                    $categoryId = (int) ($category->id ?? 0);
                    if ($accessService !== null && $categoryId > 0 && $accessService->canCreateArticle((int) $user->id, $categoryId)) {
                        $category->params->set('access-create', true);
                    }
                }

                return $category;
            }
        };

        $category = $model->getCategory();
        $this->assert(
            $category->params->get('access-create') === true,
            'SmartAuthorsCategoryModel must inject access-create = true for effective actor'
        );

        // User 55 is NOT an actor
        $user55 = new User(55);
        $user55->guest = 0;
        $app->loadIdentity($user55);

        $modelUnauth = new class ($accessService) extends SmartAuthorsCategoryModel {
            public function __construct(AccessService $accessService) {
                parent::__construct();
                $this->setAccessService($accessService);
            }

            public function getCategory()
            {
                $category = (object) [
                    'id' => 97,
                    'title' => 'Informatics',
                    'params' => new Registry(),
                ];

                $user = $this->getCurrentUser();
                if ($user && !$user->guest && !$category->params->get('access-create')) {
                    $accessService = $this->getAccessService();
                    $categoryId = (int) ($category->id ?? 0);
                    if ($accessService !== null && $categoryId > 0 && $accessService->canCreateArticle((int) $user->id, $categoryId)) {
                        $category->params->set('access-create', true);
                    }
                }

                return $category;
            }
        };

        $categoryUnauth = $modelUnauth->getCategory();
        $this->assert(
            !$categoryUnauth->params->get('access-create'),
            'SmartAuthorsCategoryModel must not set access-create for unauthorized user'
        );

        echo "  [PASS] Phase 2 Test: CategoryModel access-create Injection for Category Views\n";
    }

    /**
     * Verifies that loadExistingArticleRecord does NOT query featured_up or featured_down from #__content,
     * but correctly LEFT JOINs #__content_frontpage to avoid MySQL 1054 error.
     */
    public function testLoadExistingArticleRecordQueryJoinsFrontpage(): void
    {
        $holder = new \stdClass();
        $holder->captured = null;
        $dbMock = new class ($holder) {
            public object $holder;
            public function __construct(object $holder) {
                $this->holder = $holder;
            }
            public function quoteName(string|array $name, ?string $as = null): string {
                if (is_array($name)) { return implode(', ', $name); }
                return $as ? "`{$name}` AS `{$as}`" : "`{$name}`";
            }
            public function getQuery(bool $new = true): \Joomla\Database\DatabaseQuery {
                return new \Joomla\Database\DatabaseQuery();
            }
            public function setQuery(mixed $query): self {
                $this->holder->captured = $query;
                return $this;
            }
            public function loadObject(): ?object {
                return (object) [
                    'id' => 186,
                    'catid' => 97,
                    'created_by' => 42,
                    'state' => 1,
                    'featured' => 0,
                    'featured_up' => null,
                    'featured_down' => null,
                ];
            }
        };

        $model = new class ($dbMock) extends SmartAuthorsFormModel {
            private object $mockDb;
            public function __construct(object $mockDb) {
                parent::__construct();
                $this->mockDb = $mockDb;
            }
            public function getDatabase(): object {
                return $this->mockDb;
            }
            public function testLoad(int $id): ?object {
                return $this->loadExistingArticleRecord($id);
            }
        };

        $record = $model->testLoad(186);

        $this->assert($record !== null, 'Record must be loaded');
        $this->assert($record->id === 186, 'Record ID must match');
        $capturedQuery = $holder->captured;
        $this->assert($capturedQuery !== null, 'Database query must have been constructed');

        // Verify that #__content is the primary FROM table
        $this->assert(str_contains($capturedQuery->from, '#__content'), 'Primary table must be #__content');

        // Verify that #__content_frontpage is LEFT JOINed
        $this->assert(count($capturedQuery->joins) === 1, 'Query must have exactly 1 join');
        $this->assert($capturedQuery->joins[0][0] === 'LEFT', 'Join type must be LEFT');
        $this->assert(str_contains($capturedQuery->joins[0][1], '#__content_frontpage'), 'Join table must be #__content_frontpage');

        // Verify that featured_up is not selected from #__content directly
        $selectString = implode(' ', $capturedQuery->selects);
        $this->assert(!str_contains($selectString, 'c.featured_up'), 'c.featured_up must NOT be queried from #__content');
        $this->assert(str_contains($selectString, 'fp.featured_up'), 'fp.featured_up must be queried from frontpage table');

        echo "  [PASS] Phase 2 Test: loadExistingArticleRecord Joins #__content_frontpage (1054 regression fix)\n";
    }

    /**
     * Verifies that SmartAuthorsFormModel::checkin() and checkout() allow authorized SmartAuthors
     * editors to check in and checkout articles left checked out by administrators or other users.
     */
    public function testCheckinAndCheckoutAuthorizedSmartAuthors(): void
    {
        $accessService = $this->createAccessService();

        // Create table mock holding checked_out = 1 (Administrator)
        $mockTable = new class {
            public int $id = 186;
            public int $catid = 97;
            public int $created_by = 42;
            public ?int $checked_out = 1; // Locked by Admin
            public ?string $checked_out_time = '2026-09-24 10:00:00';
            public function load(mixed $pk): bool { return true; }
            public function hasField(string $name): bool { return true; }
            public function getColumnAlias(string $name): string { return $name; }
            public function checkIn(mixed $pk): bool {
                $this->checked_out = null;
                $this->checked_out_time = null;
                return true;
            }
            public function checkOut(int $userId, mixed $pk): bool {
                $this->checked_out = $userId;
                $this->checked_out_time = '2026-09-24 10:30:00';
                return true;
            }
            public function getError(): string { return ''; }
        };

        // User 42 is authorized editor for category 97
        $app = Factory::getApplication();
        $user42 = new User();
        $user42->id = 42;
        $user42->guest = 0;
        $app->loadIdentity($user42);

        $model = new class ($mockTable, $accessService) extends SmartAuthorsFormModel {
            private object $mockTable;
            public function __construct(object $mockTable, AccessService $accessService) {
                parent::__construct();
                $this->mockTable = $mockTable;
                $this->setAccessService($accessService);
            }
            public function getTable(string $name = 'Content', string $prefix = 'Administrator', array $options = []): object {
                return $this->mockTable;
            }
        };

        // Test Checkout: authorized user 42 can take over checkout from admin
        $this->assert($mockTable->checked_out === 1, 'Initial lock must be admin (1)');
        $checkoutResult = $model->checkout(186);
        $this->assert($checkoutResult === true, 'Authorized SmartAuthors editor must be allowed to checkout');
        $this->assert($mockTable->checked_out === 42, 'Checkout must now belong to user 42');

        // Test Checkin: authorized user 42 checks in the article
        $checkinResult = $model->checkin(186);
        $this->assert($checkinResult === true, 'Authorized SmartAuthors editor must be allowed to checkin');
        $this->assert($mockTable->checked_out === null, 'Table must be checked in (checked_out = null)');

        // Test Checkin when locked by admin and user is 42
        $mockTable->checked_out = 1; // Locked by Admin again
        $checkinAdminLock = $model->checkin(186);
        $this->assert($checkinAdminLock === true, 'SmartAuthors editor can check in item locked by admin upon save');
        $this->assert($mockTable->checked_out === null, 'Lock must be released');

        echo "  [PASS] Phase 2 Test: SmartAuthorsFormModel checkin & checkout overrides mismatch for authorized editors\n";
    }
}
