<?php

use Zend\Expressive\Application;
use Zend\Expressive\Container;
use Zend\Expressive\Delegate;
use Zend\Expressive\Helper;
use Zend\Expressive\Middleware;

return [
  'dependencies' => [
    'aliases' => [
      'Zend\Expressive\Delegate\DefaultDelegate' => Delegate\NotFoundDelegate::class,
    ],
    'invokables' => [
      Helper\ServerUrlHelper::class => Helper\ServerUrlHelper::class,
    ],
    'factories' => [
      Application::class                => Container\ApplicationFactory::class,

      Delegate\NotFoundDelegate::class  => Container\NotFoundDelegateFactory::class,
      Helper\ServerUrlMiddleware::class => Helper\ServerUrlMiddlewareFactory::class,
      Helper\UrlHelper::class           => Helper\UrlHelperFactory::class,
      Helper\UrlHelperMiddleware::class => Helper\UrlHelperMiddlewareFactory::class,

      Zend\Stratigility\Middleware\ErrorHandler::class => Container\ErrorHandlerFactory::class,
      Middleware\ErrorResponseGenerator::class         => Container\ErrorResponseGeneratorFactory::class,
      Middleware\NotFoundHandler::class                => Container\NotFoundHandlerFactory::class,
    ],
  ],
];
