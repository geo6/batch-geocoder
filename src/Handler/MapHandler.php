<?php

declare(strict_types=1);

namespace App\Handler;

use App\Middleware\ConfigMiddleware;
use App\Middleware\DbAdapterMiddleware;
use Geo6\Text\Text;
use Geocoder\Formatter\StringFormatter;
use Geocoder\Model\Address;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Zend\Db\Sql\Expression;
use Zend\Db\Sql\Sql;
use Zend\Diactoros\Response\HtmlResponse;
use Zend\Expressive\Router\RouterInterface;
use Zend\Expressive\Session\SessionMiddleware;
use Zend\Expressive\Template\TemplateRendererInterface;

class MapHandler implements RequestHandlerInterface
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

        $table = $session->get('table');

        $sql = new Sql($adapter, $table);

        $select = $sql->select();
        $select->columns([
            'id',
            'streetname',
            'housenumber',
            'postalcode',
            'locality',
            'process_count',
            'process_provider',
            'process_address',
            'the_geog' => new Expression('ST_AsGeoJSON("the_geog")')
        ]);
        $select->where
            ->equalTo('valid', 't')
            ->isNotNull('process_count')
            ->notEqualTo('process_count', 0);
        $select->order(['postalcode', 'streetname', 'housenumber']);

        $qsz = $sql->buildSqlString($select);
        $resultsGeocoded = $adapter->query($qsz, $adapter::QUERY_MODE_EXECUTE);

        $geojson = [
            'type' => 'FeatureCollection',
            'features' => [],
        ];
        foreach ($resultsGeocoded as $r) {
            $geojson['features'][] = [
                'type' => 'Feature',
                'id' => $r->id,
                'properties' => [
                    'address' => $r->streetname,
                    'provider' => $r->process_provider,
                ],
                'geometry' => json_decode($r->the_geog),
            ];
        }

        $data = [
            'title'   => substr($config['name'], strpos($config['name'], '/') + 1),
            'geojson' => $geojson,
        ];

        return new HtmlResponse($this->template->render('app::map', $data));
    }
}
