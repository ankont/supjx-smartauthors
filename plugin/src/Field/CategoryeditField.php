<?php

declare(strict_types=1);

namespace SuperSoft\Plugin\System\SmartAuthors\Field;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\Component\Categories\Administrator\Field\CategoryeditField as CoreCategoryeditField;
use Joomla\Database\ParameterType;
use Joomla\Utilities\ArrayHelper;
use SuperSoft\Plugin\System\SmartAuthors\Domain\Service\AccessService;
use SuperSoft\Plugin\System\SmartAuthors\Extension\SmartAuthors;

/**
 * Custom CategoryeditField with SmartAuthors contextual create & move authorization.
 */
class CategoryeditField extends CoreCategoryeditField
{
    protected ?AccessService $accessService = null;

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
            // Graceful fallback if booting fails
        }

        return $this->accessService;
    }

    /**
     * Builds category options respecting Joomla ACL and SmartAuthors contextual permissions.
     *
     * @return array<int, object>
     */
    protected function getOptions()
    {
        $published = $this->element['published'] ? explode(',', (string) $this->element['published']) : [0, 1];
        $name      = (string) $this->element['name'];

        $jinput = Factory::getApplication()->getInput();
        $isParentCategoryField = isset($this->element['parent']) || $jinput->getCmd('option') === 'com_categories';

        if ($isParentCategoryField) {
            $oldCat    = $jinput->get('id', 0);
            $oldParent = $this->form ? $this->form->getValue($name, 0) : 0;
            $extension = $this->element['extension'] ? (string) $this->element['extension'] : (string) $jinput->get('extension', 'com_content');
        } else {
            $oldCat    = $this->form ? $this->form->getValue($name, 0) : 0;
            $extension = $this->element['extension'] ? (string) $this->element['extension'] : (string) $jinput->get('option', 'com_content');
        }

        $oldCat = \is_array($oldCat) ? (int) reset($oldCat) : (int) $oldCat;

        $db   = $this->getDatabase();
        $user = $this->getCurrentUser();

        $query = $db->getQuery(true)
            ->select(
                [
                    $db->quoteName('a.id', 'value'),
                    $db->quoteName('a.title', 'text'),
                    $db->quoteName('a.level'),
                    $db->quoteName('a.published'),
                    $db->quoteName('a.lft'),
                    $db->quoteName('a.language'),
                ]
            )
            ->from($db->quoteName('#__categories', 'a'));

        if ($isParentCategoryField) {
            $query->where('(' . $db->quoteName('a.extension') . ' = :extension OR ' . $db->quoteName('a.parent_id') . ' = 0)')
                ->bind(':extension', $extension);
        } else {
            $query->where($db->quoteName('a.extension') . ' = :extension')
                ->bind(':extension', $extension);
        }

        if (isset($this->element['language'])) {
            $language = str_contains((string) $this->element['language'], ',')
                ? explode(',', (string) $this->element['language'])
                : (string) $this->element['language'];

            $query->whereIn($db->quoteName('a.language'), (array) $language, ParameterType::STRING);
        }

        $state = ArrayHelper::toInteger($published);
        $query->whereIn($db->quoteName('a.published'), $state);

        if (!$user->authorise('core.admin')) {
            $groups = $user->getAuthorisedViewLevels();
            $query->whereIn($db->quoteName('a.access'), $groups);
        }

        $query->order($db->quoteName('a.lft') . ' ASC');

        if ($oldCat != 0 && $isParentCategoryField) {
            $query->join(
                'LEFT',
                $db->quoteName('#__categories', 'p'),
                $db->quoteName('p.id') . ' = :oldcat'
            )
                ->bind(':oldcat', $oldCat, ParameterType::INTEGER)
                ->where('NOT(' . $db->quoteName('a.lft') . ' >= ' . $db->quoteName('p.lft')
                    . ' AND ' . $db->quoteName('a.rgt') . ' <= ' . $db->quoteName('p.rgt') . ')');
        }

        $db->setQuery($query);

        try {
            $options = $db->loadObjectList();
        } catch (\RuntimeException $e) {
            Factory::getApplication()->enqueueMessage($e->getMessage(), 'error');
            $options = [];
        }

        foreach ($options as $option) {
            if ($isParentCategoryField && (int) $option->level === 0) {
                $option->text = Text::_('JGLOBAL_ROOT_PARENT');
            }

            if ((int) $option->published === 1) {
                $option->text = str_repeat('- ', !(int) $option->level ? 0 : (int) $option->level - 1) . $option->text;
            } else {
                $option->text = str_repeat('- ', !(int) $option->level ? 0 : (int) $option->level - 1) . '[' . $option->text . ']';
            }

            if ($option->language !== '*') {
                $option->text .= ' (' . $option->language . ')';
            }
        }

        $accessService = $this->getAccessService();

        // For parent category selection in com_categories (Phase 3 will extend this)
        if ($isParentCategoryField) {
            return $options;
        }

        // For content items:
        if ($oldCat === 0) {
            // New Article: filter categories where user has Create permission
            foreach ($options as $i => $option) {
                $catId = (int) $option->value;
                if ((int) $option->level === 0) {
                    unset($options[$i]);
                    continue;
                }

                $canCreate = $user->authorise('core.create', $extension . '.category.' . $catId)
                    || ($accessService !== null && $accessService->canCreateArticle((int) $user->id, $catId));

                if (!$canCreate) {
                    unset($options[$i]);
                }
            }
        } else {
            // Existing Article (Category Move):
            $articleId = (int) ($this->form ? $this->form->getValue('id', 0) : 0);
            $articleCreatedBy = (int) ($this->form ? $this->form->getValue('created_by', 0) : 0);

            if ($articleId > 0 && $articleCreatedBy <= 0) {
                $artQuery = $db->getQuery(true)
                    ->select($db->quoteName(['catid', 'created_by']))
                    ->from($db->quoteName('#__content'))
                    ->where($db->quoteName('id') . ' = :id')
                    ->bind(':id', $articleId, ParameterType::INTEGER);
                $db->setQuery($artQuery);
                $articleRow = $db->loadObject();
                if ($articleRow) {
                    $articleCreatedBy = (int) $articleRow->created_by;
                }
            }

            // Can user edit the source article?
            $articleData = ['id' => $articleId, 'catid' => $oldCat, 'created_by' => $articleCreatedBy];
            $canEditSource = $user->authorise('core.edit', $extension . '.article.' . $articleId)
                || ($user->id > 0 && $user->id == $articleCreatedBy && $user->authorise('core.edit.own', $extension . '.article.' . $articleId))
                || ($accessService !== null && $accessService->canEditArticle((int) $user->id, $articleData));

            foreach ($options as $i => $option) {
                $catId = (int) $option->value;

                if ((int) $option->level === 0) {
                    unset($options[$i]);
                    continue;
                }

                // If user cannot edit the source article, they cannot move it anywhere
                if (!$canEditSource) {
                    if ($catId !== $oldCat) {
                        unset($options[$i]);
                    }
                    continue;
                }

                // User CAN edit source article:
                // Can keep it in current category:
                if ($catId === $oldCat) {
                    continue;
                }

                // Or move to another category where user has Create permission:
                $canCreateDest = $user->authorise('core.create', $extension . '.category.' . $catId)
                    || ($accessService !== null && $accessService->canCreateArticle((int) $user->id, $catId));

                if (!$canCreateDest) {
                    unset($options[$i]);
                }
            }
        }

        return array_values($options);
    }
}
