<?php

declare(strict_types=1);

namespace M03r\PsalmPDOMySQL\Hooks;

use Exception;
use M03r\PsalmPDOMySQL\Issues\PDOInvalidFetchClass;
use M03r\PsalmPDOMySQL\Issues\PDOStatementNotExecuted;
use M03r\PsalmPDOMySQL\Issues\PDOStatementZeroRows;
use M03r\PsalmPDOMySQL\Issues\SQLParserIssue;
use M03r\PsalmPDOMySQL\Parser\SQLParser;
use M03r\PsalmPDOMySQL\Types\TPDOStatement;
use M03r\PsalmPDOMySQL\Types\TSqlSelectString;
use PDO;
use PhpMyAdmin\SqlParser\Exceptions\ParserException;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\MethodCall;
use Psalm\CodeLocation;
use Psalm\Internal\Analyzer\Statements\Expression\ExpressionIdentifier;
use Psalm\IssueBuffer;
use Psalm\Plugin\EventHandler\AfterMethodCallAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\AfterMethodCallAnalysisEvent;
use Psalm\StatementsSource;
use Psalm\Type;
use Psalm\Type\Atomic\TList;
use Psalm\Type\Atomic\TNull;
use Psalm\Type\Atomic\TString;
use Psalm\Type\Union;
use UnexpectedValueException;

class FetchChecker implements AfterMethodCallAnalysisInterface
{
    public static function afterMethodCallAnalysis(AfterMethodCallAnalysisEvent $event): void
    {
        $expr = $event->getExpr();
        $method_id = $event->getMethodId();
        $statements_source = $event->getStatementsSource();
        $context = $event->getContext();

        if (!$expr instanceof MethodCall) {
            return;
        }

        if (strpos($method_id, 'PDOStatement::') !== 0) {
            return;
        }

        $var_id = ExpressionIdentifier::getVarId($expr->var, null, $statements_source);

        if (!$var_id) {
            return;
        }

        $sourceType = $context->vars_in_scope[$var_id] ?? null;

        if (!$sourceType) {
            return;
        }

        $pdoStatement = $sourceType->getAtomicTypes()['PDOStatement'] ?? null;

        if (!$pdoStatement instanceof TPDOStatement) {
            return;
        }

        if (strpos($method_id, "PDOStatement::execute") === 0) {
            $pdoStatement->executed = true;
            $pdoStatement->hasRows = null;

            $pdoStatement->syncToContext($context, $var_id);

            return;
        }

        if (strpos($method_id, "PDOStatement::fetch") === 0) {
            $pdoStatement->syncFromContext($context, $var_id);

            if (!$pdoStatement->executed) {
                IssueBuffer::accepts(
                    new PDOStatementNotExecuted(
                        'Statement was not executed',
                        new CodeLocation($statements_source, $expr)
                    ),
                    $statements_source->getSuppressedIssues()
                );
            }

            if ($pdoStatement->hasRows === false) {
                IssueBuffer::accepts(
                    new PDOStatementZeroRows(
                        'PDO statement has zero remaining rows',
                        new CodeLocation($statements_source, $expr)
                    ),
                    $statements_source->getSuppressedIssues()
                );
            }

            $returnType = self::getMethodReturnType(
                $pdoStatement,
                strtolower(substr($method_id, strlen('PDOStatement::'))),
                $statements_source,
                new CodeLocation($statements_source, $event->getExpr()),
                $event->getExpr()->args
            );

            if ($returnType) {
                $event->setReturnTypeCandidate($returnType);
            }

            if ($method_id === 'PDOStatement::fetchall') {
                $pdoStatement->hasRows = false;
            } else {
                $pdoStatement->hasRows = null;
            }

            $pdoStatement->syncToContext($context, $var_id);
        }
    }

