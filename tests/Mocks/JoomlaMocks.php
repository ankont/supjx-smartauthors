<?php

declare(strict_types=1);

namespace Joomla\Registry {
    if (!class_exists(\Joomla\Registry\Registry::class, false)) {
        class Registry
        {
            protected array $data = [];

            public function __construct(mixed $data = null)
            {
                if (is_array($data)) {
                    $this->data = $data;
                } elseif (is_string($data) && $data !== '') {
                    $decoded = json_decode($data, true);
                    if (is_array($decoded)) {
                        $this->data = $decoded;
                    }
                }
            }

            public function get(string $path, mixed $default = null): mixed
            {
                return $this->data[$path] ?? $default;
            }

            public function set(string $path, mixed $value): mixed
            {
                return $this->data[$path] = $value;
            }

            public function exists(string $path): bool
            {
                return array_key_exists($path, $this->data);
            }

            public function merge(mixed $source): static
            {
                $arr = $source instanceof Registry ? $source->data : (array) $source;
                $this->data = array_replace_recursive($this->data, $arr);
                return $this;
            }

            public function toArray(): array
            {
                return $this->data;
            }
        }
    }
}

namespace Joomla\CMS\Application {
    if (!interface_exists(\Joomla\CMS\Application\CMSApplicationInterface::class, false)) {
        interface CMSApplicationInterface {}
    }


    if (!interface_exists(\Joomla\CMS\Application\CMSWebApplicationInterface::class, false)) {
        interface CMSWebApplicationInterface extends CMSApplicationInterface {}
    }

    if (!class_exists(\Joomla\CMS\Application\ApplicationHelper::class, false)) {
        class ApplicationHelper
        {
            public static function stringURLSafe(string $string, string $language = '*'): string
            {
                $str = trim(strtolower($string));
                $str = (string) preg_replace('/[^a-z0-9_-]+/u', '-', $str);
                return trim($str, '-');
            }
        }
    }
}

namespace Joomla\CMS\Table {
    if (!class_exists(\Joomla\CMS\Table\Table::class, false)) {
        class Table
        {
            public int $id = 0;
            public string $title = '';
            public string $alias = '';
            public int $parent_id = 1;
            public string $extension = 'com_content';
            public string $language = '*';
            public int $lft = 0;
            public int $rgt = 0;
            public int $level = 0;
            public string $path = '';

            public function load(mixed $keys = null, bool $reset = true): bool
            {
                return false;
            }

            public function store(bool $updateNulls = false): bool
            {
                return true;
            }

            public function rebuildPath(?int $pk = null): bool
            {
                $this->path = $this->alias;
                return true;
            }

            public function rebuild(?int $parentId = null, int $leftId = 0, int $level = 0, string $path = ''): int|bool
            {
                return true;
            }
        }
    }

    if (!class_exists(\Joomla\CMS\Table\Category::class, false)) {
        class Category extends Table
        {
        }
    }
}

namespace Joomla\CMS\Log {
    if (!class_exists(\Joomla\CMS\Log\Log::class, false)) {
        class Log
        {
            public const WARNING = 4;
            /** @var list<string> */
            public static array $messages = [];

            public static function add(string $message, int $level = self::WARNING, string $category = ''): void
            {
                self::$messages[] = $message;
            }
        }
    }
}

namespace Joomla\CMS\Language {
    if (!class_exists(\Joomla\CMS\Language\Text::class, false)) {
        class Text
        {
            public static function _(string $string, mixed ...$args): string
            {
                return $string;
            }
        }
    }
}

namespace Joomla\Database {
    if (!class_exists(\Joomla\Database\ParameterType::class, false)) {
        class ParameterType
        {
            public const INTEGER = 1;
            public const STRING  = 2;
        }
    }

    if (!interface_exists(\Joomla\Database\DatabaseInterface::class, false)) {
        interface DatabaseInterface {}
    }

    if (!interface_exists(\Joomla\Database\DatabaseAwareInterface::class, false)) {
        interface DatabaseAwareInterface
        {
            public function setDatabase(\Joomla\Database\DatabaseInterface $db): void;
        }
    }

