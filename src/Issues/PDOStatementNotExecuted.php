<?php

declare(strict_types=1);

namespace M03r\PsalmPDOMySQL\Issues;

use Psalm\Issue\PluginIssue;

class PDOStatementNotExecuted extends PluginIssue
{
    public const ERROR_LEVEL = 3;
}
