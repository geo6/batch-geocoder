<?php

declare(strict_types=1);

namespace App\Provider;

use App\Validator\Address as AddressValidator;
use Geocoder\Collection;
use Geocoder\Model\Address;
use Geocoder\Model\AddressCollection;
use Geocoder\Provider\Provider;
use Geocoder\Query\GeocodeQuery;
use Geocoder\Query\ReverseQuery;
use Zend\Db\Adapter\Adapter;

final class BatchGeocoderProvider implements Provider
{
    /**
     * @var Provider[]
     */
    private $providers = [];

    /**
     * @var Adapter
     */
    private $adapter;

    /**
     * @param Provider[] $providers
     */
    public function __construct(array $providers, Adapter $adapter)
    {
        $this->providers = $providers;
        $this->adapter = $adapter;
    }

    /**
     * {@inheritdoc}
     */
    public function geocodeQuery(GeocodeQuery $query): Collection
    {
        $firstNotEmptyResult = null;

        $validator = new AddressValidator($query->getData('address'), $this->adapter);

        foreach ($this->providers as $provider) {
            try {
                $result = $provider->geocodeQuery($query);

                if (!$result->isEmpty()) {
                    if (is_null($firstNotEmptyResult)) {
                        $firstNotEmptyResult = $result;
                    }

                    if ($result->count() === 1) {
                        if ($validator->isValid($result->first()) === true) {
                            return $result;
                        }
                    } elseif ($result->count() > 1) {
                        if (self::extract($query, $result, $this->adapter) === true) {
                            return $result;
                        }
                    }
                }
            }
            catch (\Geocoder\Exception\InvalidServerResponse $e) {
                // Todo: Add log !!
            }
        }

        return $firstNotEmptyResult ?? new AddressCollection();
    }

    /**
     * {@inheritdoc}
     */
    public function reverseQuery(ReverseQuery $query): Collection
    {
        foreach ($this->providers as $provider) {
            $result = $provider->reverseQuery($query);

            if (!$result->isEmpty()) {
                return $result;
            }
        }

        return new AddressCollection();
    }

    /**
     * {@inheritdoc}
     */
    public function getName(): string
    {
        return 'chain-batch-geocoder';
    }

    /**
     * Adds a provider.
     *
     * @param Provider $provider
     *
     * @return Chain
     */
    public function add(Provider $provider): self
    {
        $this->providers[] = $provider;

        return $this;
    }

    private static function extract(GeocodeQuery $query, Collection &$collection, Adapter $adapter)
    {
        $result = [];

        $validator = new AddressValidator($query->getData('address'), $adapter);

        foreach ($collection as $address) {
            $hnr1 = $query->getData('address')->getStreetnumber();
            $hnr2 = $address->getStreetnumber();

            if ($validator->isValid($address) === true && $hnr1 === $hnr2) {
                $result[] = $address;
            }
        }

        if (count($result) === 1) {
            $collection = new AddressCollection($result);

            return true;
        }

        return false;
    }
}
