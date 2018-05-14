<?php

declare(strict_types=1);

use Psr\Container\ContainerInterface;
use Zend\Expressive\Application;
use Zend\Expressive\MiddlewareFactory;

return function (Application $app, MiddlewareFactory $factory, ContainerInterface $container) : void {
    $app->get('/app/batch-geocoder/', App\Handler\HomeHandler::class, 'home');
    $app->post('/app/batch-geocoder/upload', [
        App\Handler\UploadHandler::class,
        App\Handler\ValidateHandler::class,
    ], 'upload');
    $app->route('/app/batch-geocoder/validate', App\Handler\ValidateHandler::class, ['GET', 'POST'], 'validate');
    $app->get('/app/batch-geocoder/geocode', App\Handler\GeocodeHandler::class, 'geocode');
    $app->get('/app/batch-geocoder/geocode/process', App\Handler\GeocodeProcessHandler::class, 'geocode.process');
    $app->get('/app/batch-geocoder/geocode/choose', App\Handler\GeocodeChooseHandler::class, 'geocode.choose');
    $app->get('/app/batch-geocoder/view', App\Handler\ViewHandler::class, 'view');
};