    if (!trait_exists(\Joomla\Database\DatabaseAwareTrait::class, false)) {
        trait DatabaseAwareTrait
        {
            protected ?\Joomla\Database\DatabaseInterface $databaseAwareTraitDatabase = null;

            public function setDatabase(\Joomla\Database\DatabaseInterface $db): void
            {
                $this->databaseAwareTraitDatabase = $db;
            }

            protected function getDatabase(): \Joomla\Database\DatabaseInterface
            {
                if ($this->databaseAwareTraitDatabase !== null) {
                    return $this->databaseAwareTraitDatabase;
                }

                return \Joomla\CMS\Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
            }
        }
    }
}

namespace Joomla\Database\Query {
    if (!class_exists(\Joomla\Database\Query\QueryElement::class, false)) {
        class QueryElement
        {
            public function __construct(
                protected string $name,
                protected mixed $elements,
                protected string $glue = ' AND '
            ) {
                if (!is_array($this->elements)) {
                    $this->elements = [$this->elements];
                }
            }

            public function getElements(): array
            {
                return $this->elements;
            }

            public function __toString(): string
            {
                return $this->name . ' ' . implode($this->glue, $this->elements);
            }
        }
    }
}

namespace Joomla\Database {
    if (!class_exists(\Joomla\Database\DatabaseQuery::class, false)) {
        class DatabaseQuery
        {
            public ?\Joomla\Database\Query\QueryElement $where = null;
            public array $bounded = [];
            public array $joins = [];
            public array $selects = [];
            public ?string $from = null;

            public function select(mixed $columns): self
            {
                $this->selects = (array) $columns;
                return $this;
            }

            public function from(string $table): self
            {
                $this->from = $table;
                return $this;
            }

            public function join(string $type, string $table, string $condition): self
            {
                $this->joins[] = [$type, $table, $condition];
                return $this;
            }

            public function where(mixed $conditions, string $glue = 'AND'): self
            {
                if ($this->where === null) {
                    $this->where = new \Joomla\Database\Query\QueryElement('WHERE', (array) $conditions, " $glue ");
                } else {
                    $cur = $this->where->getElements();
                    $this->where = new \Joomla\Database\Query\QueryElement('WHERE', array_merge($cur, (array) $conditions), " $glue ");
                }
                return $this;
            }

            public function clear(?string $clause = null): self
            {
                if ($clause === 'where' || $clause === null) {
                    $this->where = null;
                }
                return $this;
            }

            public function bind(mixed $key, mixed &$value, int $type = ParameterType::STRING): self
            {
                if (is_array($key)) {
                    foreach ($key as $k) {
                        $this->bounded[$k] = $value;
                    }
                } else {
                    $this->bounded[$key] = $value;
                }
                return $this;
            }

            public function bindArray(array $values, int $type = ParameterType::INTEGER): array
            {
                $placeholders = [];
                foreach ($values as $i => $v) {
                    $p = ':arr' . count($this->bounded);
                    $this->bounded[$p] = $v;
                    $placeholders[] = $p;
                }
                return $placeholders;
            }

            public function __toString(): string
            {
                $sql = 'SELECT ' . implode(', ', $this->selects) . ' FROM ' . $this->from;
                if ($this->where) {
                    $sql .= ' ' . (string) $this->where;
                }
                return $sql;
            }
        }
    }
}

namespace Joomla\CMS\Form {
    if (!class_exists(\Joomla\CMS\Form\FormHelper::class, false)) {
        class FormHelper
        {
            public static array $prefixes = [
                'field' => ['Joomla\\CMS\\Form\\Field'],
            ];

            public static function addFieldPrefix(mixed $new = null): array
            {
                if (!isset(self::$prefixes['field'])) {
                    self::$prefixes['field'] = ['Joomla\\CMS\\Form\\Field'];
                }

                $prefixes = &self::$prefixes['field'];
                settype($new, 'array');

                foreach ($new as $prefix) {
                    $prefix = trim($prefix);
                    if (!\in_array($prefix, $prefixes, true)) {
                        array_unshift($prefixes, $prefix);
                    }
                }

                return $prefixes;
            }

            public static function loadFieldType(string $type): string|false
            {
                foreach (self::$prefixes['field'] ?? [] as $prefix) {
                    $class = rtrim($prefix, '\\') . '\\' . ucfirst($type) . 'Field';
                    if (class_exists($class)) {
                        return $class;
                    }
                }

                return false;
            }
        }
    }

