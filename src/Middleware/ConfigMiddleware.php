<?php

namespace App\Middleware;

use Interop\Http\ServerMiddleware\DelegateInterface;
use Interop\Http\ServerMiddleware\MiddlewareInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Zend\ConfigAggregator\ConfigAggregator;
use Zend\ConfigAggregator\ZendConfigProvider;

class ConfigMiddleware implements MiddlewareInterface
{
    public const CONFIG_ATTRIBUTE = 'config';

    public function process(ServerRequestInterface $request, DelegateInterface $delegate) : ResponseInterface
    {
        $config = new ConfigAggregator([
      new ZendConfigProvider('../composer.json'),
      new ZendConfigProvider('../config/application/*.{php,ini,xml,json,yaml}'),
    ]);

        return $delegate->process($request->withAttribute(self::CONFIG_ATTRIBUTE, $config->getMergedConfig()));
    }
}
