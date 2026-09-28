<?php

declare(strict_types=1);

namespace SuperSoft\Plugin\System\SmartAuthors\Controller;

defined('_JEXEC') or die;

use Joomla\CMS\Application\CMSApplicationInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\MVC\Factory\MVCFactoryInterface;
use Joomla\Component\Content\Site\Controller\DisplayController as CoreDisplayController;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use Joomla\Input\Input;
use SuperSoft\Plugin\System\SmartAuthors\Domain\Service\AccessService;
use SuperSoft\Plugin\System\SmartAuthors\Extension\SmartAuthors;

/**
 * Decorated DisplayController enabling SmartAuthors authorization for direct frontend Form views.
 */
class SmartAuthorsDisplayController extends CoreDisplayController
{
    protected $name = 'display';
    protected $option = 'com_content';
    protected $default_view = 'categories';

    protected ?AccessService $accessService = null;

    public function __construct(
        $config = [],
        ?MVCFactoryInterface $factory = null,
        ?CMSApplicationInterface $app = null,
        ?Input $input = null
    ) {
        $config['name'] = $config['name'] ?? 'display';
        $config['option'] = $config['option'] ?? 'com_content';

        $this->name = 'display';
        $this->option = 'com_content';

        parent::__construct($config, $factory, $app, $input);

        $this->name = 'display';
        $this->option = 'com_content';

        if ($factory !== null) {
            $this->factory = $factory;
        }
    }

    public function getName(): string
    {
        return 'display';
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
     * Displays a view, validating direct SmartAuthors edit authorization if unheld.
     *
     * @param boolean $cachable If true, the view output will be cached.
     * @param boolean|array $urlparams An array of safe URL parameters.
     * @return static
     */
    public function display($cachable = false, $urlparams = false)
    {
        $id = $this->input->getInt('a_id');
        $vName = $this->input->getCmd('view', 'categories');

        // Path A (Direct access to view=form for existing article without prior article.edit task)
        if ($vName === 'form' && $id > 0 && !$this->checkEditId('com_content.edit.article', $id)) {
            $user = $this->app->getIdentity();
            if ($user && !$user->guest) {
                $accessService = $this->getAccessService();
                if ($accessService !== null) {
                    $article = $this->loadArticleRecord($id);
                    if ($article !== null && $accessService->canEditArticle((int) $user->id, $article)) {
                        $this->holdEditId('com_content.edit.article', $id);
                        try {
                            $model = $this->getModel('Form');
                            if ($model && method_exists($model, 'checkout')) {
                                $model->checkout($id);
                            }
                        } catch (\Throwable) {
                        }
                    }
                }
            }
        }

        parent::display($cachable, $urlparams);

        return $this;
    }

    /**
     * Loads article record for edit permission evaluation.
     */
    protected function loadArticleRecord(int $articleId): ?object
    {
        try {
            $db = $this->getDatabase();
            $query = $db->getQuery(true)
                ->select(['id', 'catid', 'created_by', 'state', 'publish_up', 'publish_down'])
                ->from($db->quoteName('#__content'))
                ->where($db->quoteName('id') . ' = :id')
                ->bind(':id', $articleId, ParameterType::INTEGER);

            $db->setQuery($query);
            $result = $db->loadObject();

            return is_object($result) ? $result : null;
        } catch (\Throwable) {
            return null;
        }
    }

    protected function getDatabase(): DatabaseInterface
    {
        return Factory::getContainer()->get(DatabaseInterface::class);
    }
}
