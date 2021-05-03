<?php

declare(strict_types=1);

namespace M03r\PsalmPDOMySQL;

use M03r\PsalmPDOMySQL\Hooks\FetchChecker;
use M03r\PsalmPDOMySQL\Hooks\PDOMethodsReturnType;
use M03r\PsalmPDOMySQL\Hooks\FetchReturnProvider;
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

        $registration->addStubFile(__DIR__ . '/Stubs/PDOStatement.php');

        class_exists(PDOMethodsReturnType::class, true);
        class_exists(FetchReturnProvider::class, true);
        class_exists(FetchChecker::class, true);

        $registration->registerHooksFromClass(PDOMethodsReturnType::class);
        $registration->registerHooksFromClass(FetchReturnProvider::class);
        $registration->registerHooksFromClass(FetchChecker::class);
    }
}
