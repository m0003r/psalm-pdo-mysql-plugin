<?php

declare(strict_types=1);

namespace M03r\PsalmPDOMySQL;

use M03r\PsalmPDOMySQL\Hooks\FetchChecker;
use M03r\PsalmPDOMySQL\Hooks\PDOPrepareReturnType;
use M03r\PsalmPDOMySQL\Hooks\TypedPDOStatementFetchReturn;
use Psalm\Plugin\PluginEntryPointInterface;
use Psalm\Plugin\RegistrationInterface;
use SimpleXMLElement;

class Plugin implements PluginEntryPointInterface
{

    public function __invoke(RegistrationInterface $registration, ?SimpleXMLElement $config = null): void
    {
        if (!$config || !($config->databases instanceof SimpleXMLElement)) {
            return;
        }

        Parser\SQLParser::loadDatabaseDescription($config->databases);

        $registration->addStubFile(__DIR__ . '/Stubs/TypedPDOStatement.php');


        class_exists(PDOPrepareReturnType::class, true);
        class_exists(TypedPDOStatementFetchReturn::class, true);
        class_exists(FetchChecker::class, true);

        $registration->registerHooksFromClass(PDOPrepareReturnType::class);
        $registration->registerHooksFromClass(TypedPDOStatementFetchReturn::class);
        $registration->registerHooksFromClass(FetchChecker::class);
    }
}
