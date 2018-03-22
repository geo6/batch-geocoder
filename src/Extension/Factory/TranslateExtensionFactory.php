<?php

declare(strict_types=1);

namespace App\Extension\Factory;

use Psr\Container\ContainerInterface;
use Zend\I18n\Translator\Translator;

class TranslateExtensionFactory
{
    public function __invoke(ContainerInterface $container)
    {
        $translator = new Translator();
        $translator->addTranslationFilePattern('gettext', './data/locale', '%s/messages.mo');

        return new \App\Extension\TranslateExtension($translator);
    }
}
