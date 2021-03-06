<?php

declare(strict_types=1);

namespace App\Handler;

use App\Middleware\ConfigMiddleware;
use App\Middleware\DbAdapterMiddleware;
use App\Tools\AddressCheck;
use Geocoder\Formatter\StringFormatter;
use Geocoder\Model\Address;
use Geocoder\Query\GeocodeQuery;
use Geocoder\StatefulGeocoder;
use Laminas\Db\Sql\Expression;
use Laminas\Db\Sql\Sql;
use Laminas\Diactoros\Response\HtmlResponse;
use Laminas\Diactoros\Response\RedirectResponse;
use Mezzio\Router\RouterInterface;
use Mezzio\Session\SessionMiddleware;
use Mezzio\Template\TemplateRendererInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class GeocodeChooseHandler implements RequestHandlerInterface
{
    private $router;
    private $template;

    public function __construct(RouterInterface $router, TemplateRendererInterface $template)
    {
        $this->router = $router;
        $this->template = $template;
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $adapter = $request->getAttribute(DbAdapterMiddleware::DBADAPTER_ATTRIBUTE);
        $config = $request->getAttribute(ConfigMiddleware::CONFIG_ATTRIBUTE);
        $session = $request->getAttribute(SessionMiddleware::SESSION_ATTRIBUTE);

        $query = $request->getQueryParams();

        $table = $session->get('table');

        $sql = new Sql($adapter, $table);

        if (isset($query['skipall'])) {
            $update = $sql->update();
            $update->set([
                'process_datetime' => date('c'),
                'process_status'   => new Expression('NULL'),
                'process_provider' => new Expression('NULL'),
            ]);
            $update->where
                ->equalTo('valid', 't')
                ->isNotNull('process_status')
                ->nest()
                ->equalTo('process_status', 0)
                ->or
                ->equalTo('process_status', 2)
                ->unnest()
                ->isNull('process_address');

            $qsz = $sql->buildSqlString($update);
            $adapter->query($qsz, $adapter::QUERY_MODE_EXECUTE);

            $session->unset('id');
            $session->unset('addresses');
        } elseif (isset($query['skip'])) {
            $update = $sql->update();
            $update->set([
                'process_datetime' => date('c'),
                'process_status'   => new Expression('NULL'),
                'process_provider' => new Expression('NULL'),
            ]);
            $update->where(['id' => $query['skip']]);

            $qsz = $sql->buildSqlString($update);
            $adapter->query($qsz, $adapter::QUERY_MODE_EXECUTE);

            $session->unset('id');
            $session->unset('addresses');
        } elseif (isset($query['id'], $query['provider'], $query['address']) && $query['id'] === $session->get('id')) {
            $addresses = $session->get('addresses');

            $select = $sql->select();
            $select->where(['id' => $query['id']]);
            $qsz = $sql->buildSqlString($select);
            $r = $adapter->query($qsz, $adapter::QUERY_MODE_EXECUTE)->current();

            $address = Address::createFromArray([
                'streetNumber' => trim($r->housenumber),
                'streetName'   => trim($r->streetname),
                'postalCode'   => trim(
                    isset($validation->postalcode) ?
                        $validation->postalcode :
                        $r->postalcode
                ),
                'locality'     => trim(
                    isset($validation->locality) ?
                        $validation->locality :
                        $r->locality
                ),
            ]);

            if (isset($addresses[$query['provider']], $addresses[$query['provider']][$query['address']])) {
                $validator = new AddressCheck($address, $adapter, false);

                $selection = $addresses[$query['provider']][$query['address']];
                $addr = Address::createFromArray([
                    'streetNumber' => $selection['streetnumber'],
                    'streetName'   => $selection['streetname'],
                    'postalCode'   => $selection['postalcode'],
                    'locality'     => $selection['locality'],
                ]);

                $update = $sql->update();
                $update->set([
                    'process_datetime' => date('c'),
                    'process_status'   => 9,
                    'process_provider' => $query['provider'],
                    'process_address'  => $selection['display'],
                    'process_score'    => $validator->getScore($addr),
                    'the_geog'         => new Expression(
                        'ST_SetSRID(ST_MakePoint(?, ?), 4326)',
                        [
                            $selection['longitude'],
                            $selection['latitude'],
                        ]
                    ),
                ]);
                $update->where(['id' => $query['id']]);

                $qsz = $sql->buildSqlString($update);
                $adapter->query($qsz, $adapter::QUERY_MODE_EXECUTE);
            }

            $session->unset('id');
            $session->unset('addresses');
        } elseif (isset($query['id'], $query['longitude'], $query['latitude']) &&
            $query['id'] === $session->get('id')
        ) {
            $update = $sql->update();
            $update->set([
                'process_datetime' => date('c'),
                'process_status'   => 9,
                'process_provider' => 'manual',
                'process_address'  => '',
                'the_geog'         => new Expression(
                    'ST_SetSRID(ST_MakePoint(?, ?), 4326)',
                    [
                        $query['longitude'],
                        $query['latitude'],
                    ]
                ),
            ]);
            $update->where(['id' => $query['id']]);

            $qsz = $sql->buildSqlString($update);
            $adapter->query($qsz, $adapter::QUERY_MODE_EXECUTE);

            $session->unset('id');
            $session->unset('addresses');
        }

        $select = $sql->select();
        $select->columns([
            'id',
            'streetname',
            'housenumber',
            'postalcode',
            'locality',
            'validation'         => new Expression('hstore_to_json(validation)'),
            'process_doublepass' => isset($config['doublePass']) && $config['doublePass'] === true ? new Expression('hstore_to_json(process_doublepass)') : null,
        ]);
        $select->where
            ->equalTo('valid', 't')
            ->isNotNull('process_status')
            ->nest()
            ->equalTo('process_status', 0)
            ->or
            ->equalTo('process_status', 2)
            ->unnest()
            ->isNull('process_address');
        $select->limit(1);

        $qsz = $sql->buildSqlString($select);
        $results = $adapter->query($qsz, $adapter::QUERY_MODE_EXECUTE);

        if ($results->count() > 0) {
            $result = $results->current();

            $validation = !is_null($result->validation) ? json_decode($result->validation) : null;

            $address = Address::createFromArray([
                'streetNumber' => trim($result->housenumber),
                'streetName'   => trim($result->streetname),
                'postalCode'   => trim(
                    isset($validation->postalcode) ?
                        $validation->postalcode :
                        $result->postalcode
                ),
                'locality'     => trim(
                    isset($validation->locality) ?
                        $validation->locality :
                        $result->locality
                ),
            ]);

            if (isset($config['doublePass']) && $config['doublePass'] === true) {
                $doublePass = !is_null($result->process_doublepass) ? json_decode($result->process_doublepass) : null;

                if (!is_null($doublePass)) {
                    $sourceAddress = $address;

                    $address = Address::createFromArray([
                        'streetNumber' => trim($doublePass->housenumber),
                        'streetName'   => trim($doublePass->streetname),
                        'postalCode'   => trim($doublePass->postalcode),
                        'locality'     => trim($doublePass->locality),
                    ]);
                }
            }

            $formatter = new StringFormatter();

            $addresses = [];

            if (isset($config['providers']['automatic'], $config['providers']['manual'])) {
                $providers = $config['providers']['manual'];
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

                $query = GeocodeQuery::create($formatter->format($address, '%S %n, %z %L'));
                $query = $query->withData('address', $address);

                $query = $query->withData('streetName', $address->getStreetName());
                $query = $query->withData('streetNumber', $address->getStreetNumber());
                $query = $query->withData('locality', $address->getLocality());
                $query = $query->withData('postalCode', $address->getPostalCode());

                try {
                    $collection = (new StatefulGeocoder($provider))->geocodeQuery($query);

                    if ($collection->count() === 0) {
                        $query = GeocodeQuery::create($formatter->format($address, '%S, %z %L'));
                        $query = $query->withData('address', $address);

                        $query = $query->withData('streetName', $address->getStreetName());
                        $query = $query->withData('locality', $address->getLocality());
                        $query = $query->withData('postalCode', $address->getPostalCode());

                        $collection = (new StatefulGeocoder($provider))->geocodeQuery($query);
                    }

                    if ($collection->count() > 0) {
                        $addresses[$provider->getName()] = [];

                        foreach ($collection as $addr) {
                            $addresses[$provider->getName()][] = [
                                'streetname'   => $addr->getStreetName(),
                                'streetnumber' => $addr->getStreetNumber(),
                                'locality'     => $addr->getLocality(),
                                'postalcode'   => $addr->getPostalCode(),
                                'display'      => $formatter->format($addr, '%S %n, %z %L'),
                                'longitude'    => $addr->getCoordinates()->getLongitude(),
                                'latitude'     => $addr->getCoordinates()->getLatitude(),
                            ];
                        }
                    }
                } catch (\Geocoder\Exception\InvalidServerResponse $e) {
                    // TODO : add log
                } catch (\Http\Client\Exception\NetworkException $e) {
                    // TODO: add log
                }
            }

            if (empty($addresses)) {
                $update = $sql->update();
                $update->set([
                    'process_datetime' => date('c'),
                    'process_status'   => 9,
                    'process_provider' => new Expression('NULL'),
                ]);
                $update->where(['id' => $result->id]);

                $qsz = $sql->buildSqlString($update);
                $adapter->query($qsz, $adapter::QUERY_MODE_EXECUTE);

                return new RedirectResponse($this->router->generateUri('geocode.choose'));
            } else {
                $session->set('id', $result->id);
                $session->set('addresses', $addresses);

                $data = [
                    'table'   => $table,
                    'address' => $formatter->format($sourceAddress ?? $address, '%S %n, %z %L'),
                    'id'      => $result->id,
                    'results' => $addresses,
                ];

                return new HtmlResponse($this->template->render('app::geocode-choose', $data));
            }
        } else {
            return new RedirectResponse($this->router->generateUri('view'));
        }
    }
}
