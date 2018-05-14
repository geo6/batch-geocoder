<?php

declare(strict_types=1);

namespace App\Handler;

use App\Middleware\ConfigMiddleware;
use App\Middleware\DbAdapterMiddleware;
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
use Zend\Db\Sql\Expression;
use Zend\Db\Sql\Sql;
use Zend\Diactoros\Response\HtmlResponse;
use Zend\Diactoros\Response\RedirectResponse;
use Zend\Expressive\Router\RouterInterface;
use Zend\Expressive\Session\SessionMiddleware;
use Zend\Expressive\Template\TemplateRendererInterface;

class GeocodeChooseHandler implements RequestHandlerInterface
{
    private $router;
    private $template;

    public function __construct(RouterInterface $router, TemplateRendererInterface $template)
    {
        $this->router = $router;
        $this->template = $template;
    }

    public function handle(ServerRequestInterface $request) : ResponseInterface
    {
        $adapter = $request->getAttribute(DbAdapterMiddleware::DBADAPTER_ATTRIBUTE);
        $config = $request->getAttribute(ConfigMiddleware::CONFIG_ATTRIBUTE);
        $session = $request->getAttribute(SessionMiddleware::SESSION_ATTRIBUTE);

        $query = $request->getQueryParams();

        $table = $session->get('table');

        $sql = new Sql($adapter, $table);

        if (isset($query['skip'])) {
            $update = $sql->update();
            $update->set([
                'process_datetime' => date('c'),
                'process_count'    => 0,
                'process_provider' => new Expression('NULL'),
            ]);
            $update->where(['id' => $query['skip']]);

            $qsz = $sql->buildSqlString($update);
            $adapter->query($qsz, $adapter::QUERY_MODE_EXECUTE);

            $session->unset('id');
            $session->unset('addresses');
        } elseif (isset($query['id'], $query['provider'], $query['address']) && $query['id'] === $session->get('id')) {
            $addresses = $session->get('addresses');

            if (isset($addresses[$query['provider']], $addresses[$query['provider']][$query['address']])) {
                $address = $addresses[$query['provider']][$query['address']];

                $update = $sql->update();
                $update->set([
                    'process_datetime' => date('c'),
                    'process_count'    => -1,
                    'process_provider' => $query['provider'],
                    'process_address'  => $address['address'],
                    'the_geog'         => new Expression(sprintf(
                        'ST_SetSRID(ST_MakePoint(%f, %f), 4326)',
                        $address['longitude'],
                        $address['latitude']
                    )),
                ]);
                $update->where(['id' => $query['id']]);

                $qsz = $sql->buildSqlString($update);
                $adapter->query($qsz, $adapter::QUERY_MODE_EXECUTE);
            }

            $session->unset('id');
            $session->unset('addresses');
        }

        $select = $sql->select();
        $select->where
            ->equalTo('valid', 't')
            ->isNotNull('process_count')
            ->greaterThan('process_count', 0)
            ->isNull('process_address');
        $select->limit(1);

        $qsz = $sql->buildSqlString($select);
        $results = $adapter->query($qsz, $adapter::QUERY_MODE_EXECUTE);

        if ($results->count() > 0) {
            $client = new Guzzle6Client();

            $geocoderExternal = new ProviderAggregator();
            $geocoderExternal->registerProviders([
                new Provider\UrbIS\UrbIS($client),
                new Provider\Geopunt\Geopunt($client),
                new Provider\SPW\SPW($client),
                new Provider\bpost\bpost($client),
            ]);
            $providers = array_keys($geocoderExternal->getProviders());

            $result = $results->current();

            $address = Address::createFromArray([
                'streetNumber' => trim($result->housenumber),
                'streetName'   => trim($result->streetname),
                'postalCode'   => trim(
                    isset($validation->postalcode) ?
                        (string) $validation->postalcode :
                        (string) $result->postalcode
                ),
                'locality'     => trim(
                    isset($validation->locality) ?
                        $validation->locality :
                        $result->locality
                ),
            ]);

            $formatter = new StringFormatter();

            $addresses = [];

            $geocoder = new StatefulGeocoder(new Provider\Geo6\Geo6($client, $config['access']['geo6']['consumer'], $config['access']['geo6']['secret']));

            $query = GeocodeQuery::create($formatter->format($address, '%n %S, %z %L'));
            $query = $query->withLocale(Locale::getDefault());
            $query = $query->withData('address', $address);

            $query = $query->withData('streetName', $address->getStreetName());
            $query = $query->withData('streetNumber', $address->getStreetNumber());
            $query = $query->withData('locality', $address->getLocality());
            $query = $query->withData('postalCode', $address->getPostalCode());

            $collection = $geocoder->geocodeQuery($query);
            foreach ($collection as $addr) {
                $providedBy = $addr->getProvidedBy();
                if (!isset($addresses[$providedBy])) {
                    $addresses[$providedBy] = [];
                }
                $addresses[$providedBy][] = [
                    'address'   => $formatter->format($addr, '%S %n, %z %L'),
                    'longitude' => $addr->getCoordinates()->getLongitude(),
                    'latitude'  => $addr->getCoordinates()->getLatitude(),
                ];
            }

            foreach ($providers as $provider) {
                $collection = $geocoderExternal
                    ->using($provider)
                    ->geocodeQuery(GeocodeQuery::create($formatter->format($address, '%S %n, %z %L')));
                foreach ($collection as $addr) {
                    $providedBy = $addr->getProvidedBy();
                    if (!isset($addresses[$providedBy])) {
                        $addresses[$providedBy] = [];
                    }
                    $addresses[$providedBy][] = [
                        'address'   => $formatter->format($addr, '%S %n, %z %L'),
                        'longitude' => $addr->getCoordinates()->getLongitude(),
                        'latitude'  => $addr->getCoordinates()->getLatitude(),
                    ];
                }
            }

            $session->set('id', $result->id);
            $session->set('addresses', $addresses);

            $data = [
                'title'   => substr($config['name'], strpos($config['name'], '/') + 1),
                'table'   => $table,
                'address' => $formatter->format($address, '%S %n, %z %L'),
                'id'      => $result->id,
                'results' => $addresses,
            ];

            return new HtmlResponse($this->template->render('app::geocode-choose', $data));
        } else {
            return new RedirectResponse($this->router->generateUri('view'));
        }
    }
}
