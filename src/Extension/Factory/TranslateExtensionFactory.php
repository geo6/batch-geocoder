<?php

declare(strict_types=1);

namespace App\Extension\Factory;

use Laminas\I18n\Translator\Translator;
use Psr\Container\ContainerInterface;

class TranslateExtensionFactory
{
    public function __invoke(ContainerInterface $container)
    {
        $translator = new Translator();
        $translator->addTranslationFilePattern('gettext', './data/locale', '%s/messages.mo');

        return new \App\Extension\TranslateExtension($translator);
    }
}
