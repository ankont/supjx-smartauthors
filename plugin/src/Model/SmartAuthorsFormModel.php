<?php

declare(strict_types=1);

namespace SuperSoft\Plugin\System\SmartAuthors\Model;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Form\FormFactoryInterface;
use Joomla\CMS\Form\FormHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Factory\MVCFactoryInterface;
use Joomla\Component\Content\Site\Model\FormModel as CoreFormModel;
use Joomla\Database\ParameterType;
use SuperSoft\Plugin\System\SmartAuthors\Domain\Service\AccessService;
use SuperSoft\Plugin\System\SmartAuthors\Extension\SmartAuthors;

/**
 * Decorated FormModel handling SmartAuthors form preparation, category moves, and state integrity.
 */
class SmartAuthorsFormModel extends CoreFormModel
{
    protected $name = 'form';
    protected $option = 'com_content';
    protected $text_prefix = 'COM_CONTENT';
    public $typeAlias = 'com_content.article';
    protected $associationsContext = 'com_content.item';

    protected ?AccessService $accessService = null;

    public function __construct($config = [], ?MVCFactoryInterface $factory = null, ?FormFactoryInterface $formFactory = null)
    {
        $config['name'] = $config['name'] ?? 'form';
        $config['option'] = $config['option'] ?? 'com_content';

        $this->name = 'form';
        $this->option = 'com_content';
        $this->text_prefix = 'COM_CONTENT';

        parent::__construct($config, $factory, $formFactory);

        $this->name = 'form';
        $this->option = 'com_content';
        $this->text_prefix = 'COM_CONTENT';
    }

    public function getName(): string
    {
        return 'form';
    }

    public function setAccessService(AccessService $accessService): void
    {
        $this->accessService = $accessService;
    }

    protected function getAccessService(): ?AccessService
    {
        if ($this->accessService !== null) {
            return $this->accessService;
        }

        $plugin = SmartAuthors::getInstance();
        if ($plugin !== null) {
            $this->accessService = $plugin->getAccessService();
            return $this->accessService;
        }

        try {
            /** @var SmartAuthors|null $booted */
            $booted = Factory::getApplication()->bootPlugin('smartauthors', 'system');
            if ($booted !== null) {
                $this->accessService = $booted->getAccessService();
            }
        } catch (\Throwable) {
        }

        return $this->accessService;
    }

    /**
     * Injects SmartAuthors access-edit and access-change permissions into the item.
     *
     * @param int|null $itemId
     * @return object|false
     */
    public function getItem($itemId = null)
    {
        $value = parent::getItem($itemId);
        if (!$value || !is_object($value)) {
            return $value;
        }

        $user = $this->getCurrentUser();
        $accessService = $this->getAccessService();

        if (!$user || $user->guest || $accessService === null) {
            return $value;
        }

        $userId = (int) $user->id;
        $id = (int) ($value->id ?? 0);

        // Evaluate access-edit if not already granted by Joomla ACL
        if (!$value->params->get('access-edit')) {
            if ($accessService->canEditArticle($userId, $value)) {
                $value->params->set('access-edit', true);
            }
        }

        // Evaluate access-change (state and scheduling) if not already granted by Joomla ACL
        if (!$value->params->get('access-change')) {
            if ($id > 0) {
                if ($accessService->canManageArticleState($userId, $value)) {
                    $value->params->set('access-change', true);
                }
            } else {
                $catId = (int) $this->getState('article.catid', $value->catid ?? 0);
                if ($catId > 0 && $accessService->canManageNewArticleState($userId, $catId)) {
                    $value->params->set('access-change', true);
                }
            }
        }

        return $value;
    }

