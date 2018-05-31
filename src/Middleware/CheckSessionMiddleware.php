<?php

declare(strict_types=1);

namespace App\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Zend\Diactoros\Response\RedirectResponse;
use Zend\Expressive\Session\SessionMiddleware;

class CheckSessionMiddleware implements MiddlewareInterface
{
    private $session;

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler) : ResponseInterface
    {
        $this->session = $request->getAttribute(SessionMiddleware::SESSION_ATTRIBUTE);

        $invalidSession = false;

        $path = $request->getUri()->getPath();
        switch ($path) {
            case '/app/batch-geocoder/validate':
            case '/app/batch-geocoder/geocode':
            case '/app/batch-geocoder/geocode/process':
            case '/app/batch-geocoder/geocode/choose':
            case '/app/batch-geocoder/view':
            case '/app/batch-geocoder/map':
            case '/app/batch-geocoder/export/csv':
            case '/app/batch-geocoder/export/geojson':
                $invalidSession = $this->checkTableName();
                break;
        }

        if ($invalidSession === true) {
            return new RedirectResponse('/app/batch-geocoder/');
        }

        return $handler->handle($request);
    }

    private function checkTableName()
    {
        return is_null($this->session->get('table'));
    }
}
