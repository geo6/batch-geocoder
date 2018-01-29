<?php

use Zend\Expressive\Flash\FlashMessageMiddleware;
use Zend\Expressive\Helper\ServerUrlMiddleware;
use Zend\Expressive\Helper\UrlHelperMiddleware;
//use Zend\Expressive\Middleware\ImplicitHeadMiddleware;
//use Zend\Expressive\Middleware\ImplicitOptionsMiddleware;
use Zend\Expressive\Middleware\NotFoundHandler;
use Zend\Expressive\Session\SessionMiddleware;
use Zend\Stratigility\Middleware\ErrorHandler;

$app->pipe(ErrorHandler::class);
$app->pipe(ServerUrlMiddleware::class);
$app->pipe(SessionMiddleware::class);
$app->pipe(FlashMessageMiddleware::class);

$app->pipe(App\Middleware\ConfigMiddleware::class);
$app->pipe(App\Middleware\DbAdapterMiddleware::class);
$app->pipe(App\Middleware\LocalizationMiddleware::class);

$app->pipeRoutingMiddleware();
//$app->pipe(ImplicitHeadMiddleware::class);
//$app->pipe(ImplicitOptionsMiddleware::class);
$app->pipe(UrlHelperMiddleware::class);
$app->pipeDispatchMiddleware();
$app->pipe(NotFoundHandler::class);
