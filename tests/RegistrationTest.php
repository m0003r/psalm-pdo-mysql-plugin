<?php

declare(strict_types=1);

namespace M03r\PsalmPDOMySQL\Test;

use M03r\PsalmPDOMySQL\Plugin;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Psalm\Plugin\RegistrationInterface;
use SimpleXMLElement;
use UnexpectedValueException;

/**
 * @covers \M03r\PsalmPDOMySQL\Plugin
 */
class RegistrationTest extends TestCase
{
    use ProphecyTrait;

    public function testItShouldntRegisterWithoutConfig(): void
    {
        $plugin = new Plugin();
        $registration = $this->prophesize(RegistrationInterface::class);
        $registration->addStubFile()->shouldNotBeCalled();
        $registration->registerHooksFromClass()->shouldNotBeCalled();

        $plugin($registration->reveal());
    }

    public function testItShouldntRegisterWithBadConfig(): void
    {
        $plugin = new Plugin();
        $registration = $this->prophesize(RegistrationInterface::class);
        $registration->addStubFile()->shouldNotBeCalled();
        $registration->registerHooksFromClass()->shouldNotBeCalled();

        /** @var SimpleXMLElement $config */
        $config = (new SimpleXMLElement('<config><foo /><bar /></config>'))->config;
        $plugin($registration->reveal(), $config);
    }

    public function testItShouldRegisterWithoutDatabases(): void
    {
        $plugin = new Plugin();
        $registration = $this->prophesize(RegistrationInterface::class);
        $registration->addStubFile()->shouldNotBeCalled();
        $registration->registerHooksFromClass()->shouldNotBeCalled();

        $this->expectException(UnexpectedValueException::class);
        $plugin($registration->reveal(), (
            new SimpleXMLElement(
                '<config><databases /></config>'
            )));
    }
    public function testItShouldRegisterWithProperConfig(): void
    {
        $plugin = new Plugin();
        $registration = $this->prophesize(RegistrationInterface::class);
        $registration->addStubFile(Argument::any())->shouldBeCalled();
        $registration->registerHooksFromClass(Argument::any())->shouldBeCalled();

        $plugin($registration->reveal(), (
            new SimpleXMLElement(
                '<config><databases><database name="empty" /></databases></config>'
            )));
    }
}
