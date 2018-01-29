<?php

namespace App\Extension;

use League\Plates\Engine;
use League\Plates\Extension\ExtensionInterface;
use Zend\I18n\Translator\Translator;

class TranslateExtension implements ExtensionInterface
{
    private $translator;

    public function __construct(Translator $translator)
    {
        $this->translator = $translator;
    }

    public function register(Engine $engine)
    {
        $engine->registerFunction('translate', [$this, 'generateTranslate']);
        $engine->registerFunction('translatePlural', [$this, 'generateTranslatePlural']);
    }

    public function generateTranslate(string $message) : string
    {
        return $this->translator->translate($message);
    }

    public function generateTranslatePlural(string $singular, string $plural, int $number) : string
    {
        return $this->translator->translatePlural($singular, $plural, $number);
    }
}
