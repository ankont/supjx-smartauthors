<?php

declare(strict_types=1);

namespace SuperSoft\Plugin\System\SmartAuthors\Model;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\MVC\Factory\MVCFactoryInterface;
use Joomla\Component\Content\Site\Model\CategoryModel as CoreCategoryModel;
use SuperSoft\Plugin\System\SmartAuthors\Domain\Service\AccessService;
use SuperSoft\Plugin\System\SmartAuthors\Extension\SmartAuthors;

/**
 * Decorated CategoryModel ensuring actor category blog and list views display unpublished items.
 */
class SmartAuthorsCategoryModel extends CoreCategoryModel
{
    protected $name = 'category';
    protected $option = 'com_content';
    protected $text_prefix = 'COM_CONTENT';
    protected $context = 'com_content.category';

    protected ?AccessService $accessService = null;

    public function __construct($config = [], ?MVCFactoryInterface $factory = null)
    {
        $config['name'] = $config['name'] ?? 'category';
        $config['option'] = $config['option'] ?? 'com_content';

        $this->name = 'category';
        $this->option = 'com_content';
        $this->text_prefix = 'COM_CONTENT';
        $this->context = 'com_content.category';

        parent::__construct($config, $factory);

        $this->name = 'category';
        $this->option = 'com_content';
        $this->text_prefix = 'COM_CONTENT';
        $this->context = 'com_content.category';
    }

    public function getName(): string
    {
        return 'category';
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

    protected function populateState($ordering = null, $direction = null)
    {
        parent::populateState($ordering, $direction);

        $user = $this->getCurrentUser();
        $pk   = (int) ($this->state ? $this->state->get('category.id') : 0);

        if ($pk > 0 && $user && !$user->guest) {
            $accessService = $this->getAccessService();
            if ($accessService !== null && $accessService->isEffectiveActor((int) $user->id, $pk)) {
                $this->setState('filter.published', [0, 1]);
            }
        }
    }

    /**
     * Injects access-create permission into category parameters if authorized by SmartAuthors.
     *
     * @return object|false
     */
    public function getCategory()
    {
        $category = parent::getCategory();
        if (!is_object($category)) {
            return $category;
        }

        $user = $this->getCurrentUser();
        if ($user && !$user->guest && !$category->getParams()->get('access-create')) {
            $accessService = $this->getAccessService();
            $categoryId = (int) ($category->id ?? 0);
            if ($accessService !== null && $categoryId > 0 && $accessService->canCreateArticle((int) $user->id, $categoryId)) {
                $category->getParams()->set('access-create', true);
            }
        }

        return $category;
    }
}

