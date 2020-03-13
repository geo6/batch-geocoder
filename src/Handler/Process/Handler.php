<?php

declare(strict_types=1);

namespace App\Handler\Process;

use Geocoder\Http\Provider\AbstractHttpProvider;
use Geocoder\Model\Address;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

interface Handler
{
    public function handle(ServerRequestInterface $request): ResponseInterface;

    public function getAddresses(): array;

    public function buildAddress(): Address;

    public function geocode(AbstractHttpProvider $provider, int &$rawCount): array;

    public function geocodeStreet(AbstractHttpProvider $provider): array;

    public function storeSingleResult(AbstractHttpProvider $provider, int $providerPointer, Address $result): void;

    public function storeMultipleResult(AbstractHttpProvider $provider): void;
}
