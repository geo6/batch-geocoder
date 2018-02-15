<?php

declare(strict_types=1);

namespace App;

use Psr\Container\ContainerInterface;
use Zend\Expressive\Application;

class RoutesDelegator
{
    /**
     * @param ContainerInterface $container
     * @param string             $serviceName Name of the service being created.
     * @param callable           $callback    Creates and returns the service.
     *
     * @return Application
     */
    public function __invoke(ContainerInterface $container, $serviceName, callable $callback) : Application
    {
        /** @var $app Application */
        $app = $callback();

        // Setup routes:
        $app->get('/applications/batch-geocoder/', Action\HomeAction::class, 'home');
        $app->post('/applications/batch-geocoder/upload', [
            Action\UploadAction::class,
            Action\ValidateAction::class,
        ], 'upload');
        $app->route('/applications/batch-geocoder/validate', Action\ValidateAction::class, ['GET', 'POST'], 'validate');
        $app->get('/applications/batch-geocoder/geocode', Action\GeocodeAction::class, 'geocode');
        $app->get('/applications/batch-geocoder/geocode/process', Action\GeocodeProcessAction::class, 'geocode.process');
        $app->get('/applications/batch-geocoder/geocode/choose', Action\GeocodeChooseAction::class, 'geocode.choose');
        $app->get('/applications/batch-geocoder/view', Action\ViewAction::class, 'view');

        return $app;
    }
}
