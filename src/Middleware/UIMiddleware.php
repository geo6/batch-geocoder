<?php

declare(strict_types=1);

namespace App\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Mezzio\Router\RouterInterface;
use Mezzio\Template\TemplateRendererInterface;

class UIMiddleware implements MiddlewareInterface
{
    private $router;
    private $template;

    public function __construct(RouterInterface $router, TemplateRendererInterface $template)
    {
        $this->router = $router;
        $this->template = $template;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $config = $request->getAttribute(ConfigMiddleware::CONFIG_ATTRIBUTE);

        $this->template->addDefaultParam('partial::header', 'params', $request->getQueryParams());

        if (isset($config['providers']['automatic'], $config['providers']['manual'])) {
            $providersAutomatic = [];
            foreach ($config['providers']['automatic'] as $provider) {
                $providersAutomatic[] = is_array($provider) ? $provider[0]->getName() : $provider->getName();
            }
            $providersManual = [];
            foreach ($config['providers']['manual'] as $provider) {
                $providersManual[] = is_array($provider) ? $provider[0]->getName() : $provider->getName();
            }

            $this->template->addDefaultParam('partial::modal-info', 'providersAutomatic', $providersAutomatic ?? []);
            $this->template->addDefaultParam('partial::modal-info', 'providersManual', $providersManual ?? []);
        } else {
            $providers = [];
            foreach ($config['providers'] as $provider) {
                $providers[] = is_array($provider) ? $provider[0]->getName() : $provider->getName();
            }

            $this->template->addDefaultParam('partial::modal-info', 'providers', $providers ?? []);
        }

        $this->template->addDefaultParam(
            $this->template::TEMPLATE_ALL,
            'title',
            $config['title'] ?? substr($config['name'], strpos($config['name'], '/') + 1)
        );

        return $handler->handle($request);
    }
}
