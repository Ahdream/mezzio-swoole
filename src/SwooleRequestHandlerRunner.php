<?php

/**
 * @see       https://github.com/mezzio/mezzio-swoole for the canonical source repository
 * @copyright https://github.com/mezzio/mezzio-swoole/blob/master/COPYRIGHT.md
 * @license   https://github.com/mezzio/mezzio-swoole/blob/master/LICENSE.md New BSD License
 */

declare(strict_types=1);

namespace Mezzio\Swoole;

use Laminas\HttpHandlerRunner\Emitter\EmitterInterface;
use Laminas\HttpHandlerRunner\RequestHandlerRunner;
use Mezzio\Swoole\Exception;
use PackageVersions\Versions;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Swoole\Http\Request as SwooleHttpRequest;
use Swoole\Http\Response as SwooleHttpResponse;
use Swoole\Http\Server as SwooleHttpServer;
use Swoole\Process as SwooleProcess;
use Symfony\Component\Console\Application as CommandLine;
use Throwable;

use function date;
use function microtime;
use function time;
use function usleep;

/**
 * "Run" a request handler using Swoole.
 *
 * The RequestHandlerRunner will marshal a request using the composed factory, and
 * then pass the request to the composed handler. Finally, it emits the response
 * returned by the handler using the Swoole emitter.
 *
 * If the factory for generating the request raises an exception or throwable,
 * then the runner will use the composed error response generator to generate a
 * response, based on the exception or throwable raised.
 */
class SwooleRequestHandlerRunner extends RequestHandlerRunner
{
    /**
     * @internal
     * @var bool Whether or not to exit from the command; used during unit
     *     testing only.
     */
    public $exitFromCommand = true;

    /**
     * Keep CWD in daemon mode.
     *
     * @var string
     */
    private $cwd;

    /**
     * A request handler to run as the application.
     *
     * @var RequestHandlerInterface
     */
    private $handler;

    /**
     * @var Log\AccessLogInterface
     */
    private $logger;

    /**
     * A manager to handle pid about the application.
     *
     * @var PidManager
     */
    private $pidManager;

    /**
     * Factory for creating an HTTP server instance.
     *
     * @var ServerFactory
     */
    private $serverFactory;

    /**
     * A factory capable of generating an error response in the scenario that
     * the $serverRequestFactory raises an exception during generation of the
     * request instance.
     *
     * The factory will receive the Throwable or Exception that caused the error,
     * and must return a Psr\Http\Message\ResponseInterface instance.
     *
     * @var callable
     */
    private $serverRequestErrorResponseGenerator;

    /**
     * A factory capable of generating a Psr\Http\Message\ServerRequestInterface instance.
     * The factory will not receive any arguments.
     *
     * @var callable
     */
    private $serverRequestFactory;

    /**
     * @var ?StaticResourceHandlerInterface
     */
    private $staticResourceHandler;

    public function __construct(
        RequestHandlerInterface $handler,
        callable $serverRequestFactory,
        callable $serverRequestErrorResponseGenerator,
        PidManager $pidManager,
        ServerFactory $serverFactory,
        StaticResourceHandlerInterface $staticResourceHandler = null,
        Log\AccessLogInterface $logger = null
    ) {
        $this->handler = $handler;

        // Factories are cast as Closures to ensure return type safety.
        $this->serverRequestFactory = function ($request) use ($serverRequestFactory) : ServerRequestInterface {
            return $serverRequestFactory($request);
        };

        $this->serverRequestErrorResponseGenerator =
            function (Throwable $exception) use ($serverRequestErrorResponseGenerator) : ResponseInterface {
                return $serverRequestErrorResponseGenerator($exception);
            };

        $this->serverFactory = $serverFactory;
        $this->pidManager = $pidManager;
        $this->staticResourceHandler = $staticResourceHandler;
        $this->logger = $logger ?: new Log\Psr3AccessLogDecorator(
            new Log\StdoutLogger(),
            new Log\AccessLogFormatter()
        );
        $this->cwd = getcwd();
    }

    /**
     * Run the application
     *
     * Determines which action was requested from the command line, and then
     * executes the task associated with it. If no action was provided, it
     * assumes "start".
     */
    public function run() : void
    {
        $version = strstr(Versions::getVersion('mezzio/mezzio-swoole'), '@', true);
        $commandLine = new CommandLine('Mezzio web server', $version);
        $commandLine->setAutoExit($this->exitFromCommand);
        $commandLine->add(new Command\StartCommand($this, 'start'));
        $commandLine->add(new Command\StopCommand($this, 'stop'));
        $commandLine->add(new Command\ReloadCommand($this, 'reload'));
        $commandLine->run();
    }

