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
use Zend\Expressive\Flash\FlashMessageMiddleware;
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
        $flashMessages = $request->getAttribute(FlashMessageMiddleware::FLASH_ATTRIBUTE);

        $adapter = $request->getAttribute(DbAdapterMiddleware::DBADAPTER_ATTRIBUTE);
        $config = $request->getAttribute(ConfigMiddleware::CONFIG_ATTRIBUTE);
        $session = $request->getAttribute(SessionMiddleware::SESSION_ATTRIBUTE);

        $query = $request->getParsedBody();

        $table = $session->get('table');

        $sql = new Sql($adapter, $table);

        if (isset($query['validate']) && is_array($query['validate'])) {
            foreach ($query['validate'] as $postalcode => $validate) {
                foreach ($validate as $locality => $v) {
                    $valid = explode('|', $v);

                    $update = $sql->update();
                    $update->set([
                        'valid'      => new Expression('true'),
                        'validation' => new Expression('hstore(ARRAY[\'postalcode\', ?, \'locality\', ?, \'region\', ?])', $valid),
                    ]);
                    if ($postalcode === 'null') {
                        $update->where
                            ->equalTo('valid', new Expression('false'))
                            ->isNull('postalcode')
                            ->equalTo('locality', $locality);
                    } elseif ($locality === 'null') {
                        $update->where
                            ->equalTo('valid', new Expression('false'))
                            ->equalTo('postalcode', $postalcode)
                            ->isNull('locality');
                    } else {
                        $update->where
                            ->equalTo('valid', new Expression('false'))
                            ->equalTo('postalcode', $postalcode)
                            ->equalTo('locality', $locality);
                    }

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

        // Check NULL for postal code and locality
        $update = $sql->update();
        $update->set(['valid' => new Expression('false')]);
        $update->where
            ->isNull('process_status')
            ->nest()
            ->isNull('postalcode')
            ->or
            ->isNull('locality')
            ->unnest();
        $qsz = $sql->buildSqlString($update);
        $adapter->query($qsz, $adapter::QUERY_MODE_EXECUTE);

        // Check postal code
        $update = $sql->update();
        $update->set(['valid' => new Expression('false')]);
        $update->where
            ->isNull('process_status')
            ->isNull(new Expression('validation->\'postalcode\''))
            ->notIn('postalcode', (new Select('validation_bpost'))->columns(['postalcode']));
        $qsz = $sql->buildSqlString($update);
        $adapter->query($qsz, $adapter::QUERY_MODE_EXECUTE);

        // Check if locality name match postal code
        $update = $sql->update();
        $update->set(['valid' => new Expression('false')]);
        $update->where
            ->isNull('process_status')
            ->isNull(new Expression('"validation"->\'locality\''))
            ->notIn(
                new Expression('unaccent(UPPER("locality"))'),
                (new Select('validation_bpost'))->where(
                    $adapter->getPlatform()->quoteIdentifierChain([$table, 'postalcode']).' = '.
                    $adapter->getPlatform()->quoteIdentifierChain(['validation_bpost', 'postalcode'])
                )->columns(['normalized'])
            );
        $qsz = $sql->buildSqlString($update);
        $adapter->query($qsz, $adapter::QUERY_MODE_EXECUTE);

        // Try to find correct region
        $update = $sql->update();
        $update->set([
            'validation' => new Expression('hstore(\'region\', (SELECT region FROM validation_bpost v WHERE postalcode = v.postalcode AND unaccent(UPPER(locality)) = v.normalized LIMIT 1))', ['test']),
        ]);
        $update->where
            ->isNull('process_status')
            ->equalTo('valid', 't');
        $qsz = $sql->buildSqlString($update);
        $adapter->query($qsz, $adapter::QUERY_MODE_EXECUTE);
    }

    private static function getSuggestions(Adapter $adapter, string $table)
    {
        $sql = new Sql($adapter, $table);

        $list = $sql->select();
        $list->columns([
            'postalcode',
            'locality',
            'count' => new Expression('COUNT(*)'),
            'list'  => new Expression('string_agg(CONCAT("streetname", \' \', "housenumber", \', \', "postalcode", \' \', "locality"), \''.PHP_EOL.'\')'),
        ]);
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
                $suggestion->columns(['postalcode', 'name', 'region']);
                $suggestion->where
                    ->equalTo('postalcode', $r->postalcode)
                    ->or
                    ->like('normalized', strtoupper(Text::removeAccents($r->locality ?? '')));
                $suggestion->order(['postalcode', 'level']);

                $qsz = $sql->buildSqlString($suggestion);
                $resultsSuggestion = $adapter->query($qsz, $adapter::QUERY_MODE_EXECUTE);

                if ($resultsSuggestion->count() > 0) {
                    if (!isset($suggestions[$r->postalcode])) {
                        $suggestions[$r->postalcode] = [];
                    }
                    if (!isset($suggestions[$r->postalcode][$r->locality])) {
                        $suggestions[$r->postalcode][$r->locality] = [];
                    }
                    $suggestions[$r->postalcode][$r->locality] = [
                        'count'       => $r->count,
                        'list'        => $r->list,
                        'suggestions' => $resultsSuggestion->toArray(),
                    ];
                }
            }

            return $suggestions;
        }

        return false;
    }
}
