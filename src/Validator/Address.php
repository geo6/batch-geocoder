<?php

declare(strict_types=1);

namespace App\Validator;

use Geo6\Text\Text;
use Geocoder\Model\Address as AddressModel;
use Zend\Db\Adapter\Adapter;
use Zend\Db\Sql\Sql;
use Zend\Filter\FilterChain;
use Zend\Filter\StringToUpper;
use Zend\I18n\Filter\Alnum;

final class Address
{
    private $adapter;
    private $address;

    public function __construct(AddressModel $address, Adapter $adapter)
    {
        $this->adapter = $adapter;
        $this->address = $address;
    }

    public function isValid(AddressModel $address): bool
    {
        return $this->validatePostalCode($address) &&
            $this->validateLocality($address) &&
            $this->validateStreetname($address) &&
            $this->validateStreetNumber($address);
    }

    private static function filter(string $str): string
    {
        $filterChain = new FilterChain();
        $filterChain
            ->attach(new Alnum())
            ->attach(new StringToUpper());

        return $filterChain->filter(Text::removeAccents($str));
    }

    private function validatePostalCode(AddressModel $address): bool
    {
        $postalCode1 = $this->address->getPostalCode();
        $postalCode2 = $address->getPostalCode();

        return $postalCode1 === $postalCode2;
    }

    private function validateLocality(AddressModel $address): bool
    {
        $locality1 = self::filter($this->address->getLocality());
        $locality2 = self::filter($address->getLocality());

        if ($locality1 === $locality2) {
            return true;
        } else {
            $postalcode = $this->address->getPostalcode();

            $sql = new Sql($this->adapter, 'validation_bpost');

            $select = $sql->select();
            $select->where->equalTo('postalcode', $postalcode);
            $select->columns(['normalized']);

            $qsz = $sql->buildSqlString($select);
            $results = $this->adapter->query($qsz, $this->adapter::QUERY_MODE_EXECUTE);

            $localities = array_column($results->toArray(), 'normalized');
            $localities = array_map('self::filter', $localities);

            return in_array($locality2, $localities);
        }
    }

    private function validateStreetname(AddressModel $address): bool
    {
        $streetname1 = self::filter($this->address->getStreetname());
        $streetname2 = self::filter($address->getStreetname());

        $levenshtein = levenshtein($streetname1, $streetname2);

        return $levenshtein < 5;
    }

    private function validateStreetNumber(AddressModel $address): bool
    {
        $streetNumber1 = $this->address->getStreetNumber();
        $streetNumber2 = $address->getStreetNumber();

        if ($streetNumber1 === $streetNumber2) {
            return true;
        } else if (intval($streetNumber1) === intval($streetNumber2)) {
            return true;
        }

        return false;
    }
}
