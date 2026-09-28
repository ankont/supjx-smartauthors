<?php

declare(strict_types=1);

namespace SuperSoft\Plugin\System\SmartAuthors\Helper;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\User\User;
use SuperSoft\Plugin\System\SmartAuthors\Domain\Service\AccessService;
use SuperSoft\Plugin\System\SmartAuthors\Extension\SmartAuthors;

/**
 * Public Bridge/Helper for external extensions (e.g. SmartBrowser, Custom Modules).
 *
 * Provides high-level permission check methods that combine native Joomla ACL
 * with contextual SmartAuthors category policies and draft lifecycles.
 */
class SmartAuthorsHelper
{
    /**
     * Resolves the AccessService instance from container or plugin.
     */
    public static function getAccessService(): ?AccessService
    {
        try {
            $container = Factory::getContainer();
            if (method_exists($container, 'has') && $container->has(AccessService::class)) {
                return $container->get(AccessService::class);
            }
            if (method_exists($container, 'get')) {
                $service = $container->get(AccessService::class);
                if ($service instanceof AccessService) {
                    return $service;
                }
            }
        } catch (\Throwable) {
        }

        return SmartAuthors::getInstance()?->getAccessService();
    }

    /**
     * Checks if the user can create an article in the given category.
     * Checks native core.create first, then SmartAuthors policy.
     */
    public static function canCreateArticle(User|int|null $user, int $categoryId): bool
    {
        $user = self::resolveUser($user);
        $userId = (int) ($user->id ?? 0);

        if ($user->authorise('core.create', 'com_content.category.' . $categoryId)) {
            return true;
        }

        $service = self::getAccessService();
        return $service !== null && $service->canCreateArticle($userId, $categoryId);
    }

    /**
     * Checks if the user can edit this article.
     * Checks native core.edit / core.edit.own, then SmartAuthors policy.
     */
    public static function canEditArticle(User|int|null $user, object|array|int $article): bool
    {
        $user = self::resolveUser($user);
        $userId = (int) ($user->id ?? 0);
        $articleObj = self::resolveArticle($article);
        if ($articleObj === null) {
            return false;
        }

        $articleId = (int) ($articleObj->id ?? 0);
        $createdBy = (int) ($articleObj->created_by ?? 0);

        if ($articleId > 0) {
            if ($user->authorise('core.edit', 'com_content.article.' . $articleId)) {
                return true;
            }
            if ($createdBy === $userId && $user->authorise('core.edit.own', 'com_content.article.' . $articleId)) {
                return true;
            }
        }

        $service = self::getAccessService();
        return $service !== null && $service->canEditArticle($userId, $articleObj);
    }

    /**
     * Checks if the user can publish/unpublish directly to the live site.
     * Checks native core.edit.state first, then SmartAuthors policy.
     */
    public static function canManageArticleState(User|int|null $user, object|array|int $article): bool
    {
        $user = self::resolveUser($user);
        $userId = (int) ($user->id ?? 0);
        $articleObj = self::resolveArticle($article);
        if ($articleObj === null) {
            return false;
        }

        $articleId = (int) ($articleObj->id ?? 0);

        if ($articleId > 0 && $user->authorise('core.edit.state', 'com_content.article.' . $articleId)) {
            return true;
        }

        $service = self::getAccessService();
        return $service !== null && $service->canManageArticleState($userId, $articleObj);
    }

    /**
     * Checks if the user can trash or restore this draft article.
     * Checks native core.delete / core.edit.state first, then SmartAuthors draft lifecycle (0, -2).
     */
    public static function canTrashArticle(User|int|null $user, object|array|int $article): bool
    {
        $user = self::resolveUser($user);
        $userId = (int) ($user->id ?? 0);
        $articleObj = self::resolveArticle($article);
        if ($articleObj === null) {
            return false;
        }

        $articleId = (int) ($articleObj->id ?? 0);

        if ($articleId > 0) {
            if ($user->authorise('core.delete', 'com_content.article.' . $articleId)) {
                return true;
            }
            if ($user->authorise('core.edit.state', 'com_content.article.' . $articleId)) {
                return true;
            }
        }

        $service = self::getAccessService();
        return $service !== null && $service->canTrashArticle($userId, $articleObj);
    }

