<?php

namespace App\Action;

use App\Middleware\ConfigMiddleware;
use Interop\Http\ServerMiddleware\DelegateInterface;
use Interop\Http\ServerMiddleware\MiddlewareInterface;
use Psr\Http\Message\ServerRequestInterface;
use Zend\Diactoros\Response\HtmlResponse;
use Zend\Expressive\Flash\FlashMessageMiddleware;
use Zend\Expressive\Router\RouterInterface;
use Zend\Expressive\Template\TemplateRendererInterface;

class HomeAction implements MiddlewareInterface
{
    private $router;
    private $template;

    public function __construct(RouterInterface $router, TemplateRendererInterface $template)
    {
        $this->router = $router;
        $this->template = $template;
    }

    public function process(ServerRequestInterface $request, DelegateInterface $delegate)
    {
        $config = $request->getAttribute(ConfigMiddleware::CONFIG_ATTRIBUTE);
        $flashMessages = $request->getAttribute(FlashMessageMiddleware::FLASH_ATTRIBUTE);

        $error = $flashMessages->getFlash('error-upload');

        $data = [
            'title' => substr($config['name'], strpos($config['name'], '/') + 1),
            'error' => $error,
        ];

        return new HtmlResponse($this->template->render('app::home', $data));
    }
}
