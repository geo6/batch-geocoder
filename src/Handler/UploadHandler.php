<?php

declare(strict_types=1);

namespace App\Handler;

use App\Middleware\ConfigMiddleware;
use App\Middleware\DbAdapterMiddleware;
use ErrorException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
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

class UploadHandler implements RequestHandlerInterface
{
    private $adapter;
    private $file;
    private $flashMessages;
    private $path;
    private $router;
    private $table;
    private $template;

    public function __construct(RouterInterface $router, TemplateRendererInterface $template)
    {
        $this->router = $router;
        $this->template = $template;
    }

    public function handle(ServerRequestInterface $request) : ResponseInterface
    {
        $config = $request->getAttribute(ConfigMiddleware::CONFIG_ATTRIBUTE);
        $session = $request->getAttribute(SessionMiddleware::SESSION_ATTRIBUTE);

        $query = $request->getParsedBody();

        if (isset($config['archives']) && $config['archives'] === true && isset($query['table'])) {
            $session->set('table', $query['table']);
        } else {
            $this->flashMessages = $request->getAttribute(FlashMessageMiddleware::FLASH_ATTRIBUTE);
            $this->adapter = $request->getAttribute(DbAdapterMiddleware::DBADAPTER_ATTRIBUTE);

            $files = $request->getUploadedFiles();

            try {
                $this->file = $files['file'];

                if ($this->uploadFile() !== false && file_exists($this->path) === true) {
                    if ($this->checkFile() === true) {
                        if ($this->importFile($config['postgresql']) &&
                            $this->checkTable($config['limit'] ?? null)
                        ) {
                            $session->set('path', $this->path);
                            $session->set('table', $this->table);
                        }
                    }
                }
            } catch (ErrorException $e) {
                $this->cleanCurrent();

                return $this->flashError($e);
            } catch (Exception $e) {
                $this->cleanCurrent();

                return $this->flashError($e);
            }
        }

        if (!isset($config['validation']) || $config['validation'] !== false) {
            return new RedirectResponse($this->router->generateUri('validate'));
        }

        return new RedirectResponse($this->router->generateUri('geocode'));
    }

    /**
     * Send flash message with error message to homepage.
     */
    private function flashError($error)
    {
        $this->flashMessages->flash('error-upload', $error->getMessage());

        return new RedirectResponse($this->router->generateUri('home'));
    }

    /**
     * Upload file on disk.
     */
    private function uploadFile()
    {
        if (!is_null($this->file) && $this->file->getError() === UPLOAD_ERR_OK) {
            $info = pathinfo($this->file->getClientFilename());

            $directory = realpath('data/upload').'/'.date('Y').'/'.date('m');
            $fname = $this->file->getClientFilename();

            if (!file_exists($directory) || !is_dir($directory)) {
                mkdir($directory, 0777, true);
            }

            $i = 1;
            while (file_exists($directory.'/'.$fname)) {
                $fname = $info['filename'].' ('.($i++).').'.$info['extension'];
            }
            $this->path = $directory.'/'.$fname;

            $this->file->moveTo($this->path);

            return $this->path;
        } else {
            $message = UploadedFile::ERROR_MESSAGES[$this->file->getError()];

            throw new ErrorException($message);
        }

        return false;
    }

    /**
     * Check file extension and mime type.
     */
    private function checkFile()
    {
        $validator = new ValidatorChain();
        $validator->attach(new Extension('csv,txt'));
        $validator->attach(new MimeType('text/csv,text/plain'));

        if ($validator->isValid($this->path) !== true) {
            $message = implode(PHP_EOL, $validator->getMessages());

            throw new ErrorException($message);
        }

        return true;
    }

    /**
     * Import data in database.
     */
    private function importFile($postgresql)
    {
        $fname = basename($this->path);
        $this->table = date('Ymd').'_'.(new Alnum())->filter($fname);

        try {
            // Create table
            $this->adapter->query(
                sprintf(file_get_contents('scripts/create-table.sql'), $this->table),
                $this->adapter::QUERY_MODE_EXECUTE
            );

            // Load data
            $pg = pg_connect(sprintf(
                'host=%s port=%s dbname=%s user=%s password=%s',
                $postgresql['host'],
                $postgresql['port'],
                $postgresql['dbname'],
                $postgresql['user'],
                $postgresql['password']
            ));

            $qsz = sprintf(
                'COPY "%s" (id, streetname, housenumber, postalcode, locality) FROM STDIN WITH (FORMAT csv);',
                $this->table
            );
            pg_query($pg, $qsz);

            $handle = fopen($this->path, 'r');
            while (!feof($handle)) {
                $row = fread($handle, 1024);
                pg_put_line($pg, $row);
            }
            fclose($handle);

            pg_end_copy($pg);

            // Create primary key
            $alter = new Ddl\AlterTable($this->table);
            $alter->addConstraint(new Ddl\Constraint\PrimaryKey('id'));
            $this->adapter->query(
                (new Sql($this->adapter))->getSqlStringForSqlObject($alter),
                $this->adapter::QUERY_MODE_EXECUTE
            );
        } catch (InvalidQueryException $e) {
            throw new ErrorException($e->getMessage());
        }

        return $this->table;
    }

    /**
     * Check table count.
     */
    private function checkTable($limit = null)
    {
        $sql = new Sql($this->adapter, $this->table);

        $select = $sql->select();
        $count = ($this->adapter->query(
            $sql->buildSqlString($select),
            $this->adapter::QUERY_MODE_EXECUTE
        ))->count();

        if ($count === 0) {
            throw new ErrorException('No record !');
        } elseif (!is_null($limit) && $count > $limit) {
            throw new ErrorException(
                sprintf(
                    'Too many records: %d !',
                    $count
                )
            );
        }

        return true;
    }

    /**
     * If there is an error, delete the current file and the current table in the database (if it exists).
     */
    private function cleanCurrent()
    {
        // Delete file
        if (!is_null($this->path) && file_exists($this->path)) {
            unlink($this->path);
        }

        // Delete table
        if (!is_null($this->table)) {
            $this->adapter->query(
                sprintf(
                    'DROP TABLE IF EXISTS "%s";',
                    $this->table
                ),
                $this->adapter::QUERY_MODE_EXECUTE
            );
        }
    }
}
