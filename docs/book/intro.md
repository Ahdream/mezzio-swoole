# Swoole

[Swoole](https://www.swoole.co.uk/) is a PECL extension for developing
asynchronous applications in PHP. It enables PHP developers to write
high-performance, scalable, concurrent TCP, UDP, Unix socket, HTTP, or Websocket
services without requiring in-depth knowledge about non-blocking I/O programming
or the low-level Linux kernel.

## Install swoole

You can install the Swoole extension on Linux or Mac environments using the
following commands:

```bash
$ pecl install swoole
```

For more information on the extension, [visit its package details on PECL](https://pecl.php.net/package/swoole).

## Install mezzio-swoole

To install ths package, use [Composer](https://getcomposer.org/):

```bash
$ composer install mezzio/mezzio-swoole
```

## Swoole with Mezzio

mezzio-swoole enables an Mezzio application to be executed with
the [Swoole](https://www.swoole.co.uk/) extension. This means you can run the
application from the command line, **without requiring a web server**.

You can run the application using the following command:

```bash
$ php public/index.php
```

This command will execute Swoole on `localhost` via port `8080`.

> ### Mezzio skeleton versions prior to 3.1.0
>
> The above will work immediately after installing mezzio-swoole if you
> are using a version of [mezzio-skeleton](https://github.com/mezzio/mezzio-skeleton)
> from 3.1.0 or later.
>
> For applications based on previous versions of the skeleton, you will need to
> create a configuration file such as `config/autoload/mezzio-swoole.global.php`
> or `config/autoload/mezzio-swoole.local.php` with the following
> contents:
>
> ```php
> <?php
> use Mezzio\Swoole\ConfigProvider;
>
> return (new ConfigProvider())();
> ```

You can change the host address and/or host name as well as the port using a
configuration file, as follows:

```php
// In config/autoload/swoole.local.php:
return [
    'mezzio-swoole' => [
        'swoole-http-server' => [
            'host' => '192.168.0.1',
            'port' => 9501,
        ],
    ],
];
```

You can also configure the Swoole HTTP server using an `options` key to specify
any accepted Swoole settings. For instance, the following configuration
demonstrates enabling SSL:

```php
// config/autoload/swoole.local.php
return [
    'mezzio-swoole' => [
        'swoole-http-server' => [
            'host' => '192.168.0.1',
            'port' => 9501,
            'mode' => SWOOLE_BASE,
            'protocol' => SWOOLE_SOCK_TCP | SWOOLE_SSL,
            'options' => [
                'ssl_cert_file' => 'path/to/ssl.crt',
                'ssl_key_file' => 'path/to/ssl.key',
            ],
        ],
    ],
];
```
