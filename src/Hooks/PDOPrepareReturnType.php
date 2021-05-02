<?php

declare(strict_types=1);

namespace M03r\PsalmPDOMySQL\Hooks;

use M03r\PsalmPDOMySQL\Types\TSqlSelectString;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use Psalm\CodeLocation;
use Psalm\Context;
use Psalm\Plugin\Hook\MethodReturnTypeProviderInterface;
use Psalm\StatementsSource;
use Psalm\Type;

use function class_exists;

class PDOPrepareReturnType implements MethodReturnTypeProviderInterface
{
    /** @inheritDoc */
    public static function getMethodReturnType(
        StatementsSource $source,
        string $fq_classlike_name,
        string $method_name_lowercase,
        array $call_args,
        Context $context,
        CodeLocation $code_location,
        ?array $template_type_parameters = null,
        ?string $called_fq_classlike_name = null,
        ?string $called_method_name_lowercase = null
    ): ?Type\Union {
        if (!class_exists('PDO')
            || ($method_name_lowercase !== 'query'
                && $method_name_lowercase !== 'prepare')
        ) {
            return null;
        }

        $literal = null;

        $argValue = $call_args[0]->value;
        $first_arg_type = $source->getNodeTypeProvider()->getType($argValue);

        if ($first_arg_type && $first_arg_type->hasLiteralString()) {
            $literalStrings = $first_arg_type->getLiteralStrings();
            $literal = SQLStringProvider::getTypeFromValue(reset($literalStrings)->value);
            $partial = false;
        } elseif ($argValue instanceof Node\Expr\BinaryOp\Concat
            || $argValue instanceof Node\Scalar\Encapsed
        ) {
            $stringExtractor = new StringExtractor($source->getNodeTypeProvider());

            $traverser = new NodeTraverser();
            $traverser->addVisitor($stringExtractor);
            $traverser->traverse([$argValue]);

            $literal = SQLStringProvider::getTypeFromValue($stringExtractor->result);
            $partial = true;
        }

        if (!($literal instanceof TSqlSelectString)) {
            $namedObject = new Type\Atomic\TNamedObject('_psalm_mysql_plugin\\PDOStatement');

            if ($method_name_lowercase === 'query') {
                $namedObject->addIntersectionType(
                    new Type\Atomic\TNamedObject('_psalm_mysql_plugin\\state\\executed')
                );
            }

            return new Type\Union([$namedObject]);
        }

        $literal->partial = $partial;
        $generic = new Type\Atomic\TGenericObject(
            '_psalm_mysql_plugin\\PDOStatement',
            [new Type\Union([$literal])]
        );

        if ($method_name_lowercase === 'query') {
            $generic->addIntersectionType(
                new Type\Atomic\TNamedObject('_psalm_mysql_plugin\\state\\executed')
            );
        }

        return new Type\Union([$generic]);
    }

    /** @inheritDoc */
    public static function getClassLikeNames(): array
    {
        return ['PDO'];
    }
}
