<?php

declare(strict_types=1);

namespace M03r\PsalmPDOMySQL\Parser;

use PhpMyAdmin\SqlParser\Components\Expression;
use Psalm\Type\Union;
use SimpleXMLElement;
use UnexpectedValueException;

class SQLParser
{
    /** @var array<string, array<string, array<string, ColumnType>>> */
    public static $databases;

    /**
     * @return array<string, Union>|null List of arguments with nullable flag or null if it can't be parsed
     */
    public static function parseSQL(string $query): ?array
    {
        $parsedStatement = new ParsedStatement($query);

        // let's figure out which database we are currently querying
        $unprefixedTables = array_keys($parsedStatement->allUnprefixedTables);
        $defaultDatabase = null;

        foreach (self::$databases as $databaseName => $database) {
            $array_diff = array_diff($unprefixedTables, array_keys($database));

            if (count($array_diff) === 0) {
                $defaultDatabase = $databaseName;
                break;
            }
        }

        // alas
        if ($defaultDatabase === null) {
            return null;
        }

        $columns = self::getColumns($parsedStatement, $defaultDatabase);

        return array_map(static function (ColumnType $columnType): Union {
            return $columnType->type;
        }, $columns);
    }

    /**
     * @return array<string, ColumnType>
     */
    private static function getColumns(ParsedStatement $parsedStatement, string $defaultDatabase): array
    {
        // get all table aliases
        /** @var array<string, array<string, ColumnType>> $aliasedTables */
        $aliasedTables = [];

        foreach ($parsedStatement->aliases as $alias => $tableOrSubquery) {
            if (is_string($tableOrSubquery)) {
                // look up database name
                $exploded = explode('.', $tableOrSubquery, 2);

                if (count($exploded) < 2) {
                    array_unshift($exploded, $defaultDatabase);
                }

                [$databaseName, $tableName] = $exploded;

                // look for table description
                if (!isset(self::$databases[$databaseName][$tableName])) {
                    throw new UnexpectedValueException("Table '$tableName' not found in '$databaseName'");
                }

                $aliasedTables[$alias] = self::$databases[$databaseName][$tableName];
                continue;
            }

            // use recursion for subquery
            $aliasedTables[$alias] = self::getColumns($tableOrSubquery, $defaultDatabase);
        }

        foreach ($aliasedTables as $alias => $data) {
            // if table is left joined or something else is right joined, then it values are always nullable
            if (isset($parsedStatement->leftJoins[$alias])
                || (count($parsedStatement->rightJoins) > 0 && !isset($parsedStatement->rightJoins[$alias]))
            ) {
                foreach ($data as $columnName => $type) {
                    $aliasedTables[$alias][$columnName] = clone $type;
                    $aliasedTables[$alias][$columnName]->addNullable();
                }
            }
        }

        /** @var array<string, ColumnType> $outColumns */
        $outColumns = [];

        // for each selected expression
        foreach ($parsedStatement->stmt->expr as $expr) {
            // special case
            if ($expr->expr === '*') {
                foreach ($aliasedTables as $table) {
                    $columnLowerCaseAdded = [];

                    foreach ($table as $column => $nullable) {
                        if (isset($columnLowerCaseAdded[$column])) {
                            continue;
                        }

                        $columnLowerCaseAdded[strtolower($column)] = true;
                        $outColumns[$column] = $nullable;
                    }
                }

                continue;
            }

            // all from specific table
            if ($expr->table !== null && $expr->expr && substr($expr->expr, -1) === '*') {
                if (!isset($aliasedTables[$expr->table])) {
                    throw new UnexpectedValueException("Can't find table '{$expr->table}'");
                }

                $columnLowerCaseAdded = [];

                foreach ($aliasedTables[$expr->table] as $column => $columnType) {
                    if (isset($columnLowerCaseAdded[$column])) {
                        continue;
                    }

                    $columnLowerCaseAdded[strtolower($column)] = true;
                    $outColumns[$column] = $columnType;
                }

                continue;
            }

            self::fixAnsiQuotes($expr);

            if ($expr->column === null && $expr->expr !== null) {
                if ($table = self::findColumnInTables($aliasedTables, $expr->expr)) {
                    $expr->column = $expr->expr;
                    $expr->expr = null;
                    $expr->table = $table;
                } else {
                    $nameFromAliasOrExpr = $expr->alias ?? $expr->expr;

                    if ($expr->function === null && $expr->subquery === null) {
                        if ($expr->expr === 'NULL') {
                            $outColumns[$nameFromAliasOrExpr] = new ColumnType('null');
                            continue;
                        }

                        if (is_numeric($expr->expr)) {
                            $outColumns[$nameFromAliasOrExpr] = new ColumnType('int', false);
                            continue;
                        }

                        if ($expr->expr[0] === '"' || $expr->expr[0] === "'") {
                            $outColumns[$nameFromAliasOrExpr] = new ColumnType('ENUM(' . $expr->expr . ')', false);
                            continue;
                        }
                    }

                    if ($expr->subquery !== null && strtoupper((string)$expr->function) !== 'EXISTS') {
                        $outColumns[$nameFromAliasOrExpr] = new ColumnType();
                        continue;
                    }

                    if (is_string($expr->function)) {
                        switch (strtoupper($expr->function)) {
                            case 'COUNT':
                            case 'EXISTS':
                                $outColumns[$nameFromAliasOrExpr] = new ColumnType('int', false);
                                break;

                            case 'AVG':
                            case 'MIN':
                            case 'MAX':
                            case 'SUM':
                                $outColumns[$nameFromAliasOrExpr] = new ColumnType('int', true);
                                break;

                            default:
                                $outColumns[$nameFromAliasOrExpr] = new ColumnType();
                        }

                        continue;
                    }
                }
            }

            // get column name or alias
            $columnName = $expr->alias ?? $expr->column ?? $expr->expr;

            if (!$columnName) {
                throw new UnexpectedValueException(
                    "\$expr->column and \$expr->alias is null in table (alias) '$expr->table'"
                );
            }

            // other functions and subqueries is nullable
            if ($expr->function !== null || $expr->subquery !== null) {
                $outColumns[$columnName] = new ColumnType();
                continue;
            }

            // выясняем таблицу, из которой выбирают, а если нет — то ищем с подходящим столбцом
            $table = $expr->table;

            if (!$table) {
                $table = self::findColumnInTables($aliasedTables, $expr->column);
            }

            if (!$table) {
                throw new UnexpectedValueException(
                    "Can't find table for column '{$expr->column}' (tables: "
                    . implode(
                        ', ',
                        array_keys($aliasedTables)
                    )
                    . ')'
                );
            }

            if (!isset($aliasedTables[$table])) {
                throw new UnexpectedValueException("Can't resolve table (alias) '$table'");
            }

            if ($expr->column && !isset($aliasedTables[$table][strtolower($expr->column)])) {
                throw new UnexpectedValueException("Can't find column '{$expr->column}' in table (alias) '$table'");
            }

            if (!$expr->column) {
                throw new UnexpectedValueException("\$expr->column is null in table (alias) '$table'");
            }

            // и присваиваем флаг nullable такой же, как в справочнике
            $outColumns[$columnName] = $aliasedTables[$table][strtolower($expr->column)];
        }

        return $outColumns;
    }

