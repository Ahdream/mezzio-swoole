<?php

/**
 * @see       https://github.com/mezzio/mezzio-swoole for the canonical source repository
 * @copyright https://github.com/mezzio/mezzio-swoole/blob/master/COPYRIGHT.md
 * @license   https://github.com/mezzio/mezzio-swoole/blob/master/LICENSE.md New BSD License
 */

declare(strict_types=1);

namespace Mezzio\Swoole\HotCodeReload\FileWatcher;

use Mezzio\Swoole\Exception\ExtensionNotLoadedException;
use Mezzio\Swoole\Exception\RuntimeException;
use Mezzio\Swoole\HotCodeReload\FileWatcherInterface;

use function array_values;
use function extension_loaded;
use function inotify_add_watch;
use function inotify_init;
use function inotify_read;
use function is_array;
use function stream_set_blocking;

class InotifyFileWatcher implements FileWatcherInterface
{
    /** @var resource */
    private $inotify;

    /** @var string[] */
    private $filePathByWd = [];

    public function __construct()
    {
        if (! extension_loaded('inotify')) {
            throw new ExtensionNotLoadedException('PHP extension "inotify" is required for this file watcher');
        }
        $resource = inotify_init();
        if (false === $resource) {
            throw new RuntimeException('Unable to initialize an inotify instance');
        }
        if (! stream_set_blocking($resource, false)) {
            throw new RuntimeException('Unable to set non-blocking mode on inotify stream');
        }

        $this->inotify = $resource;
    }

    /**
     * Add a file path to be monitored for changes by this watcher.
     */
    public function addFilePath(string $path) : void
    {
        $wd = inotify_add_watch($this->inotify, $path, IN_MODIFY);
        $this->filePathByWd[$wd] = $path;
    }

    public function readChangedFilePaths() : array
    {
        $events = inotify_read($this->inotify);
        $paths = [];
        if (is_array($events)) {
            foreach ($events as $event) {
                $wd = $event['wd'] ?? null;
                if (null === $wd) {
                    throw new RuntimeException('Missing watch descriptor from inotify event');
                }
                $path = $this->filePathByWd[$wd] ?? null;
                if (null === $path) {
                    throw new RuntimeException("Unrecognized watch descriptor: \"{$wd}\"");
                }
                $paths[$path] = $path;
            }
        }

        return array_values($paths);
    }
}
