<?php

declare(strict_types=1);

namespace M03r\PsalmPDOMySQL\Parser;

use Exception;
use PhpMyAdmin\SqlParser\Components\Expression;
use PhpMyAdmin\SqlParser\Lexer;
use PhpMyAdmin\SqlParser\Parser;
use PhpMyAdmin\SqlParser\Statements\SelectStatement;
use PhpMyAdmin\SqlParser\Token;
use RuntimeException;
use UnexpectedValueException;

class ParsedStatement
{
    /** @var array<string, string|ParsedStatement> */
    public $aliases = [];

    /** @var array<string, true> */
    public $allUnprefixedTables = [];

    /** @var array<string, true>  */
    public $leftJoins = [];

    /** @var array<string, true>  */
    public $rightJoins = [];

    /** @var SelectStatement */
    public $stmt;

    /** @var string */
    public $query;

    /**
     * @param array<string, string|ParsedStatement> $knownTables
     */
    public function __construct(string $query, array $knownTables = [])
    {
        $this->query = $query;

        try {
            $this->stmt = $this->parseSelect($query);
            $this->aliases = $knownTables;

            $this->parseFrom();
            $this->parseJoin();
        } catch (Exception $e) {
            throw new RuntimeException('Error parsing query ' . $query, (int)$e->getCode(), $e);
        }
    }

    /**
     */
    protected function parseSelect(string $query): SelectStatement
    {
        $parser = new Parser($query);
        $select = $parser->statements[0] ?? null;

        if (!$select instanceof SelectStatement) {
            throw new UnexpectedValueException("Subselect $query must be parsed to SelectStatement");
        }

        foreach ($parser->errors as $error) {
            throw $error;
        }

        return $select;
    }

    protected function parseFrom(): void
    {
        foreach ($this->stmt->from as $from) {
            $this->addTableLikeExpression($from);
        }
    }

    protected function addTableLikeExpression(Expression $expr): void
    {
        if (self::isTable($expr)) {
            $this->addTable($expr);
            return;
        }

        if (self::isSubquery($expr)) {
            $this->addSubquery($expr);
            return;
        }

        throw new UnexpectedValueException('Should be table or subselect!');
    }

    /**
     */
    protected static function isTable(Expression $expr): bool
    {
        return $expr->table && !$expr->column && !$expr->function && !$expr->subquery;
    }

    protected function addTable(Expression $expr): void
    {
        $table = $expr->table;
        $alias = $expr->alias ?? $expr->table;

        if ($expr->database) {
            $table = $expr->database . '.' . $table;

            if (strtolower($expr->database) === 'information_schema') {
                $table = strtolower($table);
            }
        } else {
            $this->allUnprefixedTables[$expr->table] = true;
        }

        $this->aliases[$alias] = $table;
    }

    /**
     */
    protected static function isSubquery(Expression $expr): bool
    {
        return (bool)$expr->subquery;
    }

    protected function addSubquery(Expression $expr): void
    {
        $subquery = self::extractSubquery($expr);
        $alias = $expr->alias;

        $parsedSubselect = new self($subquery, $this->aliases);
        $this->aliases[$alias] = $parsedSubselect;

        foreach (array_keys($parsedSubselect->allUnprefixedTables) as $unprefixedTable) {
            $this->allUnprefixedTables[$unprefixedTable] = true;
        }
    }

    protected static function extractSubquery(Expression $expr): string
    {
        // мы собираемся начать захватывать токены тогда, когда
        // встретим начало подзапроса (извлекается из $expr->subquery, по идее
        // там должно быть только SELECT), и продолжаем извлекать
        // пока количество скобок не станет меньше нуля.
        //
        // т.е. в подзапросе вида EXISTS(SELECT .... ) извлечение начнётся при
        // токене SELECT и закончится перед закрывающей его скобкой

        $tokenList = Lexer::getTokens($expr->expr);
        $capture = false;
        $out = [];
        $brackets = null;

        foreach ($tokenList->tokens as $token) {
            // начинаем захватывать
            if (!$capture) {
                if ($token->value === $expr->subquery) {
                    $capture = true;
                    $brackets = 0;
                } else {
                    continue;
                }
            }

            if ($token->type === Token::TYPE_OPERATOR && $token->value === ')') {
                // если есть закрывающая скобка и счётчик на нуле, то это — конец субселекта
                if ($brackets === 0) {
                    break;
                }

                $brackets--;
            }

            $out[] = $token->value;

            if ($token->type === Token::TYPE_OPERATOR && $token->value === '(') {
                $brackets++;
            }
        }

        if (!$capture) {
            throw new UnexpectedValueException("Can not capture subquery type $expr->subquery in $expr->expr");
        }

        return implode($out);
    }

    protected function parseJoin(): void
    {
        if ($this->stmt->join) {
            foreach ($this->stmt->join as $join) {
                $this->addTableLikeExpression($join->expr);
                $alias = $join->expr->alias ?? $join->expr->table;

                switch (strtolower($join->type)) {
                    case 'left':
                        $this->leftJoins[$alias] = true;
                        break;

                    case 'right':
                        $this->rightJoins[$alias] = true;
                        break;
                }
            }
        }
    }
}