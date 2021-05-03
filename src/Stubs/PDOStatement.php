<?php


class PDOStatement
{
    /** @var bool */
    public $__psalm_rowCountZero;

    public $__psalm_rowCountFetched = false;

    /**
     * @psalm-assert-if-false true $this->__psalm_rowCountZero
     * @psalm-assert true $this->__psalm_rowCountFetched
     */
    public function rowCount() {}

//    /**
//     * @psalm-assert true|false $this->__psalm_rowCountZero
//     * @psalm-assert false $this->__psalm_rowCountFetched
//     */
//    public function execute() {}
}