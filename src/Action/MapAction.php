<?php

namespace App\Action;

use Interop\Http\ServerMiddleware\DelegateInterface;
use Interop\Http\ServerMiddleware\MiddlewareInterface;
use Psr\Http\Message\ServerRequestInterface;
use Zend\Diactoros\Response\HtmlResponse;
use Zend\Expressive\Flash\FlashMessageMiddleware;
use Zend\Expressive\Router\RouterInterface;
use Zend\Expressive\Template\TemplateRendererInterface;

use App\Middleware\ConfigMiddleware;

class MapAction implements MiddlewareInterface
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
    $data = [
      'title' => substr($config['name'], strpos($config['name'], '/') + 1),
    ];

    return new HtmlResponse($this->template->render('app::map', $data));
  }
}