    if (!class_exists(\Joomla\CMS\Form\FormField::class, false)) {
        abstract class FormField
        {
            public string $type = '';
            public array $element = [];
            public ?Form $form = null;
        }
    }
}

namespace Joomla\CMS\Form\Field {
    if (!class_exists(\Joomla\CMS\Form\Field\ListField::class, false)) {
        class ListField extends \Joomla\CMS\Form\FormField
        {
            protected function getOptions()
            {
                return [];
            }
        }
    }
}

namespace Joomla\CMS\Form {

    if (!class_exists(\Joomla\CMS\Form\Form::class, false)) {
        class Form
        {
            protected array $attributes = [];
            protected array $values = [];
            protected ?\SimpleXMLElement $xml = null;

            public function __construct(string $name = 'test', ?string $xmlString = null)
            {
                if ($xmlString !== null) {
                    $this->xml = simplexml_load_string($xmlString);
                }
            }

            public function getName(): string
            {
                return 'com_content.article';
            }

            public function getXml(): ?\SimpleXMLElement
            {
                return $this->xml;
            }

            public function setFieldAttribute(string $name, string $attribute, mixed $value, ?string $group = null): void
            {
                $this->attributes[$group ? "$group.$name" : $name][$attribute] = $value;
            }

            public function getFieldAttribute(string $name, string $attribute, mixed $default = null, ?string $group = null): mixed
            {
                return $this->attributes[$group ? "$group.$name" : $name][$attribute] ?? $default;
            }

            public function setValue(string $name, ?string $group, mixed $value): void
            {
                $this->values[$group ? "$group.$name" : $name] = $value;
            }

            public function getValue(string $name, ?string $group = null, mixed $default = null): mixed
            {
                return $this->values[$group ? "$group.$name" : $name] ?? $default;
            }
        }
    }
}

namespace Joomla\Component\Categories\Administrator\Field {
    if (!class_exists(\Joomla\Component\Categories\Administrator\Field\CategoryeditField::class, false)) {
        class CategoryeditField extends \Joomla\CMS\Form\Field\ListField
        {
            public function getCurrentUser(): object
            {
                return \Joomla\CMS\Factory::getUser();
            }

            public function getDatabase(): object
            {
                return \Joomla\CMS\Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
            }
        }
    }
}

namespace Joomla\CMS\User {
    if (!class_exists(\Joomla\CMS\User\User::class, false)) {
        class User
        {
            public int $id = 0;
            public string $name = 'Mock User';
            public string $username = 'mockuser';
            public int $guest = 1;

            public array $authorisedActions = [];

            public function __construct(int $id = 0)
            {
                $this->id = $id;
                $this->guest = $id > 0 ? 0 : 1;
            }

            public function authorise(string $action, ?string $asset = null): bool
            {
                if (isset($this->authorisedActions[$action])) {
                    return (bool) $this->authorisedActions[$action];
                }
                if ($asset !== null && isset($this->authorisedActions[$action . ':' . $asset])) {
                    return (bool) $this->authorisedActions[$action . ':' . $asset];
                }
                return false;
            }

            public function getAuthorisedCategories(string $extension, string $action): array
            {
                return [];
            }
        }
    }
}

