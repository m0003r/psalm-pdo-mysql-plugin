<?php

declare(strict_types=1);

namespace M03r\PsalmPDOMySQL\Test;

use M03r\PsalmPDOMySQL\Parser\SQLParser;
use PHPUnit\Framework\TestCase;
use Psalm\Type\Union;
use RuntimeException;
use SimpleXMLElement;
use Throwable;
use UnexpectedValueException;

class SQLParserTest extends TestCase
{
    /**
     * @var SimpleXMLElement
     */
    protected static $xml;

    public static function setUpBeforeClass(): void
    {
        $xml = simplexml_load_file(__DIR__ . '/Integration/_data/databases.xml');

        if (!$xml) {
            throw new UnexpectedValueException("Invalid XML!");
        }

        self::$xml = $xml;
    }

    /**
     * @return array<string, array{string, array<string>}>
     */
    public function validDataProvider(): array
    {
        return [
            'simple' => [
                "SELECT 1",
                [
                    1 => 'numeric-string',
                ],
            ],
            'simple with alias' => [
                "SELECT 1 as data",
                [
                    'data' => 'numeric-string',
                ],
            ],
            'simple text' => [
                "SELECT 'data' as data",
                [
                    'data' => '"data"',
                ],
            ],
            'simple text with alias' => [
                "SELECT 'data' as col",
                [
                    'col' => '"data"',
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
                    't_float' => 'numeric-string',
                ],
            ],
            'simple enum' => [
                'SELECT t_enumABC FROM basic_types',
                [
                    't_enumABC' => '"A"|"B"|"C"',
                ],
            ],
            'all' => [
                'SELECT * FROM all_types',
                [
                    't_integer' => 'numeric-string',
                    't_int' => 'numeric-string',
                    't_smallint' => 'numeric-string',
                    't_tinyint' => 'numeric-string',
                    't_mediumint' => 'numeric-string',
                    't_bigint' => 'numeric-string',
                    't_decimal' => 'numeric-string',
                    't_numeric' => 'numeric-string',
                    't_float' => 'numeric-string',
                    't_double' => 'numeric-string',
                    't_bit' => 'numeric-string',
                    't_date' => 'string',
                    't_datetime' => 'string',
                    't_timestamp' => 'string',
                    't_time' => 'string',
                    't_year' => 'string',
                    't_char' => 'string',
                    't_varchar' => 'string',
                    't_binary' => 'string',
                    't_varbinary' => 'string',
                    't_blob' => 'string',
                    't_text' => 'string',
                    't_enum' => '"F"|"U"|"L"',
                    't_set' => 'string',

                ],
            ],
            'all with alias' => [
                'SELECT a.* FROM all_types a LEFT JOIN basic_types',
                [
                    't_integer' => 'numeric-string',
                    't_int' => 'numeric-string',
                    't_smallint' => 'numeric-string',
                    't_tinyint' => 'numeric-string',
                    't_mediumint' => 'numeric-string',
                    't_bigint' => 'numeric-string',
                    't_decimal' => 'numeric-string',
                    't_numeric' => 'numeric-string',
                    't_float' => 'numeric-string',
                    't_double' => 'numeric-string',
                    't_bit' => 'numeric-string',
                    't_date' => 'string',
                    't_datetime' => 'string',
                    't_timestamp' => 'string',
                    't_time' => 'string',
                    't_year' => 'string',
                    't_char' => 'string',
                    't_varchar' => 'string',
                    't_binary' => 'string',
                    't_varbinary' => 'string',
                    't_blob' => 'string',
                    't_text' => 'string',
                    't_enum' => '"F"|"U"|"L"',
                    't_set' => 'string',

                ],
            ],
            'specify database' => [
                'SELECT test.basic_types.t_int FROM test.basic_types',
                [
                    't_int' => 'numeric-string',
                ],
            ],
            'left join' => [
                'SELECT bt.t_enumABC, bt2.t_enumABC joined FROM basic_types bt LEFT JOIN basic_types bt2',
                [
                    't_enumABC' => '"A"|"B"|"C"',
                    'joined' => '"A"|"B"|"C"|null',
                ],
            ],
            'right join' => [
                'SELECT bt.t_enumABC, bt2.t_enumABC joined FROM basic_types bt RIGHT JOIN basic_types bt2',
                [
                    't_enumABC' => '"A"|"B"|"C"|null',
                    'joined' => '"A"|"B"|"C"',
                ],
            ],
            'inner join' => [
                'SELECT bt.t_enumABC, bt2.t_enumABC joined FROM basic_types bt INNER JOIN basic_types bt2',
                [
                    't_enumABC' => '"A"|"B"|"C"',
                    'joined' => '"A"|"B"|"C"',
                ],
            ],
            'multi join' => [
                'SELECT bt.t_int `left`, btr.t_int `right` FROM basic_types bt 
    LEFT JOIN basic_types btl RIGHT JOIN basic_types btr',
                [
                    'left' => 'numeric-string|null',
                    'right' => 'numeric-string',
                ],
            ],
            'functions' => [
                'SELECT COUNT(1) c, EXISTS(SELECT 2) e, AVG(3) a, MIN(4) AS mi, MAX(5) AS ma,
       SUM(6) s, TRIM(t_int) t FROM basic_types',
                [
                    'c' => 'numeric-string',
                    'e' => 'numeric-string',
                    'a' => 'numeric-string|null',
                    'mi' => 'numeric-string|null',
                    'ma' => 'numeric-string|null',
                    's' => 'numeric-string|null',
                    't' => 'string|null',
                ],
            ],
            'subquery' => [
                'SELECT (SELECT 1) `data`',
                [
                    'data' => 'string|null',
                ],
            ],
            'subquery in from' => [
                'SELECT a FROM (SELECT t_int a FROM basic_types) ta',
                [
                    'a' => 'numeric-string',
                ],
            ],
            'subquery in join' => [
                'SELECT a FROM basic_types LEFT JOIN (SELECT t_int a FROM basic_types) ta',
                [
                    'a' => 'numeric-string|null',
                ],
            ],
            'intersecting *' => [
                'SELECT * FROM single INNER JOIN single',
                [
                    'id' => 'numeric-string',
                ],
            ],
        ];
    }

