<?php

declare(strict_types=1);

namespace App\Middleware;

use Locale;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class LocalizationMiddleware implements MiddlewareInterface
{
    public const LOCALIZATION_ATTRIBUTE = 'locale';

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler) : ResponseInterface
    {
        $cookies = $request->getCookieParams();
        $query = $request->getQueryParams();
        $server = $request->getServerParams();

        if (isset($query['lang']) && preg_match('/^(?P<locale>[a-z]{2,3}([-_][a-zA-Z]{2}|))$/', $query['lang'])) {
            Locale::setDefault(Locale::getPrimaryLanguage($query['lang']));

            if (isset($cookies['lang'])) {
                setcookie('lang', '', time() - 3600);
            }
            setcookie('lang', Locale::getDefault(), 0, '', '', (!empty($server['HTTPS'])), true);
        } elseif (isset($cookies['lang'])) {
            Locale::setDefault(Locale::getPrimaryLanguage($cookies['lang']));
        } elseif (isset($server['HTTP_ACCEPT_LANGUAGE'])) {
            $locale = Locale::acceptFromHttp($server['HTTP_ACCEPT_LANGUAGE']);
            Locale::setDefault(Locale::getPrimaryLanguage($locale));
        } else {
            Locale::setDefault('en');
        }

        return $handler->handle($request->withAttribute(self::LOCALIZATION_ATTRIBUTE, Locale::getDefault()));
    }
}
