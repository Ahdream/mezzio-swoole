<?php

/**
 * @see       https://github.com/mezzio/mezzio-swoole for the canonical source repository
 * @copyright https://github.com/mezzio/mezzio-swoole/blob/master/COPYRIGHT.md
 * @license   https://github.com/mezzio/mezzio-swoole/blob/master/LICENSE.md New BSD License
 */

declare(strict_types=1);

namespace MezzioTest\Swoole;

use Laminas\Diactoros\Response;
use Mezzio\Swoole\SwooleEmitter;
use PHPUnit\Framework\TestCase;
use Swoole\Http\Response as SwooleHttpResponse;

class SwooleEmitterTest extends TestCase
{
    public function setUp()
    {
        $this->swooleResponse = $this->prophesize(SwooleHttpResponse::class);
        $this->emitter = new SwooleEmitter($this->swooleResponse->reveal());
    }

    public function testEmit()
    {
        $response = (new Response())
            ->withStatus(200)
            ->withAddedHeader('Content-Type', 'text/plain');
        $response->getBody()->write('Content!');

        $this->assertTrue($this->emitter->emit($response));

        $this->swooleResponse
            ->status(200)
            ->shouldHaveBeenCalled();
        $this->swooleResponse
            ->header('Content-Type', 'text/plain')
            ->shouldHaveBeenCalled();
        $this->swooleResponse
            ->end('Content!')
            ->shouldHaveBeenCalled();
    }

    public function testMultipleHeaders()
    {
        $response = (new Response())
            ->withStatus(200)
            ->withHeader('Content-Type', 'text/plain')
            ->withHeader('Content-Length', '256');

        $this->assertTrue($this->emitter->emit($response));

        $this->swooleResponse
            ->status(200)
            ->shouldHaveBeenCalled();
        $this->swooleResponse
            ->header('Content-Type', 'text/plain')
            ->shouldHaveBeenCalled();
        $this->swooleResponse
            ->header('Content-Length', '256')
            ->shouldHaveBeenCalled();
    }

    public function testMultipleSetCookieHeaders()
    {
        $response = (new Response())
            ->withStatus(200)
            ->withAddedHeader('Set-Cookie', 'foo=bar')
            ->withAddedHeader('Set-Cookie', 'bar=baz');

        $this->assertTrue($this->emitter->emit($response));

        $this->swooleResponse
            ->status(200)
            ->shouldHaveBeenCalled();
        $this->swooleResponse
            ->header('Set-Cookie', 'foo=bar, bar=baz')
            ->shouldHaveBeenCalled();
    }

    public function testEmitWithBigContentBody()
    {
        $content = base64_encode(random_bytes(SwooleEmitter::CHUNK_SIZE)); // CHUNK_SIZE * 1.33333
        $response = (new Response())
            ->withStatus(200)
            ->withAddedHeader('Content-Type', 'text/plain');
        $response->getBody()->write($content);

        $this->assertTrue($this->emitter->emit($response));

        $this->swooleResponse
            ->status(200)
            ->shouldHaveBeenCalled();
        $this->swooleResponse
            ->header('Content-Type', 'text/plain')
            ->shouldHaveBeenCalled();
        $this->swooleResponse
            ->write(substr($content, 0, SwooleEmitter::CHUNK_SIZE))
            ->shouldHaveBeenCalled();
        $this->swooleResponse
            ->write(substr($content, SwooleEmitter::CHUNK_SIZE))
            ->shouldHaveBeenCalled();
        $this->swooleResponse
            ->end()
            ->shouldHaveBeenCalled();
    }
}
