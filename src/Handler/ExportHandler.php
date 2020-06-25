<?php

declare(strict_types=1);

namespace App\Handler;

use App\Middleware\ConfigMiddleware;
use App\Middleware\DbAdapterMiddleware;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Laminas\Db\ResultSet\ResultSet;
use Laminas\Db\Sql\Expression;
use Laminas\Db\Sql\Sql;
use Laminas\Diactoros\Response;
use Laminas\Diactoros\Response\EmptyResponse;
use Laminas\Diactoros\Response\JsonResponse;
use Laminas\Diactoros\Response\TextResponse;
use Laminas\Diactoros\Stream;
use Mezzio\Session\SessionMiddleware;

class ExportHandler implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
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

        $qsz = $sql->buildSqlString($select);
        $results = $adapter->query($qsz, $adapter::QUERY_MODE_EXECUTE);

        switch ($type) {
            case 'csv':
                return self::exportCSV($results)->
                    withHeader('Pragma', 'no-cache')->
                    withHeader('Expires', '0')->
                    withHeader('Cache-Control', 'no-cache, must-revalidate');
                break;
            case 'geojson':
                return self::exportGeoJSON($results)->
                    withHeader('Pragma', 'no-cache')->
                    withHeader('Expires', '0')->
                    withHeader('Cache-Control', 'no-cache, must-revalidate');
                break;
            case 'xlsx':
                return self::exportXLSX($results)->
                    withHeader('Pragma', 'no-cache')->
                    withHeader('Expires', '0')->
                    withHeader('Cache-Control', 'no-cache, must-revalidate');
                break;

            default:
                return (new EmptyResponse())->withStatus(400);
                break;
        }
    }

    private static function str_putcsv(array $input, string $delimiter = ',', string $enclosure = '"'): string
    {
        $fp = fopen('php://memory', 'r+b');
        fputcsv($fp, $input, $delimiter, $enclosure);
        rewind($fp);
        $data = stream_get_contents($fp);
        fclose($fp);

        return rtrim($data, "\n");
    }

    private static function exportCSV(ResultSet $results): TextResponse
    {
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

        foreach ($results as $result) {
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
            withHeader('Content-Disposition', 'attachment; filename=batch-geocoder.csv');
    }

    private static function exportGeoJSON(ResultSet $results): JsonResponse
    {
        $geojson = [
            'type'     => 'FeatureCollection',
            'features' => [],
        ];

        foreach ($results as $i => $result) {
            $validation = !is_null($result->validation) ? json_decode($result->validation) : null;

            $geometry = null;
            if (!is_null($result->longitude) && !is_null($result->latitude)) {
                $geometry = [
                    'type'        => 'Point',
                    'coordinates' => [
                        round($result->longitude, 6),
                        round($result->latitude, 6),
                    ],
                ];
            }

            $feature = [
                'type'       => 'Feature',
                'id'         => ($i + 1),
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
                'geometry' => $geometry,
            ];

            $geojson['features'][] = $feature;
        }

        return (new JsonResponse($geojson))->
            withHeader('Content-Disposition', 'attachment; filename=batch-geocoder.json');
    }

    private static function exportXLSX(ResultSet $results): Response
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray([
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
        ], null, 'A1');

        foreach ($results as $i => $result) {
            $sheet->fromArray([
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
            ], null, 'A'.($i + 2));
        }

        /*$spreadsheet->getActiveSheet()->setAutoFilter(
            $spreadsheet->getActiveSheet()
                ->calculateWorksheetDimension()
        );*/

        for ($c = 65; $c <= 74; $c++) {
            $spreadsheet->getActiveSheet()->getColumnDimension(chr($c))->setAutoSize(true);
            $sheet->getStyle(chr($c).'1')->getFont()->setBold(true);
        }

        $file = 'data/upload/batch-geocoder.xlsx';

        $writer = new Xlsx($spreadsheet);
        $writer->save($file);

        $body = new Stream('php://temp', 'w+');
        $body->write(file_get_contents($file));

        unlink($file);

        return (new Response($body))->
            withHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')->
            withHeader('Content-Disposition', 'attachment; filename=batch-geocoder.xlsx');
    }
}
