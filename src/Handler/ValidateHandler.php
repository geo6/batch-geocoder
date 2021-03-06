<?php

declare(strict_types=1);

namespace App\Handler;

use App\Middleware\ConfigMiddleware;
use App\Middleware\DbAdapterMiddleware;
use Geo6\Text\Text;
use Laminas\Db\Sql\Expression;
use Laminas\Db\Sql\Select;
use Laminas\Db\Sql\Sql;
use Laminas\Diactoros\Response\HtmlResponse;
use Laminas\Diactoros\Response\RedirectResponse;
use Mezzio\Flash\FlashMessageMiddleware;
use Mezzio\Router\RouterInterface;
use Mezzio\Session\SessionMiddleware;
use Mezzio\Template\TemplateRendererInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * @see http://www.bpost.be/site/fr/envoyer/adressage/rechercher-un-code-postal/
 */
class ValidateHandler implements RequestHandlerInterface
{
    private $router;
    private $session;
    private $table;
    private $template;

    public function __construct(RouterInterface $router, TemplateRendererInterface $template)
    {
        $this->router = $router;
        $this->template = $template;
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $flashMessages = $request->getAttribute(FlashMessageMiddleware::FLASH_ATTRIBUTE);
        $config = $request->getAttribute(ConfigMiddleware::CONFIG_ATTRIBUTE);

        $this->adapter = $request->getAttribute(DbAdapterMiddleware::DBADAPTER_ATTRIBUTE);
        $this->session = $request->getAttribute(SessionMiddleware::SESSION_ATTRIBUTE);

        $query = $request->getParsedBody();

        $this->table = $this->session->get('table');

        $sql = new Sql($this->adapter, $this->table);

        if (isset($query['validate']) && is_array($query['validate'])) {
            $suggestions = $this->session->get('suggestions');

            foreach ($query['validate'] as $postalcode => $validate) {
                foreach ($validate as $locality => $v) {
                    $values = $suggestions[$postalcode][$locality]['suggestions'][$v];

                    $update = $sql->update();
                    $update->set([
                        'valid'      => new Expression('true'),
                        'validation' => new Expression(
                            'hstore('.
                                'ARRAY[\'postalcode\', \'locality\', \'region\', \'nis5\', \'municipality\'],'.
                                'ARRAY[?, ?, ?, ?, ?]'.
                            ')',
                            [
                                $values['postalcode'],
                                $values['name'],
                                $values['region'],
                                $values['nis5'],
                                $values['municipality'],
                            ]
                        ),
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
                    $this->adapter->query($qsz, $this->adapter::QUERY_MODE_EXECUTE);
                }
            }

            $this->session->unset('suggestions');

            return new RedirectResponse($this->router->generateUri('geocode'));
        }

        $this->validate();

        $suggestions = $this->getSuggestions();

        if (!empty($suggestions)) {
            $this->session->set('suggestions', $suggestions);

            $data = [
                'table'       => $this->table,
                'suggestions' => $suggestions,
            ];

            return new HtmlResponse($this->template->render('app::validate', $data));
        }

        return new RedirectResponse($this->router->generateUri('geocode'));
    }

    private function validate()
    {
        $sql = new Sql($this->adapter, $this->table);

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
        $this->adapter->query($qsz, $this->adapter::QUERY_MODE_EXECUTE);

        // Check postal code
        $update = $sql->update();
        $update->set(['valid' => new Expression('false')]);
        $update->where
            ->isNull('process_status')
            ->isNull(new Expression('validation->\'postalcode\''))
            ->notIn('postalcode', (new Select('validation'))->columns(['postalcode']));
        $qsz = $sql->buildSqlString($update);
        $this->adapter->query($qsz, $this->adapter::QUERY_MODE_EXECUTE);

        // Check if locality name match postal code
        $update = $sql->update();
        $update->set(['valid' => new Expression('false')]);
        $update->where
            ->isNull('process_status')
            ->isNull('validation')
            ->notIn(
                new Expression('unaccent(UPPER("locality"))'),
                (new Select('validation'))->where(
                    $this->adapter->getPlatform()->quoteIdentifierChain([$this->table, 'postalcode']).' = '.
                    $this->adapter->getPlatform()->quoteIdentifierChain(['validation', 'postalcode'])
                )->columns(['normalized'])
            );
        $qsz = $sql->buildSqlString($update);
        $this->adapter->query($qsz, $this->adapter::QUERY_MODE_EXECUTE);

        // Try to find correct region
        $update = $sql->update();
        $update->set([
            'validation' => new Expression('(SELECT hstore(ARRAY[\'region\',\'nis5\',\'municipality\'], ARRAY[region, nis5::text, municipality]) FROM validation v WHERE postalcode = v.postalcode AND unaccent(UPPER(locality)) = v.normalized LIMIT 1)'),
        ]);
        $update->where
            ->isNull('process_status')
            ->isNull('validation')
            ->equalTo('valid', 't');
        $qsz = $sql->buildSqlString($update);
        $this->adapter->query($qsz, $this->adapter::QUERY_MODE_EXECUTE);
    }

    private function getSuggestions()
    {
        $sql = new Sql($this->adapter, $this->table);

        $list = $sql->select();
        $list->columns([
            'postalcode',
            'locality',
            'count' => new Expression('COUNT(*)'),
            'list'  => new Expression('json_agg(CONCAT("streetname", \' \', "housenumber", \', \', "postalcode", \' \', "locality"))'),
        ]);
        $list->where(['valid' => new Expression('false')]);
        $list->group(['postalcode', 'locality']);
        $list->order(['postalcode']);

        $qsz = $sql->buildSqlString($list);
        $results = $this->adapter->query($qsz, $this->adapter::QUERY_MODE_EXECUTE);

        if ($results->count() > 0) {
            $suggestions = [];
            $sqlValidation = new Sql($this->adapter, 'validation');
            foreach ($results as $r) {
                $selectNIS5 = $sqlValidation->select();
                $selectNIS5->columns(['nis5']);
                $selectNIS5->where(['postalcode' => $r->postalcode]);

                $suggestion = $sqlValidation->select();
                $suggestion->columns(['postalcode', 'name', 'region', 'nis5', 'municipality']);
                $suggestion->where
                    ->equalTo('postalcode', $r->postalcode)
                    ->or
                    ->like('normalized', strtoupper(Text::removeAccents($r->locality ?? '')))
                    ->or
                    ->in('nis5', $selectNIS5);
                $suggestion->order(['postalcode', 'level']);

                $qsz = $sql->buildSqlString($suggestion);
                $resultsSuggestion = $this->adapter->query($qsz, $this->adapter::QUERY_MODE_EXECUTE);

                if ($resultsSuggestion->count() > 0) {
                    if (!isset($suggestions[$r->postalcode])) {
                        $suggestions[$r->postalcode] = [];
                    }
                    if (!isset($suggestions[$r->postalcode][$r->locality])) {
                        $suggestions[$r->postalcode][$r->locality] = [];
                    }
                    $suggestions[$r->postalcode][$r->locality] = [
                        'count'       => $r->count,
                        'list'        => array_slice(json_decode($r->list), 0, 5),
                        'suggestions' => $resultsSuggestion->toArray(),
                    ];
                }
            }

            return $suggestions;
        }

        return [];
    }
}
