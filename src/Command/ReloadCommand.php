<?php

/**
 * @see       https://github.com/mezzio/mezzio-swoole for the canonical source repository
 * @copyright https://github.com/mezzio/mezzio-swoole/blob/master/COPYRIGHT.md
 * @license   https://github.com/mezzio/mezzio-swoole/blob/master/LICENSE.md New BSD License
 */

declare(strict_types=1);

namespace Mezzio\Swoole\Command;

use Mezzio\Swoole\SwooleRequestHandlerRunner;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ReloadCommand extends Command
{
    public const HELP = <<< 'EOH'
Reload the web server. Sends a SIGUSR1 signal to master process and reload
all worker processes.

This command is only relevant when the server was started using the
--daemonize option, and the mezzio-swoole.swoole-http-server.mode
configuration value is set to SWOOLE_PROCESS.
EOH;

    /**
     * @var SwooleRequestHandlerRunner
     */
    private $runner;

    public function __construct(SwooleRequestHandlerRunner $runner, string $name = null)
    {
        $this->runner = $runner;
        parent::__construct($name);
    }

    protected function configure() : void
    {
        $this->setDescription('Reload the web server.');
        $this->setHelp(self::HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output) : int
    {
        if ($this->runner->reloadWorker()) {
            $output->writeln('<info>Server reloaded</info>');
            return 0;
        }

        $output->writeln('<error>Error reloading server; check logs for details</error>');
        return 1;
    }
}
