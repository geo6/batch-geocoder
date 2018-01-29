<?php

use Zend\ConfigAggregator\ArrayProvider;
use Zend\ConfigAggregator\ConfigAggregator;
use Zend\ConfigAggregator\PhpFileProvider;
use Zend\ConfigAggregator\ZendConfigProvider;

$cacheConfig = [
  'config_cache_path' => 'data/cache/config.php',
];

$aggregator = new ConfigAggregator([
  new ArrayProvider($cacheConfig),

  App\ConfigProvider::class,

  Zend\Db\ConfigProvider::class,
  Zend\Expressive\Flash\ConfigProvider::class,
  Zend\Expressive\Session\ConfigProvider::class,
  Zend\Expressive\Session\Ext\ConfigProvider::class,
  Zend\Filter\ConfigProvider::class,
  Zend\I18n\ConfigProvider::class,
  Zend\Validator\ConfigProvider::class,

  new PhpFileProvider(__DIR__.'/autoload/*.php'),
  new PhpFileProvider(__DIR__.'/development.config.php'),
], $cacheConfig['config_cache_path']);

return $aggregator->getMergedConfig();
