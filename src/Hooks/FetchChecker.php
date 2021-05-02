<?php

declare(strict_types=1);

namespace M03r\PsalmPDOMySQL\Hooks;

use M03r\PsalmPDOMySQL\Issues\PDOStatementNotExecuted;
use M03r\PsalmPDOMySQL\Issues\PDOStatementZeroRows;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use Psalm\Codebase;
use Psalm\CodeLocation;
use Psalm\Context;
use Psalm\FileManipulation;
use Psalm\Internal\Analyzer\Statements\Expression\ExpressionIdentifier;
use Psalm\IssueBuffer;
use Psalm\Plugin\Hook\AfterMethodCallAnalysisInterface;
use Psalm\StatementsSource;
use Psalm\Type;

class FetchChecker implements AfterMethodCallAnalysisInterface
{
    private const STATE_PREFIX = '_psalm_mysql_plugin\\state\\';

    /**
     * @param MethodCall|StaticCall $expr
     * @param FileManipulation[] $file_replacements
     */
    public static function afterMethodCallAnalysis(
        Expr $expr,
        string $method_id,
        string $appearing_method_id,
        string $declaring_method_id,
        Context $context,
        StatementsSource $statements_source,
        Codebase $codebase,
        array &$file_replacements = [],
        ?Type\Union &$return_type_candidate = null
    ): void {
        if (!$expr instanceof MethodCall) {
            return;
        }

        if ($method_id === '_psalm_mysql_plugin\\PDOStatement::fetchall'
            && $return_type_candidate
            && $return_type_candidate->hasArray()
        ) {
            $return_type_candidate->removeType('false');
        }

        $var_id = ExpressionIdentifier::getVarId($expr->var, null, $statements_source);
        $sourceType = $context->vars_in_scope[$var_id] ?? null;

        if (!$sourceType) {
            // still no source type :(
            return;
        }

        /** @var array<string, bool>|null $pdoStatementTypes */
        $pdoStatementTypes = null;

        foreach ($sourceType->getAtomicTypes() as $atomic) {
            if (!$atomic instanceof Type\Atomic\TNamedObject) {
                continue;
            }

            if (strpos($atomic->getKey(false), '_psalm_mysql_plugin\\PDOStatement') === 0) {
                $pdoStatementTypes = [
                    'executed' => false,
                    'rowCount' => false,
                    'zeroRows' => false,
                    'fetched'  => false,
                ];

                foreach ($atomic->extra_types ?? [] as $extraType) {
                    $id = $extraType->getKey(false);

                    if (strpos($id, self::STATE_PREFIX) === 0) {
                        $pdoStatementTypes[substr($id, 26)] = true;
                    }
                }

                break;
            }
        }

        if ($pdoStatementTypes === null) {
            return;
        }

        if (strpos($method_id, "_psalm_mysql_plugin\\PDOStatement::execute") === 0) {
            foreach ($sourceType->getAtomicTypes() as $atomic) {
                if (!$atomic instanceof Type\Atomic\TNamedObject) {
                    continue;
                }

                if ($atomic->extra_types) {
                    unset(
                        $atomic->extra_types[self::STATE_PREFIX . 'zeroRows'],
                        $atomic->extra_types[self::STATE_PREFIX . 'rowCount'],
                        $atomic->extra_types[self::STATE_PREFIX . 'fetched']
                    );
                }

                $atomic->addIntersectionType(
                    new Type\Atomic\TNamedObject('_psalm_mysql_plugin\\state\\executed')
                );
            }

            return;
        }

        if (strpos($method_id, "_psalm_mysql_plugin\\PDOStatement::fetch") === 0) {
            if (!$pdoStatementTypes['executed']) {
                IssueBuffer::accepts(
                    new PDOStatementNotExecuted(
                        'Statement was not executed',
                        new CodeLocation($statements_source, $expr)
                    ),
                    $statements_source->getSuppressedIssues()
                );
            }

            if ($pdoStatementTypes['zeroRows']) {
                IssueBuffer::accepts(
                    new PDOStatementZeroRows(
                        'PDO statement has zero remaining rows',
                        new CodeLocation($statements_source, $expr)
                    ),
                    $statements_source->getSuppressedIssues()
                );
            }

            if ($pdoStatementTypes['rowCount']
                && !$pdoStatementTypes['zeroRows']
            ) {
                if ($return_type_candidate) {
                    $return_type_candidate->removeType('false');
                }

                if ($method_id === '_psalm_mysql_plugin\\PDOStatement::fetchall'
                    && $return_type_candidate
                    && $return_type_candidate->hasArray()
                    && ($list_type = $return_type_candidate->getAtomicTypes()['array']) instanceof Type\Atomic\TList
                ) {
                    $return_type_candidate = new Type\Union([new Type\Atomic\TNonEmptyList($list_type->type_param)]);
                }
            }

            if ($method_id === "_psalm_mysql_plugin\\PDOStatement::fetchall") {
                foreach ($sourceType->getAtomicTypes() as $atomic) {
                    if ($atomic instanceof Type\Atomic\TNamedObject) {
                        $atomic->addIntersectionType(
                            new Type\Atomic\TNamedObject('_psalm_mysql_plugin\\state\\zeroRows')
                        );
                    }
                }
            } else {
                foreach ($sourceType->getAtomicTypes() as $atomic) {
                    if ($atomic instanceof Type\Atomic\TNamedObject) {
                        $atomic->addIntersectionType(
                            new Type\Atomic\TNamedObject('_psalm_mysql_plugin\\state\\fetched')
                        );
                    }
                }
            }
        }
    }
}
