<?php

declare(strict_types=1);

namespace M03r\PsalmPDOMySQL\Test;

use M03r\PsalmPDOMySQL\Parser\ColumnType;
use PHPUnit\Framework\TestCase;

class ColumnTypeTest extends TestCase
{
    /**
     * @dataProvider columnDefinitionsProvider
     */
    public function testColumnDefinition(string $type, bool $nullable, string $expectedId): void
    {
        $columnType = new ColumnType($type, $nullable);
        $this->assertEquals($expectedId, $columnType->type->getId());
    }

    /**
     * @return array<array{string, bool, string}>
     */
    public function columnDefinitionsProvider(): array
    {
        return [
            'varchar' => ['varchar', false, 'string'],
            'int' => ['int', false, 'numeric-string'],
            'enum' => ["enum('A', 'B', 'C')", true, '"A"|"B"|"C"|null'],
        ];
    }
}
