<?php

declare(strict_types=1);

namespace App\Extension;

use Laminas\I18n\Translator\Translator;
use League\Plates\Engine;
use League\Plates\Extension\ExtensionInterface;

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

    public function generateTranslate(string $message): string
    {
        return $this->translator->translate($message);
    }

    public function generateTranslatePlural(string $singular, string $plural, int $number): string
    {
        return $this->translator->translatePlural($singular, $plural, $number);
    }
}
