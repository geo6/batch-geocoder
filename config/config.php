<?php

declare(strict_types=1);

use Zend\ConfigAggregator\ArrayProvider;
use Zend\ConfigAggregator\ConfigAggregator;
use Zend\ConfigAggregator\PhpFileProvider;

$cacheConfig = [
    'config_cache_path' => 'data/cache/config.php',
];

$aggregator = new ConfigAggregator([
    // Include cache configuration
    new ArrayProvider($cacheConfig),

    Zend\Db\ConfigProvider::class,
    Zend\Expressive\ConfigProvider::class,
    Zend\Expressive\Flash\ConfigProvider::class,
    Zend\Expressive\Helper\ConfigProvider::class,
    Zend\Expressive\Plates\ConfigProvider::class,
    Zend\Expressive\Router\ConfigProvider::class,
    Zend\Expressive\Router\FastRouteRouter\ConfigProvider::class,
    Zend\Expressive\Session\ConfigProvider::class,
    Zend\Expressive\Session\Ext\ConfigProvider::class,
    Zend\HttpHandlerRunner\ConfigProvider::class,
    Zend\Filter\ConfigProvider::class,
    Zend\I18n\ConfigProvider::class,
    Zend\Validator\ConfigProvider::class,

    // Default App module config
    App\ConfigProvider::class,

    // Load application config in a pre-defined order in such a way that local settings
    // overwrite global settings. (Loaded as first to last):
    //   - `global.php`
    //   - `*.global.php`
    //   - `local.php`
    //   - `*.local.php`
    new PhpFileProvider(realpath(__DIR__).'/autoload/{{,*.}global,{,*.}local}.php'),

    // Load development config if it exists
    new PhpFileProvider(__DIR__.'/development.config.php'),
], $cacheConfig['config_cache_path']);

return $aggregator->getMergedConfig();
