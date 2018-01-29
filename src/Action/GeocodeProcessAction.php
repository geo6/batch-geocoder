<?php

namespace App\Action;

use Interop\Http\ServerMiddleware\DelegateInterface;
use Interop\Http\ServerMiddleware\MiddlewareInterface;
use Psr\Http\Message\ServerRequestInterface;
use Zend\Db\Sql\Expression;
use Zend\Db\Sql\Predicate;
use Zend\Db\Sql\Sql;
use Zend\Diactoros\Response\JsonResponse;
use Zend\Expressive\Router\RouterInterface;
use Zend\Expressive\Session\SessionMiddleware;
use Zend\Expressive\Template\TemplateRendererInterface;

use App\Middleware\ConfigMiddleware;
use App\Middleware\DbAdapterMiddleware;

use App\Provider\BatchGeocoderProvider;
use App\Validator\Address as AddressValidator;
use Geocoder\Formatter\StringFormatter;
use Geocoder\Model\Address;
use Geocoder\Model\AdminLevelCollection;
use Geocoder\ProviderAggregator;
use Geocoder\Provider;
use Geocoder\Query\GeocodeQuery;
use Http\Adapter\Guzzle6\Client as Guzzle6Client;
use Locale;

class GeocodeProcessAction implements MiddlewareInterface
{
    public const LIMIT = 25;

    public function process(ServerRequestInterface $request, DelegateInterface $delegate)
    {
        $adapter = $request->getAttribute(DbAdapterMiddleware::DBADAPTER_ATTRIBUTE);
        $config = $request->getAttribute(ConfigMiddleware::CONFIG_ATTRIBUTE);
        $session = $request->getAttribute(SessionMiddleware::SESSION_ATTRIBUTE);

        $query = $request->getQueryParams();

        $table = $session->get('table');

        $sql = new Sql($adapter, $table);

        $geocoder = new ProviderAggregator();
        $client = new Guzzle6Client();

        $chain = new BatchGeocoderProvider([
            new Provider\UrbIS\UrbIS($client),
            new Provider\Geopunt\Geopunt($client),
            new Provider\SPW\SPW($client),
            new Provider\bpost\bpost($client),
        ], $adapter);

        $geocoder->registerProvider($chain);

        $select = $sql->select();
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
                'count' => $results->count(),
                'countSingle' => 0,
                'countMultiple' => 0,
                'countNoResult' => 0,
            ];

            foreach ($results as $r) {
                $address = Address::createFromArray([
                    'streetNumber' => $r->housenumber,
                    'streetName' => $r->streetname,
                    'postalCode' => $r->postalcode,
                    'locality' => $r->locality,
                ]);

                $formatter = new StringFormatter();
                $validated = false;

                $query = GeocodeQuery::create($formatter->format($address, '%S %n, %z %L'));
                $query = $query->withLocale(Locale::getDefault());
                $query = $query->withData('address', $address);
                $result = $geocoder->geocodeQuery($query);
                $count = $result->count();

                $updateData = [
                    'process_datetime' => date('c'),
                    'process_count' => $count,
                ];

                if ($count >= 1) {
                    $updateData['process_provider'] = $result->first()->getProvidedBy();


                    if ($count === 1) {
                        $validator = new AddressValidator($query->getData('address'), $adapter);

                        if ($validator->isValid($result->first()) === true) {
                            $updateData['process_address'] = $formatter->format($result->first(), '%S %n, %z %L');
                            /*$processData['coordinates'] = [
                                $result->first()->getCoordinates()->getLongitude(),
                                $result->first()->getCoordinates()->getLatitude(),
                            ];*/

                            $data['countSingle']++;
                        } else {
                            $data['countMultiple']++;
                        }
                    } else {
                        $data['countMultiple']++;
                    }
                } else {
                    $data['countNoResult']++;
                }

                /*if (isset($coordinates)) {
                    $updateData['the_geog'] = new Expression(sprintf(
                        'ST_MakePoint(%f, %f)',
                        $coordinates->getLongitude(),
                        $coordinates->getLatitude()
                    ));
                }*/

                $update = $sql->update();
                $update->set($updateData);
                $update->where(['id' => $r->id]);

                $qsz = $sql->buildSqlString($update);
                $adapter->query($qsz, $adapter::QUERY_MODE_EXECUTE);
            }

            return new JsonResponse($data);
        }
    }
}
