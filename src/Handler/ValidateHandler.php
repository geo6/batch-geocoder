<?php

declare(strict_types=1);

namespace App\Handler;

use App\Middleware\ConfigMiddleware;
use App\Middleware\DbAdapterMiddleware;
use Geo6\Text\Text;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Zend\Db\Adapter\Adapter;
use Zend\Db\Sql\Expression;
use Zend\Db\Sql\Select;
use Zend\Db\Sql\Sql;
use Zend\Diactoros\Response\HtmlResponse;
use Zend\Diactoros\Response\RedirectResponse;
use Zend\Expressive\Router\RouterInterface;
use Zend\Expressive\Session\SessionMiddleware;
use Zend\Expressive\Template\TemplateRendererInterface;

/**
 * @see http://www.bpost.be/site/fr/envoyer/adressage/rechercher-un-code-postal/
 */
class ValidateHandler implements RequestHandlerInterface
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

        $query = $request->getParsedBody();

        if ($config['archives'] === true && isset($query['table'])) {
            $session->set('table', $query['table']);
        }

        $table = $session->get('table');

        $sql = new Sql($adapter, $table);

        if (isset($query['validate']) && is_array($query['validate'])) {
            foreach ($query['validate'] as $postalcode => $validate) {
                foreach ($validate as $locality => $v) {
                    $valid = explode('|', $v);

                    $update = $sql->update();
                    $update->set([
                        'valid'      => new Expression('true'),
                        'validation' => new Expression('hstore(ARRAY[\'postalcode\', ?, \'locality\', ?])', $valid),
                    ]);
                    $update->where([
                        'valid'      => new Expression('false'),
                        'postalcode' => $postalcode,
                        'locality'   => $locality,
                    ]);

                    $qsz = $sql->buildSqlString($update);
                    $adapter->query($qsz, $adapter::QUERY_MODE_EXECUTE);
                }
            }

            return new RedirectResponse($this->router->generateUri('geocode'));
        }

        self::validate($adapter, $table);

        $suggestions = self::getSuggestions($adapter, $table);

        if ($suggestions !== false && !empty($suggestions)) {
            $data = [
                'title'       => substr($config['name'], strpos($config['name'], '/') + 1),
                'table'       => $table,
                'suggestions' => $suggestions,
            ];

            return new HtmlResponse($this->template->render('app::validate', $data));
        }

        return new RedirectResponse($this->router->generateUri('geocode'));
    }

    private static function validate(Adapter $adapter, string $table)
    {
        $sql = new Sql($adapter, $table);

        $update = $sql->update();
        $update->set(['valid' => new Expression('false')]);
        $update->where
            ->isNull(new Expression('validation->\'postalcode\''))
            ->notIn('postalcode', (new Select('validation_bpost'))->columns(['postalcode']));

        $qsz = $sql->buildSqlString($update);
        $adapter->query($qsz, $adapter::QUERY_MODE_EXECUTE);

        $update = $sql->update();
        $update->set(['valid' => new Expression('false')]);
        $update->where
            ->isNull(new Expression('validation->\'locality\''))
            ->notIn(
                new Expression('unaccent(UPPER("locality"))'),
                (new Select(['b' => 'validation_bpost']))->where('postalcode = b."postalcode"')->columns(['normalized'])
            );

        $qsz = $sql->buildSqlString($update);
        $adapter->query($qsz, $adapter::QUERY_MODE_EXECUTE);
    }

    private static function getSuggestions(Adapter $adapter, string $table)
    {
        $sql = new Sql($adapter, $table);

        $list = $sql->select();
        $list->columns(['postalcode', 'locality']);
        $list->where(['valid' => new Expression('false')]);
        $list->group(['postalcode', 'locality']);
        $list->order(['postalcode']);

        $qsz = $sql->buildSqlString($list);
        $results = $adapter->query($qsz, $adapter::QUERY_MODE_EXECUTE);

        if ($results->count() > 0) {
            $suggestions = [];
            $sqlValidation = new Sql($adapter, 'validation_bpost');
            foreach ($results as $r) {
                $suggestion = $sqlValidation->select();
                $suggestion->columns(['postalcode', 'name']);
                $suggestion->where
                    ->equalTo('postalcode', $r->postalcode)
                    ->or
                    ->like('normalized', strtoupper(Text::removeAccents($r->locality)));
                $suggestion->order(['level', 'postalcode']);

                $qsz = $sql->buildSqlString($suggestion);
                $resultsSuggestion = $adapter->query($qsz, $adapter::QUERY_MODE_EXECUTE);

                if (isset($suggestions[$r->postalcode])) {
                    $suggestions[$r->postalcode] = [];
                }
                if (isset($suggestions[$r->postalcode][$r->locality])) {
                    $suggestions[$r->postalcode][$r->locality] = [];
                }
                $suggestions[$r->postalcode][$r->locality] = $resultsSuggestion->toArray();
            }

            return $suggestions;
        }

        return false;
    }
}