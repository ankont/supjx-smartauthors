<?php

declare(strict_types=1);

namespace SuperSoft\Plugin\System\SmartAuthors\Model;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\MVC\Factory\MVCFactoryInterface;
use Joomla\Component\Content\Site\Model\ArticlesModel as CoreArticlesModel;
use Joomla\Database\DatabaseQuery;
use Joomla\Database\ParameterType;
use SuperSoft\Plugin\System\SmartAuthors\Domain\Service\AccessService;
use SuperSoft\Plugin\System\SmartAuthors\Extension\SmartAuthors;

/**
 * Decorated ArticlesModel providing unified editorial visibility for SmartAuthors effective actors.
 */
class SmartAuthorsArticlesModel extends CoreArticlesModel
{
    protected $name = 'articles';
    protected $option = 'com_content';
    protected $text_prefix = 'COM_CONTENT';
    protected $context = 'com_content.articles';

    protected ?AccessService $accessService = null;

    public function __construct($config = [], ?MVCFactoryInterface $factory = null)
    {
        $config['name'] = $config['name'] ?? 'articles';
        $config['option'] = $config['option'] ?? 'com_content';

        $this->name = 'articles';
        $this->option = 'com_content';
        $this->text_prefix = 'COM_CONTENT';
        $this->context = 'com_content.articles';

        parent::__construct($config, $factory);

        $this->name = 'articles';
        $this->option = 'com_content';
        $this->text_prefix = 'COM_CONTENT';
        $this->context = 'com_content.articles';
    }

    public function getName(): string
    {
        return 'articles';
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
     * Decorates getListQuery to allow effective actors to view unpublished and scheduled articles
     * in their assigned categories without exposing foreign unpublished content or Trashed items.
     *
     * @return DatabaseQuery
     */
    protected function getListQuery()
    {
        $user = $this->getCurrentUser();
        $accessService = $this->getAccessService();

        // Unauthenticated users or users with component-wide core.edit do not need decoration
        if (!$user || $user->guest || $accessService === null || $user->authorise('core.edit', 'com_content')) {
            return parent::getListQuery();
        }

        $actorCategoryIds = $accessService->getEffectiveActorCategoryIds((int) $user->id);
        if (empty($actorCategoryIds)) {
            return parent::getListQuery();
        }

        /** @var DatabaseQuery $query */
        $query = parent::getListQuery();
        if (!$query || !$query->where) {
            return $query;
        }

        $db = $this->getDatabase();
        $nowDate = Factory::getDate()->toSql();

        // Extract where elements
        $elements = $query->where->getElements();
        $filteredElements = [];

        foreach ($elements as $element) {
            $elemStr = (string) $element;

            // Strip native state filtering: 'a.state = ...' or 'a.state IN (...)'
            if (str_contains($elemStr, 'a.state') || str_contains($elemStr, '`a`.`state`')) {
                continue;
            }

            // Strip native publication date filtering: 'a.publish_up' or 'a.publish_down'
            if (str_contains($elemStr, 'publish_up') || str_contains($elemStr, 'publish_down')) {
                continue;
            }

            $filteredElements[] = $element;
        }

        // Clear where and re-apply non-state elements
        $query->clear('where');
        if (!empty($filteredElements)) {
            $query->where($filteredElements);
        }

        // Branch 1: Standard public published content within valid publish dates
        $publicBranch = '(' . $db->quoteName('c.published') . ' = 1 AND ' . $db->quoteName('a.state') . ' = 1'
            . ' AND (' . $db->quoteName('a.publish_up') . ' IS NULL OR ' . $db->quoteName('a.publish_up') . ' <= :saNowUp)'
            . ' AND (' . $db->quoteName('a.publish_down') . ' IS NULL OR ' . $db->quoteName('a.publish_down') . ' >= :saNowDown))';

        // Branch 2: Actor categories where article state is 0 (Unpublished) or 1 (Published)
        // Strictly excludes Trashed (-2) content. Editorial visibility is unified for all effective actors.
        $boundCats = implode(',', $query->bindArray($actorCategoryIds, ParameterType::INTEGER));
        $actorBranch = '(' . $db->quoteName('c.published') . ' = 1 AND '
            . $db->quoteName('a.catid') . ' IN (' . $boundCats . ')'
            . ' AND ' . $db->quoteName('a.state') . ' IN (0, 1))';

        $compositeCondition = '(' . $publicBranch . ' OR ' . $actorBranch . ')';

        $query->where($compositeCondition)
            ->bind(':saNowUp', $nowDate)
            ->bind(':saNowDown', $nowDate);

        return $query;
    }
}
