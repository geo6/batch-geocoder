<?php

declare(strict_types=1);

namespace App\Middleware;

use Exception;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Laminas\Db\Adapter\Adapter;

class DbAdapterMiddleware implements MiddlewareInterface
{
    public const DBADAPTER_ATTRIBUTE = 'adapter';

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $config = $request->getAttribute(ConfigMiddleware::CONFIG_ATTRIBUTE);

        if (isset($config['postgresql'])) {
            $adapter = new Adapter(array_merge(['driver' => 'Pdo_Pgsql'], $config['postgresql']));
        } else {
            throw new Exception(sprintf(
                'Cannot create %s; could not locate PostgreSQL parameters in application configuration.',
                self::class
            ));
        }

        return $handler->handle($request->withAttribute(self::DBADAPTER_ATTRIBUTE, $adapter));
    }
}
