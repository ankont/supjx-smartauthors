<?php

declare(strict_types=1);

namespace SuperSoft\Plugin\System\SmartAuthors\Domain\Enum;

defined('_JEXEC') or die;

enum CategoryManagement: string
{
    case None = 'none';
    case Subtree = 'subtree';

    public function allowsSubtree(): bool
    {
        return $this === self::Subtree;
    }

    public static function fromString(string $value): self
    {
        return self::tryFrom(strtolower(trim($value))) ?? self::None;
    }
}
