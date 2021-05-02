<?php

declare(strict_types=1);

namespace M03r\PsalmPDOMySQL\Types;

use Psalm\Type\Atomic\TLiteralString;

class TSqlSelectString extends TLiteralString
{
    public $partial = true;

    public function getKey(bool $include_extra = true): string
    {
        return 'sql-select-string';
    }

    public function getId(bool $nested = true): string
    {
        return 'sql-select-' . parent::getId($nested);
    }

    public function canBeFullyExpressedInPhp(int $php_major_version, int $php_minor_version): bool
    {
        return false;
    }
}
