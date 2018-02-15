<?php

declare(strict_types=1);

namespace App\Action;

use App\Middleware\ConfigMiddleware;
use App\Middleware\DbAdapterMiddleware;
use Interop\Http\ServerMiddleware\DelegateInterface;
use Interop\Http\ServerMiddleware\MiddlewareInterface;
use Psr\Http\Message\ServerRequestInterface;
use Zend\Db\Sql\Expression;
use Zend\Db\Sql\Sql;
use Zend\Diactoros\Response\HtmlResponse;
use Zend\Expressive\Router\RouterInterface;
use Zend\Expressive\Session\SessionMiddleware;
use Zend\Expressive\Template\TemplateRendererInterface;

class GeocodeAction implements MiddlewareInterface
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

        if (isset($query['reset'])) {
            $reset = $sql->update();
            $reset->set([
                'process_datetime' => new Expression('NULL'),
                'process_count'    => new Expression('NULL'),
                'process_provider' => new Expression('NULL'),
                'process_address'  => new Expression('NULL'),
                'the_geog'         => new Expression('NULL'),
            ]);

            $qsz = $sql->buildSqlString($reset);
            $adapter->query($qsz, $adapter::QUERY_MODE_EXECUTE);
        } elseif (isset($query['launch'])) {
            $reset = $sql->update();
            $reset->set([
                'process_datetime' => new Expression('NULL'),
                'process_count'    => new Expression('NULL'),
                'process_provider' => new Expression('NULL'),
                'process_address'  => new Expression('NULL'),
                'the_geog'         => new Expression('NULL'),
            ]);
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
            ->isNotNull('process_count')
            ->isNotNull('process_address');

        $qsz = $sql->buildSqlString($countGeocoded);
        $resultGeocoded = $adapter->query($qsz, $adapter::QUERY_MODE_EXECUTE)->current();

        $data = [
            'title'            => substr($config['name'], strpos($config['name'], '/') + 1),
            'table'            => $table,
            'count'            => $resultCount->count,
            'countInvalid'     => $resultInvalid->count,
            'countGeocoded'    => $resultGeocoded->count,
            'countNotGeocoded' => ($resultCount->count - ($resultInvalid->count + $resultGeocoded->count)),
            'launch'           => (isset($query['launch'])),
        ];

        return new HtmlResponse($this->template->render('app::geocode', $data));
    }
}