    /**
     * @param Arg[] $args
     */
    public static function getMethodReturnType(
        TPDOStatement $pdoStatement,
        string $methodName,
        StatementsSource $source,
        CodeLocation $location,
        array $args
    ): ?Type\Union {
        $isSuitableMethod =
            $methodName === 'fetch'
            || $methodName === 'fetchall'
            || $methodName === 'fetchcolumn';

        if (!$isSuitableMethod) {
            return null;
        }

        $firstArgRequired = $methodName !== 'fetchcolumn';

        // if first arg is set and it isn't single int literal, we can't do anything
        if (isset($args[0]) &&
            (
                !($first_arg_type = $source->getNodeTypeProvider()->getType($args[0]->value)) ||
                !$first_arg_type->isSingleIntLiteral())
        ) {
            return null;
        }

        // if it is required and
        if ($firstArgRequired && !isset($args[0])) {
            return null;
        }

        /** @var Union|void $first_arg_type */

        $sqlOut = null;

        if ($pdoStatement->sqlString instanceof TSqlSelectString) {
            try {
                $sqlOut = SQLParser::parseSQL($pdoStatement->sqlString->value);
            } catch (Exception $e) {
                // it throws UnexpectedValueException only if query is parsed
                // we don't wanna report parsing of partial queries
                if ($e instanceof UnexpectedValueException/* || !$strParam->partial*/) {
                    $message = $e->getMessage();

                    if (($prev = $e->getPrevious()) && $prev instanceof ParserException) {
                        $message .= (': ' . $prev->getMessage() . ' ' . $prev->token->getInlineToken());
                    }

                    IssueBuffer::accepts(
                        new SQLParserIssue($message, $location),
                        $source->getSuppressedIssues()
                    );
                }
            }
        }

        if (isset($first_arg_type)
            && ($first_arg_type->getSingleIntLiteral()->value) === PDO::FETCH_CLASS
            && isset($args[1])
            && ($second_arg_type = $source->getNodeTypeProvider()->getType($args[1]->value))
            && ($second_arg_type->isSingleStringLiteral())
        ) {
            $class_name = $second_arg_type->getSingleStringLiteral()->value;

            if (!$source->getCodebase()->classOrInterfaceExists($class_name)) {
                IssueBuffer::accepts(
                    new PDOInvalidFetchClass("Class $class_name does not exists", $location),
                    $source->getSuppressedIssues()
                );
            }

            $return_type = new Type\Union([
                new Type\Atomic\TNamedObject($class_name),
                new Type\Atomic\TFalse(),
            ]);

            if ($methodName === 'fetchall') {
                $return_type->removeType('false');
                $return_type = new Type\Union([
                    new TList($return_type),
                ]);

                return $return_type;
            }

            return $return_type;
        }

        if ($methodName === 'fetchcolumn') {
            $returnType = self::getRowType(
                PDO::FETCH_COLUMN,
                $sqlOut,
                isset($args[0]) ? $first_arg_type->getSingleIntLiteral()->value : 0
            );

            if ($returnType) {
                if (!$pdoStatement->hasRows) {
                    $returnType->addType(new Type\Atomic\TFalse());
                }

                return $returnType;
            }
        } else {
            // fetch or fetchall
            $rowType = null;
            $fetch_mode = isset($first_arg_type) ? $first_arg_type->getSingleIntLiteral()->value : PDO::FETCH_BOTH;

            if ($fetch_mode === PDO::FETCH_ASSOC
                || $fetch_mode === PDO::FETCH_BOTH
                || $fetch_mode === PDO::FETCH_NUM
                || $fetch_mode === PDO::FETCH_OBJ
            ) {
                $rowType = self::getRowType($fetch_mode, $sqlOut);
            } elseif ($fetch_mode === PDO::FETCH_COLUMN) {
                $column = 0;

                if (isset($args[1])
                    && ($second_arg_type = $source->getNodeTypeProvider()->getType($args[1]->value))
                    && $second_arg_type->isSingleIntLiteral()
                ) {
                    $column = $second_arg_type->getSingleIntLiteral()->value;
                }

                $rowType = self::getRowType($fetch_mode, $sqlOut, $column);
            }

            if ($rowType !== null) {
                switch ($methodName) {
                    case 'fetch':
                        if (!$pdoStatement->hasRows) {
                            $rowType->addType(new Type\Atomic\TFalse());
                        }

                        return $rowType;

                    case 'fetchall':
                        return new Type\Union([
                            $pdoStatement->hasRows ?
                                new Type\Atomic\TNonEmptyList($rowType) :
                                new TList($rowType),
                        ]);
                }
            }
        }

        return null;
    }

    /**
     * @param ?array<string, Union> $sqlOut
     */
    private static function getRowType(int $fetch_mode, ?array $sqlOut, ?int $column = null): ?Type\Union
    {
        $properties = [];
        $columnIndex = 0;

        if (!$sqlOut) {
            if ($fetch_mode === PDO::FETCH_COLUMN) {
                return new Union([
                    new TString(),
                    new TNull(),
                ]);
            }

            if ($fetch_mode === PDO::FETCH_NUM) {
                return new Union([
                    new TList(new Union([
                            new TString(),
                            new TNull(),
                        ])),
                ]);
            }

            return new Union([
                new Type\Atomic\TArray([
                    new Union([
                        new Type\Atomic\TArrayKey(),
                    ]),
                    new Union([
                        new TString(),
                        new TNull(),
                    ]),
                ]),
            ]);
        }

        foreach ($sqlOut as $key => $columnType) {
            if ($column !== null && $column === $columnIndex) {
                return $columnType;
            }

            switch ($fetch_mode) {
                case PDO::FETCH_ASSOC:
                case PDO::FETCH_OBJ:
                    $properties[$key] = $columnType;
                    break;

                case PDO::FETCH_BOTH:
                    $properties[$key] = $columnType;
                    $properties[$columnIndex] = $columnType;
                    break;

                case PDO::FETCH_NUM:
                    $properties[$columnIndex] = $columnType;
                    break;
            }

            $columnIndex++;
        }

        if (!empty($properties) && $column === null) {
            if ($fetch_mode === PDO::FETCH_OBJ) {
                return new Type\Union([new Type\Atomic\TObjectWithProperties($properties)]);
            }

            return new Type\Union([new Type\Atomic\TKeyedArray($properties)]);
        }

        return null;
    }

    private static function replaceScalarToStringInUnion(Type\Union $union): Type\Union
    {
        $atomicTypes = $union->getAtomicTypes();
        $unionTypes = [];

        foreach ($atomicTypes as $type) {
            $unionTypes[] = self::replaceAtomicToString($type);
        }

        $type = new Type\Union($unionTypes);

        if ($type->equals(Type::getString())) {
            $type->addType(new Type\Atomic\TNull());
        }

        return $type;
    }

    private static function replaceAtomicToString(Type\Atomic $atomic): Type\Atomic
    {
        if ($atomic instanceof Type\Atomic\TScalar) {
            return new Type\Atomic\TString();
        }

        if ($atomic instanceof Type\Atomic\TArray) {
            $atomic->type_params[1] = self::replaceScalarToStringInUnion($atomic->type_params[1]);
        }

        if ($atomic instanceof TList) {
            $atomic->type_param = self::replaceScalarToStringInUnion($atomic->type_param);
        }

        return $atomic;
    }
}