namespace Joomla\CMS {
    if (!class_exists(\Joomla\CMS\Factory::class, false)) {
        class Factory
        {
            public static ?object $currentUser = null;
            public static ?object $container = null;
            public static ?object $application = null;
            public static ?object $dbo = null;

            public static function getDbo(): \Joomla\Database\DatabaseInterface
            {
                return self::$dbo ??= new class implements \Joomla\Database\DatabaseInterface {
                    public function quoteName(string|array $name, ?string $as = null): string {
                        if (is_array($name)) { return implode(', ', $name); }
                        return $as ? "{$name} AS {$as}" : $name;
                    }
                    public function getQuery(bool $new = true): \Joomla\Database\DatabaseQuery {
                        return new \Joomla\Database\DatabaseQuery();
                    }
                    public function setQuery(mixed $query): self {
                        return $this;
                    }
                    public function loadObject(): ?object {
                        return null;
                    }
                };
            }

            public static function getUser(?int $id = null): object
            {
                if (self::$currentUser !== null) {
                    return self::$currentUser;
                }

                return new \Joomla\CMS\User\User($id ?? 0);
            }

            public static function getDate(string $time = 'now'): object
            {
                return (object) [
                    'toSql' => static fn() => '2026-09-24 02:00:00',
                ];
            }

            public static function getApplication(): object
            {
                return self::$application ??= new class implements \Joomla\CMS\Application\CMSWebApplicationInterface {
                    private ?object $identity = null;
                    private array $userState = [];

                    public function getIdentity(): object {
                        return $this->identity ?? \Joomla\CMS\Factory::getUser();
                    }
                    public function loadIdentity(object $user): void {
                        $this->identity = $user;
                        \Joomla\CMS\Factory::$currentUser = $user;
                    }
                    public function getUserState(string $key, mixed $default = null): mixed {
                        return $this->userState[$key] ?? $default;
                    }
                    public function setUserState(string $key, mixed $value): void {
                        $this->userState[$key] = $value;
                    }
                    public function getInput(): object {
                        return new \Joomla\Input\Input();
                    }
                    public function bootPlugin(string $name, string $type): ?object { return null; }
                    public function enqueueMessage(string $msg, string $type): void {}
                    public function setHeader(string $name, mixed $value, bool $replace = true): void {}
                    public function getTemplate(): string { return 'cassiopeia'; }
                };
            }

            public static function getContainer(): object
            {
                return self::$container ??= new class {
                    private array $services = [];
                    public function set(string $id, mixed $service): void { $this->services[$id] = $service; }
                    public function get(string $id): mixed { return $this->services[$id] ?? null; }
                    public function has(string $id): bool { return isset($this->services[$id]); }
                };
            }
        }
    }
}


namespace Joomla\CMS\MVC\Model {
    if (!interface_exists(\Joomla\CMS\MVC\Model\ModelInterface::class, false)) {
        interface ModelInterface
        {
            public function getName(): string;
        }
    }

    if (!class_exists(\Joomla\CMS\MVC\Model\BaseDatabaseModel::class, false)) {
        abstract class BaseDatabaseModel implements ModelInterface
        {
            protected array $state = [];
            protected $option;
            protected $name;

            public function __construct(array $config = [], mixed $factory = null, mixed $formFactory = null)
            {
                $this->name = $config['name'] ?? $this->getName();
                $this->option = $config['option'] ?? 'com_ugin';
            }

            public function getName(): string {
                return $this->name ?? 'base';
            }
            public function setState(string $property, mixed $value = null): mixed {
                return $this->state[$property] = $value;
            }
            public function getState(?string $property = null, mixed $default = null): mixed {
                return $property === null ? $this->state : ($this->state[$property] ?? $default);
            }
            public function getCurrentUser(): object {
                return \Joomla\CMS\Factory::getUser();
            }
            public function getDatabase(): object {
                return new class {
                    public function quoteName(string|array $name, ?string $as = null): string {
                        if (is_array($name)) { return implode(', ', $name); }
                        return $as ? "{$name} AS {$as}" : $name;
                    }
                    public function getQuery(bool $new = true): \Joomla\Database\DatabaseQuery {
                        return new \Joomla\Database\DatabaseQuery();
                    }
                    public function setQuery(mixed $query): self {
                        return $this;
                    }
                    public function loadObject(): ?object {
                        return null;
                    }
                };
            }
        }
    }

