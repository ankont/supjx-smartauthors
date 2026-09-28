<?php

declare(strict_types=1);

namespace SuperSoft\Plugin\System\SmartAuthors\Tests\Runtime;

use Joomla\CMS\Factory;
use Joomla\CMS\MVC\Factory\MVCFactoryInterface;
use Joomla\Input\Input;
use SuperSoft\Plugin\System\SmartAuthors\Controller\SmartAuthorsArticleController;
use SuperSoft\Plugin\System\SmartAuthors\Domain\Service\AccessService;
use SuperSoft\Plugin\System\SmartAuthors\Extension\SmartAuthorsMVCFactory;
use SuperSoft\Plugin\System\SmartAuthors\Model\SmartAuthorsArticleModel;
use SuperSoft\Plugin\System\SmartAuthors\Model\SmartAuthorsArticlesModel;
use SuperSoft\Plugin\System\SmartAuthors\Model\SmartAuthorsCategoryModel;
use SuperSoft\Plugin\System\SmartAuthors\Model\SmartAuthorsFormModel;
use SuperSoft\Plugin\System\SmartAuthors\Service\PolicyResolver;
use SuperSoft\Plugin\System\SmartAuthors\Service\RequestCache;
use SuperSoft\Plugin\System\SmartAuthors\Tests\Mocks\InMemoryPolicyRepository;

