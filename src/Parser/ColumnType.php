<?php

declare(strict_types=1);

namespace M03r\PsalmPDOMySQL\Parser;

use PhpMyAdmin\SqlParser\Lexer;
use PhpMyAdmin\SqlParser\Token;
use Psalm\Type\Atomic;
use Psalm\Type\Atomic\TNull;
use Psalm\Type\Atomic\TNumericString;
use Psalm\Type\Atomic\TString;
use Psalm\Type\Union;

class ColumnType
{
    /** @var Union */
    public $type;

    public function __construct(string $type = '', bool $nullable = true)
    {
        /** @var ?Union $phpType */
        $phpType = null;

        switch (strtolower($type)) {
            case 'null':
                $this->type = new Union([new TNull()]);
                return;

            case 'int':
            case 'tinyint':
            case 'smallint':
            case 'mediumint':
            case 'bigint':
            case 'integer':
            case 'numeric':
            case 'decimal':
            case 'float':
            case 'double':
            case 'bit':
                $phpType = new Union([new TNumericString()]);
        }

        if (stripos($type, 'enum') === 0) {
            $lexer = new Lexer($type);
            /** @var list<Atomic> $atomics */
            $atomics = [];

            while ($strToken = $lexer->list->getNextOfType(Token::TYPE_STRING)) {
                $atomics[] = new Atomic\TLiteralString((string)$strToken->value);
            }

            if (empty($atomics)) {
                $atomics[] = new Atomic\TLiteralString('');
            }

            $phpType = new Union($atomics);
        }

        if (!$phpType) {
            $phpType = new Union([new TString()]);
        }

        if ($nullable) {
            $phpType->addType(new TNull());
        }

        $this->type = $phpType;
    }

    public function addNullable(): void
    {
        $this->type = clone $this->type;
        $this->type->addType(new TNull());
    }

    public function __clone()
    {
        $newType = new ColumnType();
        $newType->type = clone $this->type;
        return $newType;
    }
}
