<?php

declare(strict_types=1);

namespace M03r\PsalmPDOMySQL\Test;

use M03r\PsalmPDOMySQL\Parser\SQLParser;
use PHPUnit\Framework\TestCase;
use Psalm\Type\Union;

class SQLParserTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        $xml = simplexml_load_file(__DIR__ . '/Integration/_data/databases.xml');
        SQLParser::loadDatabaseDescription($xml);
    }

    /**
     * @return array<array{string, array<string, string>}>
     */
    public function validDataProvider(): array
    {
        return [
            'simple' => [
                "SELECT 1 as data",
                [
                    'data' => 'string|null',
                ],
            ],
            'simple null' => [
                'SELECT NULL',
                [
                    'NULL' => 'null',
                ],
            ],
            'simple string' => [
                'SELECT t_varchar, t_char, t_text FROM basic_types',
                [
                    't_varchar' => 'string',
                    't_char' => 'string',
                    't_text' => 'string',
                ],
            ],
            'simple numbres' => [
                'SELECT t_int, t_float FROM basic_types',
                [
                    't_int' => 'numeric-string',
                    't_float' => 'numeric-string'
                ],
            ],
            'simple enum' => [
                'SELECT t_enumABC FROM basic_types',
                [
                    't_enumABC' => '"A"|"B"|"C"',
                ],
            ],
            'left join' => [
                'SELECT bt.t_enumABC, bt2.t_enumABC `joined` FROM basic_types bt LEFT JOIN basic_types bt2',
                [
                    't_enumABC' => '"A"|"B"|"C"',
                    'joined' => '"A"|"B"|"C"|null',
                ],
            ],
            'right join' => [
                'SELECT bt.t_enumABC, bt2.t_enumABC `joined` FROM basic_types bt RIGHT JOIN basic_types bt2',
                [
                    't_enumABC' => '"A"|"B"|"C"|null',
                    'joined' => '"A"|"B"|"C"',
                ],
            ],
            'inner join' => [
                'SELECT bt.t_enumABC, bt2.t_enumABC `joined` FROM basic_types bt INNER JOIN basic_types bt2',
                [
                    't_enumABC' => '"A"|"B"|"C"',
                    'joined' => '"A"|"B"|"C"',
                ],
            ],
        ];
    }

    /**
     * @dataProvider validDataProvider
     * @param ?array<string, string> $expectedTypes
     */
    public function testSQLParser(string $query, ?array $expectedTypes): void
    {
        $actualTypes = SQLParser::parseSQL($query);

        if ($expectedTypes === null) {
            $this->assertNull($actualTypes);
            return;
        }

        $this->assertNotNull($actualTypes);

        $actualTypesKeysExploded = array_map(static function (Union $type): array {
            return explode('|', $type->getId());
        }, $actualTypes);
        $expectedTypesKeysExploded = array_map(static function (string $type): array {
            return explode('|', $type);
        }, $expectedTypes);

        $this->assertEqualsCanonicalizing($expectedTypesKeysExploded, $actualTypesKeysExploded);
    }
}
