<?php

declare(strict_types=1);

namespace SuperSoft\Plugin\System\SmartAuthors\Tests\Mocks;

defined('_JEXEC') or die;

use SuperSoft\Plugin\System\SmartAuthors\Repository\PolicyRepository;
use SuperSoft\Plugin\System\SmartAuthors\Service\PersonalOwnerBinder;
use SuperSoft\Plugin\System\SmartAuthors\Service\RequestCache;

final class TestablePersonalOwnerBinder extends PersonalOwnerBinder
{
    /** @var array<int, array{id: int, username: string, block: int}> */
    public static array $users = [];

    public function __construct(PolicyRepository $repository, ?RequestCache $cache = null)
    {
        parent::__construct($repository, null, $cache);
        $this->setTableFactory(static fn() => new MockCategoryTable());
    }

    public static function resetUsers(): void
    {
        self::$users = [];
    }

    public static function seedUser(int $id, string $username, int $block = 0): void
    {
        self::$users[$id] = [
            'id' => $id,
            'username' => $username,
            'block' => $block,
        ];
    }

    /**
     * Finds user ID by username regardless of block status (identity binding).
     */
    protected function findUserIdByUsername(string $username): ?int
    {
        foreach (self::$users as $u) {
            if ($u['username'] === $username) {
                return $u['id'];
            }
        }
        return null;
    }

    protected function getCategoryAlias(int $categoryId): ?string
    {
        return MockCategoryTable::$registry[$categoryId]['alias'] ?? null;
    }

    protected function hasSiblingAliasConflict(int $categoryId, int $parentId, string $extension, string $alias): bool
    {
        foreach (MockCategoryTable::$registry as $otherId => $row) {
            if ($otherId !== $categoryId
                && (int) $row['parent_id'] === $parentId
                && $row['extension'] === $extension
                && $row['alias'] === $alias) {
                return true;
            }
        }
        return false;
    }
}
