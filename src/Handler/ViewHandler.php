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

        $reset = $sql->update();
        $reset->set([
            'process_count'    => 0,
            'process_provider' => new Expression('NULL'),
            'process_address'  => new Expression('NULL'),
            'the_geog'         => new Expression('NULL'),
        ]);
        $reset->where
            ->equalTo('valid', 't')
            ->isNull('process_address');

        $qsz = $sql->buildSqlString($reset);
        $adapter->query($qsz, $adapter::QUERY_MODE_EXECUTE);

        $select = $sql->select();
        $select->where
            ->equalTo('valid', 't')
            ->isNotNull('process_count')
            ->notEqualTo('process_count', 0);
        $select->order(['postalcode', 'streetname', 'housenumber']);

        $qsz = $sql->buildSqlString($select);
        $resultsGeocoded = $adapter->query($qsz, $adapter::QUERY_MODE_EXECUTE);

        $addressGeocoded = [];
        foreach ($resultsGeocoded as $r) {
            $address = Address::createFromArray([
                'streetNumber' => $r->housenumber,
                'streetName'   => $r->streetname,
                'postalCode'   => (string) $r->postalcode,
                'locality'     => $r->locality,
            ]);

            $formatter = new StringFormatter();

            $diff = Text::renderDiff(Text::diff(
                $formatter->format($address, '%S %n, %z %L'),
                $r->process_address
            ));

            $score = 0;
            if ($r->process_score & 1) { $score++; }
            if ($r->process_score & 2) { $score++; }
            if ($r->process_score & 4) { $score++; }
            if ($r->process_score & 8) { $score++; }

            $addressGeocoded[] = [
                $r->process_provider,
                $diff['old'],
                $diff['new'],
                $score,
                (intval($r->process_count) === -1),
            ];
        }

        $select = $sql->select();
        $select->where
            ->equalTo('valid', 't')
            ->isNotNull('process_count')
            ->equalTo('process_count', 0);
        $select->order(['postalcode', 'streetname', 'housenumber']);

        $qsz = $sql->buildSqlString($select);
        $resultsNotGeocoded = $adapter->query($qsz, $adapter::QUERY_MODE_EXECUTE);

        $addressNotGeocoded = [];
        foreach ($resultsNotGeocoded as $r) {
            $address = Address::createFromArray([
                'streetNumber' => $r->housenumber,
                'streetName'   => $r->streetname,
                'postalCode'   => (string) $r->postalcode,
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
              'postalCode'   => (string) $r->postalcode,
              'locality'     => $r->locality,
            ]);

            $addressInvalid[] = $formatter->format($address, '%S %n, %z %L');
        }

        $data = [
            'title'              => substr($config['name'], strpos($config['name'], '/') + 1),
            'addressGeocoded'    => $addressGeocoded,
            'addressNotGeocoded' => $addressNotGeocoded,
            'addressInvalid'     => $addressInvalid,
        ];

        return new HtmlResponse($this->template->render('app::view', $data));
    }
}
