<?php

declare(strict_types=1);

namespace App\Action;

use App\Middleware\ConfigMiddleware;
use App\Middleware\DbAdapterMiddleware;
use Geo6\Text\Text;
use Geocoder\Formatter\StringFormatter;
use Geocoder\Model\Address;
use Interop\Http\ServerMiddleware\DelegateInterface;
use Interop\Http\ServerMiddleware\MiddlewareInterface;
use Psr\Http\Message\ServerRequestInterface;
use Zend\Db\Sql\Sql;
use Zend\Diactoros\Response\HtmlResponse;
use Zend\Expressive\Router\RouterInterface;
use Zend\Expressive\Session\SessionMiddleware;
use Zend\Expressive\Template\TemplateRendererInterface;

class ViewAction implements MiddlewareInterface
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

        $table = $session->get('table');

        $sql = new Sql($adapter, $table);

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
                'postalCode'   => $r->postalcode,
                'locality'     => $r->locality,
            ]);

            $formatter = new StringFormatter();

            $diff = Text::renderDiff(Text::diff(
                $formatter->format($address, '%n %S, %z %L'),
                $r->process_address
            ));

            $addressGeocoded[] = [
                $r->process_provider,
                $diff['old'],
                $diff['new'],
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
                'postalCode'   => $r->postalcode,
                'locality'     => $r->locality,
            ]);

            $addressNotGeocoded[] = $formatter->format($address, '%n %S, %z %L');
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

            $addressInvalid[] = $formatter->format($address, '%n %S, %z %L');
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
