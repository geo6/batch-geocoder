<?php

declare(strict_types=1);

namespace App\Handler;

use App\Middleware\ConfigMiddleware;
use App\Middleware\DbAdapterMiddleware;
use Laminas\Db\Sql\Expression;
use Laminas\Db\Sql\Sql;
use Laminas\Diactoros\Response\HtmlResponse;
use Mezzio\Router\RouterInterface;
use Mezzio\Session\SessionMiddleware;
use Mezzio\Template\TemplateRendererInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class GeocodeHandler implements RequestHandlerInterface
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

        $count = $sql->select();
        $count->columns(['count' => new Expression('COUNT(*)')]);

        $qsz = $sql->buildSqlString($count);
        $resultCount = $adapter->query($qsz, $adapter::QUERY_MODE_EXECUTE)->current();

        $countInvalid = $sql->select();
        $countInvalid->columns(['count' => new Expression('COUNT(*)')]);
        $countInvalid->where
            ->equalTo('valid', 'f');

        $qsz = $sql->buildSqlString($countInvalid);
        $resultInvalid = $adapter->query($qsz, $adapter::QUERY_MODE_EXECUTE)->current();

        if (isset($config['doublePass']) && $config['doublePass'] === true) {
            $countDoublePass = $sql->select();
            $countDoublePass->columns(['count' => new Expression('COUNT(*)')]);
            $countDoublePass->where
                ->isNotNull('process_doublepass');

            $qsz = $sql->buildSqlString($countDoublePass);
            $resultDoublePass = $adapter->query($qsz, $adapter::QUERY_MODE_EXECUTE)->current();
        }

        $resetData = [
            'process_datetime' => new Expression('NULL'),
            'process_status'   => new Expression('NULL'),
            'process_provider' => new Expression('NULL'),
            'process_address'  => new Expression('NULL'),
            'process_score'    => new Expression('NULL'),
            'the_geog'         => new Expression('NULL'),
        ];

        if (isset($config['doublePass']) && $config['doublePass'] === true) {
            $resetData['process_doublepass'] = new Expression('NULL');
        }

        if (isset($query['reset'])) {
            $reset = $sql->update();
            $reset->set($resetData);

            $qsz = $sql->buildSqlString($reset);
            $adapter->query($qsz, $adapter::QUERY_MODE_EXECUTE);
        } elseif (isset($query['launch'])) {
            $reset = $sql->update();
            $reset->set($resetData);
            $reset->where
                ->equalTo('valid', 't')
                ->isNull('process_address');

            $qsz = $sql->buildSqlString($reset);
            $adapter->query($qsz, $adapter::QUERY_MODE_EXECUTE);
        }

        $countGeocoded = $sql->select();
        $countGeocoded->columns(['count' => new Expression('COUNT(*)')]);
        $countGeocoded->where
            ->equalTo('valid', 't')
            ->isNotNull('process_status')
            ->isNotNull('process_address');

        $qsz = $sql->buildSqlString($countGeocoded);
        $resultGeocoded = $adapter->query($qsz, $adapter::QUERY_MODE_EXECUTE)->current();

        $data = [
            'table'            => $table,
            'count'            => $resultCount->count,
            'countInvalid'     => $resultInvalid->count,
            'countGeocoded'    => $resultGeocoded->count,
            'countNotGeocoded' => ($resultCount->count - ($resultInvalid->count + $resultGeocoded->count)),
            'launch'           => (isset($query['launch'])),
            'doublePass'       => (isset($config['doublePass']) && $config['doublePass'] === true),
        ];

        return new HtmlResponse($this->template->render('app::geocode', $data));
    }
}
