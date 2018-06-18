<?php

declare(strict_types=1);

namespace App\Handler\Process;

use App\Tools\AddressCheck;
use Geocoder\Formatter\StringFormatter;
use Geocoder\Http\Provider\AbstractHttpProvider;
use Geocoder\Model\Address;
use Geocoder\Model\AddressBuilder;
use Zend\Db\Sql\Expression;

class SecondHandler extends FirstHandler
{
    public function getAddresses() : array
    {
        $adapter = $this->sql->getAdapter();

        $select = $this->sql->select();
        $select->columns([
            'id',
            'streetname'  => new Expression('process_doublepass->\'streetname\''),
            'housenumber' => new Expression('process_doublepass->\'housenumber\''),
            'postalcode'  => new Expression('process_doublepass->\'postalcode\''),
            'locality'    => new Expression('process_doublepass->\'locality\''),
            'validation'  => new Expression('hstore_to_json(hstore(\'region\', validation->\'region\'))'),
            'provider'    => new Expression('process_doublepass->\'provider\''),
            'source'      => new Expression('hstore_to_json(hstore('.
                'ARRAY['.
                    '\'streetname\','.
                    '\'housenumber\','.
                    '\'postalcode\','.
                    '\'locality\''.
                '],'.
                'ARRAY['.
                    '"streetname",'.
                    '"housenumber",'.
                    '"postalcode",'.
                    '"locality"'.
                ']'.
            '))'),
        ]);
        $select->where
            ->equalTo('valid', 't')
            ->equalTo('process_status', 1)
            ->isNotNull('process_doublepass')
            ->isNull(new Expression('process_doublepass->\'checked\''));
        $select->limit(self::LIMIT);

        $result = $adapter->query(
            $this->sql->buildSqlString($select),
            $adapter::QUERY_MODE_EXECUTE
        );

        return $result->toArray();
    }

    public function storeSingleResult(AbstractHttpProvider $provider, int $providerPointer, Address $result) : void
    {
        $id = $this->addresses[$this->pointer]['id'];
        $address = $this->buildAddress($provider);

        $source = json_decode($this->addresses[$this->pointer]['source'], true);

        $builder = new AddressBuilder('');
        $builder->setStreetNumber($source['housenumber'])
            ->setStreetName($source['streetname'])
            ->setLocality($source['locality'])
            ->setPostalCode($source['postalcode']);
        $sourceAddress = $builder->build();

        $validator = new AddressCheck(
            $sourceAddress,
            $this->sql->getAdapter(),
            $this->validation
        );

        $data = [
            'process_datetime' => date('c'),
            'process_status'   => 1,
            'process_provider' => $provider->getName(),
            'process_address'  => (new StringFormatter())->format($result, self::FORMAT_STREETNUMBER),
            'process_score'    => $validator->getScore($result),
            'the_geog'         => new Expression(
                'ST_SetSRID(ST_MakePoint(?, ?), 4326)',
                [
                    $result->getCoordinates()->getLongitude(),
                    $result->getCoordinates()->getLatitude(),
                ]
            ),
        ];

        if ($this->doublePass === true) {
            $data['process_doublepass'] = new Expression(
                '"process_doublepass" || hstore('.
                    'ARRAY[\'checked\'],'.
                    'ARRAY[?]'.
                ')',
                [
                    true,
                ]
            );
        }

        $update = $this->sql->update();
        $update->set($data);
        $update->where(['id' => $id]);

        $this->sql->getAdapter()->query(
            $this->sql->buildSqlString($update),
            $this->sql->getAdapter()::QUERY_MODE_EXECUTE
        );
    }

    public function storeMultipleResult(AbstractHttpProvider $provider) : void
    {
        $id = $this->addresses[$this->pointer]['id'];

        $data = [
            'process_datetime' => date('c'),
            'process_status'   => 2,
            'process_provider' => $provider->getName(),
            'process_address'  => null,
            'process_score'    => null,
        ];

        if ($this->doublePass === true) {
            $data['process_doublepass'] = new Expression(
                '"process_doublepass" || hstore('.
                    'ARRAY[\'checked\'],'.
                    'ARRAY[?]'.
                ')',
                [
                    true,
                ]
            );
        }

        $update = $this->sql->update();
        $update->set($data);
        $update->where(['id' => $id]);

        $this->sql->getAdapter()->query(
            $this->sql->buildSqlString($update),
            $this->sql->getAdapter()::QUERY_MODE_EXECUTE
        );
    }
}
