<?php

declare(strict_types=1);

namespace SuperSoft\Plugin\System\SmartAuthors\View\Form;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Helper\TagsHelper;
use Joomla\CMS\Language\Multilanguage;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\GenericDataException;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\Component\Content\Site\View\Form\HtmlView as CoreFormHtmlView;
use SuperSoft\Plugin\System\SmartAuthors\Domain\Service\AccessService;
use SuperSoft\Plugin\System\SmartAuthors\Extension\SmartAuthors;

/**
 * Decorated Form HtmlView supporting SmartAuthors new article creation and frontend editing.
 */
class HtmlView extends CoreFormHtmlView
{
    protected ?AccessService $accessService = null;

    public function __construct($config = [])
    {
        $config['name'] = $config['name'] ?? 'form';
        if (defined('JPATH_SITE')) {
            $config['base_path'] = $config['base_path'] ?? (JPATH_SITE . '/components/com_content');
        }

        parent::__construct($config);

        if (defined('JPATH_SITE')) {
            $this->addTemplatePath(JPATH_SITE . '/components/com_content/tmpl/form');

            try {
                $app = Factory::getApplication();
                if (method_exists($app, 'getTemplate') && defined('JPATH_THEMES')) {
                    $template = $app->getTemplate();
                    if ($template) {
                        $this->addTemplatePath(JPATH_THEMES . '/' . $template . '/html/com_content/form');
                    }
                }
            } catch (\Throwable) {
            }
        }
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
     * Executes and displays the article edit/create form.
     *
     * @param string|null $tpl The name of the template file to parse.
     * @return void|boolean
     */
    public function display($tpl = null)
    {
        $app  = Factory::getApplication();
        $user = $app->getIdentity();

        $model             = $this->getModel();
        $this->state       = $model->getState();
        $this->item        = $model->getItem();
        $this->form        = $model->getForm();
        $this->return_page = $model->getReturnPage();

        $authorised = false;

        if (empty($this->item->id)) {
            // New Article Path
            $catid = (int) ($this->state->get('params')?->get('catid') ?? $app->getInput()->getInt('catid', 0));

            if ($this->state->get('params')?->get('enable_category') == 1 && $catid > 0) {
                $authorised = $user->authorise('core.create', 'com_content.category.' . $catid);
            } else {
                $authorised = $user->authorise('core.create', 'com_content') || \count($user->getAuthorisedCategories('com_content', 'core.create'));
            }

            // Fallback to SmartAuthors authorization
            if (!$authorised && $user && !$user->guest) {
                $accessService = $this->getAccessService();
                if ($accessService !== null) {
                    $userId = (int) $user->id;
                    if ($catid > 0) {
                        $authorised = $accessService->canCreateArticle($userId, $catid);
                    } else {
                        $authorised = $accessService->canCreateInAnyCategory($userId);
                    }
                }
            }
        } else {
            // Existing Article Path: evaluated by SmartAuthorsFormModel::getItem()
            $authorised = $this->item->params?->get('access-edit');
        }

        if ($authorised !== true) {
            $app->enqueueMessage(Text::_('JERROR_ALERTNOAUTHOR'), 'error');
            $app->setHeader('status', 403, true);

            return false;
        }

        $this->item->tags = new TagsHelper();

        if (!empty($this->item->id)) {
            $this->item->tags->getItemTags('com_content.article', $this->item->id);

            $this->item->images = is_string($this->item->images) ? json_decode($this->item->images) : $this->item->images;
            $this->item->urls   = is_string($this->item->urls) ? json_decode($this->item->urls) : $this->item->urls;

            $tmp         = new \stdClass();
            $tmp->images = $this->item->images;
            $tmp->urls   = $this->item->urls;
            $this->form->bind($tmp);
        }

        // Check for errors
        if (\count($errors = $model->getErrors())) {
            throw new GenericDataException(implode("\n", $errors), 500);
        }

        // Create shortcut to parameters
        $params = $this->state->get('params') ?? new \Joomla\Registry\Registry();

        // Escape strings for HTML output
        $this->pageclass_sfx = htmlspecialchars($params->get('pageclass_sfx', ''));

        $this->params = $params;

        // Override global params with article specific params
        if ($this->item->params) {
            $this->params->merge($this->item->params);
        }
        $this->user = $user;

        // Propose current language as default when creating new article
        if (empty($this->item->id) && Multilanguage::isEnabled() && $params->get('enable_category') != 1) {
            $lang = $this->getLanguage()->getTag();
            $this->form->setFieldAttribute('language', 'default', $lang);
        }

        $captchaSet = $params->get('captcha', Factory::getApplication()->get('captcha', '0'));

        foreach (PluginHelper::getPlugin('captcha') as $plugin) {
            if ($captchaSet === $plugin->name) {
                $this->captchaEnabled = true;
                break;
            }
        }

        // If the article is being edited and the current user has permission to create article
        if ($this->item->id) {
            $canCreateNative = $user->authorise('core.create', 'com_content') || \count($user->getAuthorisedCategories('com_content', 'core.create'));
            $canCreateSmart  = $user && !$user->guest && ($this->getAccessService()?->canCreateInAnyCategory((int) $user->id) ?? false);

            if ($canCreateNative || $canCreateSmart) {
                $this->showSaveAsCopy = true;
            }
        }

        // Add form control fields
        $this->form
            ->addControlField('task', '')
            ->addControlField('return', $this->return_page ?? '');

        $this->_prepareDocument();

        // Call BaseHtmlView::display to render layout template
        BaseHtmlView::display($tpl);
    }
}
