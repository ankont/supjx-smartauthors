<?php

declare(strict_types=1);

namespace SuperSoft\Plugin\System\SmartAuthors\Model;

defined('_JEXEC') or die;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Factory\MVCFactoryInterface;
use Joomla\Component\Content\Site\Model\ArticleModel as CoreArticleModel;
use Joomla\Database\ParameterType;
use Joomla\Registry\Registry;
use SuperSoft\Plugin\System\SmartAuthors\Domain\Service\AccessService;
use SuperSoft\Plugin\System\SmartAuthors\Extension\SmartAuthors;

/**
 * Decorated ArticleModel with safe SmartAuthors direct editorial visibility (excluding Trashed).
 */
class SmartAuthorsArticleModel extends CoreArticleModel
{
    protected $name = 'article';
    protected $option = 'com_content';
    protected $text_prefix = 'COM_CONTENT';
    protected $_context = 'com_content.article';

    protected ?AccessService $accessService = null;

    public function __construct($config = [], ?MVCFactoryInterface $factory = null)
    {
        $config['name'] = $config['name'] ?? 'article';
        $config['option'] = $config['option'] ?? 'com_content';

        $this->name = 'article';
        $this->option = 'com_content';
        $this->text_prefix = 'COM_CONTENT';
        $this->_context = 'com_content.article';

        parent::__construct($config, $factory);

        $this->name = 'article';
        $this->option = 'com_content';
        $this->text_prefix = 'COM_CONTENT';
        $this->_context = 'com_content.article';
    }

    public function getName(): string
    {
        return 'article';
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
     * Retrieves article with safe editorial visibility for SmartAuthors actors.
     *
     * @param int|null $pk
     * @return object|false
     * @throws \Exception
     */
    public function getItem($pk = null)
    {
        $pk = (int) (!empty($pk) ? $pk : $this->getState('article.id'));
        if ($pk <= 0) {
            return false;
        }

        $user = $this->getCurrentUser();
        $accessService = $this->getAccessService();

        try {
            $item = parent::getItem($pk);
            if ($item && is_object($item) && !$user->guest && $accessService !== null) {
                if ($accessService->canEditArticle((int) $user->id, $item)) {
                    $item->params->set('access-edit', true);
                }
            }

            return $item;
        } catch (\Throwable $e) {
            // If user is guest or no access service, propagate 404
            if (!$user || $user->guest || $accessService === null) {
                throw $e;
            }

            // Inspect database record directly
            $db = $this->getDatabase();
            $checkQuery = $db->getQuery(true)
                ->select([
                    $db->quoteName('a.id'),
                    $db->quoteName('a.catid'),
                    $db->quoteName('a.state'),
                    $db->quoteName('a.created_by'),
                    $db->quoteName('c.published', 'category_published'),
                ])
                ->from($db->quoteName('#__content', 'a'))
                ->join('INNER', $db->quoteName('#__categories', 'c'), $db->quoteName('c.id') . ' = ' . $db->quoteName('a.catid'))
                ->where($db->quoteName('a.id') . ' = :pk')
                ->bind(':pk', $pk, ParameterType::INTEGER);

            $db->setQuery($checkQuery);
            $record = $db->loadObject();

            if (!$record || (int) $record->category_published <= 0) {
                throw $e;
            }

            // Invariant: Trashed content is NEVER accessible via direct view
            if ((int) $record->state === -2) {
                throw $e;
            }

            // Only valid content states (0: Unpublished, 1: Published, 2: Archived)
            if (!\in_array((int) $record->state, [0, 1, 2], true)) {
                throw $e;
            }

            // Invariant: User MUST be an effective actor in this category
            if (!$accessService->isEffectiveActor((int) $user->id, (int) $record->catid)) {
                throw $e;
            }

            // User IS an authorized effective actor! Load full article without date restriction
            $query = $db->getQuery(true)
                ->select($this->getState(
                    'item.select',
                    [
                        $db->quoteName('a.id'),
                        $db->quoteName('a.asset_id'),
                        $db->quoteName('a.title'),
                        $db->quoteName('a.alias'),
                        $db->quoteName('a.introtext'),
                        $db->quoteName('a.fulltext'),
                        $db->quoteName('a.state'),
                        $db->quoteName('a.catid'),
                        $db->quoteName('a.created'),
                        $db->quoteName('a.created_by'),
                        $db->quoteName('a.created_by_alias'),
                        $db->quoteName('a.modified'),
                        $db->quoteName('a.modified_by'),
                        $db->quoteName('a.checked_out'),
                        $db->quoteName('a.checked_out_time'),
                        $db->quoteName('a.publish_up'),
                        $db->quoteName('a.publish_down'),
                        $db->quoteName('a.images'),
                        $db->quoteName('a.urls'),
                        $db->quoteName('a.attribs'),
                        $db->quoteName('a.version'),
                        $db->quoteName('a.ordering'),
                        $db->quoteName('a.metakey'),
                        $db->quoteName('a.metadesc'),
                        $db->quoteName('a.access'),
                        $db->quoteName('a.hits'),
                        $db->quoteName('a.metadata'),
                        $db->quoteName('a.featured'),
                        $db->quoteName('a.language'),
                        $db->quoteName('a.note'),
                        $db->quoteName('c.title', 'category_title'),
                        $db->quoteName('c.alias', 'category_alias'),
                        $db->quoteName('c.access', 'category_access'),
                        $db->quoteName('c.language', 'category_language'),
                        $db->quoteName('u.name', 'author'),
                    ]
                ))
                ->from($db->quoteName('#__content', 'a'))
                ->join('INNER', $db->quoteName('#__categories', 'c'), $db->quoteName('c.id') . ' = ' . $db->quoteName('a.catid'))
                ->join('LEFT', $db->quoteName('#__users', 'u'), $db->quoteName('u.id') . ' = ' . $db->quoteName('a.created_by'))
                ->where([
                    $db->quoteName('a.id') . ' = :pk',
                    $db->quoteName('c.published') . ' > 0',
                ])
                ->bind(':pk', $pk, ParameterType::INTEGER);

            $db->setQuery($query);
            $data = $db->loadObject();

            if (empty($data)) {
                throw new \Exception(Text::_('COM_CONTENT_ERROR_ARTICLE_NOT_FOUND'), 404);
            }

            // Hydrate parameters
            $registry = new Registry($data->attribs);
            $data->params = clone $this->getState('params');
            $globalParams = ComponentHelper::getParams('com_content', true);

            foreach ($data->params->toArray() as $key => $value) {
                if ($value === 'use_article') {
                    $data->params->set($key, $registry->get($key, $globalParams->get($key)));
                }
            }

            $data->params->merge($registry);
            $data->metadata = new Registry($data->metadata);

            // Set contextual authorization params
            $data->params->set('access-view', true);
            $data->params->set('access-edit', $accessService->canEditArticle((int) $user->id, $data));

            $this->_item[$pk] = $data;

            return $data;
        }
    }
}
