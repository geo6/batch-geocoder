<?php

declare(strict_types=1);

namespace App\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Zend\Expressive\Router\RouterInterface;
use Zend\Expressive\Template\TemplateRendererInterface;

class UIMiddleware implements MiddlewareInterface
{
    private $router;
    private $template;

    public function __construct(RouterInterface $router, TemplateRendererInterface $template)
    {
        $this->router = $router;
        $this->template = $template;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler) : ResponseInterface
    {
        $config = $request->getAttribute(ConfigMiddleware::CONFIG_ATTRIBUTE);

        $providers = [];
        foreach ($config['providers'] as $provider) {
            $providers[] = is_array($provider) ? $provider[0]->getName() : $provider->getName();
        }

        $this->template->addDefaultParam(
            $this->template::TEMPLATE_ALL,
            'title',
            $config['title'] ?? substr($config['name'], strpos($config['name'], '/') + 1)
        );

        $this->template->addDefaultParam('partial::header', 'params', $request->getQueryParams());

        $this->template->addDefaultParam('partial::modal-info', 'version', $config['version']);
        $this->template->addDefaultParam('partial::modal-info', 'providers', $providers);

        return $handler->handle($request);
    }
}
