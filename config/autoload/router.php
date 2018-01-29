<?php

use Zend\Expressive\Router\RouterInterface;
use Zend\Expressive\Router\FastRouteRouter;

return [
  'dependencies' => [
    'invokables' => [
      RouterInterface::class => FastRouteRouter::class,
    ],
  ],
];