    if (!class_exists(\Joomla\CMS\MVC\Model\ListModel::class, false)) {
        class ListModel extends BaseDatabaseModel
        {
            protected function getListQuery() {
                return new \Joomla\Database\DatabaseQuery();
            }
        }
    }
}

namespace Joomla\Component\Content\Site\Model {
    if (!class_exists(\Joomla\Component\Content\Site\Model\ArticlesModel::class, false)) {
        class ArticlesModel extends \Joomla\CMS\MVC\Model\ListModel
        {
            protected function getListQuery() {
                return new \Joomla\Database\DatabaseQuery();
            }
        }
    }

    if (!class_exists(\Joomla\Component\Content\Site\Model\ArticleModel::class, false)) {
        class ArticleModel extends \Joomla\CMS\MVC\Model\BaseDatabaseModel
        {
            public function getItem($pk = null) {
                return false;
            }
        }
    }

    if (!class_exists(\Joomla\Component\Content\Site\Model\CategoryModel::class, false)) {
        class CategoryModel extends \Joomla\CMS\MVC\Model\ListModel
        {
            protected function populateState($ordering = null, $direction = null) {}
        }
    }

    if (!class_exists(\Joomla\Component\Content\Site\Model\FormModel::class, false)) {
        class FormModel extends \Joomla\CMS\MVC\Model\BaseDatabaseModel
        {
            public function getItem($itemId = null) {
                return (object) ['id' => $itemId, 'params' => new class {
                    private array $p = [];
                    public function get(string $k, mixed $d = null): mixed { return $this->p[$k] ?? $d; }
                    public function set(string $k, mixed $v): void { $this->p[$k] = $v; }
                }];
            }
            public function getForm($data = [], $loadData = true) {
                return new \Joomla\CMS\Form\Form();
            }
            public function save($data) {
                return true;
            }
            public function checkin($pk = null) {
                if ($pk) {
                    $this->getTable()->checkIn($pk);
                }
                return true;
            }
            public function checkout($pk = null) {
                if ($pk) {
                    $user = $this->getCurrentUser();
                    $this->getTable()->checkOut((int) ($user->id ?? 0), $pk);
                }
                return true;
            }
            public function getTable(string $name = 'Content', string $prefix = 'Administrator', array $options = []): object {
                return new class {
                    public int $id = 0;
                    public ?int $checked_out = null;
                    public ?string $checked_out_time = null;
                    public int $catid = 97;
                    public int $created_by = 42;
                    public function load(mixed $pk): bool { return true; }
                    public function hasField(string $name): bool { return true; }
                    public function getColumnAlias(string $name): string { return $name; }
                    public function checkIn(mixed $pk): bool { $this->checked_out = null; return true; }
                    public function checkOut(int $userId, mixed $pk): bool { $this->checked_out = $userId; return true; }
                    public function getError(): string { return ''; }
                };
            }
        }
    }
}

namespace Joomla\CMS\Component {
    if (!class_exists(\Joomla\CMS\Component\ComponentHelper::class, false)) {
        class ComponentHelper
        {
            public static function getComponentName(object $object, string $alternativeName): string
            {
                $reflect = new \ReflectionClass($object);
                if (!$reflect->getNamespaceName()) {
                    return 'com_' . strtolower($alternativeName);
                }
                $from = strpos($reflect->getNamespaceName(), '\\Component');
                $to   = strpos(substr($reflect->getNamespaceName(), $from + 11), '\\');
                return 'com_' . strtolower(substr($reflect->getNamespaceName(), $from + 11, $to));
            }
        }
    }
}

namespace Joomla\CMS\MVC\Controller {
    if (!interface_exists(\Joomla\CMS\MVC\Controller\ControllerInterface::class, false)) {
        interface ControllerInterface {}
    }