    /**
     * Customizes the article edit form for SmartAuthors policies.
     *
     * @param array $data
     * @param bool $loadData
     * @return \Joomla\CMS\Form\Form|false
     */
    public function getForm($data = [], $loadData = true)
    {
        // Guarantee SmartAuthors field prefix is registered first
        FormHelper::addFieldPrefix('SuperSoft\\Plugin\\System\\SmartAuthors\\Field');

        $form = parent::getForm($data, $loadData);
        if (empty($form)) {
            return false;
        }

        $app  = Factory::getApplication();
        $user = $this->getCurrentUser();
        $accessService = $this->getAccessService();

        $id = (int) $this->getState('article.id', $app->getInput()->getInt('a_id', 0));
        $catId = (int) $this->getState('article.catid', $app->getInput()->getInt('catid', 0));

        if (!$user || $user->guest || $accessService === null) {
            return $form;
        }

        $userId = (int) $user->id;

        // 1. Category Move Handling: unlock catid if user can edit the article
        if ($id > 0) {
            $article = $this->loadExistingArticleRecord($id);
            $canEditSource = $user->authorise('core.edit', 'com_content.article.' . $id)
                || ($article && $article->created_by == $userId && $user->authorise('core.edit.own', 'com_content.article.' . $id))
                || ($article && $accessService->canEditArticle($userId, $article));

            if ($canEditSource) {
                $form->setFieldAttribute('catid', 'readonly', 'false');
                $form->setFieldAttribute('catid', 'required', 'true');
                $form->setFieldAttribute('catid', 'filter', 'int');
            }
        }

        // 2. State & Scheduling Configuration
        $hasNativeState = $user->authorise(
            'core.edit.state',
            $id > 0 ? 'com_content.article.' . $id : ($catId > 0 ? 'com_content.category.' . $catId : 'com_content')
        );

        if ($id === 0) {
            // New Article Path
            if (!$hasNativeState) {
                $canManageNewState = $catId > 0 && $accessService->canManageNewArticleState($userId, $catId);

                if (!$canManageNewState) {
                    // StateMode::NONE: strictly enforce unpublished state=0 and lock fields
                    $form->setValue('state', null, 0);
                    $form->setFieldAttribute('state', 'disabled', 'true');
                    $form->setFieldAttribute('state', 'filter', 'unset');
                    $form->setFieldAttribute('publish_up', 'disabled', 'true');
                    $form->setFieldAttribute('publish_up', 'filter', 'unset');
                    $form->setFieldAttribute('publish_down', 'disabled', 'true');
                    $form->setFieldAttribute('publish_down', 'filter', 'unset');
                } else {
                    // StateMode::OWN or StateMode::ALL: unlock state and scheduling
                    $form->setFieldAttribute('state', 'disabled', 'false');
                    $form->setFieldAttribute('state', 'filter', 'int');
                    $form->setFieldAttribute('publish_up', 'disabled', 'false');
                    $form->setFieldAttribute('publish_up', 'filter', 'user_utc');
                    $form->setFieldAttribute('publish_down', 'disabled', 'false');
                    $form->setFieldAttribute('publish_down', 'filter', 'user_utc');

                    // Remove Trashed (-2) from selectable options for NEW articles
                    $this->removeTrashedOptionFromStateField($form);
                }
            }
        } else {
            // Existing Article Path
            if (!$hasNativeState) {
                $article = $this->loadExistingArticleRecord($id);
                $canManageState = $article && $accessService->canManageArticleState($userId, $article);

                if ($canManageState) {
                    $form->setFieldAttribute('state', 'disabled', 'false');
                    $form->setFieldAttribute('state', 'filter', 'int');
                    $form->setFieldAttribute('publish_up', 'disabled', 'false');
                    $form->setFieldAttribute('publish_up', 'filter', 'user_utc');
                    $form->setFieldAttribute('publish_down', 'disabled', 'false');
                    $form->setFieldAttribute('publish_down', 'filter', 'user_utc');
                } elseif ($article && $accessService->canTrashArticle($userId, $article)) {
                    // Own unpublished draft: allow choosing between Unpublished (0) and Trashed (-2)
                    $form->setFieldAttribute('state', 'disabled', 'false');
                    $form->setFieldAttribute('state', 'filter', 'int');
                    $this->filterStateOptionsForDraftsOnly($form);
                }
            }
        }

        // 3. Strict Neutralization of Featured Fields (SmartAuthors never grants Featured)
        if (!$hasNativeState) {
            $form->setFieldAttribute('featured', 'type', 'hidden');
            $form->setFieldAttribute('featured', 'filter', 'unset');
            $form->setFieldAttribute('featured_up', 'type', 'hidden');
            $form->setFieldAttribute('featured_up', 'filter', 'unset');
            $form->setFieldAttribute('featured_down', 'type', 'hidden');
            $form->setFieldAttribute('featured_down', 'filter', 'unset');
        }

        return $form;
    }

