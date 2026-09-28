<?php

declare(strict_types=1);

namespace SuperSoft\Plugin\System\SmartAuthors\Extension;

defined('_JEXEC') or die;

use Joomla\CMS\Application\CMSApplicationInterface;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\MVC\Controller\ControllerInterface;
use Joomla\CMS\MVC\Factory\MVCFactoryInterface;
use Joomla\CMS\MVC\Model\ModelInterface;
use Joomla\CMS\MVC\View\ViewInterface;
use Joomla\CMS\Table\Table;
use Joomla\Input\Input;
use SuperSoft\Plugin\System\SmartAuthors\Controller\SmartAuthorsArticleController;
use SuperSoft\Plugin\System\SmartAuthors\Controller\SmartAuthorsDisplayController;
use SuperSoft\Plugin\System\SmartAuthors\Domain\Service\AccessService;
use SuperSoft\Plugin\System\SmartAuthors\Model\SmartAuthorsArticleModel;
use SuperSoft\Plugin\System\SmartAuthors\Model\SmartAuthorsArticlesModel;
use SuperSoft\Plugin\System\SmartAuthors\Model\SmartAuthorsCategoryModel;
use SuperSoft\Plugin\System\SmartAuthors\Model\SmartAuthorsFormModel;
use SuperSoft\Plugin\System\SmartAuthors\View\Form\HtmlView as SmartAuthorsFormView;

/**
 * Decorating MVCFactory for com_content to inject SmartAuthors models and controllers.
 */
class SmartAuthorsMVCFactory implements MVCFactoryInterface
{
    public function __construct(
        private readonly MVCFactoryInterface $inner,
        private readonly AccessService $accessService,
    ) {
    }

    public function getInner(): MVCFactoryInterface
    {
        return $this->inner;
    }

    public function getAccessService(): AccessService
    {
        return $this->accessService;
    }

    public function createController($name, $prefix, array $config, CMSApplicationInterface $app, Input $input): ControllerInterface
    {
        $normalizedName = ucfirst($name);
        $isSite = empty($prefix) || strcasecmp($prefix, 'site') === 0 || str_ends_with(strtolower($prefix), 'site');

        if ($isSite) {
            if ($normalizedName === 'Article') {
                $config['name'] = 'article';
                $config['option'] = 'com_content';
                $controller = new SmartAuthorsArticleController($config, $this, $app, $input);
                $controller->setAccessService($this->accessService);
                return $controller;
            }

            if ($normalizedName === 'Display') {
                $config['name'] = 'display';
                $config['option'] = 'com_content';
                $controller = new SmartAuthorsDisplayController($config, $this, $app, $input);
                $controller->setAccessService($this->accessService);
                return $controller;
            }
        }

        $controller = $this->inner->createController($name, $prefix, $config, $app, $input);
        if ($controller instanceof BaseController) {
            \Closure::bind(function ($c, $factory) {
                $c->factory = $factory;
            }, null, BaseController::class)($controller, $this);
        }

        return $controller;
    }

    public function createModel($name, $prefix = '', array $config = []): ModelInterface
    {
        $normalizedName = ucfirst($name);
        $isSite = empty($prefix) || strcasecmp($prefix, 'site') === 0 || str_ends_with(strtolower($prefix), 'site');

        if ($isSite) {
            $config['option'] = 'com_content';

            if ($normalizedName === 'Form') {
                $config['name'] = 'form';
                $model = new SmartAuthorsFormModel($config, $this);
                $model->setAccessService($this->accessService);
                return $model;
            }

            if ($normalizedName === 'Article') {
                $config['name'] = 'article';
                $model = new SmartAuthorsArticleModel($config, $this);
                $model->setAccessService($this->accessService);
                return $model;
            }

            if ($normalizedName === 'Articles') {
                $config['name'] = 'articles';
                $model = new SmartAuthorsArticlesModel($config, $this);
                $model->setAccessService($this->accessService);
                return $model;
            }

            if ($normalizedName === 'Category') {
                $config['name'] = 'category';
                $model = new SmartAuthorsCategoryModel($config, $this);
                $model->setAccessService($this->accessService);
                return $model;
            }
        }

        return $this->inner->createModel($name, $prefix, $config);
    }

    public function createView($name, $prefix = '', $type = '', array $config = []): ViewInterface
    {
        $normalizedName = ucfirst($name);
        $isSite = empty($prefix) || strcasecmp($prefix, 'site') === 0 || str_ends_with(strtolower($prefix), 'site');
        $isHtml = empty($type) || strtolower($type) === 'html';

        if ($isSite && $normalizedName === 'Form' && $isHtml) {
            $config['name'] = 'form';
            $config['option'] = 'com_content';
            $view = new SmartAuthorsFormView($config);
            $view->setAccessService($this->accessService);
            return $view;
        }

        return $this->inner->createView($name, $prefix, $type, $config);
    }


    public function createTable($name, $prefix = '', array $config = []): Table
    {
        $table = $this->inner->createTable($name, $prefix, $config);

        if (!$table instanceof Table) {
            $prefixStr = (string) ($prefix !== '' ? $prefix : '(empty)');
            throw new \RuntimeException(
                sprintf('Table "%s" not found with prefix "%s".', (string) $name, $prefixStr)
            );
        }

        return $table;
    }
}