class MVCComponentConventionsTest
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

    public function runAll(): void
    {
        $this->testArticleControllerInvariants();
        $this->testDisplayControllerInvariants();
        $this->testFormModelInvariants();
        $this->testArticleModelInvariants();
        $this->testArticlesModelInvariants();
        $this->testCategoryModelInvariants();
        $this->testMVCFactoryCreationInvariants();
    }

    public function testArticleControllerInvariants(): void
    {
        $app = Factory::getApplication();
        $input = new Input();
        $controller = new SmartAuthorsArticleController([], null, $app, $input);

        $this->assert($controller->getName() === 'article', 'ArticleController name must be "article"');
        $this->assert(
            $this->getProtectedProperty($controller, 'option') === 'com_content',
            'ArticleController option must be "com_content" and NEVER "com_ugin"'
        );
        $this->assert(
            $this->getProtectedProperty($controller, 'context') === 'article',
            'ArticleController context must be "article"'
        );
        $this->assert(
            $this->getProtectedProperty($controller, 'text_prefix') === 'COM_CONTENT',
            'ArticleController text_prefix must be "COM_CONTENT"'
        );
        $this->assert(
            $this->getProtectedProperty($controller, 'view_item') === 'form',
            'ArticleController view_item must be "form"'
        );
        $this->assert(
            $this->getProtectedProperty($controller, 'view_list') === 'categories',
            'ArticleController view_list must be "categories"'
        );
        $this->assert(
            $this->getProtectedProperty($controller, 'urlVar') === 'a.id',
            'ArticleController urlVar must be "a.id"'
        );
    }

    public function testDisplayControllerInvariants(): void
    {
        $app = Factory::getApplication();
        $input = new Input();
        $controller = new \SuperSoft\Plugin\System\SmartAuthors\Controller\SmartAuthorsDisplayController([], null, $app, $input);

        $this->assert($controller->getName() === 'display', 'DisplayController name must be "display"');
        $this->assert(
            $this->getProtectedProperty($controller, 'option') === 'com_content',
            'DisplayController option must be "com_content" and NEVER "com_ugin"'
        );
        $this->assert(
            $this->getProtectedProperty($controller, 'default_view') === 'categories',
            'DisplayController default_view must be "categories"'
        );
    }

    public function testFormModelInvariants(): void
    {
        $model = new SmartAuthorsFormModel();

        $this->assert($model->getName() === 'form', 'FormModel name must be "form"');
        $this->assert(
            $this->getProtectedProperty($model, 'option') === 'com_content',
            'FormModel option must be "com_content" and NEVER "com_ugin"'
        );
        $this->assert(
            $this->getProtectedProperty($model, 'text_prefix') === 'COM_CONTENT',
            'FormModel text_prefix must be "COM_CONTENT"'
        );
        $this->assert(
            $model->typeAlias === 'com_content.article',
            'FormModel typeAlias must be "com_content.article"'
        );
    }

    public function testArticleModelInvariants(): void
    {
        $model = new SmartAuthorsArticleModel();

        $this->assert($model->getName() === 'article', 'ArticleModel name must be "article"');
        $this->assert(
            $this->getProtectedProperty($model, 'option') === 'com_content',
            'ArticleModel option must be "com_content" and NEVER "com_ugin"'
        );
        $this->assert(
            $this->getProtectedProperty($model, 'text_prefix') === 'COM_CONTENT',
            'ArticleModel text_prefix must be "COM_CONTENT"'
        );
        $this->assert(
            $this->getProtectedProperty($model, '_context') === 'com_content.article',
            'ArticleModel _context must be "com_content.article"'
        );
    }

    public function testArticlesModelInvariants(): void
    {
        $model = new SmartAuthorsArticlesModel();

        $this->assert($model->getName() === 'articles', 'ArticlesModel name must be "articles"');
        $this->assert(
            $this->getProtectedProperty($model, 'option') === 'com_content',
            'ArticlesModel option must be "com_content" and NEVER "com_ugin"'
        );
        $this->assert(
            $this->getProtectedProperty($model, 'text_prefix') === 'COM_CONTENT',
            'ArticlesModel text_prefix must be "COM_CONTENT"'
        );
        $this->assert(
            $this->getProtectedProperty($model, 'context') === 'com_content.articles',
            'ArticlesModel context must be "com_content.articles"'
        );
    }

    public function testCategoryModelInvariants(): void
    {
        $model = new SmartAuthorsCategoryModel();

        $this->assert($model->getName() === 'category', 'CategoryModel name must be "category"');
        $this->assert(
            $this->getProtectedProperty($model, 'option') === 'com_content',
            'CategoryModel option must be "com_content" and NEVER "com_ugin"'
        );
        $this->assert(
            $this->getProtectedProperty($model, 'text_prefix') === 'COM_CONTENT',
            'CategoryModel text_prefix must be "COM_CONTENT"'
        );
        $this->assert(
            $this->getProtectedProperty($model, 'context') === 'com_content.category',
            'CategoryModel context must be "com_content.category"'
        );

        // Invariant: populateState must not call getState() recursively
        $model->setState('category.id', 42);
        $stateVal = $model->getState('category.id');
        $this->assert($stateVal === 42, 'CategoryModel getState should return 42 without recursion');
    }

    public function testMVCFactoryCreationInvariants(): void
    {
        $repo = new InMemoryPolicyRepository();
        $resolver = new PolicyResolver($repo, null, new RequestCache());
        $accessService = new AccessService($resolver);

        $innerFactory = new class implements MVCFactoryInterface {
            public function createController($name, $prefix, array $config, \Joomla\CMS\Application\CMSApplicationInterface $app, Input $input): \Joomla\CMS\MVC\Controller\ControllerInterface {
                throw new \RuntimeException('Should not be called for Article Site');
            }
            public function createModel($name, $prefix = '', array $config = []): \Joomla\CMS\MVC\Model\ModelInterface {
                throw new \RuntimeException('Should not be called for Site models');
            }
            public function createView($name, $prefix = '', $type = '', array $config = []): \Joomla\CMS\MVC\View\ViewInterface {
                throw new \RuntimeException('Not tested');
            }
            public function createTable($name, $prefix = '', array $config = []) {
                if ($name === 'Article') {
                    return new \Joomla\CMS\Table\Table();
                }
                return null;
            }
        };

        $factory = new SmartAuthorsMVCFactory($innerFactory, $accessService);
        $app = Factory::getApplication();
        $input = new Input();

        $controller = $factory->createController('Article', 'Site', [], $app, $input);
        $this->assert($controller instanceof SmartAuthorsArticleController, 'Must return SmartAuthorsArticleController');
        $this->assert($this->getProtectedProperty($controller, 'option') === 'com_content', 'Factory created controller option must be com_content');

        $formModel = $factory->createModel('Form', 'Site', []);
        $this->assert($formModel instanceof SmartAuthorsFormModel, 'Must return SmartAuthorsFormModel');
        $this->assert($this->getProtectedProperty($formModel, 'option') === 'com_content', 'Factory created form model option must be com_content');

        $articleModel = $factory->createModel('Article', 'Site', []);
        $this->assert($articleModel instanceof SmartAuthorsArticleModel, 'Must return SmartAuthorsArticleModel');
        $this->assert($this->getProtectedProperty($articleModel, 'option') === 'com_content', 'Factory created article model option must be com_content');

        $articlesModel = $factory->createModel('Articles', 'Site', []);
        $this->assert($articlesModel instanceof SmartAuthorsArticlesModel, 'Must return SmartAuthorsArticlesModel');
        $this->assert($this->getProtectedProperty($articlesModel, 'option') === 'com_content', 'Factory created articles model option must be com_content');

        $categoryModel = $factory->createModel('Category', 'Site', []);
        $this->assert($categoryModel instanceof SmartAuthorsCategoryModel, 'Must return SmartAuthorsCategoryModel');
        $this->assert($this->getProtectedProperty($categoryModel, 'option') === 'com_content', 'Factory created category model option must be com_content');

        // Test createTable: success path
        $table = $factory->createTable('Article', 'Administrator');
        $this->assert($table instanceof \Joomla\CMS\Table\Table, 'createTable must return Table instance when found');

        // Test createTable: null result throws clear RuntimeException with name and prefix
        $caughtBatchArticle = false;
        try {
            $factory->createTable('BatchArticle', 'Administrator');
        } catch (\RuntimeException $e) {
            $caughtBatchArticle = true;
            $this->assert(
                str_contains($e->getMessage(), 'BatchArticle') && str_contains($e->getMessage(), 'Administrator'),
                'Exception message must mention table name and prefix: ' . $e->getMessage()
            );
        }
        $this->assert($caughtBatchArticle, 'createTable must throw RuntimeException when table not found');

        // Test createTable: empty prefix
        $caughtEmptyPrefix = false;
        try {
            $factory->createTable('UnknownTable', '');
        } catch (\RuntimeException $e) {
            $caughtEmptyPrefix = true;
            $this->assert(
                str_contains($e->getMessage(), 'UnknownTable'),
                'Exception message must mention table name: ' . $e->getMessage()
            );
        }
        $this->assert($caughtEmptyPrefix, 'createTable must throw RuntimeException when table not found with empty prefix');
    }
}
