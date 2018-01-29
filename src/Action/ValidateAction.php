<?php

namespace App\Action;

use App\Middleware\DbAdapterMiddleware;
use Interop\Http\ServerMiddleware\DelegateInterface;
use Interop\Http\ServerMiddleware\MiddlewareInterface;
use Psr\Http\Message\ServerRequestInterface;
use Zend\Db\Sql\Expression;
use Zend\Db\Sql\Select;
use Zend\Db\Sql\Sql;
use Zend\Diactoros\Response\RedirectResponse;
use Zend\Expressive\Router\RouterInterface;
use Zend\Expressive\Session\SessionMiddleware;
use Zend\Expressive\Template\TemplateRendererInterface;

/**
 * @see http://www.bpost.be/site/fr/envoyer/adressage/rechercher-un-code-postal/
 */
class ValidateAction implements MiddlewareInterface
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
        $session = $request->getAttribute(SessionMiddleware::SESSION_ATTRIBUTE);

        $table = $session->get('table');

        $sql = new Sql($adapter, $table);

        $update = $sql->update();
        $update->set(['valid' => new Expression('false')]);
        $update->where
      ->notIn('postalcode', (new Select('validation_bpost'))->columns(['postalcode']));

        $qsz = $sql->buildSqlString($update);
        $results = $adapter->query($qsz, $adapter::QUERY_MODE_EXECUTE);

        $update = $sql->update();
        $update->set(['valid' => new Expression('false')]);
        $update->where
      ->notIn(
          new Expression('unaccent(UPPER("locality"))'),
          (new Select(['b' => 'validation_bpost']))->where('postalcode = b."postalcode"')->columns(['normalized'])
      );

        $qsz = $sql->buildSqlString($update);
        $results = $adapter->query($qsz, $adapter::QUERY_MODE_EXECUTE);

        return new RedirectResponse($this->router->generateUri('geocode'));
    }
}
