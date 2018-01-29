<?php

namespace App\Action\Factory;

use App\Action\GeocodeAction;
use Psr\Container\ContainerInterface;
use Zend\Expressive\Router\RouterInterface;
use Zend\Expressive\Template\TemplateRendererInterface;

class GeocodeFactory
{
  public function __invoke(ContainerInterface $container)
  {
    $router   = $container->get(RouterInterface::class);
    $template = $container->get(TemplateRendererInterface::class);

    return new GeocodeAction($router, $template);
  }
}
