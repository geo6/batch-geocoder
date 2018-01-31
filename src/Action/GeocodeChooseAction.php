<?php

declare(strict_types=1);

namespace App\Action;

use App\Middleware\ConfigMiddleware;
use App\Middleware\DbAdapterMiddleware;
use Geocoder\Formatter\StringFormatter;
use Geocoder\Model\Address;
use Geocoder\Provider;
use Geocoder\ProviderAggregator;
use Geocoder\Query\GeocodeQuery;
use Http\Adapter\Guzzle6\Client as Guzzle6Client;
use Interop\Http\ServerMiddleware\DelegateInterface;
use Interop\Http\ServerMiddleware\MiddlewareInterface;
use Psr\Http\Message\ServerRequestInterface;
use Zend\Db\Sql\Expression;
use Zend\Db\Sql\Sql;
use Zend\Diactoros\Response\HtmlResponse;
use Zend\Diactoros\Response\RedirectResponse;
use Zend\Expressive\Router\RouterInterface;
use Zend\Expressive\Session\SessionMiddleware;
use Zend\Expressive\Template\TemplateRendererInterface;

class GeocodeChooseAction implements MiddlewareInterface
{
    private $router;
    private $template;

    public function __construct(RouterInterface $router, TemplateRendererInterface $template)
    {
        $this->router = $router;
        $this->template = $template;
    }

    public function process(ServerRequestInterface $request, DelegateInterface $delegate)
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
            $geocoder = new ProviderAggregator();
            $client = new Guzzle6Client();

            $geocoder->registerProviders([
                new Provider\UrbIS\UrbIS($client),
                new Provider\Geopunt\Geopunt($client),
                new Provider\SPW\SPW($client),
                new Provider\bpost\bpost($client),
            ]);
            $providers = array_keys($geocoder->getProviders());

            $result = $results->current();

            $address = Address::createFromArray([
                'streetNumber' => $result->housenumber,
                'streetName'   => str_replace('/', '', $result->streetname), // Issue with SPW service
                'postalCode'   => $result->postalcode,
                'locality'     => $result->locality,
            ]);

            $formatter = new StringFormatter();

            $addresses = [];
            foreach ($providers as $provider) {
                $collection = $geocoder->using($provider)->geocodeQuery(GeocodeQuery::create($formatter->format($address, '%S %n, %z %L')));
                foreach ($collection as $addr) {
                    $providedBy = $addr->getProvidedBy();
                    if (!isset($addresses[$providedBy])) {
                        $addresses[$providedBy] = [];
                    }
                    $addresses[$providedBy][] = [
                        'address' => $formatter->format($addr, '%S %n, %z %L'),
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
