<?php

declare(strict_types=1);

namespace SuperSoft\Plugin\System\SmartAuthors\Extension;

defined('_JEXEC') or die;

use Joomla\CMS\Event\Model\AfterDeleteEvent;
use Joomla\CMS\Event\Model\AfterSaveEvent as ModelAfterSaveEvent;
use Joomla\CMS\Event\Model\BeforeSaveEvent as ModelBeforeSaveEvent;
use Joomla\CMS\Event\Model\PrepareFormEvent;
use Joomla\CMS\Event\User\AfterSaveEvent as UserAfterSaveEvent;
use Joomla\CMS\Event\User\BeforeSaveEvent as UserBeforeSaveEvent;
use Joomla\CMS\Extension\ComponentInterface;
use Joomla\CMS\Extension\MVCComponent;
use Joomla\CMS\Factory;
use Joomla\CMS\Form\Form;
use Joomla\CMS\Form\FormHelper;
use Joomla\CMS\MVC\Factory\MVCFactoryInterface;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Database\DatabaseAwareInterface;
use Joomla\Database\DatabaseAwareTrait;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use Joomla\DI\Container;
use Joomla\Event\SubscriberInterface;
use SuperSoft\Plugin\System\SmartAuthors\Domain\Entity\Policy;
use SuperSoft\Plugin\System\SmartAuthors\Domain\Enum\CategoryManagement;
use SuperSoft\Plugin\System\SmartAuthors\Domain\Enum\EditMode;
use SuperSoft\Plugin\System\SmartAuthors\Domain\Enum\PolicyType;
use SuperSoft\Plugin\System\SmartAuthors\Domain\Enum\StateMode;
use SuperSoft\Plugin\System\SmartAuthors\Domain\Service\AccessService;
use SuperSoft\Plugin\System\SmartAuthors\Repository\PolicyRepository;
use SuperSoft\Plugin\System\SmartAuthors\Service\PersonalOwnerBinder;
use SuperSoft\Plugin\System\SmartAuthors\Service\PolicyResolver;
use SuperSoft\Plugin\System\SmartAuthors\Service\RequestCache;

final class SmartAuthors extends CMSPlugin implements SubscriberInterface, DatabaseAwareInterface
{
    use DatabaseAwareTrait;
    /**
     * Affects constructor behavior. Automatically loads language files.
     *
     * @var boolean
     */
    protected $autoloadLanguage = true;

    protected $_name = 'smartauthors';
    protected $_type = 'system';

    private static ?self $instance = null;

    private ?PolicyRepository $policyRepository = null;
    private ?PolicyResolver $policyResolver = null;
    private ?AccessService $accessService = null;
    private ?PersonalOwnerBinder $ownerBinder = null;
    private ?RequestCache $cache = null;

    public static function getInstance(): ?self
    {
        return self::$instance;
    }

