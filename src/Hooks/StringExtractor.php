<?php

declare(strict_types=1);

namespace M03r\PsalmPDOMySQL\Hooks;

use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use Psalm\NodeTypeProvider;

class StringExtractor extends NodeVisitorAbstract
{
    /** @var string */
    public $result = '';

    /** @var NodeTypeProvider */
    private $typeProvider;

    public function __construct(NodeTypeProvider $typeProvider)
    {
        $this->typeProvider = $typeProvider;
    }

    /**
     * @inheritDoc
     */
    public function enterNode(Node $node)
    {
        if ($node instanceof Node\Scalar\String_
            || $node instanceof Node\Scalar\DNumber
            || $node instanceof Node\Scalar\LNumber
            || $node instanceof Node\Scalar\EncapsedStringPart
        ) {
            $value = (string)$node->value;
            $this->result .= $value;
            return null;
        }

        if ($node instanceof Node\Expr
            || $node instanceof Node\Name
        ) {
            $nodeType = $this->typeProvider->getType($node);

            if ($nodeType && $nodeType->hasLiteralString()) {
                $literalStrings = $nodeType->getLiteralStrings();
                $firstLiteral = reset($literalStrings);
                $this->result .= $firstLiteral->value;
            }
        }

        if (!$node instanceof Node\Expr\BinaryOp\Concat
            && !$node instanceof Node\Scalar\Encapsed
        ) {
            return NodeTraverser::DONT_TRAVERSE_CHILDREN;
        }

        return null;
    }
}
