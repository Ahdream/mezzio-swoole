<?php

/**
 * @see       https://github.com/mezzio/mezzio-swoole for the canonical source repository
 * @copyright https://github.com/mezzio/mezzio-swoole/blob/master/COPYRIGHT.md
 * @license   https://github.com/mezzio/mezzio-swoole/blob/master/LICENSE.md New BSD License
 */

declare(strict_types=1);

namespace MezzioTest\Swoole\Command;

use Mezzio\Swoole\Command\StatusCommand;
use Mezzio\Swoole\PidManager;
use MezzioTest\Swoole\AttributeAssertionTrait;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

use function getmypid;

class StatusCommandTest extends TestCase
{
    use AttributeAssertionTrait;
    use ReflectMethodTrait;

    protected function setUp(): void
    {
        $this->input      = $this->prophesize(InputInterface::class);
        $this->output     = $this->prophesize(OutputInterface::class);
        $this->pidManager = $this->prophesize(PidManager::class);
    }

    public function testConstructorAcceptsPidManager(): StatusCommand
    {
        $command = new StatusCommand($this->pidManager->reveal());
        $this->assertAttributeSame($this->pidManager->reveal(), 'pidManager', $command);
        return $command;
    }

    /**
     * @depends testConstructorAcceptsPidManager
     */
    public function testConstructorSetsDefaultName(StatusCommand $command)
    {
        $this->assertSame('status', $command->getName());
    }

    /**
     * @depends testConstructorAcceptsPidManager
     */
    public function testStatusCommandIsASymfonyConsoleCommand(StatusCommand $command)
    {
        $this->assertInstanceOf(Command::class, $command);
    }

    public function runningProcesses(): iterable
    {
        yield 'base-mode'    => [[getmypid(), null]];
        yield 'process-mode' => [[1000000, getmypid()]];
    }

    /**
     * @dataProvider runningProcesses
     */
    public function testExecuteIndicatesRunningServerWhenServerDetected(array $pids)
    {
        $this->pidManager->read()->willReturn($pids);

        $command = new StatusCommand($this->pidManager->reveal());

        $execute = $this->reflectMethod($command, 'execute');

        $this->assertSame(0, $execute->invoke(
            $command,
            $this->input->reveal(),
            $this->output->reveal()
        ));

        $this->output
            ->writeln(Argument::containingString('Server is running'))
            ->shouldHaveBeenCalled();
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
    public function testExecuteIndicatesNoRunningServerWhenServerNotDetected(array $pids)
    {
        $this->pidManager->read()->willReturn($pids);

        $command = new StatusCommand($this->pidManager->reveal());

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
}
