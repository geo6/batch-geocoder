<?php

namespace App\Middleware;

use Interop\Http\ServerMiddleware\DelegateInterface;
use Interop\Http\ServerMiddleware\MiddlewareInterface;
use Locale;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class LocalizationMiddleware implements MiddlewareInterface
{
    public const LOCALIZATION_ATTRIBUTE = 'locale';

    public function process(ServerRequestInterface $request, DelegateInterface $delegate) : ResponseInterface
    {
        $cookies = $request->getCookieParams();
        $query = $request->getQueryParams();
        $server = $request->getServerParams();

        if (isset($query['lang']) && preg_match('/^(?P<locale>[a-z]{2,3}([-_][a-zA-Z]{2}|))$/', $query['lang'])) {
            Locale::setDefault(Locale::canonicalize($query['lang']));

            if (isset($cookies['lang'])) {
                setcookie('lang', false, null, null, null, (!empty($server['HTTPS'])), false);
            }
            setcookie('lang', Locale::getDefault(), null, null, null, (!empty($server['HTTPS'])), false);
        } elseif (isset($cookies['lang'])) {
            Locale::setDefault(Locale::canonicalize($cookies['lang']));
        } elseif (isset($server['HTTP_ACCEPT_LANGUAGE'])) {
            $locale = Locale::acceptFromHttp($server['HTTP_ACCEPT_LANGUAGE']);
            Locale::setDefault(Locale::canonicalize($locale));
        } else {
            Locale::setDefault('en_US');
        }

        return $delegate->process($request->withAttribute(self::LOCALIZATION_ATTRIBUTE, Locale::getDefault()));
    }
}
