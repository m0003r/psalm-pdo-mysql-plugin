<?php

declare(strict_types=1);

namespace M03r\PsalmPDOMySQL\Parser;

use PhpMyAdmin\SqlParser\Components\Expression;
use SimpleXMLElement;
use UnexpectedValueException;

class SQLParser
{
    /** @var array<string, array<string, array<string, bool>>> */
    public static $databases;

    /**
     * @return array<string, bool>|null List of arguments with nullable flag or null if it can't be parsed
     */
    public static function parseSQL(string $query): ?array
    {
        $parsedStatement = new ParsedStatement($query);

        // выясняем, к какой базе относится запрос
        // ища все таблицы без баз в справочнике
        $unprefixedTables = array_keys($parsedStatement->allUnprefixedTables);
        $unprefixedDatabase = null;

        foreach (self::$databases as $databaseName => $database) {
            $array_diff = array_diff($unprefixedTables, array_keys($database));

            if (count($array_diff) === 0) {
                $unprefixedDatabase = $databaseName;
                break;
            }
        }

        // увы
        if ($unprefixedDatabase === null) {
            return null;
        }

        $columns = self::getColumns($parsedStatement, $unprefixedDatabase);

        if (count($columns) === 0) {
            return null;
        }

        return $columns;
    }

    /**
     * @return array<string, boolean>
     */
    protected static function getColumns(ParsedStatement $parsedStatement, string $unprefixedDatabase): array
    {
        // сначала выпиываем все алиасы таблиц из этого запроса
        $aliasedTables = [];

        foreach ($parsedStatement->aliases as $alias => $table) {
            if (is_string($table)) {
                // ищем базу
                $exploded = explode('.', $table, 2);

                if (count($exploded) < 2) {
                    array_unshift($exploded, $unprefixedDatabase);
                }

                [$databaseName, $tableName] = $exploded;

                // ищем таблицу в справочнике
                if (!isset(self::$databases[$databaseName][$tableName])) {
                    throw new UnexpectedValueException("Table '$tableName' not found in '$databaseName'");
                }

                $aliasedTables[$alias] = self::$databases[$databaseName][$tableName];
                continue;
            }

            // а если это подзапрос, то используем волшебную рекурсию
            $aliasedTables[$alias] = self::getColumns($table, $unprefixedDatabase);
        }

        foreach ($aliasedTables as $alias => $data) {
            // если эта таблица присоеднияется left join'ом
            // или в выражении есть right join, и это не он
            if (isset($parsedStatement->leftJoins[$alias])
                || (count($parsedStatement->rightJoins) > 0 && !isset($parsedStatement->rightJoins[$alias]))
            ) {
                $aliasedTables[$alias] = array_fill_keys(array_keys($data), true);
            }
        }

        $outColumns = [];

        // и для каждого выбираемого выражения
        foreach ($parsedStatement->stmt->expr as $expr) {
            // особый случай, всё из всего
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

            // всё из таблицы
            if ($expr->table !== null && substr($expr->expr, -1) === '*') {
                if (!isset($aliasedTables[$expr->table])) {
                    throw new UnexpectedValueException("Can't find table '{$expr->table}'");
                }

                $columnLowerCaseAdded = [];

                foreach ($aliasedTables[$expr->table] as $column => $nullable) {
                    if (isset($columnLowerCaseAdded[$column])) {
                        continue;
                    }

                    $columnLowerCaseAdded[strtolower($column)] = true;
                    $outColumns[$column] = $nullable;
                }

                continue;
            }

            self::fixExpression($expr);

            if ($expr->column === null && $expr->expr !== null) {
                if ($table = self::findColumnInTables($aliasedTables, $expr->expr)) {
                    $expr->column = $expr->expr;
                    $expr->expr = null;
                    $expr->table = $table;
                } else {
                    $nameFromAliasOrExpr = $expr->alias ?? $expr->expr;

                    if ($expr->function === null) {
                        if ($expr->subquery === null && $expr->expr !== 'NULL') {
                            $outColumns[$nameFromAliasOrExpr] = false;
                        } else {
                            $outColumns[$nameFromAliasOrExpr] = true;
                        }

                        continue;
                    }

                    if ($expr->subquery !== null && strtoupper($expr->function) !== 'EXISTS') {
                        $outColumns[$nameFromAliasOrExpr] = true;
                        continue;
                    }

                    switch (strtoupper($expr->function)) {
                        case 'COUNT':
                        case 'EXISTS':
                            $outColumns[$nameFromAliasOrExpr] = false;
                            break;

                        case 'AVG':
                        case 'MIN':
                        case 'MAX':
                        case 'SUM':
                            $outColumns[$nameFromAliasOrExpr] = true;
                            break;

                        default:
                            $outColumns[$nameFromAliasOrExpr] = true;
                    }

                    continue;
                }
            }

            // выясняем имя
            $columnName = $expr->alias ?? $expr->column;

            // если это функция или подзапрос, то они точно nullable
            if ($expr->function !== null || $expr->subquery !== null) {
                $outColumns[$columnName] = true;
                continue;
            }

            // выясняем таблицу, из которой выбирают, а если нет — то ищем с подходящим столбцом
            $table = $expr->table;

            if (!$table) {
                $table = self::findColumnInTables($aliasedTables, $expr->column);
            }

            if (!$table) {
                throw new UnexpectedValueException("Can't find table for column '{$expr->column}' (tables: " . implode(
                    ', ',
                    array_keys($aliasedTables)
                ) . ')');
            }

            if (!isset($aliasedTables[$table])) {
                throw new UnexpectedValueException("Can't resolve table (alias) '$table'");
            }

            if (!isset($aliasedTables[$table][strtolower($expr->column)])) {
                throw new UnexpectedValueException("Can't find column '{$expr->column}' in table (alias) '$table'");
            }

            // и присваиваем флаг nullable такой же, как в справочнике
            $outColumns[$columnName] = $aliasedTables[$table][strtolower($expr->column)];
        }

        return $outColumns;
    }

    /**
     */
    protected static function fixExpression(Expression $expr): void
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
     * @param array<string, array<string, bool>> $aliasedTables
     */
    protected static function findColumnInTables(array $aliasedTables, ?string $column): ?string
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
        if (!is_countable($databases->database) || count($databases->database) === 0) {
            throw new UnexpectedValueException("No databases configured");
        }

        foreach ($databases->database as $database) {
            $databaseName = (string)$database['name'];
            self::$databases[$databaseName] = [];

            foreach ($database->table_structure as $table) {
                $tableName = (string)$table['name'];
                self::$databases[$databaseName][$tableName] = [];

                foreach ($table->field as $field) {
                    $fieldName = (string)$field['Field'];
                    $nullable = (string)$field['Null'] === 'YES';

                    if (strtolower($databaseName) === 'information_schema') {
                        $databaseName = strtolower($databaseName);
                        $tableName = strtolower($tableName);
                    }

                    self::$databases[$databaseName][$tableName][$fieldName] = $nullable;
                    self::$databases[$databaseName][$tableName][strtolower($fieldName)] = $nullable;
                }
            }
        }
    }
}
