<?php

declare(strict_types=1);

namespace App\Handler;

use App\Middleware\ConfigMiddleware;
use App\Middleware\DbAdapterMiddleware;
use App\Validator\Address as AddressValidator;
use Geocoder\Formatter\StringFormatter;
use Geocoder\Model\Address;
use Geocoder\Provider;
use Geocoder\Query\GeocodeQuery;
use Geocoder\StatefulGeocoder;
use Http\Adapter\Guzzle6\Client as Guzzle6Client;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Zend\Db\Adapter\Adapter;
use Zend\Db\Sql\Expression;
use Zend\Db\Sql\Sql;
use Zend\Diactoros\Response\JsonResponse;
use Zend\Expressive\Session\SessionMiddleware;

class GeocodeProcessHandler implements RequestHandlerInterface
{
    public const LIMIT = 25;
    public const RESULT_MULTIPLE = 2;
    public const RESULT_NORESULT = 0;
    public const RESULT_SINGLE = 1;

    public function handle(ServerRequestInterface $request) : ResponseInterface
    {
        $adapter = $request->getAttribute(DbAdapterMiddleware::DBADAPTER_ATTRIBUTE);
        $config = $request->getAttribute(ConfigMiddleware::CONFIG_ATTRIBUTE);
        $session = $request->getAttribute(SessionMiddleware::SESSION_ATTRIBUTE);

        $query = $request->getQueryParams();

        $table = $session->get('table');

        $client = new Guzzle6Client();

        $providers = [
            new Provider\Geo6\Geo6($client, $config['access']['geo6']['consumer'], $config['access']['geo6']['secret']),
            new Provider\UrbIS\UrbIS($client),
            new Provider\Geopunt\Geopunt($client),
            new Provider\SPW\SPW($client),
            new Provider\bpost\bpost($client),
        ];

        $sql = new Sql($adapter, $table);
        $select = $sql->select();
        $select->columns([
            'id',
            'streetname',
            'housenumber',
            'postalcode',
            'locality',
            'validation' => new Expression('hstore_to_json(validation)'),
        ]);
        $select->where
            ->equalTo('valid', 't')
            ->isNull('process_count');
        $select->limit(self::LIMIT);

        $qsz = $sql->buildSqlString($select);
        $results = $adapter->query($qsz, $adapter::QUERY_MODE_EXECUTE);

        if ($results->count() === 0) {
            return new JsonResponse(null);
        } else {
            $data = [
                'count'         => $results->count(),
                'countSingle'   => 0,
                'countMultiple' => 0,
                'countNoResult' => 0,
            ];

            foreach ($results as $r) {
                $validation = !is_null($r->validation) ? json_decode($r->validation) : null;

                $address = Address::createFromArray([
                    'streetNumber' => trim($r->housenumber),
                    'streetName'   => trim($r->streetname),
                    'postalCode'   => trim(
                        isset($validation->postalcode) ?
                            (string) $validation->postalcode :
                            (string) $r->postalcode
                    ),
                    'locality'     => trim(
                        isset($validation->locality) ?
                            $validation->locality :
                            $r->locality
                    ),
                ]);

                $formatter = new StringFormatter();
                $progress = [];
                $noresult = true;

                foreach ($providers as $i => $provider) {
                    switch ($provider->getName()) {
                        case 'geo6':
                            $format = '%n %S, %z %L';
                            break;

                        default:
                            $format = '%S %n, %z %L';
                            break;
                    }

                    $result = self::RESULT_NORESULT;
                    $query = self::geocode($provider, $address, $format, $adapter, $result);

                    $progress[$i] = $query;

                    if ($result === self::RESULT_SINGLE) {
                        $data['countSingle']++;
                        $noresult = false;

                        $update = $sql->update();
                        $update->set($query);
                        $update->where(['id' => $r->id]);

                        $qsz = $sql->buildSqlString($update);
                        $adapter->query($qsz, $adapter::QUERY_MODE_EXECUTE);

                        break;
                    }
                }

                if ($noresult === true) {
                    // Find the first Provider that returned results
                    foreach ($progress as $p => $result) {
                        if ($result['process_count'] > 0) {
                            $data['countMultiple']++;
                            $noresult = false;

                            $update = $sql->update();
                            $update->set($result);
                            $update->where(['id' => $r->id]);

                            $qsz = $sql->buildSqlString($update);
                            $adapter->query($qsz, $adapter::QUERY_MODE_EXECUTE);

                            break;
                        }
                    }
                }

                if ($noresult === true) {
                    $data['countNoResult']++;

                    $update = $sql->update();
                    $update->set([
                        'process_count' => 0,
                    ]);
                    $update->where(['id' => $r->id]);

                    $qsz = $sql->buildSqlString($update);
                    $adapter->query($qsz, $adapter::QUERY_MODE_EXECUTE);
                }
            }

            return new JsonResponse($data);
        }
    }

    private static function geocode($provider, Address $address, string $format, Adapter $adapter, int &$result)
    {
        $formatter = new StringFormatter();

        $query = GeocodeQuery::create($formatter->format($address, $format));

        $query = $query->withData('address', $address);

        $query = $query->withData('streetName', $address->getStreetName());
        $query = $query->withData('streetNumber', $address->getStreetNumber());
        $query = $query->withData('locality', $address->getLocality());
        $query = $query->withData('postalCode', $address->getPostalCode());

        $result = (new StatefulGeocoder($provider))->geocodeQuery($query);
        $count = $result->count();

        $updateData = [
            'process_datetime' => date('c'),
            'process_count'    => 0,
            'process_provider' => $provider->getName(),
        ];

        if ($count >= 1) {
            $validResult = [];
            $validator = new AddressValidator($query->getData('address'), $adapter);
            foreach ($result as $address) {
                if ($validator->isValid($address) === true) {
                    $validResult[] = $address;
                }
            }

            if (count($validResult) === 1) {
                $updateData['process_count'] = 1;
                $updateData['process_address'] = $formatter->format($result->first(), '%S %n, %z %L');
                $updateData['the_geog'] = new Expression(sprintf(
                    'ST_SetSRID(ST_MakePoint(%f, %f), 4326)',
                    $result->first()->getCoordinates()->getLongitude(),
                    $result->first()->getCoordinates()->getLatitude()
                ));

                $result = self::RESULT_SINGLE;
            } else {
                $updateData['process_count'] = count($validResult);

                $result = self::RESULT_MULTIPLE;
            }
        } else {
            $result = self::RESULT_NORESULT;
        }

        return $updateData;
    }
}
