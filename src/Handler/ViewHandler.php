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

class ViewHandler implements RequestHandlerInterface
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
            'validation' => new Expression('hstore_to_json(validation)'),
            'process_address',
            'process_status',
            'process_score',
            'process_provider',
            'process_doublepass' => isset($config['doublePass']) && $config['doublePass'] === true ? new Expression('hstore_to_json(process_doublepass)') : null,
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

        $addressGeocoded = [];
        foreach ($resultsGeocoded as $r) {
            $validation = !is_null($r->validation) ? json_decode($r->validation) : null;
            $doublePass = !is_null($r->process_doublepass) ? json_decode($r->process_doublepass) : null;

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

            $formatter = new StringFormatter();

            $diff = Text::renderDiff(Text::diff(
                $formatter->format($address, '%S %n, %z %L'),
                $r->process_address
            ));

            $score = 0;
            if ($r->process_score & 1) {
                $score++;
            }
            if ($r->process_score & 2) {
                $score++;
            }
            if ($r->process_score & 4) {
                $score++;
            }
            if ($r->process_score & 8) {
                $score++;
            }

            $addressGeocoded[] = [
                isset($doublePass->provider) ? $doublePass->provider.' + '.$r->process_provider : $r->process_provider,
                $diff['old'],
                $diff['new'],
                $score,
                (intval($r->process_status) === 9),
            ];
        }

        $select = $sql->select();
        $select->where
            ->equalTo('valid', 't')
            ->nest()
            ->isNull('process_status')
            ->or
            ->equalTo('process_status', -1)
            ->unnest();
        $select->order(['postalcode', 'streetname', 'housenumber']);

        $qsz = $sql->buildSqlString($select);
        $resultsNotGeocoded = $adapter->query($qsz, $adapter::QUERY_MODE_EXECUTE);

        $addressNotGeocoded = [];
        foreach ($resultsNotGeocoded as $r) {
            $address = Address::createFromArray([
                'streetNumber' => $r->housenumber,
                'streetName'   => $r->streetname,
                'postalCode'   => $r->postalcode,
                'locality'     => $r->locality,
            ]);

            $addressNotGeocoded[] = $formatter->format($address, '%S %n, %z %L');
        }

        $select = $sql->select();
        $select->where
            ->equalTo('valid', 'f');
        $select->order(['postalcode', 'streetname', 'housenumber']);

        $qsz = $sql->buildSqlString($select);
        $resultsInvalid = $adapter->query($qsz, $adapter::QUERY_MODE_EXECUTE);

        $addressInvalid = [];
        foreach ($resultsInvalid as $r) {
            $address = Address::createFromArray([
              'streetNumber' => $r->housenumber,
              'streetName'   => $r->streetname,
              'postalCode'   => $r->postalcode,
              'locality'     => $r->locality,
            ]);

            $addressInvalid[] = $formatter->format($address, '%S %n, %z %L');
        }

        $data = [
            'addressGeocoded'    => $addressGeocoded,
            'addressNotGeocoded' => $addressNotGeocoded,
            'addressInvalid'     => $addressInvalid,
        ];

        return new HtmlResponse($this->template->render('app::view', $data));
    }
}
