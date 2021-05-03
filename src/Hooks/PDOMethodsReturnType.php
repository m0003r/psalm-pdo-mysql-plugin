<?php

declare(strict_types=1);

namespace M03r\PsalmPDOMySQL\Hooks;

use M03r\PsalmPDOMySQL\Types\SQLStringProvider;
use M03r\PsalmPDOMySQL\Types\TPDOStatement;
use M03r\PsalmPDOMySQL\Types\TSqlSelectString;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use Psalm\Plugin\EventHandler\Event\MethodReturnTypeProviderEvent;
use Psalm\Plugin\EventHandler\MethodReturnTypeProviderInterface;
use Psalm\Type;

use function class_exists;

class PDOMethodsReturnType implements MethodReturnTypeProviderInterface
{
    /** @inheritDoc */
    public static function getClassLikeNames(): array
    {
        return ['PDO'];
    }

    /** @inheritDoc */
    public static function getMethodReturnType(MethodReturnTypeProviderEvent $event): ?Type\Union
    {
        $methodName = $event->getMethodNameLowercase();
        $args = $event->getCallArgs();
        $source = $event->getSource();

        if (!class_exists('PDO')
            || ($methodName !== 'query'
                && $methodName !== 'prepare')
        ) {
            return null;
        }

        $literal = null;

        $argValue = $args[0]->value;
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

        $namedObject = new TPDOStatement('PDOStatement');

        if ($methodName === 'query') {
            $namedObject->executed = true;
        }

        if ($literal instanceof TSqlSelectString) {
            $namedObject->sqlString = $literal;
        }

        $literal->partial = $partial;

        return new Type\Union([$namedObject]);
    }
}
