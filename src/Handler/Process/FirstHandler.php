<?php

declare(strict_types=1);

namespace App\Handler\Process;

use App\Middleware\ConfigMiddleware;
use App\Middleware\DbAdapterMiddleware;
use App\Tools\AddressCheck;
use Geocoder\Formatter\StringFormatter;
use Geocoder\Http\Provider\AbstractHttpProvider;
use Geocoder\Model\Address;
use Geocoder\Model\AddressBuilder;
use Geocoder\Query\GeocodeQuery;
use Geocoder\StatefulGeocoder;
use Laminas\Db\Sql\Expression;
use Laminas\Db\Sql\Sql;
use Laminas\Diactoros\Response\JsonResponse;
use Mezzio\Session\SessionMiddleware;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class FirstHandler implements Handler, RequestHandlerInterface
{
    const LIMIT = 25;
    const FORMAT_STREETNUMBER = '%S %n, %z %L';
    const FORMAT_STREET = '%S, %z %L';

    protected $addresses;
    protected $doublePass;
    protected $pointer = 0;
    protected $sql;
    protected $validation = true;

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $adapter = $request->getAttribute(DbAdapterMiddleware::DBADAPTER_ATTRIBUTE);
        $config = $request->getAttribute(ConfigMiddleware::CONFIG_ATTRIBUTE);
        $session = $request->getAttribute(SessionMiddleware::SESSION_ATTRIBUTE);

        $query = $request->getQueryParams();

        $table = $session->get('table');

        $this->sql = new Sql($adapter, $table);
        $this->addresses = $this->getAddresses();
        $this->validation = !isset($config['validation']) || $config['validation'] !== false;
        $this->doublePass = isset($config['doublePass']) && $config['doublePass'] === true;

        if (empty($this->addresses)) {
            return new JsonResponse(null);
        } else {
            $data = [
                'count'         => count($this->addresses),
                'countSingle'   => 0,
                'countMultiple' => 0,
                'countNoResult' => 0,
            ];

            foreach ($this->addresses as $this->pointer => $address) {
                $validation = !is_null($address['validation']) ? json_decode($address['validation']) : null;

                $progress = [];
                $novalidresult = true;

                if (isset($config['providers']['automatic'], $config['providers']['manual'])) {
                    $providers = $config['providers']['automatic'];
                } else {
                    $providers = $config['providers'];
                }

                foreach ($providers as $i => $provider) {
                    if (is_array($provider)) {
                        if (!in_array($validation->region, $provider[1])) {
                            continue;
                        }

                        $provider = $provider[0];
                    }

                    $rawCount = 0;

                    try {
                        $validResults = $this->geocode($provider, $rawCount);

                        if (count($validResults) === 1) {
                            $data['countSingle']++;

                            $this->storeSingleResult($provider, $i, $validResults[0]);

                            $novalidresult = false;
                            break;
                        } elseif (count($validResults) > 1) {
                            $exactMatch = [];
                            foreach ($validResults as $validResult) {
                                if ($address['housenumber'] == $validResult->getStreetNumber()) {
                                    $exactMatch[] = $validResult;
                                }
                            }

                            if (count($exactMatch) === 1) {
                                $data['countSingle']++;

                                $this->storeSingleResult($provider, $i, $exactMatch[0]);
                            } else {
                                $data['countMultiple']++;

                                $this->storeMultipleResult($provider);
                            }

                            $novalidresult = false;
                            break;
                        } else {
                            $validStreetResults = $this->geocodeStreet($provider);

                            if (count($validStreetResults) > 0) {
                                $data['countMultiple']++;

                                $this->storeMultipleResult($provider);

                                $novalidresult = false;
                                break;
                            }
                        }
                    } catch (\Geocoder\Exception\InvalidServerResponse $e) {
                        // TODO: add log
                    } catch (\Http\Client\Exception\NetworkException $e) {
                        // TODO: add log
                    }

                    $progress[$provider->getName()] = $rawCount;
                }

                if ($novalidresult === true) {
                    $data['countNoResult']++;

                    $update = $this->sql->update();
                    $update->set([
                        'process_datetime' => date('c'),
                        'process_status'   => isset($config['providers']['automatic'], $config['providers']['manual']) ? 0 : (array_sum($progress) === 0 ? -1 : 0),
                    ]);
                    $update->where(['id' => $address['id']]);

                    $qsz = $this->sql->buildSqlString($update);
                    $adapter->query($qsz, $adapter::QUERY_MODE_EXECUTE);
                }
            }

            return new JsonResponse($data);
        }
    }

    public function getAddresses(): array
    {
        $adapter = $this->sql->getAdapter();

        $select = $this->sql->select();
        $select->columns([
            'id',
            'streetname',
            'housenumber',
            'postalcode',
            'locality',
            'validation' => new Expression('hstore_to_json("validation")'),
        ]);
        $select->where
            ->equalTo('valid', 't')
            ->isNull('process_status');
        $select->limit(self::LIMIT);

        $result = $adapter->query(
            $this->sql->buildSqlString($select),
            $adapter::QUERY_MODE_EXECUTE
        );

        return $result->toArray();
    }

    public function buildAddress(): Address
    {
        $address = $this->addresses[$this->pointer];
        $validation = !is_null($address['validation']) ? json_decode($address['validation'], true) : null;

        $builder = new AddressBuilder('');
        $builder->setStreetNumber($address['housenumber'])
            ->setStreetName($address['streetname'])
            ->setLocality($validation['locality'] ?? $address['locality'])
            ->setPostalCode($validation['postalcode'] ?? $address['postalcode']);

        return $builder->build();
    }

    public function geocode(AbstractHttpProvider $provider, int &$rawCount): array
    {
        $address = $this->buildAddress();

        $query = GeocodeQuery::create((new StringFormatter())->format($address, self::FORMAT_STREETNUMBER));

        $query = $query->withData('streetNumber', $address->getStreetNumber());
        $query = $query->withData('streetName', $address->getStreetName());
        $query = $query->withData('locality', $address->getLocality());
        $query = $query->withData('postalCode', $address->getPostalCode());

        $result = (new StatefulGeocoder($provider))->geocodeQuery($query);
        $rawCount = $result->count();

        $validResults = [];

        $validator = new AddressCheck(
            $address,
            $this->sql->getAdapter(),
            $this->validation
        );
        foreach ($result as $r) {
            if ($validator->isValid($r) === true) {
                $validResults[] = $r;
            }
        }

        return $validResults;
    }

    public function geocodeStreet(AbstractHttpProvider $provider): array
    {
        $address = $this->buildAddress();

        $query = GeocodeQuery::create((new StringFormatter())->format($address, self::FORMAT_STREET));

        $query = $query->withData('streetName', $address->getStreetName());
        $query = $query->withData('locality', $address->getLocality());
        $query = $query->withData('postalCode', $address->getPostalCode());

        $result = (new StatefulGeocoder($provider))->geocodeQuery($query);

        $validResults = [];

        $validator = new AddressCheck(
            $address,
            $this->sql->getAdapter(),
            $this->validation
        );
        foreach ($result as $address) {
            if ($validator->checkPostalCode($address) &&
                $validator->checkLocality($address) &&
                $validator->checkStreetname($address)
            ) {
                $validResults[] = $address;
            }
        }

        return $validResults;
    }

    public function storeSingleResult(AbstractHttpProvider $provider, int $providerPointer, Address $result): void
    {
        $id = $this->addresses[$this->pointer]['id'];
        $address = $this->buildAddress();

        $validator = new AddressCheck(
            $address,
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

        if ($this->doublePass === true && $providerPointer > 0) {
            $data['process_doublepass'] = new Expression(
                'hstore('.
                    'ARRAY['.
                        '\'provider\','.
                        '\'streetname\','.
                        '\'housenumber\','.
                        '\'postalcode\','.
                        '\'locality\','.
                        '\'longitude\','.
                        '\'latitude\''.
                    '],'.
                    'ARRAY[?,?,?,?,?,?,?]'.
                ')',
                [
                    $provider->getName(),
                    $result->getStreetName(),
                    $result->getStreetNumber(),
                    $result->getPostalCode(),
                    $result->getLocality(),
                    $result->getCoordinates()->getLongitude(),
                    $result->getCoordinates()->getLatitude(),
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

    public function storeMultipleResult(AbstractHttpProvider $provider): void
    {
        $id = $this->addresses[$this->pointer]['id'];

        $data = [
            'process_datetime' => date('c'),
            'process_status'   => 2,
            'process_provider' => $provider->getName(),
        ];

        $update = $this->sql->update();
        $update->set($data);
        $update->where(['id' => $id]);

        $this->sql->getAdapter()->query(
            $this->sql->buildSqlString($update),
            $this->sql->getAdapter()::QUERY_MODE_EXECUTE
        );
    }
}
