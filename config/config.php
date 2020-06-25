<?php

declare(strict_types=1);

use Laminas\ConfigAggregator\ArrayProvider;
use Laminas\ConfigAggregator\ConfigAggregator;
use Laminas\ConfigAggregator\PhpFileProvider;

$cacheConfig = [
    'config_cache_path' => 'data/cache/config.php',
];

$aggregator = new ConfigAggregator([
    // Include cache configuration
    new ArrayProvider($cacheConfig),

    Laminas\Db\ConfigProvider::class,
    Mezzio\ConfigProvider::class,
    Mezzio\Flash\ConfigProvider::class,
    Mezzio\Helper\ConfigProvider::class,
    Mezzio\Plates\ConfigProvider::class,
    Mezzio\Router\ConfigProvider::class,
    Mezzio\Router\FastRouteRouter\ConfigProvider::class,
    Mezzio\Session\ConfigProvider::class,
    Mezzio\Session\Ext\ConfigProvider::class,
    Laminas\HttpHandlerRunner\ConfigProvider::class,
    Laminas\Filter\ConfigProvider::class,
    Laminas\I18n\ConfigProvider::class,
    Laminas\Validator\ConfigProvider::class,

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
], $cacheConfig['config_cache_path'], [\Laminas\ZendFrameworkBridge\ConfigPostProcessor::class]);

return $aggregator->getMergedConfig();