    if (!class_exists(\Joomla\CMS\MVC\Controller\BaseController::class, false)) {
        class BaseController implements ControllerInterface
        {
            protected $name;
            protected object $app;
            protected object $input;
            protected $factory;

            public function __construct(
                array $config = [],
                mixed $factory = null,
                mixed $app = null,
                mixed $input = null
            ) {
                $this->app = $app ?? \Joomla\CMS\Factory::getApplication();
                $this->input = $input ?? $this->app->getInput();
                $this->factory = $factory;
                if (empty($this->name)) {
                    $this->name = $config['name'] ?? $this->getName();
                }
            }

            public function getName(): string
            {
                if (empty($this->name)) {
                    $this->name = 'base';
                }
                return $this->name;
            }

            protected function checkEditId(string $context, int $id): bool
            {
                if ($id) {
                    $values = (array) $this->app->getUserState($context . '.id');
                    return in_array((int) $id, $values, true);
                }
                return true;
            }

            protected function holdEditId(string $context, int $id): void
            {
                if (!empty($id)) {
                    $values = (array) $this->app->getUserState($context . '.id');
                    $values[] = (int) $id;
                    $this->app->setUserState($context . '.id', array_unique($values));
                }
            }

            public function display($cachable = false, $urlparams = false)
            {
                return $this;
            }
        }
    }

    if (!class_exists(\Joomla\CMS\MVC\Controller\FormController::class, false)) {
        class FormController extends BaseController
        {
            protected $option;
            protected $context;
            protected $text_prefix;
            protected $view_item = 'form';
            protected $view_list = 'categories';
            protected $urlVar = 'a.id';

            public function __construct(
                array $config = [],
                mixed $factory = null,
                mixed $app = null,
                mixed $input = null,
                mixed $formFactory = null
            ) {
                parent::__construct($config, $factory, $app, $input);
                if (empty($this->option)) {
                    $this->option = \Joomla\CMS\Component\ComponentHelper::getComponentName($this, $this->getName());
                }
                if (empty($this->text_prefix)) {
                    $this->text_prefix = strtoupper((string) $this->option);
                }
                if (empty($this->context)) {
                    $this->context = 'guessed_' . strtolower(get_class($this));
                }
            }

            protected function allowAdd($data = []) { return false; }
            protected function allowEdit($data = [], $key = 'id') { return false; }
            public function getModel($name = 'Form', $prefix = 'Site', $config = []) {
                return new \Joomla\Component\Content\Site\Model\FormModel();
            }
        }
    }
}

namespace Joomla\Component\Content\Site\Controller {
    if (!class_exists(\Joomla\Component\Content\Site\Controller\ArticleController::class, false)) {
        class ArticleController extends \Joomla\CMS\MVC\Controller\FormController {}
    }

    if (!class_exists(\Joomla\Component\Content\Site\Controller\DisplayController::class, false)) {
        class DisplayController extends \Joomla\CMS\MVC\Controller\BaseController
        {
            public function display($cachable = false, $urlparams = false)
            {
                $id = $this->input->getInt('a_id');
                $vName = $this->input->getCmd('view', 'categories');
                if ($vName === 'form' && !$this->checkEditId('com_content.edit.article', $id)) {
                    throw new \Exception(\Joomla\CMS\Language\Text::sprintf('JLIB_APPLICATION_ERROR_UNHELD_ID', $id), 403);
                }
                return parent::display($cachable, $urlparams);
            }
        }
    }
}

namespace Joomla\Input {
    if (!class_exists(\Joomla\Input\Input::class, false)) {
        class Input
        {
            public function __construct(public array $data = []) {}
            public function get(string $name, mixed $default = null): mixed { return $this->data[$name] ?? $default; }
            public function getInt(string $name, int $default = 0): int { return (int) ($this->data[$name] ?? $default); }
            public function getString(string $name, string $default = ''): string { return (string) ($this->data[$name] ?? $default); }
            public function getCmd(string $name, string $default = ''): string { return (string) ($this->data[$name] ?? $default); }
        }
    }
}

