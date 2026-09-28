<?php

declare(strict_types=1);

namespace SuperSoft\Plugin\System\SmartAuthors\Domain\Enum;

defined('_JEXEC') or die;

enum PolicyType: string
{
    case Personal = 'personal';
    case Collaborative = 'collaborative';

    public function isPersonal(): bool
    {
        return $this === self::Personal;
    }

    public function isCollaborative(): bool
    {
        return $this === self::Collaborative;
    }

    public static function fromString(string $value): self
    {
        return self::tryFrom(strtolower(trim($value))) ?? self::Personal;
    }
}