    /**
     * Start the swoole HTTP server
     *
     * @param array $options Swoole server options
     */
    public function startServer(array $options = []) : void
    {
        $swooleServer = $this->serverFactory->createSwooleServer($options);
        $swooleServer->on('start', [$this, 'onStart']);
        $swooleServer->on('workerstart', [$this, 'onWorkerStart']);
        $swooleServer->on('request', [$this, 'onRequest']);
        $swooleServer->start();
    }

    /**
     * Stop the swoole HTTP server
     *
     * @return bool Return value indicates whether or not the server was stopped.
     */
    public function stopServer() : bool
    {
        if (! $this->isRunning()) {
            $this->logger->notice('Server is not running yet');
            return false;
        }

        $this->logger->notice('Server stopping ...');

        [$masterPid, ] = $this->pidManager->read();
        $startTime = time();
        $result = SwooleProcess::kill((int) $masterPid);

        while (! $result) {
            if (! SwooleProcess::kill((int) $masterPid, 0)) {
                continue;
            }
            if (time() - $startTime >= 60) {
                $result = false;
                break;
            }
            usleep(10000);
        }

        if (! $result) {
            $this->logger->error('Server stop failure');
            return false;
        }

        $this->pidManager->delete();
        $this->logger->notice('Server stopped');

        return true;
    }

    /**
     * Reload all workers
     *
     * Please note: the reload worker action can ONLY run when operating in
     * SWOOLE_PROCESS mode.
     *
     * @return bool Return value indicates whether or not the server was
     *     reloaded.
     */
    public function reloadWorker() : bool
    {
        if (! $this->isRunning()) {
            $this->logger->notice('Server is not running yet');
            return false;
        }

        $this->logger->notice('Worker reloading ...');

        [$masterPid, ] = $this->pidManager->read();
        $result = SwooleProcess::kill((int) $masterPid, SIGUSR1);

        if (! $result) {
            $this->logger->error('Worker reload failure');
            return false;
        }

        $this->logger->notice('Worker reloaded');
        return true;
    }

    /**
     * Is the swoole HTTP server running?
     */
    public function isRunning() : bool
    {
        [$masterPid, $managerPid] = $this->pidManager->read();
        if ($managerPid) {
            // Swoole process mode
            return $masterPid && $managerPid && SwooleProcess::kill((int) $managerPid, 0);
        }
        // Swoole base mode, no manager process
        return $masterPid && SwooleProcess::kill((int) $masterPid, 0);
    }

    /**
     * Handle a start event for swoole HTTP server manager process.
     *
     * Writes the master and manager PID values to the PidManager, and ensures
     * the manager and/or workers use the same PWD as the master process.
     */
    public function onStart(SwooleHttpServer $server) : void
    {
        $this->pidManager->write($server->master_pid, $server->manager_pid);

        // Reset CWD
        chdir($this->cwd);

        $this->logger->notice('Swoole is running at {host}:{port}, in {cwd}', [
            'host' => $server->host,
            'port' => $server->port,
            'cwd'  => getcwd(),
        ]);
    }

    /**
     * Handle a workerstart event for swoole HTTP server worker process
     *
     * Ensures workers all use the same PWD as the master process.
     */
    public function onWorkerStart(SwooleHttpServer $server, int $workerId) : void
    {
        // Reset CWD
        chdir($this->cwd);

        $this->logger->notice('Worker started in {cwd} with ID {pid}', [
            'cwd' => getcwd(),
            'pid' => $workerId,
        ]);
    }

    /**
     * Handle an incoming HTTP request
     */
    public function onRequest(
        SwooleHttpRequest $request,
        SwooleHttpResponse $response
    ) : void {
        $staticResourceResponse = $this->staticResourceHandler
            ? $this->staticResourceHandler->processStaticResource($request, $response)
            : null;
        if ($staticResourceResponse) {
            // Eventually: emit a request log here
            $this->logger->logAccessForStaticResource($request, $staticResourceResponse);
            return;
        }

        $emitter = new SwooleEmitter($response);

        try {
            $psr7Request = ($this->serverRequestFactory)($request);
        } catch (Throwable $e) {
            // Error in generating the request
            $this->emitMarshalServerRequestException($emitter, $e);
            return;
        }

        $psr7Response = $this->handler->handle($psr7Request);
        $emitter->emit($psr7Response);
        $this->logger->logAccessForPsr7Resource($request, $psr7Response);
    }

    /**
     * Emit marshal server request exception
     */
    private function emitMarshalServerRequestException(
        EmitterInterface $emitter,
        Throwable $exception,
        SwooleHttpRequest $request
    ) : void {
        $psr7Response = ($this->serverRequestErrorResponseGenerator)($exception);
        $emitter->emit($psr7Response);
        $this->logger->logAccessForPsr7Resource($request, $psr7Response);
    }
}