    /**
     * Checks if the user can transition the article to a specific target state.
     * Target states:
     * - 1 (Published) and 2 (Archived): Publicly accessible in Joomla, requires native core.edit.state
     *   OR SmartAuthors canManageArticleState.
     * - 0 (Unpublished) and -2 (Trashed): Private draft lifecycle permitted for drafts
     *   (any unpublished draft if category has EditMode::ALL, or own drafts if EditMode::OWN).
     */
    public static function canChangeState(User|int|null $user, object|array|int $article, int $targetState): bool
    {
        $user = self::resolveUser($user);
        $userId = (int) ($user->id ?? 0);
        $articleObj = self::resolveArticle($article);
        if ($articleObj === null) {
            return false;
        }

        if (self::canManageArticleState($user, $articleObj)) {
            return true;
        }

        $service = self::getAccessService();
        if ($service === null) {
            return false;
        }

        return $service->canTransitionState($userId, $articleObj, $targetState);
    }

    /**
     * Checks if the user can manage subcategories in the given category.
     */
    public static function canManageCategory(User|int|null $user, int $categoryId): bool
    {
        $user = self::resolveUser($user);
        $userId = (int) ($user->id ?? 0);

        if ($user->authorise('core.admin', 'com_content.category.' . $categoryId)) {
            return true;
        }

        $service = self::getAccessService();
        return $service !== null && $service->canManageCategory($userId, $categoryId);
    }

    private static function resolveUser(User|int|null $user): User
    {
        if ($user instanceof User) {
            return $user;
        }

        if (is_int($user) && $user > 0) {
            return Factory::getUser($user);
        }

        return Factory::getApplication()->getIdentity() ?? Factory::getUser();
    }

    /**
     * Resolves article argument into an object containing at least id, catid, created_by, state.
     * If fields are missing or an ID was passed, loads from #__content.
     */
    public static function resolveArticle(object|array|int $article): ?object
    {
        if (is_int($article)) {
            $articleId = $article;
            $articleObj = (object) ['id' => $articleId];
        } else {
            $articleObj = (object) $article;
            $articleId = (int) ($articleObj->id ?? 0);
        }

        if ($articleId <= 0 && empty($articleObj->catid)) {
            return null;
        }

        if ($articleId > 0 && (!isset($articleObj->catid) || !isset($articleObj->created_by) || !isset($articleObj->state))) {
            try {
                $db = null;
                $container = Factory::getContainer();
                if (method_exists($container, 'has') && $container->has(\Joomla\Database\DatabaseInterface::class)) {
                    $db = $container->get(\Joomla\Database\DatabaseInterface::class);
                } elseif (method_exists($container, 'get')) {
                    $db = $container->get(\Joomla\Database\DatabaseInterface::class);
                }
                if ($db === null) {
                    $db = SmartAuthors::getInstance()?->getDatabase();
                }

                if ($db !== null) {
                    $query = $db->getQuery(true)
                        ->select($db->quoteName(['id', 'catid', 'created_by', 'state']))
                        ->from($db->quoteName('#__content'))
                        ->where($db->quoteName('id') . ' = :id')
                        ->bind(':id', $articleId, \Joomla\Database\ParameterType::INTEGER);
                    $db->setQuery($query);
                    $loaded = $db->loadObject();
                    if ($loaded) {
                        $articleObj->catid = (int) $loaded->catid;
                        $articleObj->created_by = (int) $loaded->created_by;
                        $articleObj->state = (int) $loaded->state;
                    }
                }
            } catch (\Throwable) {
            }
        }

        return $articleObj;
    }
}