    /**
     * Subscribed events for Joomla 5.4+ and Joomla 6.
     *
     * @return array<string, string>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            'onAfterExtensionBoot' => 'onAfterExtensionBoot',
            'onContentPrepareForm' => 'onContentPrepareForm',
            'onContentBeforeSave'  => 'onContentBeforeSave',
            'onContentAfterSave'   => 'onContentAfterSave',
            'onContentAfterDelete' => 'onContentAfterDelete',
            'onUserBeforeSave'     => 'onUserBeforeSave',
            'onUserAfterSave'      => 'onUserAfterSave',
            'onUserLogin'          => 'onUserLogin',
        ];
    }

    public function setServices(
        PolicyRepository $repository,
        PolicyResolver $resolver,
        AccessService $accessService,
        PersonalOwnerBinder $ownerBinder,
        RequestCache $cache,
        ?DatabaseInterface $db = null,
    ): void {
        self::$instance         = $this;
        $this->policyRepository = $repository;
        $this->policyResolver   = $resolver;
        $this->accessService    = $accessService;
        $this->ownerBinder      = $ownerBinder;
        $this->cache            = $cache;
        if ($db !== null) {
            $this->setDatabase($db);
        }
    }

    public function getDatabase(): DatabaseInterface
    {
        if ($this->databaseAwareTraitDatabase !== null) {
            return $this->databaseAwareTraitDatabase;
        }

        try {
            /** @var DatabaseInterface */
            return Factory::getContainer()->get(DatabaseInterface::class);
        } catch (\Throwable) {
            /** @var DatabaseInterface */
            return Factory::getDbo();
        }
    }

    public function getPolicyRepository(): PolicyRepository
    {
        if ($this->policyRepository === null) {
            $db = $this->getDatabase();
            $this->cache ??= new RequestCache();
            $this->policyRepository = new PolicyRepository($db, $this->cache);
        }

        return $this->policyRepository;
    }

    public function getPolicyResolver(): PolicyResolver
    {
        if ($this->policyResolver === null) {
            $db = $this->getDatabase();
            $this->cache ??= new RequestCache();
            $this->policyResolver = new PolicyResolver($this->getPolicyRepository(), $db, $this->cache);
        }

        return $this->policyResolver;
    }

    public function getAccessService(): AccessService
    {
        if ($this->accessService === null) {
            $this->accessService = new AccessService($this->getPolicyResolver());
        }

        return $this->accessService;
    }

    public function getOwnerBinder(): PersonalOwnerBinder
    {
        if ($this->ownerBinder === null) {
            $db = $this->getDatabase();
            $this->cache ??= new RequestCache();
            $this->ownerBinder = new PersonalOwnerBinder($this->getPolicyRepository(), $db, $this->cache);
        }

        return $this->ownerBinder;
    }

    /**
     * Intercepts component boot to decorate com_content with SmartAuthorsMVCFactory.
     */
    public function onAfterExtensionBoot(mixed $event): void
    {
        $extensionName = '';
        $type = '';
        $container = null;

        if (is_object($event) && method_exists($event, 'getArgument')) {
            $extensionName = (string) $event->getArgument('extensionName');
            $type = (string) $event->getArgument('type');
            $container = $event->getArgument('container');
        } elseif (is_array($event)) {
            $extensionName = (string) ($event['extensionName'] ?? '');
            $type = (string) ($event['type'] ?? '');
            $container = $event['container'] ?? null;
        }

        if ($extensionName === 'content' && $container instanceof Container) {
            if ($container->has(MVCFactoryInterface::class)) {
                $innerFactory = $container->get(MVCFactoryInterface::class);
                if (!($innerFactory instanceof SmartAuthorsMVCFactory)) {
                    $container->set(
                        MVCFactoryInterface::class,
                        new SmartAuthorsMVCFactory($innerFactory, $this->getAccessService())
                    );
                }
            }
        }
    }

    /**
     * Ensures com_content MVCComponent has its MVCFactory decorated with SmartAuthorsMVCFactory.
     */
    public function ensureContentComponentDecorated(): void
    {
        try {
            $app = $this->getApplication();
            if (method_exists($app, 'bootComponent')) {
                $component = $app->bootComponent('com_content');
                if ($component instanceof MVCComponent) {
                    $factory = $component->getMVCFactory();
                    if (!($factory instanceof SmartAuthorsMVCFactory)) {
                        $component->setMVCFactory(new SmartAuthorsMVCFactory($factory, $this->getAccessService()));
                    }
                }
            }
        } catch (\Throwable) {
        }
    }

    /**
     * Injects the SmartAuthors fields into the category edit form or registers article form field prefix.
     */
    public function onContentPrepareForm(mixed $event): void
    {
        $form = null;
        $data = null;

        if ($event instanceof PrepareFormEvent) {
            $form = $event->getForm();
            $data = $event->getData();
        } elseif ($event instanceof Form) {
            $form = $event;
            $data = func_num_args() > 1 ? func_get_arg(1) : null;
        }

        if (!$form instanceof Form) {
            return;
        }

        $formName = $form->getName();

        // Handle com_content.article
        if ($formName === 'com_content.article') {
            FormHelper::addFieldPrefix('SuperSoft\\Plugin\\System\\SmartAuthors\\Field');
            $this->ensureContentComponentDecorated();
            return;
        }

        if (!str_starts_with($formName, 'com_categories.category')) {
            return;
        }

        // Server-side authorization check: core.admin on com_content
        $user = $this->getApplication()->getIdentity() ?? Factory::getUser();
        if (!$user->authorise('core.admin', 'com_content')) {
            return;
        }

        $this->loadLanguage();

        $xmlPath = JPATH_PLUGINS . '/system/smartauthors/forms/category_policy.xml';
        if (!file_exists($xmlPath)) {
            $xmlPath = dirname(__DIR__, 2) . '/forms/category_policy.xml';
        }

        if (file_exists($xmlPath)) {
            $form->loadFile($xmlPath, false);

            $doc = $this->getApplication()->getDocument();
            if ($doc instanceof \Joomla\CMS\Document\HtmlDocument) {
                $doc->addStyleDeclaration('
                    .smartauthors-owner-field > .controls,
                    .smartauthors-contributors-field > .controls {
                        max-width: 50%;
                        min-width: 340px;
                    }
                    .smartauthors-owner-field > .controls joomla-field-user,
                    .smartauthors-owner-field > .controls .input-group {
                        width: 100% !important;
                        max-width: 100% !important;
                    }
                    .smartauthors-contributors-field .subform-repeatable-wrapper,
                    .smartauthors-contributors-field joomla-field-subform,
                    .smartauthors-contributors-field .table {
                        width: 100%;
                        max-width: 100%;
                    }
                    .smartauthors-contributors-field .table td .controls,
                    .smartauthors-contributors-field .table td joomla-field-user,
                    .smartauthors-contributors-field .table td .input-group {
                        width: 100% !important;
                        max-width: 100% !important;
                    }
                    @media (max-width: 768px) {
                        .smartauthors-owner-field > .controls,
                        .smartauthors-contributors-field > .controls {
                            max-width: 100%;
                            min-width: auto;
                        }
                    }
                ');
            }
        }

        $categoryId = 0;
        if (is_array($data) && !empty($data['id'])) {
            $categoryId = (int) $data['id'];
        } elseif (is_object($data) && !empty($data->id)) {
            $categoryId = (int) $data->id;
        } else {
            $categoryId = (int) $form->getValue('id');
        }

        if ($categoryId > 0) {
            $policy = $this->getPolicyRepository()->loadPolicy($categoryId);

            if ($policy !== null) {
                $form->setValue('policy', 'smartauthors', 'custom');
                $form->setValue('policy_type', 'smartauthors', $policy->getPolicyType()->value);
                $form->setValue('owner_id', 'smartauthors', $policy->getOwnerId());
                $form->setValue('edit_mode', 'smartauthors', $policy->getEditMode()->value);
                $form->setValue('state_mode', 'smartauthors', $policy->getStateMode()->isNone() ? 'none' : 'all');
                $form->setValue('category_management', 'smartauthors', $policy->getCategoryManagement()->value);

                $contributorsSubform = array_map(
                    static fn(int $id) => ['user_id' => $id],
                    $policy->getContributorIds()
                );
                $form->setValue('contributors', 'smartauthors', $contributorsSubform);
            } else {
                $form->setValue('policy', 'smartauthors', 'inherit');
            }
        } else {
            $form->setValue('policy', 'smartauthors', 'inherit');
        }
    }

    /**
     * Defense-in-depth protection before article is saved.
     * Enforces StateMode::NONE (state=0), created_by immutability, and Featured protection.
     */
    public function onContentBeforeSave(mixed $event): void
    {
        $context = '';
        $table   = null;
        $isNew   = false;

        if ($event instanceof ModelBeforeSaveEvent) {
            $context = $event->getContext();
            $table   = $event->getItem();
            $isNew   = $event->getIsNew();
        } else {
            $context = (string) $event;
            $table   = func_num_args() > 1 ? func_get_arg(1) : null;
            $isNew   = func_num_args() > 2 ? (bool) func_get_arg(2) : false;
        }

        if ($context !== 'com_content.article' || !is_object($table)) {
            return;
        }

        $user = $this->getApplication()->getIdentity() ?? Factory::getUser();
        $userId = (int) ($user->id ?? 0);
        $catId = (int) ($table->catid ?? 0);

        $articleAsset = !$isNew && !empty($table->id) ? 'com_content.article.' . (int) $table->id : ($catId > 0 ? 'com_content.category.' . $catId : 'com_content');
        $hasNativeState = $user->authorise('core.edit.state', $articleAsset);

        $canChangeCreatedBy = $user->authorise('core.admin')
            || $user->authorise('core.admin', 'com_content')
            || $user->authorise('core.admin', $articleAsset)
            || $user->authorise('core.manage', 'com_users');

        if ($isNew) {
            if (!$canChangeCreatedBy && (empty($table->created_by) || (int) $table->created_by !== $userId)) {
                $table->created_by = $userId;
            }

            if (!$hasNativeState) {
                $canManageState = $catId > 0 && $this->getAccessService()->canManageNewArticleState($userId, $catId);

                if (!$canManageState) {
                    // StateMode::NONE: force state=0 (Unpublished)
                    $table->state = 0;
                    $table->publish_up = null;
                    $table->publish_down = null;
                }

                $table->featured = 0;
            }
        } else {
            $articleId = (int) ($table->id ?? 0);
            if ($articleId > 0) {
                $db = $this->getDatabase();
                $query = $db->getQuery(true)
                    ->select($db->quoteName(['created_by', 'featured']))
                    ->from($db->quoteName('#__content'))
                    ->where($db->quoteName('id') . ' = :id')
                    ->bind(':id', $articleId, ParameterType::INTEGER);

                $db->setQuery($query);
                $prev = $db->loadObject();

                if ($prev) {
                    // Invariant: created_by CANNOT change unless user is an authorized admin
                    if (!$canChangeCreatedBy) {
                        $table->created_by = (int) $prev->created_by;
                    }

                    // Invariant: featured protection
                    if (!$hasNativeState) {
                        $table->featured = (int) $prev->featured;
                    }
                }
            }
        }
    }

    /**
     * Handles saving or deleting custom policy when category is saved.
     */
    public function onContentAfterSave(mixed $event): void
    {
        $context = '';
        $table   = null;
        $isNew   = false;
        $data    = [];

        if ($event instanceof ModelAfterSaveEvent) {
            $context = $event->getContext();
            $table   = $event->getItem();
            $isNew   = $event->getIsNew();
            $data    = $event->getData();
        } else {
            $context = (string) $event;
            $table   = func_num_args() > 1 ? func_get_arg(1) : null;
            $isNew   = func_num_args() > 2 ? (bool) func_get_arg(2) : false;
            $data    = func_num_args() > 3 ? func_get_arg(3) : [];
        }

        if ($context !== 'com_categories.category') {
            return;
        }

        // Server-side authorization check: core.admin on com_content
        $user = $this->getApplication()->getIdentity() ?? Factory::getUser();
        if (!$user->authorise('core.admin', 'com_content')) {
            return;
        }

        $smartauthorsData = is_array($data) ? ($data['smartauthors'] ?? null) : null;
        if ($smartauthorsData === null) {
            $smartauthorsData = $this->getApplication()->getInput()->get('jform', [], 'array')['smartauthors'] ?? null;
        }

        if ($smartauthorsData === null || !is_array($smartauthorsData)) {
            return;
        }

        $categoryId = (int) ($table->id ?? ($data['id'] ?? 0));
        if ($categoryId <= 0) {
            return;
        }

        $policyChoice = (string) ($smartauthorsData['policy'] ?? 'inherit');

        if ($policyChoice === 'inherit') {
            $this->getPolicyRepository()->deletePolicy($categoryId);
            return;
        }

        if ($policyChoice === 'custom') {
            $policyType = PolicyType::fromString((string) ($smartauthorsData['policy_type'] ?? 'personal'));
            $editMode   = EditMode::fromString((string) ($smartauthorsData['edit_mode'] ?? 'own'));
            $stateMode  = StateMode::fromString((string) ($smartauthorsData['state_mode'] ?? 'none'));
            $catMgmt    = CategoryManagement::fromString((string) ($smartauthorsData['category_management'] ?? 'none'));

            if ($policyType->isPersonal()) {
                $ownerId = !empty($smartauthorsData['owner_id']) ? (int) $smartauthorsData['owner_id'] : null;
                $policy = new Policy(
                    categoryId: $categoryId,
                    policyType: $policyType,
                    ownerId: $ownerId,
                    contributorIds: [],
                    editMode: $editMode,
                    stateMode: $stateMode,
                    categoryManagement: $catMgmt,
                );

                $this->getPolicyRepository()->savePolicy($policy);

                // If unbound, attempt initial alias=username discovery
                if ($ownerId === null) {
                    $alias = isset($table->alias) ? (string) $table->alias : null;
                    $this->getOwnerBinder()->reconcileUnboundCategory($categoryId, $alias);
                }
            } else {
                $contributorIds = [];
                $rawContributors = $smartauthorsData['contributors'] ?? [];

                if (is_array($rawContributors)) {
                    foreach ($rawContributors as $row) {
                        $uid = (int) (is_array($row) ? ($row['user_id'] ?? 0) : $row);
                        if ($uid > 0) {
                            $contributorIds[] = $uid;
                        }
                    }
                }

                $policy = new Policy(
                    categoryId: $categoryId,
                    policyType: $policyType,
                    ownerId: null,
                    contributorIds: $contributorIds,
                    editMode: $editMode,
                    stateMode: $stateMode,
                    categoryManagement: $catMgmt,
                );

                $this->getPolicyRepository()->savePolicy($policy);
            }
        }
    }

    /**
     * Deletes SmartAuthors policies when a category is deleted.
     */
    public function onContentAfterDelete(mixed $event): void
    {
        $context = '';
        $table   = null;

        if ($event instanceof AfterDeleteEvent) {
            $context = $event->getContext();
            $table   = $event->getItem();
        } else {
            $context = (string) $event;
            $table   = func_num_args() > 1 ? func_get_arg(1) : null;
        }

        if ($context !== 'com_categories.category') {
            return;
        }

        $categoryId = (int) ($table->id ?? 0);
        if ($categoryId > 0) {
            $this->getPolicyRepository()->deletePolicy($categoryId);
        }
    }

    /**
     * Records old username before user is saved to detect real changes.
     */
    public function onUserBeforeSave(mixed $event): void
    {
        $oldUser = [];
        $isNew   = false;

        if ($event instanceof UserBeforeSaveEvent) {
            $oldUser = (array) $event->getSubject();
            $isNew   = $event->getIsNew();
        } else {
            $oldUser = (array) func_get_arg(0);
            $isNew   = func_num_args() > 1 ? (bool) func_get_arg(1) : false;
        }

        if (!$isNew && !empty($oldUser['id']) && !empty($oldUser['username'])) {
            $this->getOwnerBinder()->recordOldUsername((int) $oldUser['id'], (string) $oldUser['username']);
        }
    }

    /**
     * Handles initial binding for new users and alias synchronization on username changes.
     */
    public function onUserAfterSave(mixed $event): void
    {
        $user         = [];
        $isNew        = false;
        $savingResult = true;

        if ($event instanceof UserAfterSaveEvent) {
            $user         = (array) $event->getSubject();
            $isNew        = $event->getIsNew();
            $savingResult = $event->getSavingResult();
        } else {
            $user         = (array) func_get_arg(0);
            $isNew        = func_num_args() > 1 ? (bool) func_get_arg(1) : false;
            $savingResult = func_num_args() > 2 ? (bool) func_get_arg(2) : true;
        }

        if (!$savingResult) {
            return;
        }

        $userId   = (int) ($user['id'] ?? 0);
        $username = (string) ($user['username'] ?? '');

        if ($userId <= 0 || $username === '') {
            return;
        }

        if ($isNew) {
            $this->getOwnerBinder()->reconcileNewUser($userId, $username);
        } else {
            $this->getOwnerBinder()->onUsernameChanged($userId, $username);
        }
    }

    /**
     * Self-healing reconciliation on user login (unbound personal categories only).
     */
    public function onUserLogin(mixed $event): void
    {
        $user = [];

        if (is_array($event)) {
            $user = $event;
        } elseif (func_num_args() > 0 && is_array(func_get_arg(0))) {
            $user = func_get_arg(0);
        }

        $userId   = (int) ($user['id'] ?? 0);
        $username = (string) ($user['username'] ?? '');

        if ($userId <= 0 && $username !== '') {
            $loadedUser = Factory::getUser($username);
            $userId = (int) $loadedUser->id;
        }

        if ($userId > 0 && $username !== '') {
            $this->getOwnerBinder()->reconcileOnLogin($userId, $username);
        }
    }
}