    /**
     * Saves article data with strict validation of StateMode, Category Moves, and Featured integrity.
     *
     * @param array $data
     * @return bool
     */
    public function save($data)
    {
        $user = $this->getCurrentUser();
        $accessService = $this->getAccessService();
        $userId = (int) ($user->id ?? 0);
        $isNew = empty($data['id']);

        $catId = (int) ($data['catid'] ?? 0);
        $hasNativeState = $user->authorise(
            'core.edit.state',
            !$isNew ? 'com_content.article.' . (int) $data['id'] : ($catId > 0 ? 'com_content.category.' . $catId : 'com_content')
        );

        if ($isNew) {
            // StateMode::NONE: force state=0 (Unpublished)
            if (!$hasNativeState && $accessService !== null) {
                if (!$accessService->canManageNewArticleState($userId, $catId)) {
                    $data['state'] = 0;
                    $data['publish_up'] = null;
                    $data['publish_down'] = null;
                }
            }

            // Featured cannot be set without native core.edit.state
            if (!$hasNativeState) {
                $data['featured'] = 0;
                $data['featured_up'] = null;
                $data['featured_down'] = null;
            }
        } else {
            $id = (int) $data['id'];
            $existing = $this->loadExistingArticleRecord($id);

            if ($existing) {
                // Invariant: created_by MUST NOT change on category move or article update unless authorized admin
                $canChangeCreatedBy = $user->authorise('core.admin')
                    || $user->authorise('core.admin', 'com_content')
                    || $user->authorise('core.admin', 'com_content.article.' . $id)
                    || $user->authorise('core.manage', 'com_users');

                if (!$canChangeCreatedBy) {
                    $data['created_by'] = (int) $existing->created_by;
                }

                // Invariant: Featured integrity
                if (!$hasNativeState) {
                    $data['featured'] = (int) $existing->featured;
                    $data['featured_up'] = $existing->featured_up ?? null;
                    $data['featured_down'] = $existing->featured_down ?? null;
                }

                // Invariant: State integrity for existing articles when user cannot manage state
                if (!$hasNativeState && $accessService !== null && !$accessService->canManageArticleState($userId, $existing)) {
                    $targetState = isset($data['state']) ? (int) $data['state'] : (int) $existing->state;
                    $canTrash = $accessService->canTrashArticle($userId, $existing);

                    if ($canTrash && in_array($targetState, [0, -2], true)) {
                        $data['state'] = $targetState;
                    } else {
                        $data['state'] = (int) $existing->state;
                    }
                    $data['publish_up'] = $existing->publish_up ?? null;
                    $data['publish_down'] = $existing->publish_down ?? null;
                }

                // Invariant: Category Move validation
                if (isset($data['catid']) && (int) $data['catid'] !== (int) $existing->catid) {
                    $destCatId = (int) $data['catid'];

                    $canEditSource = $user->authorise('core.edit', 'com_content.article.' . $id)
                        || ($existing->created_by == $userId && $user->authorise('core.edit.own', 'com_content.article.' . $id))
                        || ($accessService !== null && $accessService->canEditArticle($userId, $existing));

                    $canCreateDest = $user->authorise('core.create', 'com_content.category.' . $destCatId)
                        || ($accessService !== null && $accessService->canCreateArticle($userId, $destCatId));

                    if (!$canEditSource || !$canCreateDest) {
                        $this->setError(Text::_('JLIB_APPLICATION_ERROR_EDIT_NOT_PERMITTED'));
                        return false;
                    }
                }
            }
        }

        return parent::save($data);
    }

