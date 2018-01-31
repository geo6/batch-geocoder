<?php

declare(strict_types=1);

namespace App\Extension\Factory;

use App\Extension\TranslateExtension;
use Psr\Container\ContainerInterface;
use Zend\I18n\Translator\Translator;

class TranslateFactory
{
    public function __invoke(ContainerInterface $container)
    {
        $translator = new Translator();
        $translator->addTranslationFilePattern('gettext', '../locale', '%s/messages.mo');

        return new TranslateExtension($translator);
    }
}
