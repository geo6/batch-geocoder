<?php

namespace App\Action;

use App\Middleware\DbAdapterMiddleware;
use ErrorException;
use Interop\Http\ServerMiddleware\DelegateInterface;
use Interop\Http\ServerMiddleware\MiddlewareInterface;
use Psr\Http\Message\ServerRequestInterface;
use Zend\Db\Sql\Ddl;
use Zend\Db\Sql\Sql;
use Zend\Diactoros\Response\RedirectResponse;
use Zend\Diactoros\UploadedFile;
use Zend\Expressive\Flash\FlashMessageMiddleware;
use Zend\Expressive\Router\RouterInterface;
use Zend\Expressive\Session\SessionMiddleware;
use Zend\Expressive\Template\TemplateRendererInterface;
use Zend\I18n\Filter\Alnum;
use Zend\Validator\File\Extension;
use Zend\Validator\File\MimeType;
use Zend\Validator\ValidatorChain;

class UploadAction implements MiddlewareInterface
{
    private $flashMessages;
    private $router;
    private $template;

    public function __construct(RouterInterface $router, TemplateRendererInterface $template)
    {
        $this->router = $router;
        $this->template = $template;
    }

    private function flashError($error)
    {
        $this->flashMessages->flash('error-upload', $error->getMessage());

        return new RedirectResponse($this->router->generateUri('home'));
    }

    public function process(ServerRequestInterface $request, DelegateInterface $delegate)
    {
        $this->flashMessages = $request->getAttribute(FlashMessageMiddleware::FLASH_ATTRIBUTE);

        $adapter = $request->getAttribute(DbAdapterMiddleware::DBADAPTER_ATTRIBUTE);
        $session = $request->getAttribute(SessionMiddleware::SESSION_ATTRIBUTE);

        $files = $request->getUploadedFiles();

        try {
            $file = $files['file'];

            if (!is_null($file) && $file->getError() === UPLOAD_ERR_OK) {
                $info = pathinfo($file->getClientFilename());

                $directory = realpath(__DIR__.'/../../data/upload');
                $directory = $directory.'/'.date('Y').'/'.date('m');
                $fname = $file->getClientFilename();

                if (!file_exists($directory) || !is_dir($directory)) {
                    mkdir($directory, 0777, true);
                }

                $i = 1;
                while (file_exists($directory.'/'.$fname)) {
                    $fname = $info['filename'].' ('.($i++).').'.$info['extension'];
                }
                $path = $directory.'/'.$fname;

                $file->moveTo($path);

                $validator = new ValidatorChain();
                $validator->attach(new Extension('csv,txt'));
                $validator->attach(new MimeType('text/csv,text/plain'));

                if ($validator->isValid($path) === true) {
                    $filter = new Alnum();

                    $tablename = date('Ymd').'_'.$filter->filter($fname);

                    $sql = new Sql($adapter);

                    // Create table
                    $adapter->query(
            sprintf(file_get_contents(__DIR__.'/../../scripts/create-table.sql'), $tablename),
            $adapter::QUERY_MODE_EXECUTE
          );

                    // Load data
                    $pg = $adapter->getDriver()->getConnection()->getResource();

                    $qsz = sprintf('COPY "%s" (id, streetname, housenumber, postalcode, locality) FROM STDIN WITH (FORMAT csv)', $tablename);
                    pg_query($pg, $qsz);

                    $handle = fopen($path, 'r');
                    while (!feof($handle)) {
                        $row = fread($handle, 1024);
                        pg_put_line($pg, $row);
                    }
                    fclose($handle);

                    pg_end_copy($pg);

                    // Create primary key
                    $alter = new Ddl\AlterTable($tablename);
                    $alter->addConstraint(new Ddl\Constraint\PrimaryKey('id'));
                    $adapter->query(
            $sql->getSqlStringForSqlObject($alter),
            $adapter::QUERY_MODE_EXECUTE
          );

                    $session->set('path', $path);
                    $session->set('table', $tablename);
                } else {
                    unlink($path);

                    $message = implode(PHP_EOL, $validator->getMessages());

                    throw new ErrorException($message);
                }
            } else {
                $message = UploadedFile::ERROR_MESSAGES[$file->getError()];

                throw new ErrorException($message);
            }
        } catch (InvalidQueryException $e) {
            return $this->flashError($e);
        } catch (ErrorException $e) {
            return $this->flashError($e);
        } catch (Exception $e) {
            return $this->flashError($e);
        }

        return $delegate->process($request);
    }
}