namespace Joomla\CMS\MVC\Factory {
    if (!interface_exists(\Joomla\CMS\MVC\Factory\MVCFactoryInterface::class, false)) {
        interface MVCFactoryInterface
        {
            public function createController($name, $prefix, array $config, \Joomla\CMS\Application\CMSApplicationInterface $app, \Joomla\Input\Input $input): \Joomla\CMS\MVC\Controller\ControllerInterface;
            public function createModel($name, $prefix = '', array $config = []): \Joomla\CMS\MVC\Model\ModelInterface;
            public function createView($name, $prefix = '', $type = '', array $config = []): \Joomla\CMS\MVC\View\ViewInterface;
            public function createTable($name, $prefix = '', array $config = []);
        }
    }
}

namespace Joomla\CMS\MVC\View {
    if (!interface_exists(\Joomla\CMS\MVC\View\ViewInterface::class, false)) {
        interface ViewInterface {}
    }

    if (!class_exists(\Joomla\CMS\MVC\View\GenericDataException::class, false)) {
        class GenericDataException extends \RuntimeException {}
    }

    if (!class_exists(\Joomla\CMS\MVC\View\HtmlView::class, false)) {
        class HtmlView implements ViewInterface
        {
            protected $name = 'form';
            protected array $paths = ['template' => []];
            protected $model;

            public function __construct(array $config = []) {
                $this->name = $config['name'] ?? 'form';
            }
            public function addTemplatePath(string $path): void {
                $this->paths['template'][] = $path;
            }
            public function setModel($model, bool $default = false): void {
                $this->model = $model;
            }
            public function getModel(?string $name = null): mixed {
                return $this->model;
            }
            public function display($tpl = null) {}
        }
    }
}

namespace Joomla\Component\Content\Site\View\Form {
    if (!class_exists(\Joomla\Component\Content\Site\View\Form\HtmlView::class, false)) {
        class HtmlView extends \Joomla\CMS\MVC\View\HtmlView
        {
            protected $state;
            protected $item;
            protected $form;
            protected $return_page;
            protected $pageclass_sfx;
            protected $params;
            protected $user;
            protected $showSaveAsCopy = false;
            protected $captchaEnabled = false;

            protected function _prepareDocument(): void {}
        }
    }
}

namespace Joomla\CMS\Helper {
    if (!class_exists(\Joomla\CMS\Helper\TagsHelper::class, false)) {
        class TagsHelper
        {
            public function getItemTags(string $type, int $id): array { return []; }
        }
    }
}

namespace Joomla\CMS\Language {
    if (!class_exists(\Joomla\CMS\Language\Multilanguage::class, false)) {
        class Multilanguage
        {
            public static function isEnabled(): bool { return false; }
        }
    }
}

namespace Joomla\CMS\Plugin {
    if (!class_exists(\Joomla\CMS\Plugin\PluginHelper::class, false)) {
        class PluginHelper
        {
            public static function getPlugin(string $type): array { return []; }
        }
    }
}

namespace Joomla\CMS\Form {
    if (!interface_exists(\Joomla\CMS\Form\FormFactoryInterface::class, false)) {
        interface FormFactoryInterface {}
    }
}

namespace Joomla\Event {
    if (!interface_exists(\Joomla\Event\SubscriberInterface::class, false)) {
        interface SubscriberInterface
        {
            public static function getSubscribedEvents(): array;
        }
    }
}

namespace Joomla\CMS\Plugin {
    if (!class_exists(\Joomla\CMS\Plugin\CMSPlugin::class, false)) {
        abstract class CMSPlugin
        {
            protected $autoloadLanguage = false;
            protected $_name = null;
            protected $_type = null;
            public $params = null;
            private ?object $application = null;

            public function __construct(mixed $config = [])
            {
                if (is_array($config)) {
                    $this->_name = $config['name'] ?? null;
                    $this->_type = $config['type'] ?? null;
                }
            }

            public function getApplication(): object
            {
                return $this->application ?? \Joomla\CMS\Factory::getApplication();
            }

            public function setApplication(object $application): void
            {
                $this->application = $application;
            }

            public function loadLanguage(string $extension = '', string $basePath = ''): bool
            {
                return true;
            }
        }
    }
}



