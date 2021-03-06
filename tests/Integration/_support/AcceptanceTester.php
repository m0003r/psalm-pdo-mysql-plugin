<?php

declare(strict_types=1);

namespace M03r\PsalmPDOMySQL\Test\Integration;

use Codeception\Actor;

/**
 * Inherited Methods
 *
 * @method void wantToTest($text)
 * @method void wantTo($text)
 * @method $this execute($callable)
 * @method void expectTo($prediction)
 * @method void expect($prediction)
 * @method void amGoingTo($argumentation)
 * @method void am($role)
 * @method void lookForwardTo($achieveValue)
 * @method void comment($description)
 * @method void pause()
 * @SuppressWarnings(PHPMD)
 * @psalm-suppress UnusedClass
 */
final class AcceptanceTester extends Actor
{
    use _generated\AcceptanceTesterActions;
}
