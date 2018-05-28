<?php

declare(strict_types=1);

namespace App\Handler;

use App\Middleware\ConfigMiddleware;
use App\Middleware\DbAdapterMiddleware;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Zend\Db\Sql\Expression;
use Zend\Db\Sql\Sql;
use Zend\Diactoros\Response\EmptyResponse;
use Zend\Diactoros\Response\JsonResponse;
use Zend\Diactoros\Response\TextResponse;
use Zend\Expressive\Session\SessionMiddleware;

class ExportHandler implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request) : ResponseInterface
    {
        $adapter = $request->getAttribute(DbAdapterMiddleware::DBADAPTER_ATTRIBUTE);
        $config = $request->getAttribute(ConfigMiddleware::CONFIG_ATTRIBUTE);
        $session = $request->getAttribute(SessionMiddleware::SESSION_ATTRIBUTE);

        $table = $session->get('table');
        $type = $request->getAttribute('type');

        $sql = new Sql($adapter, $table);

        $select = $sql->select();
        $select->columns([
            'id',
            'streetname',
            'housenumber',
            'postalcode',
            'locality',
            'validation' => new Expression('hstore_to_json(validation)'),
            'process_address',
            'process_score',
            'process_provider',
            'longitude' => new Expression('ST_X(the_geog::geometry)'),
            'latitude'  => new Expression('ST_Y(the_geog::geometry)'),
        ]);
        $select->where
            ->equalTo('valid', 't')
            ->isNotNull('process_count')
            ->notEqualTo('process_count', 0);

        $qsz = $sql->buildSqlString($select);
        $resultsGeocoded = $adapter->query($qsz, $adapter::QUERY_MODE_EXECUTE);

        switch ($type) {
            case 'csv':
                $text = self::str_putcsv([
                    'id',
                    'streetname',
                    'housenumber',
                    'postalcode',
                    'locality',
                    'process_address',
                    'process_score',
                    'process_provider',
                    'longitude',
                    'latitude',
                ]).PHP_EOL;

                foreach ($resultsGeocoded as $result) {
                    $text .= self::str_putcsv([
                        $result->id,
                        $result->streetname,
                        $result->housenumber,
                        $validation->postalcode ?? $result->postalcode,
                        $validation->locality ?? $result->locality,
                        $result->process_address,
                        $result->process_score,
                        $result->process_provider,
                        $result->longitude,
                        $result->latitude,
                    ]).PHP_EOL;
                }

                return (new TextResponse($text))->
                    withHeader('Content-Disposition', 'attachment; filename=batch-geocoder.csv')->
                    withHeader('Pragma', 'no-cache')->
                    withHeader('Expires', '0')->
                    withHeader('Cache-Control', 'no-cache, must-revalidate');
                break;

            case 'geojson':
                $geojson = [
                    'type'     => 'FeatureCollection',
                    'features' => [],
                ];

                foreach ($resultsGeocoded as $result) {
                    $validation = !is_null($result->validation) ? json_decode($result->validation) : null;

                    $feature = [
                        'type'       => 'Feature',
                        'id'         => $result->id,
                        'properties' => [
                            'id'               => $result->id,
                            'streetname'       => $result->streetname,
                            'housenumber'      => $result->housenumber,
                            'postalcode'       => $validation->postalcode ?? $result->postalcode,
                            'locality'         => $validation->locality ?? $result->locality,
                            'process_address'  => $result->process_address,
                            'process_score'    => $result->process_score,
                            'process_provider' => $result->process_provider,
                        ],
                        'geometry' => [
                            'type'        => 'Point',
                            'coordinates' => [
                                round($result->longitude, 6),
                                round($result->latitude, 6),
                            ],
                        ],
                    ];

                    $geojson['features'][] = $feature;
                }

                return (new JsonResponse($geojson))->
                    withHeader('Content-Disposition', 'attachment; filename=batch-geocoder.json')->
                    withHeader('Pragma', 'no-cache')->
                    withHeader('Expires', '0')->
                    withHeader('Cache-Control', 'no-cache, must-revalidate');
                break;

            default:
                return (new EmptyResponse())->withStatus(400);
                break;
        }
    }

    private static function str_putcsv($input, $delimiter = ',', $enclosure = '"')
    {
        $fp = fopen('php://memory', 'r+b');
        fputcsv($fp, $input, $delimiter, $enclosure);
        rewind($fp);
        $data = stream_get_contents($fp);
        fclose($fp);

        return rtrim($data, "\n");
    }
}
