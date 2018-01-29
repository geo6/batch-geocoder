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
        $streetname1 = self::filter($this->address->getStreetname());
        $streetname2 = self::filter($address->getStreetname());

        $levenshtein = levenshtein($streetname1, $streetname2);

        $locality1 = self::filter($this->address->getLocality());
        $locality2 = self::filter($address->getLocality());

        if ($levenshtein < 5 && $locality1 === $locality2) {
            return true;
        } else if ($levenshtein < 5) {
            $postalcode = $this->address->getPostalcode();

            $sql = new Sql($this->adapter, 'validation_bpost');

            $select = $sql->select();
            $select->where->equalTo('postalcode', $postalcode);
            $select->columns(['normalized']);

            $qsz = $sql->buildSqlString($select);
            $results = $this->adapter->query($qsz, $this->adapter::QUERY_MODE_EXECUTE);

            $localities = array_column($results->toArray(), 'normalized');
            $localities = array_map('self::filter', $localities);

            return (in_array($locality2, $localities));
        }

        return false;
    }

    private static function filter(string $str): string
    {
        $filterChain = new FilterChain();
        $filterChain
            ->attach(new Alnum())
            ->attach(new StringToUpper());

        return $filterChain->filter(Text::removeAccents($str));
    }
}
