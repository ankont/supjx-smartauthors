<?php

declare(strict_types=1);

namespace SuperSoft\Plugin\System\SmartAuthors\Tests\Mocks;

defined('_JEXEC') or die;

use Joomla\CMS\Application\ApplicationHelper;
use Joomla\CMS\Table\Table;

class MockCategoryTable extends Table
{
    /** @var array<int, array<string, mixed>> */
    public static array $registry = [];

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

    public static function resetRegistry(): void
    {
        self::$registry = [];
    }

    public static function seed(int $id, array $data): void
    {
        self::$registry[$id] = array_merge([
            'id' => $id,
            'title' => 'Cat ' . $id,
            'alias' => 'cat-' . $id,
            'parent_id' => 1,
            'extension' => 'com_content',
            'language' => '*',
            'lft' => 0,
            'rgt' => 0,
            'level' => 1,
            'path' => 'cat-' . $id,
        ], $data);
    }

    public function load(mixed $keys = null, bool $reset = true): bool
    {
        if (is_int($keys) || is_numeric($keys)) {
            $id = (int) $keys;
            if (isset(self::$registry[$id])) {
                $this->bind(self::$registry[$id]);
                return true;
            }
            return false;
        }

        if (is_array($keys)) {
            foreach (self::$registry as $row) {
                $match = true;
                foreach ($keys as $k => $v) {
                    if (!isset($row[$k]) || (string) $row[$k] !== (string) $v) {
                        $match = false;
                        break;
                    }
                }
                if ($match) {
                    $this->bind($row);
                    return true;
                }
            }
            return false;
        }

        return false;
    }

    public function bind(array $src): bool
    {
        foreach ($src as $k => $v) {
            if (property_exists($this, $k)) {
                $this->$k = $v;
            }
        }
        return true;
    }

    public function store(bool $updateNulls = false): bool
    {
        if (trim(str_replace('-', '', $this->alias)) === '') {
            return false;
        }

        // Uniqueness check among siblings
        foreach (self::$registry as $otherId => $row) {
            if ($otherId !== $this->id
                && (int) $row['parent_id'] === (int) $this->parent_id
                && $row['extension'] === $this->extension
                && $row['alias'] === $this->alias) {
                return false; // Collision!
            }
        }

        self::$registry[$this->id] = [
            'id' => $this->id,
            'title' => $this->title,
            'alias' => $this->alias,
            'parent_id' => $this->parent_id,
            'extension' => $this->extension,
            'language' => $this->language,
            'lft' => $this->lft,
            'rgt' => $this->rgt,
            'level' => $this->level,
            'path' => $this->path,
        ];

        return true;
    }

    public function rebuildPath(?int $pk = null): bool
    {
        $id = $pk ?? $this->id;
        if (isset(self::$registry[$id])) {
            $alias = self::$registry[$id]['alias'];
            $parentId = self::$registry[$id]['parent_id'];
            if ($parentId > 1 && isset(self::$registry[$parentId])) {
                $this->path = self::$registry[$parentId]['path'] . '/' . $alias;
            } else {
                $this->path = $alias;
            }
            self::$registry[$id]['path'] = $this->path;
        }
        return true;
    }

    public function rebuild(?int $parentId = null, int $leftId = 0, int $level = 0, string $path = ''): int|bool
    {
        // Rebuild paths of children recursively
        $targetId = $parentId ?? $this->id;
        foreach (self::$registry as $childId => &$row) {
            if ((int) $row['parent_id'] === $targetId) {
                $row['path'] = $path . '/' . $row['alias'];
                $this->rebuild($childId, 0, $level + 1, $row['path']);
            }
        }
        return true;
    }
}
