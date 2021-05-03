<?php

declare(strict_types=1);

namespace M03r\PsalmPDOMySQL\Types;

use M03r\PsalmPDOMySQL\Types\TSqlSelectString;
use Psalm\Plugin\Hook\StringInterpreterInterface;
use Psalm\Type\Atomic\TLiteralString;

use function preg_match;

class SQLStringProvider implements StringInterpreterInterface
{
    /**
     * @return ?TSqlSelectString
     */
    public static function getTypeFromValue(string $value): ?TLiteralString
    {
        if (preg_match('/^\s*select\b/im', $value)) {
            return new TSqlSelectString($value);
        }

        return null;
    }
}
