<?php

declare(strict_types=1);

namespace M03r\PsalmPDOMySQL;

use M03r\PsalmPDOMySQL\Hooks\FetchChecker;
use M03r\PsalmPDOMySQL\Hooks\PDOMethodsReturnType;
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

        class_exists(PDOMethodsReturnType::class, true);
        class_exists(FetchChecker::class, true);

        if ($config->PDOClass instanceof SimpleXMLElement) {
            foreach ($config->PDOClass as $item) {
                PDOMethodsReturnType::$additionalPDOClasses[] = (string)$item;
            }
        }

        $registration->addStubFile(__DIR__ . '/Stubs/PDOStatement.php');

        $registration->registerHooksFromClass(PDOMethodsReturnType::class);
        $registration->registerHooksFromClass(FetchChecker::class);
    }
}
