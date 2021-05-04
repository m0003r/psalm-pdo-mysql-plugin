<?php

declare(strict_types=1);

// phpcs:disable PSR2.Classes.PropertyDeclaration.Underscore
// phpcs:disable PSR1.Classes.ClassDeclaration.MissingNamespace
class PDOStatement
{
    /** @var bool */
    public $__psalm_rowCountZero;

    public $__psalm_rowCountFetched = false;

    /**
     * @psalm-assert-if-false true $this->__psalm_rowCountZero
     * @psalm-assert true $this->__psalm_rowCountFetched
     */
    public function rowCount(): int
    {
    }

//    /**
//     * @psalm-assert true|false $this->__psalm_rowCountZero
//     * @psalm-assert false $this->__psalm_rowCountFetched
//     */
//    public function execute() {}
}
