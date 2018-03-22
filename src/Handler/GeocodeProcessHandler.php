<?php

declare(strict_types=1);

namespace App\Handler;

use App\Middleware\ConfigMiddleware;
use App\Middleware\DbAdapterMiddleware;
use App\Provider\BatchGeocoderProvider;
use App\Validator\Address as AddressValidator;
use Geocoder\Formatter\StringFormatter;
use Geocoder\Model\Address;
use Geocoder\Provider;
use Geocoder\ProviderAggregator;
use Geocoder\Query\GeocodeQuery;
use Geocoder\StatefulGeocoder;
use Http\Adapter\Guzzle6\Client as Guzzle6Client;
use Locale;
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

        $geocoder = new StatefulGeocoder(new Provider\Addok\Addok($client, 'http://addok.geocode.be/'));
        $geocoderExternal = new ProviderAggregator();
        $chain = new BatchGeocoderProvider([
            new Provider\UrbIS\UrbIS($client),
            new Provider\Geopunt\Geopunt($client),
            new Provider\SPW\SPW($client),
            new Provider\bpost\bpost($client),
        ], $adapter);
        $geocoderExternal->registerProvider($chain);

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
                    'streetNumber' => str_replace('/', '-', $r->housenumber), // Issue with SPW service
                    'streetName'   => str_replace('/', '-', $r->streetname), // Issue with SPW service
                    'postalCode'   => (
                        isset($validation->postalcode) ?
                            (string) $validation->postalcode :
                            (string) $r->postalcode
                    ),
                    'locality'     => isset($validation->locality) ? $validation->locality : $r->locality,
                ]);

                $formatter = new StringFormatter();

                $count = -1;
                $query = self::geocode($geocoder, $address, '%n %S, %z %L', $adapter, $count);
                if ($count === self::RESULT_SINGLE) {
                    $updateData = $query;
                    $data['countSingle']++;
                } else {
                    $countExternal = -1;
                    $queryExternal = self::geocode(
                        $geocoderExternal,
                        $address,
                        '%S %n, %z %L',
                        $adapter,
                        $countExternal
                    );

                    if ($countExternal === self::RESULT_SINGLE) {
                        $updateData = $queryExternal;
                        $data['countSingle']++;
                    } elseif ($count === self::RESULT_MULTIPLE) {
                        $updateData = $query;
                        $data['countMultiple']++;
                    } elseif ($countExternal === self::RESULT_MULTIPLE) {
                        $updateData = $queryExternal;
                        $data['countMultiple']++;
                    } elseif ($countExternal === self::RESULT_NORESULT) {
                        $updateData = $queryExternal;
                        $data['countNoResult']++;
                    }
                }

                $update = $sql->update();
                $update->set($updateData);
                $update->where(['id' => $r->id]);

                $qsz = $sql->buildSqlString($update);
                $adapter->query($qsz, $adapter::QUERY_MODE_EXECUTE);
            }

            return new JsonResponse($data);
        }
    }

    /**
     * @param ProviderAggregator|StatefulGeocoder $geocoder Geocode instance
     */
    private static function geocode($geocoder, Address $address, string $format, Adapter $adapter, int &$result)
    {
        $formatter = new StringFormatter();

        $query = GeocodeQuery::create($formatter->format($address, $format));
        $query = $query->withLocale(Locale::getDefault());
        $query = $query->withData('address', $address);
        $result = $geocoder->geocodeQuery($query);
        $count = $result->count();

        $updateData = [
            'process_datetime' => date('c'),
            'process_count'    => $count,
        ];

        if ($count >= 1) {
            $updateData['process_provider'] = $result->first()->getProvidedBy();

            if ($count === 1) {
                $validator = new AddressValidator($query->getData('address'), $adapter);

                if ($validator->isValid($result->first()) === true) {
                    $updateData['process_address'] = $formatter->format($result->first(), '%S %n, %z %L');
                    $updateData['the_geog'] = new Expression(sprintf(
                        'ST_SetSRID(ST_MakePoint(%f, %f), 4326)',
                        $result->first()->getCoordinates()->getLongitude(),
                        $result->first()->getCoordinates()->getLatitude()
                    ));

                    $result = self::RESULT_SINGLE;
                } else {
                    $result = self::RESULT_MULTIPLE;
                }
            } else {
                $result = self::RESULT_MULTIPLE;
            }
        } else {
            $result = self::RESULT_NORESULT;
        }

        return $updateData;
    }
}
