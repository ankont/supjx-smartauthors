<?php

declare(strict_types=1);

namespace SuperSoft\Plugin\System\SmartAuthors\Domain\Enum;

defined('_JEXEC') or die;

enum EditMode: string
{
    case Own = 'own';
    case All = 'all';

    public function allowsAll(): bool
    {
        return $this === self::All;
    }

    public function allowsOwn(): bool
    {
        return $this === self::Own;
    }

    public static function fromString(string $value): self
    {
        return self::tryFrom(strtolower(trim($value))) ?? self::Own;
    }
}
