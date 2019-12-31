<?php

/**
 * @see       https://github.com/mezzio/mezzio-swoole for the canonical source repository
 * @copyright https://github.com/mezzio/mezzio-swoole/blob/master/COPYRIGHT.md
 * @license   https://github.com/mezzio/mezzio-swoole/blob/master/LICENSE.md New BSD License
 */

declare(strict_types=1);

namespace Mezzio\Swoole;

use Swoole\Http\Server as SwooleHttpServer;
use Swoole\Runtime as SwooleRuntime;

use function array_replace;
use function in_array;
use function method_exists;

use const SWOOLE_BASE;
use const SWOOLE_PROCESS;
use const SWOOLE_SOCK_TCP;
use const SWOOLE_SOCK_TCP6;
use const SWOOLE_SOCK_UDP;
use const SWOOLE_SOCK_UDP6;
use const SWOOLE_UNIX_DGRAM;
use const SWOOLE_UNIX_STREAM;

class ServerFactory
{
    /**
     * Swoole server supported modes
     */
    const MODES = [
        SWOOLE_BASE,
        SWOOLE_PROCESS
    ];

    /**
     * Swoole server supported protocols
     */
    const PROTOCOLS = [
        SWOOLE_SOCK_TCP,
        SWOOLE_SOCK_TCP6,
        SWOOLE_SOCK_UDP,
        SWOOLE_SOCK_UDP6,
        SWOOLE_UNIX_DGRAM,
        SWOOLE_UNIX_STREAM
    ];

    /**
     * Enable coroutines within the Swoole HTTP server.
     *
     * ONLY available for swoole 4.1.0 or later version.
     *
     * When running in coroutine mode, PDO/Mysqli (when Swoole is compiled with
     * --enable-mysqlnd), Redis, SOAP, file_get_contents, fopen(ONLY TCP, FTP,
     * HTTP protocol), stream_socket_client, and the fsockopen functions will
     * automatically switch to a non-blocking, async I/O driver. Avoid blocking
     * I/O when enabling coroutines.
     *
     * @var bool
     */
    private $enableCoroutine = false;

    /**
     * @var string
     */
    private $host;

    /**
     * @var int
     */
    private $port;

    /**
     * @var int
     */
    private $mode;

    /**
     * @var int
     */
    private $protocol;

    /**
     * @var array
     */
    private $options = [];

    /**
     * @var SwooleHttpServer
     */
    private $swooleServer;

    /**
     * @see https://www.swoole.co.uk/docs/modules/swoole-server-methods#swoole_server-__construct
     * @see https://www.swoole.co.uk/docs/modules/swoole-server/predefined-constants for $mode and $protocol constant
     * @throws Exception\InvalidArgumentException for invalid $port values
     * @throws Exception\InvalidArgumentException for invalid $mode values
     * @throws Exception\InvalidArgumentException for invalid $protocol values
     */
    public function __construct(string $host, int $port, int $mode, int $protocol, array $options = [])
    {
        if ($port < 1 || $port > 65535) {
            throw new Exception\InvalidArgumentException('Invalid port');
        }

        if (! in_array($mode, static::MODES, true)) {
            throw new Exception\InvalidArgumentException('Invalid server mode');
        }

        if (! in_array($protocol, static::PROTOCOLS, true)) {
            throw new Exception\InvalidArgumentException('Invalid server protocol');
        }

        $this->host = $host;
        $this->port = $port;
        $this->mode = $mode;
        $this->protocol = $protocol;
        $this->options = $options;

        // If provided, and Swoole 4.1.0 or later is in use, this flag can be
        // used to enable coroutines for most I/O operations.
        $this->enableCoroutine = $options['enable_coroutine'] ?? false;
    }

    /**
     * Create a swoole server instance
     *
     * @see https://www.swoole.co.uk/docs/modules/swoole-server-methods#swoole_server-set for server options
     */
    public function createSwooleServer(array $appendOptions = []): SwooleHttpServer
    {
        if ($this->swooleServer) {
            return $this->swooleServer;
        }

        if ($this->enableCoroutine && method_exists(SwooleRuntime::class, 'enableCoroutine')) {
            SwooleRuntime::enableCoroutine(true);
        }

        $this->swooleServer = new SwooleHttpServer($this->host, $this->port, $this->mode, $this->protocol);

        $options = array_replace($this->options, $appendOptions);
        if ([] !== $options) {
            $this->swooleServer->set($options);
        }

        return $this->swooleServer;
    }
}
