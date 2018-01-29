<?php

require '../vendor/autoload.php';

use Zend\Expressive\Application;
use Zend\ServiceManager\Config;
use Zend\ServiceManager\ServiceManager;

$config = require '../config/config.php';
$container = new ServiceManager();
(new Config($config['dependencies']))->configureServiceManager($container);
$container->setService('config', $config);

$app = $container->get(Application::class);

require '../config/pipelines.php';

$app->run();
