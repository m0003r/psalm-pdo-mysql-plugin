<?php

// phpcs:disable

declare(strict_types=1);

namespace _psalm_mysql_plugin {
//    use PDO;

    class PDOStatement extends \PDOStatement
    {
        /**
         * @param array|null $input_parameters
         * @psalm-assert state\executed $this
         */
        public function execute(?array $input_parameters = null): void
        {
        }
        /**
         * @psalm-assert state\rowCount $this
         * @psalm-assert-if-false state\zeroRows $this
         */
        public function rowCount(): void
        {
        }
//
//        public function fetchAll($fetch_style = null, $fetch_argument = null, ?array $ctor_args = []): void
//        {
//        }
//
//        public function fetch($fetch_style = null, $cursor_orientation = PDO::FETCH_ORI_NEXT, $cursor_offset = 0): void
//        {
//        }
    }


}

namespace _psalm_mysql_plugin\state {

    interface executed
    {
    }

    interface rowCount
    {

    }

    interface zeroRows
    {

    }

    interface fetched
    {

    }
}
