<?php

use Zend\Expressive\Plates\PlatesRendererFactory;
use Zend\Expressive\Template\TemplateRendererInterface;

return [
  'dependencies' => [
    'factories' => [
      TemplateRendererInterface::class => PlatesRendererFactory::class,
      App\Extension\TranslateExtension::class => App\Extension\Factory\TranslateFactory::class,
    ],
  ],

  'templates' => [
    'extension' => 'phtml',
    'paths' => [
      'app'     => [__DIR__.'/../../templates/app'],
      'error'   => [__DIR__.'/../../templates/error'],
      'layout'  => [__DIR__.'/../../templates/layout'],
      'partial' => [__DIR__.'/../../templates/partial'],
    ],
  ],

  'plates' => [
    'extensions' => [
      App\Extension\TranslateExtension::class,
    ],
  ]
];
