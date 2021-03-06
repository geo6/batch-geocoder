<?php

declare(strict_types=1);

use Mezzio\Application;
use Mezzio\MiddlewareFactory;
use Psr\Container\ContainerInterface;

return function (Application $app, MiddlewareFactory $factory, ContainerInterface $container): void {
    $app->get('/app/batch-geocoder/', App\Handler\HomeHandler::class, 'home');
    $app->post('/app/batch-geocoder/upload', App\Handler\UploadHandler::class, 'upload');
    $app->route('/app/batch-geocoder/validate', App\Handler\ValidateHandler::class, ['GET', 'POST'], 'validate');
    $app->get('/app/batch-geocoder/geocode', App\Handler\GeocodeHandler::class, 'geocode');
    $app->get('/app/batch-geocoder/geocode/process/first', App\Handler\Process\FirstHandler::class, 'geocode.process.first');
    $app->get('/app/batch-geocoder/geocode/process/second', App\Handler\Process\SecondHandler::class, 'geocode.process.second');
    $app->get('/app/batch-geocoder/geocode/choose', App\Handler\GeocodeChooseHandler::class, 'geocode.choose');
    $app->get('/app/batch-geocoder/view', App\Handler\ViewHandler::class, 'view');
    $app->get('/app/batch-geocoder/map', App\Handler\MapHandler::class, 'map');
    $app->get('/app/batch-geocoder/export/{type:csv|geojson|xlsx}', App\Handler\ExportHandler::class, 'export');
};
