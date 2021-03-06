<?php

declare(strict_types=1);

namespace App\Handler;

use App\Middleware\ConfigMiddleware;
use App\Middleware\DbAdapterMiddleware;
use Geocoder\Formatter\StringFormatter;
use Geocoder\Model\Address;
use Laminas\Db\Sql\Expression;
use Laminas\Db\Sql\Sql;
use Laminas\Diactoros\Response\HtmlResponse;
use Mezzio\Router\RouterInterface;
use Mezzio\Session\SessionMiddleware;
use Mezzio\Template\TemplateRendererInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class MapHandler implements RequestHandlerInterface
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

        $table = $session->get('table');

        $sql = new Sql($adapter, $table);

        $select = $sql->select();
        $select->columns([
            'id',
            'streetname',
            'housenumber',
            'postalcode',
            'locality',
            'process_status',
            'process_provider',
            'process_address',
            'the_geog' => new Expression('ST_AsGeoJSON("the_geog")'),
        ]);
        $select->where
            ->equalTo('valid', 't')
            ->isNotNull('process_status')
            ->nest()
            ->equalTo('process_status', 1)
            ->or
            ->equalTo('process_status', 9)
            ->unnest();
        $select->order(['postalcode', 'streetname', 'housenumber']);

        $qsz = $sql->buildSqlString($select);
        $resultsGeocoded = $adapter->query($qsz, $adapter::QUERY_MODE_EXECUTE);

        $geojson = [
            'type'     => 'FeatureCollection',
            'features' => [],
        ];
        foreach ($resultsGeocoded as $r) {
            $address = Address::createFromArray([
                'streetNumber' => $r->housenumber,
                'streetName'   => $r->streetname,
                'postalCode'   => $r->postalcode,
                'locality'     => $r->locality,
            ]);

            $formatter = new StringFormatter();

            $geojson['features'][] = [
                'type'       => 'Feature',
                'id'         => $r->id,
                'properties' => [
                    'address'  => $formatter->format($address, '%n %S, %z %L'),
                    'provider' => $r->process_provider,
                ],
                'geometry' => json_decode($r->the_geog),
            ];
        }

        $data = [
            'geojson' => $geojson,
        ];

        return new HtmlResponse($this->template->render('app::map', $data));
    }
}