    /**
     * @return string[][]
     */
    public function nonParsableDataProvider(): array
    {
        return [
            'unknown database' => ['SELECT * FROM `unknown`'],
        ];
    }

    /**
     * @return array<array{string, string}>
     */
    public function exceptionDataProvider(): array
    {
        return [
            'empty' => [
                '',
                'SelectStatement',
            ],
            'non-select' => [
                'UPDATE table',
                'SelectStatement',
            ],
            'empty subselect alias' => [
                'SELECT * FROM (SELECT 1)',
                'without alias',
            ],
            'bad syntax' => [
                'SELECT TABLE AS JOIN',
                'SQL Parser',
            ],
            'non-existing table' => [
                'SELECT * FROM test.basic_table LEFT JOIN test.unknown',
                'not found in',
            ],
            'unknown column' => [
                'SELECT test FROM basic_types',
                'table for column',
            ],
            'unknown table or alias' => [
                'SELECT a.id FROM basic_types',
                'resolve table',
            ],
            'star from unknown table' => [
                'SELECT a.* FROM basic_types',
                'find table',
            ],
        ];
    }

    /**
     * @dataProvider validDataProvider
     * @param ?array<string> $expectedTypes
     */
    public function testSQLParserValid(string $query, ?array $expectedTypes): void
    {
        $actualTypes = SQLParser::parseSQL($query);

        if ($expectedTypes === null) {
            self::assertNull($actualTypes);
            return;
        }

        self::assertNotNull($actualTypes);

        self::assertEquals(array_keys($expectedTypes), array_keys($actualTypes));

        $actualTypesKeysExploded = array_map(static function (Union $type): array {
            return explode('|', $type->getId());
        }, $actualTypes);
        $expectedTypesKeysExploded = array_map(static function (string $type): array {
            return explode('|', $type);
        }, $expectedTypes);

        self::assertEqualsCanonicalizing($expectedTypesKeysExploded, $actualTypesKeysExploded);
    }

    /**
     * @dataProvider nonParsableDataProvider
     */
    public function testSQLParserNonParsable(string $query): void
    {
        self::assertNull(SQLParser::parseSQL($query));
    }

    /**
     * @dataProvider exceptionDataProvider
     */
    public function testSQLParserException(string $query, string $expectedExceptionMessagePart): void
    {
        $result = null;

        try {
            $result = SQLParser::parseSQL($query);
        } catch (Throwable $e) {
            if (!$e instanceof UnexpectedValueException) {
                self::assertEquals(RuntimeException::class, get_class($e));
                self::assertStringContainsString('Error parsing query', $e->getMessage());
                self::assertStringContainsString($query, $e->getMessage());
                $e = $e->getPrevious();
            }

            self::assertInstanceOf(UnexpectedValueException::class, $e);
            self::assertStringContainsString($expectedExceptionMessagePart, $e->getMessage());
        }

        self::assertNull($result, "Expected exception '$expectedExceptionMessagePart' wasn't thrown");
    }

    protected function setUp(): void
    {
        SQLParser::loadDatabaseDescription(self::$xml);
    }
}
