<?php

declare(strict_types=1);

namespace SuperSoft\Plugin\System\SmartAuthors\Controller;

defined('_JEXEC') or die;

use Joomla\CMS\Application\CMSApplicationInterface;
use Joomla\CMS\Form\FormFactoryInterface;
use Joomla\CMS\MVC\Factory\MVCFactoryInterface;
use Joomla\Component\Content\Site\Controller\ArticleController as CoreArticleController;
use Joomla\Input\Input;
use Joomla\Utilities\ArrayHelper;
use SuperSoft\Plugin\System\SmartAuthors\Domain\Service\AccessService;
use SuperSoft\Plugin\System\SmartAuthors\Extension\SmartAuthors;

/**
 * Decorated ArticleController enabling SmartAuthors authorization for add and edit actions.
 */
class SmartAuthorsArticleController extends CoreArticleController
{
    protected $name = 'article';
    protected $option = 'com_content';
    protected $context = 'article';
    protected $text_prefix = 'COM_CONTENT';
    protected $view_item = 'form';
    protected $view_list = 'categories';
    protected $urlVar = 'a.id';

    protected ?AccessService $accessService = null;

    public function __construct(
        $config = [],
        ?MVCFactoryInterface $factory = null,
        ?CMSApplicationInterface $app = null,
        ?Input $input = null,
        ?FormFactoryInterface $formFactory = null
    ) {
        $config['name'] = $config['name'] ?? 'article';
        $config['option'] = $config['option'] ?? 'com_content';

        $this->name = 'article';
        $this->option = 'com_content';
        $this->context = 'article';
        $this->text_prefix = 'COM_CONTENT';
        $this->view_item = 'form';
        $this->view_list = 'categories';
        $this->urlVar = 'a.id';

        parent::__construct($config, $factory, $app, $input, $formFactory);

        $this->name = 'article';
        $this->option = 'com_content';
        $this->context = 'article';
        $this->text_prefix = 'COM_CONTENT';
        $this->view_item = 'form';
        $this->view_list = 'categories';
        $this->urlVar = 'a.id';
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
            $booted = $this->app->bootPlugin('smartauthors', 'system');
            if ($booted !== null) {
                $this->accessService = $booted->getAccessService();
            }
        } catch (\Throwable) {
        }

        return $this->accessService;
    }

    /**
     * Checks if current user is allowed to add a new article in the target category.
     *
     * @param array $data
     * @return bool
     */
    protected function allowAdd($data = [])
    {
        if (parent::allowAdd($data)) {
            return true;
        }

        $user = $this->app->getIdentity();
        if (!$user || $user->guest) {
            return false;
        }

        $categoryId = ArrayHelper::getValue($data, 'catid', $this->input->getInt('catid'), 'int');
        if ($categoryId <= 0) {
            return false;
        }

        $accessService = $this->getAccessService();

        return $accessService !== null && $accessService->canCreateArticle((int) $user->id, $categoryId);
    }

    /**
     * Checks if current user is allowed to edit the specified article.
     *
     * @param array $data
     * @param string $key
     * @return bool
     */
    protected function allowEdit($data = [], $key = 'id')
    {
        if (parent::allowEdit($data, $key)) {
            return true;
        }

        $user = $this->app->getIdentity();
        if (!$user || $user->guest) {
            return false;
        }

        $recordId = isset($data[$key]) ? (int) $data[$key] : 0;
        if ($recordId <= 0) {
            return false;
        }

        $record = $this->getModel()->getItem($recordId);
        if (empty($record)) {
            return false;
        }

        $accessService = $this->getAccessService();

        return $accessService !== null && $accessService->canEditArticle((int) $user->id, $record);
    }
}
