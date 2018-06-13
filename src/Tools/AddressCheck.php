<?php

declare(strict_types=1);

namespace App\Tools;

use Geo6\Text\Text;
use Geocoder\Model\Address as AddressModel;
use Zend\Db\Adapter\Adapter;
use Zend\Db\Sql\Sql;
use Zend\Filter\FilterChain;
use Zend\Filter\StringToUpper;
use Zend\I18n\Filter\Alnum;

final class AddressCheck
{
    private $adapter;
    private $address;
    private $validation;

    public function __construct(AddressModel $address, Adapter $adapter = null, bool $validation = true)
    {
        $this->adapter = $adapter;
        $this->address = $address;
        $this->validation = $validation;
    }

    public function isValid(AddressModel $address): bool
    {
        return $this->checkPostalCode($address) &&
            $this->checkLocality($address) &&
            $this->checkStreetname($address) &&
            $this->checkStreetNumber($address);
    }

    public function getScore(AddressModel $address): int
    {
        $score = 0;

        $postalCode1 = $this->address->getPostalCode();
        $postalCode2 = $address->getPostalCode();
        if ($postalCode1 === $postalCode2) {
            $score += 8;
        }

        $locality1 = self::filter($this->address->getLocality());
        $locality2 = self::filter($address->getLocality());
        if ($locality1 === $locality2) {
            $score += 4;
        }

        $streetname1 = self::filter($this->address->getStreetname());
        $streetname2 = self::filter($address->getStreetname());
        if ($streetname1 === $streetname2) {
            $score += 2;
        }

        $streetNumber1 = $this->address->getStreetNumber();
        $streetNumber2 = $address->getStreetNumber();
        if ($streetNumber1 === $streetNumber2) {
            $score += 1;
        }

        return $score;
    }

    private static function filter(string $str): string
    {
        $filterChain = new FilterChain();
        $filterChain
            ->attach(new Alnum())
            ->attach(new StringToUpper());

        return $filterChain->filter(Text::removeAccents($str));
    }

    public function checkPostalCode(AddressModel $address): bool
    {
        $postalCode1 = $this->address->getPostalCode();
        $postalCode2 = $address->getPostalCode();

        if ($postalCode1 === $postalCode2) {
            return true;
        } elseif ($this->validation !== false) {
            $sql = new Sql($this->adapter, 'validation');

            $selectNIS5 = $sql->select();
            $selectNIS5->columns(['nis5']);
            $selectNIS5->where
                ->equalTo('postalcode', $postalCode1);

            $select = $sql->select();
            $select->columns(['postalcode']);
            $select->where
                ->in('nis5', $selectNIS5);

            $qsz = $sql->buildSqlString($select);
            $results = $this->adapter->query($qsz, $this->adapter::QUERY_MODE_EXECUTE);

            $postalCodes = [];
            foreach ($results as $r) {
                $postalCodes[] = $r->postalcode;
            }
            $postalCodes = array_unique($postalCodes);

            return in_array($postalCode2, $postalCodes);
        } else {
            return false;
        }
    }

    public function checkLocality(AddressModel $address): bool
    {
        $locality1 = self::filter($this->address->getLocality());
        $locality2 = self::filter($address->getLocality());

        if ($locality1 === $locality2) {
            return true;
        } elseif ($this->validation !== false) {
            $postalcode = $this->address->getPostalcode();

            $sql = new Sql($this->adapter, 'validation');

            $select = $sql->select();
            $select->where->equalTo('postalcode', $postalcode);
            $select->columns(['normalized']);

            $qsz = $sql->buildSqlString($select);
            $results = $this->adapter->query($qsz, $this->adapter::QUERY_MODE_EXECUTE);

            $localities = array_column($results->toArray(), 'normalized');
            $localities = array_map('self::filter', $localities);

            return in_array($locality2, $localities);
        } else {
            $levenshtein = levenshtein($locality1, $locality2);

            return $levenshtein < 5;
        }
    }

    public function checkStreetname(AddressModel $address): bool
    {
        $streetname1 = self::filter($this->address->getStreetname());
        $streetname2 = self::filter($address->getStreetname());

        $levenshtein = levenshtein($streetname1, $streetname2);

        return $levenshtein < 5;
    }

    public function checkStreetNumber(AddressModel $address): bool
    {
        $streetNumber1 = $this->address->getStreetNumber();
        $streetNumber2 = $address->getStreetNumber();

        if ($streetNumber1 === $streetNumber2) {
            return true;
        } elseif (intval($streetNumber1) === intval($streetNumber2)) {
            return true;
        }

        return false;
    }
}
