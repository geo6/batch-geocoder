<?php

declare(strict_types=1);

namespace App\Handler;

use App\Middleware\ConfigMiddleware;
use App\Middleware\DbAdapterMiddleware;
use App\Tools\AddressValidator;
use Geocoder\Formatter\StringFormatter;
use Geocoder\Model\Address;
use Geocoder\Query\GeocodeQuery;
use Geocoder\StatefulGeocoder;
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

    public function handle(ServerRequestInterface $request) : ResponseInterface
    {
        $adapter = $request->getAttribute(DbAdapterMiddleware::DBADAPTER_ATTRIBUTE);
        $config = $request->getAttribute(ConfigMiddleware::CONFIG_ATTRIBUTE);
        $session = $request->getAttribute(SessionMiddleware::SESSION_ATTRIBUTE);

        $query = $request->getQueryParams();

        $table = $session->get('table');

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
            ->isNull('process_status');
        $select->limit(self::LIMIT);
        $qsz = $sql->buildSqlString($select);
        $addresses = $adapter->query($qsz, $adapter::QUERY_MODE_EXECUTE);

        if ($addresses->count() === 0) {
            return new JsonResponse(null);
        } else {
            $data = [
                'count'         => $addresses->count(),
                'countSingle'   => 0,
                'countMultiple' => 0,
                'countNoResult' => 0,
            ];

            foreach ($addresses as $address) {
                $validation = !is_null($address->validation) ? json_decode($address->validation) : null;

                $geocodeAddress = Address::createFromArray([
                    'streetNumber' => trim($address->housenumber),
                    'streetName'   => trim($address->streetname),
                    'postalCode'   => trim(
                        isset($validation->postalcode) ?
                            (string) $validation->postalcode :
                            (string) $address->postalcode
                    ),
                    'locality'     => trim(
                        isset($validation->locality) ?
                            $validation->locality :
                            $address->locality
                    ),
                ]);

                $formatter = new StringFormatter();
                $validator = new AddressValidator($geocodeAddress, $adapter, $config['validation'] ?? true);
                $progress = [];
                $novalidresult = true;

                foreach ($config['providers'] as $i => $provider) {
                    if (is_array($provider)) {
                        if (!in_array($validation->region, $provider[1])) {
                            continue;
                        }

                        $provider = $provider[0];
                    }

                    $rawCount = 0;

                    try {
                        $validResults = self::geocode($provider, $geocodeAddress, '%S %n, %z %L', $adapter, $rawCount);

                        if (count($validResults) === 1) {
                            $data['countSingle']++;

                            $update = $sql->update();
                            $update->set([
                                'process_datetime' => date('c'),
                                'process_status'   => 1,
                                'process_provider' => $provider->getName(),
                                'process_address'  => $formatter->format($validResults[0], '%S %n, %z %L'),
                                'process_score'    => $validator->getScore($validResults[0]),
                                'the_geog'         => new Expression(sprintf(
                                    'ST_SetSRID(ST_MakePoint(%f, %f), 4326)',
                                    $validResults[0]->getCoordinates()->getLongitude(),
                                    $validResults[0]->getCoordinates()->getLatitude()
                                )),
                            ]);
                            $update->where(['id' => $address->id]);

                            $qsz = $sql->buildSqlString($update);
                            $adapter->query($qsz, $adapter::QUERY_MODE_EXECUTE);

                            $novalidresult = false;
                            break;
                        } elseif (count($validResults) > 1) {
                            $exactMatch = [];
                            foreach ($validResults as $validResult) {
                                if ($geocodeAddress->getStreetNumber() === $validResult->getStreetNumber()) {
                                    $exactMatch[] = $validResult;
                                }
                            }

                            if (count($exactMatch) === 1) {
                                $data['countSingle']++;

                                $update = $sql->update();
                                $update->set([
                                    'process_datetime' => date('c'),
                                    'process_status'   => 1,
                                    'process_provider' => $provider->getName(),
                                    'process_address'  => $formatter->format($exactMatch[0], '%S %n, %z %L'),
                                    'process_score'    => $validator->getScore($exactMatch[0]),
                                    'the_geog'         => new Expression(sprintf(
                                        'ST_SetSRID(ST_MakePoint(%f, %f), 4326)',
                                        $exactMatch[0]->getCoordinates()->getLongitude(),
                                        $exactMatch[0]->getCoordinates()->getLatitude()
                                    )),
                                ]);
                                $update->where(['id' => $address->id]);

                                $qsz = $sql->buildSqlString($update);
                                $adapter->query($qsz, $adapter::QUERY_MODE_EXECUTE);
                            } else {
                                $data['countMultiple']++;

                                $update = $sql->update();
                                $update->set([
                                    'process_datetime' => date('c'),
                                    'process_status'   => 2,
                                    'process_provider' => $provider->getName(),
                                ]);
                                $update->where(['id' => $address->id]);

                                $qsz = $sql->buildSqlString($update);
                                $adapter->query($qsz, $adapter::QUERY_MODE_EXECUTE);
                            }

                            $novalidresult = false;
                            break;
                        }
                    } catch (\Geocoder\Exception\InvalidServerResponse $e) {
                        // TODO: add log
                    }

                    $progress[$provider->getName()] = $rawCount;
                }

                if ($novalidresult === true) {
                    $data['countNoResult']++;

                    $update = $sql->update();
                    $update->set([
                        'process_datetime' => date('c'),
                        'process_status'   => array_sum($progress) === 0 ? -1 : 0,
                    ]);
                    $update->where(['id' => $address->id]);

                    $qsz = $sql->buildSqlString($update);
                    $adapter->query($qsz, $adapter::QUERY_MODE_EXECUTE);
                }
            }

            return new JsonResponse($data);
        }
    }

    private static function geocode($provider, Address $address, string $format, Adapter $adapter, int &$rawCount)
    {
        $formatter = new StringFormatter();

        $query = GeocodeQuery::create($formatter->format($address, $format));

        $query = $query->withData('address', $address);

        $query = $query->withData('streetName', $address->getStreetName());
        $query = $query->withData('streetNumber', $address->getStreetNumber());
        $query = $query->withData('locality', $address->getLocality());
        $query = $query->withData('postalCode', $address->getPostalCode());

        $result = (new StatefulGeocoder($provider))->geocodeQuery($query);
        $rawCount = $result->count();

        $validResults = [];

        $validator = new AddressValidator($query->getData('address'), $adapter, $config['validation'] ?? true);
        foreach ($result as $address) {
            if ($validator->isValid($address) === true) {
                $validResults[] = $address;
            }
        }

        return $validResults;
    }
}
