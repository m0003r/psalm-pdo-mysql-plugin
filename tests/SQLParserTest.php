<?php

declare(strict_types=1);

namespace M03r\PsalmPDOMySQL\Test;

use Generator;
use M03r\PsalmPDOMySQL\Parser\SQLParser;
use PHPUnit\Framework\TestCase;

class SQLParserTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        $xml = simplexml_load_file(__DIR__ . '/Integration/_data/databases.xml');
        SQLParser::loadDatabaseDescription($xml);
    }

    /**
     * @return Generator<string, array{string, array<string, bool>}, empty, void>
     */
    public function validDataProvider(): Generator
    {
        yield 'simple' => [
            "SELECT 1 as data",
            [
                'data' => false,
            ],
        ];
    }

    /**
     * @dataProvider validDataProvider
     */
    public function testSQLParser(string $query, ?array $columnTypes, bool $aggregating = false): void
    {
        $colTypes = SQLParser::parseSQL($query);
        $this->assertEquals($columnTypes, $colTypes);
    }
}
