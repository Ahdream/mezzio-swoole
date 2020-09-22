<?php

/**
 * @see       https://github.com/mezzio/mezzio-swoole for the canonical source repository
 * @copyright https://github.com/mezzio/mezzio-swoole/blob/master/COPYRIGHT.md
 * @license   https://github.com/mezzio/mezzio-swoole/blob/master/LICENSE.md New BSD License
 */

declare(strict_types=1);

namespace MezzioTest\Swoole\Command;

use Mezzio\Swoole\Command\StopCommand;
use Mezzio\Swoole\PidManager;
use MezzioTest\Swoole\AttributeAssertionTrait;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

use function getmypid;

class StopCommandTest extends TestCase
{
    use AttributeAssertionTrait;
    use ReflectMethodTrait;

    protected function setUp(): void
    {
        $this->input      = $this->prophesize(InputInterface::class);
        $this->output     = $this->prophesize(OutputInterface::class);
        $this->pidManager = $this->prophesize(PidManager::class);
    }

    public function testConstructorAcceptsPidManager(): StopCommand
    {
        $command = new StopCommand($this->pidManager->reveal());
        $this->assertAttributeSame($this->pidManager->reveal(), 'pidManager', $command);
        return $command;
    }

    /**
     * @depends testConstructorAcceptsPidManager
     */
    public function testConstructorSetsDefaultName(StopCommand $command)
    {
        $this->assertSame('stop', $command->getName());
    }

    /**
     * @depends testConstructorAcceptsPidManager
     */
    public function testStopCommandIsASymfonyConsoleCommand(StopCommand $command)
    {
        $this->assertInstanceOf(Command::class, $command);
    }

    public function noRunningProcesses(): iterable
    {
        yield 'empty'        => [[]];
        yield 'null-all'     => [[null, null]];
        yield 'base-mode'    => [[1000000, null]];
        yield 'process-mode' => [[1000000, 1000001]];
    }

    /**
     * @dataProvider noRunningProcesses
     */
    public function testExecuteReturnsSuccessWhenServerIsNotCurrentlyRunning(array $pids)
    {
        $this->pidManager->read()->willReturn($pids);

        $command = new StopCommand($this->pidManager->reveal());

        $execute = $this->reflectMethod($command, 'execute');

        $this->assertSame(0, $execute->invoke(
            $command,
            $this->input->reveal(),
            $this->output->reveal()
        ));

        $this->output
            ->writeln(Argument::containingString('Server is not running'))
            ->shouldHaveBeenCalled();
    }

    public function runningProcesses(): iterable
    {
        yield 'base-mode'    => [[getmypid(), null]];
        yield 'process-mode' => [[1000000, getmypid()]];
    }

    /**
     * @dataProvider runningProcesses
     */
    public function testExecuteReturnsErrorIfUnableToStopServer(array $pids)
    {
        $this->pidManager->read()->willReturn($pids);

        $masterPid   = $pids[0];
        $spy         = (object) ['called' => false];
        $killProcess = static function (int $pid, ?int $signal = null) use ($masterPid, $spy) {
            TestCase::assertSame($masterPid, $pid);
            $spy->called = true;
            return $signal === 0;
        };

        $command                = new StopCommand($this->pidManager->reveal());
        $command->killProcess   = $killProcess;
        $command->waitThreshold = 1;

        $execute = $this->reflectMethod($command, 'execute');

        $this->assertSame(1, $execute->invoke(
            $command,
            $this->input->reveal(),
            $this->output->reveal()
        ));

        $this->assertTrue($spy->called);

        $this->pidManager->delete()->shouldNotHaveBeenCalled();

        $this->output
            ->writeln(Argument::containingString('Stopping server'))
            ->shouldHaveBeenCalled();

        $this->output
            ->writeln(Argument::containingString('Error stopping server'))
            ->shouldHaveBeenCalled();

        $this->output
            ->writeln(Argument::containingString('Server stopped'))
            ->shouldNotHaveBeenCalled();
    }

    /**
     * @dataProvider runningProcesses
     */
    public function testExecuteReturnsSuccessIfAbleToStopServer(array $pids)
    {
        $this->pidManager->read()->willReturn($pids);
        $this->pidManager->delete()->shouldBeCalled();

        $masterPid   = $pids[0];
        $spy         = (object) ['called' => false];
        $killProcess = static function (int $pid) use ($masterPid, $spy) {
            TestCase::assertSame($masterPid, $pid);
            $spy->called = true;
            return true;
        };

        $command              = new StopCommand($this->pidManager->reveal());
        $command->killProcess = $killProcess;

        $execute = $this->reflectMethod($command, 'execute');

        $this->assertSame(0, $execute->invoke(
            $command,
            $this->input->reveal(),
            $this->output->reveal()
        ));

        $this->assertTrue($spy->called);

        $this->output
            ->writeln(Argument::containingString('Stopping server'))
            ->shouldHaveBeenCalled();

        $this->output
            ->writeln(Argument::containingString('Error stopping server'))
            ->shouldNotHaveBeenCalled();

        $this->output
            ->writeln(Argument::containingString('Server stopped'))
            ->shouldHaveBeenCalled();
    }
}