    /**
     */
    private static function fixAnsiQuotes(Expression $expr): void
    {
        // workaround ANSI_QUOTES
        // SELECT "test" a
        if (is_string($expr->expr) && ($expr->expr[0] === '"' || $expr->expr[0] === "'")) {
            $expr->column = null;
        }

        // another ANSI_QUOTES workaround
        // SELECT "test" AS a
        if ($expr->expr === $expr->column
            && $expr->table === null
            && $expr->function === null
            && $expr->subquery === null
        ) {
            $expr->column = null;
        }
    }

    /**
     * @param array<string, array<string, ColumnType>> $aliasedTables
     */
    private static function findColumnInTables(array $aliasedTables, ?string $column): ?string
    {
        if ($column === null) {
            return null;
        }

        foreach ($aliasedTables as $alias => $tableData) {
            if (isset($tableData[strtolower($column)])) {
                return $alias;
            }
        }

        return null;
    }

    public static function loadDatabaseDescription(SimpleXMLElement $databases): void
    {
        if (!$databases->database instanceof SimpleXMLElement || count($databases->database) === 0) {
            throw new UnexpectedValueException("No databases configured");
        }

        foreach ($databases->database as $database) {
            $databaseName = (string)$database['name'];
            self::$databases[$databaseName] = [];

            /** @var SimpleXMLElement $table */
            foreach ($database->table as $table) {
                $tableName = (string)$table['name'];
                self::$databases[$databaseName][$tableName] = [];

                /** @var SimpleXMLElement $column */
                foreach ($table->column as $column) {
                    $columnName = (string)$column['name'];
                    $nullable = strtoupper((string)$column['nullable']) === 'YES';
                    $type = (string)$column['type'];

                    if (strtolower($databaseName) === 'information_schema') {
                        $databaseName = strtolower($databaseName);
                        $tableName = strtolower($tableName);
                    }

                    self::$databases[$databaseName][$tableName][$columnName]
                        = self::$databases[$databaseName][$tableName][strtolower($columnName)]
                        = new ColumnType($type, $nullable);
                }
            }
        }
    }
}