    /**
     * Checks in a record, allowing authorized SmartAuthors editors to release locks
     * even if the item was left checked out by another user (e.g. administrator).
     *
     * @param   int|null  $pk  The numeric id of the primary key.
     * @return  bool
     */
    public function checkin($pk = null)
    {
        if ($pk) {
            $user = $this->getCurrentUser();
            $table = $this->getTable();

            if (!$table->load($pk)) {
                $this->setError($table->getError());
                return false;
            }

            if (!$table->hasField('checked_out') || !$table->hasField('checked_out_time')) {
                return true;
            }

            $checkedOutField = $table->getColumnAlias('checked_out');

            // If checked out by another user, check if current user is an authorized SmartAuthors editor
            if ($table->$checkedOutField > 0 && $table->$checkedOutField != ($user->id ?? 0)) {
                $accessService = $this->getAccessService();
                $userId = (int) ($user->id ?? 0);

                if ($accessService !== null && $userId > 0 && $accessService->canEditArticle($userId, $table)) {
                    if (!$table->checkIn($pk)) {
                        $this->setError($table->getError());
                        return false;
                    }

                    return true;
                }
            }
        }

        return parent::checkin($pk);
    }

    /**
     * Checks out a record, allowing authorized SmartAuthors editors to take over checkout
     * if the record was left locked by another user (e.g. administrator).
     *
     * @param   int|null  $pk  The numeric id of the primary key.
     * @return  bool
     */
    public function checkout($pk = null)
    {
        if ($pk) {
            $user = $this->getCurrentUser();
            $table = $this->getTable();

            if (!$table->load($pk)) {
                $this->setError($table->getError());
                return false;
            }

            if (!$table->hasField('checked_out') || !$table->hasField('checked_out_time')) {
                return true;
            }

            $checkedOutField = $table->getColumnAlias('checked_out');

            // If checked out by another user, check if current user is an authorized SmartAuthors editor
            if ($table->$checkedOutField > 0 && $table->$checkedOutField != ($user->id ?? 0)) {
                $accessService = $this->getAccessService();
                $userId = (int) ($user->id ?? 0);

                if ($accessService !== null && $userId > 0 && $accessService->canEditArticle($userId, $table)) {
                    if (!$table->checkOut($userId, $pk)) {
                        $this->setError($table->getError());
                        return false;
                    }

                    return true;
                }
            }
        }

        return parent::checkout($pk);
    }

    /**
     * Removes option -2 (Trashed) from the state field SimpleXML definition.
     */
    protected function removeTrashedOptionFromStateField(\Joomla\CMS\Form\Form $form): void
    {
        $xml = $form->getXml();
        if ($xml) {
            $nodes = $xml->xpath('//field[@name="state"]/option[@value="-2"]');
            if ($nodes) {
                foreach ($nodes as $node) {
                    unset($node[0]);
                }
            }
        }
    }

    /**
     * Filters state field options to only allow private Draft states (0 Unpublished, -2 Trashed),
     * removing 1 (Published) and 2 (Archived - publicly accessible via /archive or direct URL).
     */
    protected function filterStateOptionsForDraftsOnly(\Joomla\CMS\Form\Form $form): void
    {
        $xml = $form->getXml();
        if ($xml) {
            $nodes = $xml->xpath('//field[@name="state"]/option[@value="1" or @value="2"]');
            if ($nodes) {
                foreach ($nodes as $node) {
                    unset($node[0]);
                }
            }
        }
    }

    /**
     * Helper to load existing article DB record.
     */
    protected function loadExistingArticleRecord(int $id): ?object
    {
        if ($id <= 0) {
            return null;
        }

        $db = $this->getDatabase();
        $query = $db->getQuery(true)
            ->select(
                [
                    $db->quoteName('c.id', 'id'),
                    $db->quoteName('c.catid', 'catid'),
                    $db->quoteName('c.created_by', 'created_by'),
                    $db->quoteName('c.state', 'state'),
                    $db->quoteName('c.publish_up', 'publish_up'),
                    $db->quoteName('c.publish_down', 'publish_down'),
                    $db->quoteName('c.featured', 'featured'),
                    $db->quoteName('fp.featured_up', 'featured_up'),
                    $db->quoteName('fp.featured_down', 'featured_down'),
                ]
            )
            ->from($db->quoteName('#__content', 'c'))
            ->join('LEFT', $db->quoteName('#__content_frontpage', 'fp'), $db->quoteName('c.id') . ' = ' . $db->quoteName('fp.content_id'))
            ->where($db->quoteName('c.id') . ' = :id')
            ->bind(':id', $id, ParameterType::INTEGER);

        $db->setQuery($query);

        return $db->loadObject() ?: null;
    }
}
