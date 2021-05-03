<?php

declare(strict_types=1);

namespace M03r\PsalmPDOMySQL\Hooks;

use Exception;
use M03r\PsalmPDOMySQL\Issues\PDOInvalidFetchClass;
use M03r\PsalmPDOMySQL\Issues\SQLParserIssue;
use M03r\PsalmPDOMySQL\Parser\SQLParser;
use M03r\PsalmPDOMySQL\Types\TSqlSelectString;
use PDO;
use PhpMyAdmin\SqlParser\Exceptions\ParserException;
use PhpParser\PrettyPrinter\Standard;
use PhpParser\PrettyPrinterAbstract;
use Psalm\Internal\Provider\ReturnTypeProvider\PdoStatementReturnTypeProvider;
use Psalm\IssueBuffer;
use Psalm\Plugin\EventHandler\AfterEveryFunctionCallAnalysisInterface;
use Psalm\Plugin\EventHandler\AfterFunctionCallAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\AfterEveryFunctionCallAnalysisEvent;
use Psalm\Plugin\EventHandler\Event\AfterFunctionCallAnalysisEvent;
use Psalm\Plugin\EventHandler\Event\MethodReturnTypeProviderEvent;
use Psalm\Plugin\EventHandler\MethodReturnTypeProviderInterface;
use Psalm\Type;
use UnexpectedValueException;

use function class_exists;

class FetchReturnProvider
{
    /** @inheritDoc */
    public static function getClassLikeNames(): array
    {
        return [];
    }

    public static function getMethodReturnType(MethodReturnTypeProviderEvent $event): ?Type\Union
    {
        if (!class_exists('PDO')) {
            return null;
        }

        $methodName = $event->getMethodNameLowercase();
        $source = $event->getSource();
        $location = $event->getCodeLocation();
        $args = $event->getCallArgs();
        $context = $event->getContext();


        $isSuitableMethod =
            $methodName === 'fetch'
            || $methodName === 'fetchall'
            || $methodName === 'fetchcolumn';

        if (!$isSuitableMethod) {
            return null;
        }

        $firstArgRequired = $methodName !== 'fetchcolumn';

        // if first arg is set and it isn't single int literal, we can't do anything
        if (
            isset($args[0]) &&
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

        $sqlOut = null;
        $isAggregating = false;
        $template_type_parameters = $event->getTemplateTypeParameters();

        if (is_array($template_type_parameters)
            && count($template_type_parameters) > 0
            && ($template_type_parameters[0]->isSingleStringLiteral())
            && ($strParam = $template_type_parameters[0]->getSingleStringLiteral())
            && $strParam instanceof TSqlSelectString
        ) {
            try {
                $sqlOut = SQLParser::parseSQL($strParam->value);
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
            && ($fetch_mode = $first_arg_type->getSingleIntLiteral()->value) === PDO::FETCH_CLASS
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
                    new Type\Atomic\TList($return_type),
                ]);

                return $return_type;
            }

            return $return_type;
        }

        if (is_array($sqlOut)) {
            if ($methodName === 'fetchcolumn') {
                $returnType = self::getRowType(
                    PDO::FETCH_COLUMN,
                    $sqlOut,
                    isset($args[0]) ? $first_arg_type->getSingleIntLiteral()->value : 0
                );

                if ($returnType) {
                    if (!$isAggregating) {
                        $returnType->addType(new Type\Atomic\TFalse());
                    }

                    return $returnType;
                }
            } elseif (isset($first_arg_type)) {
                // fetch or fetchall
                $rowType = null;
                $fetch_mode = $first_arg_type->getSingleIntLiteral()->value;

                if ($fetch_mode === PDO::FETCH_ASSOC
                    || $fetch_mode === PDO::FETCH_BOTH
                    || $fetch_mode === PDO::FETCH_NUM
                    || $fetch_mode === PDO::FETCH_OBJ
                ) {
                    $rowType = self::getRowType($fetch_mode, $sqlOut);
                } elseif ($fetch_mode === PDO::FETCH_COLUMN) {
                    $rowType = self::getRowType($fetch_mode, $sqlOut, 0);
                }

                if ($rowType !== null) {
                    switch ($methodName) {
                        case 'fetch':
                            if (!$isAggregating) {
                                $rowType->addType(new Type\Atomic\TFalse());
                            }

                            return $rowType;

                        case 'fetchall':
                            return new Type\Union([
                                $isAggregating ?
                                    new Type\Atomic\TNonEmptyList($rowType) :
                                    new Type\Atomic\TList($rowType),
                            ]);
                    }
                }
            }
        }

        if ($methodName === 'fetchcolumn') {
            return new Type\Union([new Type\Atomic\TString(), new Type\Atomic\TNull(), new Type\Atomic\TFalse()]);
        }

        if ($methodName === 'fetchall'
            && isset($first_arg_type)
            && $first_arg_type->getSingleIntLiteral()->value === PDO::FETCH_COLUMN
        ) {
            return new Type\Union([
                new Type\Atomic\TList(
                    new Type\Union([
                        new Type\Atomic\TString(),
                        new Type\Atomic\TNull(),
                    ]),
                ),
            ]);
        }

        $parentReturnType = PdoStatementReturnTypeProvider::getMethodReturnType(
            new MethodReturnTypeProviderEvent(
                $source,
                $event->getFqClasslikeName(),
                'fetch',
                $event->getCallArgs(),
                $event->getContext(),
                $event->getCodeLocation(),
                $template_type_parameters,
                $event->getCalledFqClasslikeName(),
                $event->getCalledMethodNameLowercase()
            )
        );

        if (!$parentReturnType) {
            return null;
        }

        $originalReturnTransformed = self::replaceScalarToStringInUnion($parentReturnType);

        // fetchAll can't return false as array elements
        if ($methodName === 'fetchall') {
            $originalReturnTransformed->removeType('false');
            $returnType = new Type\Union([
                new Type\Atomic\TList($originalReturnTransformed),
            ]);

            return $returnType;
        }

        return $originalReturnTransformed;
    }

    /**
     * @param array<string, bool> $sqlOut
     */
    private static function getRowType(int $fetch_mode, array $sqlOut, ?int $column = null): ?Type\Union
    {
        $properties = [];
        $columnIndex = 0;

        foreach ($sqlOut as $key => $isNullable) {
            $returnType = [new Type\Atomic\TString()];

            if ($isNullable) {
                $returnType[] = new Type\Atomic\TNull();
            }

            $columnType = new Type\Union($returnType);

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

    protected static function replaceScalarToStringInUnion(Type\Union $union): Type\Union
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

    protected static function replaceAtomicToString(Type\Atomic $atomic): Type\Atomic
    {
        if ($atomic instanceof Type\Atomic\TScalar) {
            return new Type\Atomic\TString();
        }

        if ($atomic instanceof Type\Atomic\TArray) {
            $atomic->type_params[1] = self::replaceScalarToStringInUnion($atomic->type_params[1]);
        }

        if ($atomic instanceof Type\Atomic\TList) {
            $atomic->type_param = self::replaceScalarToStringInUnion($atomic->type_param);
        }

        return $atomic;
    }
}
