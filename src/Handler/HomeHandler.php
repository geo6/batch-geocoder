<?php

declare(strict_types=1);

namespace App\Handler;

use App\Middleware\ConfigMiddleware;
use App\Middleware\DbAdapterMiddleware;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Zend\Db\Metadata\Metadata;
use Zend\Diactoros\Response\HtmlResponse;
use Zend\Expressive\Flash\FlashMessageMiddleware;
use Zend\Expressive\Router\RouterInterface;
use Zend\Expressive\Template\TemplateRendererInterface;

class HomeHandler implements RequestHandlerInterface
{
    private $router;
    private $template;

    public function __construct(RouterInterface $router, TemplateRendererInterface $template)
    {
        $this->router = $router;
        $this->template = $template;
    }

    public function handle(ServerRequestInterface $request) : ResponseInterface
    {
        $adapter = $request->getAttribute(DbAdapterMiddleware::DBADAPTER_ATTRIBUTE);
        $config = $request->getAttribute(ConfigMiddleware::CONFIG_ATTRIBUTE);
        $flashMessages = $request->getAttribute(FlashMessageMiddleware::FLASH_ATTRIBUTE);

        $query = $request->getQueryParams();

        $error = $flashMessages->getFlash('error-upload');

        $metadata = new Metadata($adapter);
        $tablenames = $metadata->getTableNames();

        $tables = [];
        foreach ($tablenames as $name) {
            if (preg_match('/[0-9]{8}_[0-9A-Za-z]+/', $name) === 1) {
                $tables[] = $name;
            }
        }
        rsort($tables);

        $data = [
            'title'    => substr($config['name'], strpos($config['name'], '/') + 1),
            'error'    => $error,
            'tables'   => $tables,
            'archives' => (isset($config['archives']) && $config['archives'] === true && isset($query['archives'])),
        ];

        return new HtmlResponse($this->template->render('app::home', $data));
    }
}