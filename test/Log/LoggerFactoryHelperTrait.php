<?php

/**
 * @see       https://github.com/mezzio/mezzio-swoole for the canonical source repository
 * @copyright https://github.com/mezzio/mezzio-swoole/blob/master/COPYRIGHT.md
 * @license   https://github.com/mezzio/mezzio-swoole/blob/master/LICENSE.md New BSD License
 */

declare(strict_types=1);

namespace MezzioTest\Swoole\Log;

use Mezzio\Swoole\Log\AccessLogFormatterInterface;
use Mezzio\Swoole\Log\SwooleLoggerFactory;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

trait LoggerFactoryHelperTrait
{
    public function setUp()
    {
        $this->container = $this->prophesize(ContainerInterface::class);
        $this->container->has(SwooleLoggerFactory::SWOOLE_LOGGER)->willReturn(false);
        $this->logger = $this->prophesize(LoggerInterface::class)->reveal();
        $this->formatter = $this->prophesize(AccessLogFormatterInterface::class)->reveal();
    }

    private function createContainerMockWithNamedLogger() : ContainerInterface
    {
        $this->createContainerMockWithConfigAndNotPsrLogger([
            'mezzio-swoole' => [
                'swoole-http-server' => [
                    'logger' => [
                        'logger-name' => 'my_logger',
                    ],
                ],
            ],
        ]);
        $this->container->get('my_logger')->willReturn($this->logger);

        return $this->container->reveal();
    }

    private function createContainerMockWithConfigAndPsrLogger(?array $config = null) : ContainerInterface
    {
        $this->registerConfigService($config);
        $this->container->has(LoggerInterface::class)->willReturn(true);
        $this->container->get(LoggerInterface::class)->shouldBeCalled()->willReturn($this->logger);

        return $this->container->reveal();
    }

    private function createContainerMockWithConfigAndNotPsrLogger(?array $config = null) : ContainerInterface
    {
        $this->registerConfigService($config);
        $this->container->has(LoggerInterface::class)->willReturn(false);
        $this->container->get(LoggerInterface::class)->shouldNotBeCalled();

        return $this->container->reveal();
    }

    private function registerConfigService(?array $config = null) : void
    {
        $hasConfig = $config !== null;
        $shouldBeCalledMethod = $hasConfig ? 'shouldBeCalled' : 'shouldNotBeCalled';

        $this->container->has('config')->willReturn($hasConfig);
        $this->container->get('config')->{$shouldBeCalledMethod}()->willReturn($config);
    }
}
