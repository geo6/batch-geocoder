<?php

declare(strict_types=1);

namespace App;

/**
 * The configuration provider for the App module.
 *
 * @see https://docs.zendframework.com/zend-component-installer/
 */
class ConfigProvider
{
    /**
     * Returns the configuration array.
     */
    public function __invoke(): array
    {
        return [
            'dependencies' => $this->getDependencies(),
            'templates'    => $this->getTemplates(),
            'plates'       => [
                'extensions' => $this->getPlatesExentions(),
            ],
        ];
    }

    /**
     * Returns the container dependencies.
     */
    public function getDependencies(): array
    {
        return [
            'invokables' => [
                Handler\Process\FirstHandler::class  => Handler\Process\FirstHandler::class,
                Handler\Process\SecondHandler::class => Handler\Process\SecondHandler::class,
                Handler\ExportHandler::class         => Handler\ExportHandler::class,
            ],
            'factories'  => [
                Extension\TranslateExtension::class => Extension\Factory\TranslateExtensionFactory::class,

                Middleware\UIMiddleware::class      => Middleware\Factory\UIMiddlewareFactory::class,

                Handler\GeocodeHandler::class       => Handler\Factory\GeocodeHandlerFactory::class,
                Handler\GeocodeChooseHandler::class => Handler\Factory\GeocodeChooseHandlerFactory::class,
                Handler\HomeHandler::class          => Handler\Factory\HomeHandlerFactory::class,
                Handler\MapHandler::class           => Handler\Factory\MapHandlerFactory::class,
                Handler\UploadHandler::class        => Handler\Factory\UploadHandlerFactory::class,
                Handler\ValidateHandler::class      => Handler\Factory\ValidateHandlerFactory::class,
                Handler\ViewHandler::class          => Handler\Factory\ViewHandlerFactory::class,
            ],
        ];
    }

    /**
     * Returns the templates configuration.
     */
    public function getTemplates(): array
    {
        return [
            'paths' => [
                'app'     => ['templates/app'],
                'error'   => ['templates/error'],
                'layout'  => ['templates/layout'],
                'partial' => ['templates/partial'],
            ],
        ];
    }

    /**
     * Returns the Plates extentsions configuration.
     */
    public function getPlatesExentions(): array
    {
        return [
            Extension\TranslateExtension::class,
        ];
    }
}
