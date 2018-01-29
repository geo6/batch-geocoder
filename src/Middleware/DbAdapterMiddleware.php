<?php

namespace App\Middleware;

use Exception;
use Interop\Http\ServerMiddleware\DelegateInterface;
use Interop\Http\ServerMiddleware\MiddlewareInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Zend\Db\Adapter\Adapter;

class DbAdapterMiddleware implements MiddlewareInterface
{
    public const DBADAPTER_ATTRIBUTE = 'adapter';

    public function process(ServerRequestInterface $request, DelegateInterface $delegate) : ResponseInterface
    {
        $config = $request->getAttribute(ConfigMiddleware::CONFIG_ATTRIBUTE);

        if (isset($config['postgresql'])) {
            $adapter = new Adapter(array_merge(['driver' => 'Pgsql'], $config['postgresql']));
        } else {
            throw new Exception(sprintf(
                'Cannot create %s; could not locate PostgreSQL parameters in application configuration.',
                self::class
            ));
        }

        return $delegate->process($request->withAttribute(self::DBADAPTER_ATTRIBUTE, $adapter));
    }
}
