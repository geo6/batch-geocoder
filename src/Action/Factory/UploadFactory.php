<?php

namespace App\Action\Factory;

use App\Action\UploadAction;
use Psr\Container\ContainerInterface;
use Zend\Expressive\Router\RouterInterface;
use Zend\Expressive\Template\TemplateRendererInterface;

class UploadFactory
{
    public function __invoke(ContainerInterface $container)
    {
        $router = $container->get(RouterInterface::class);
        $template = $container->get(TemplateRendererInterface::class);

        return new UploadAction($router, $template);
    }
}
