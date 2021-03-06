<?php

declare(strict_types=1);

namespace App\Handler;

use App\Db\Sql\Ddl as AppDdl;
use App\Middleware\ConfigMiddleware;
use App\Middleware\DbAdapterMiddleware;
use ErrorException;
use Laminas\Db\Metadata\Metadata;
use Laminas\Db\Sql\Ddl;
use Laminas\Db\Sql\Expression;
use Laminas\Db\Sql\Sql;
use Laminas\Diactoros\Response\RedirectResponse;
use Laminas\Diactoros\UploadedFile;
use Laminas\I18n\Filter\Alnum;
use Laminas\Validator\File\Extension;
use Laminas\Validator\File\MimeType;
use Laminas\Validator\ValidatorChain;
use Mezzio\Flash\FlashMessageMiddleware;
use Mezzio\Router\RouterInterface;
use Mezzio\Session\SessionMiddleware;
use Mezzio\Template\TemplateRendererInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class UploadHandler implements RequestHandlerInterface
{
    const SEPARATOR_COMMA = ',';
    const SEPARATOR_SEMICOLON = ';';

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

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $config = $request->getAttribute(ConfigMiddleware::CONFIG_ATTRIBUTE);
        $session = $request->getAttribute(SessionMiddleware::SESSION_ATTRIBUTE);

        $this->adapter = $request->getAttribute(DbAdapterMiddleware::DBADAPTER_ATTRIBUTE);

        $query = $request->getParsedBody();

        if (isset($config['archives']) && $config['archives'] === true && isset($query['table'])) {
            $sql = new Sql($this->adapter, $query['table']);

            $reset = $sql->update();
            $reset->set([
                'process_datetime' => new Expression('NULL'),
                'process_status'   => new Expression('NULL'),
                'process_provider' => new Expression('NULL'),
                'process_address'  => new Expression('NULL'),
                'process_score'    => new Expression('NULL'),
                'the_geog'         => new Expression('NULL'),
            ]);
            $reset->where
                ->isNull('process_address');
            $qsz = $sql->buildSqlString($reset);
            $this->adapter->query($qsz, $this->adapter::QUERY_MODE_EXECUTE);

            $session->set('table', $query['table']);
        } else {
            $this->flashMessages = $request->getAttribute(FlashMessageMiddleware::FLASH_ATTRIBUTE);

            $files = $request->getUploadedFiles();

            try {
                $this->file = $files['file'];

                if ($this->uploadFile() !== false && file_exists($this->path) === true) {
                    if ($this->checkFile() === true) {
                        if ($this->importFile($config['postgresql'], $config['limit'] ?? null, $config['doublePass'] ?? false)) {
                            $session->set('path', $this->path);
                            $session->set('table', $this->table);
                        }
                    }
                }
            } catch (ErrorException $e) {
                $this->deleteFile();
                $this->deleteTable();

                return $this->flashError($e);
            } catch (Exception $e) {
                $this->deleteFile();
                $this->deleteTable();

                return $this->flashError($e);
            }

            $this->deleteFile();
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

            $directory = realpath('data/upload');
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
    private function importFile(array $postgresql, int $limit = null, bool $doublePass = false)
    {
        $fname = basename($this->path);
        $this->table = date('Ymd').'_'.(new Alnum())->filter($fname);

        $metadata = new Metadata($this->adapter);
        $tables = $metadata->getTableNames();
        $i = 1;
        while (in_array($this->table, $tables)) {
            $this->table = date('Ymd').'_'.(new Alnum())->filter($fname).'_'.($i++);
        }

        try {
            // Create table
            $this->adapter->query(
                sprintf(file_get_contents('scripts/create-table.sql'), $this->table),
                $this->adapter::QUERY_MODE_EXECUTE
            );

            // Try to determine the separator
            $separator = self::SEPARATOR_COMMA;
            if (($handle = fopen($this->path, 'r')) !== false) {
                $dataComma = fgetcsv($handle, 1000, self::SEPARATOR_COMMA);
                $dataSemicolon = fgetcsv($handle, 1000, self::SEPARATOR_SEMICOLON);

                if (count($dataComma) === 5) {
                    $separator = self::SEPARATOR_COMMA;
                } elseif (count($dataSemicolon) === 5) {
                    $separator = self::SEPARATOR_SEMICOLON;
                }

                fclose($handle);
            }

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
                'COPY "%s"'.
                ' (id, streetname, housenumber, postalcode, locality)'.
                ' FROM STDIN WITH ('.
                'FORMAT csv'.
                ($separator === self::SEPARATOR_SEMICOLON ? ', DELIMITER \''.self::SEPARATOR_SEMICOLON.'\'' : '').
                ');',
                $this->table
            );
            pg_query($pg, $qsz);

            $count = 0;

            if (($handle = fopen($this->path, 'r')) !== false) {
                while (($buffer = fgets($handle, 4096)) !== false) {
                    pg_put_line($pg, $buffer);
                    $count++;

                    if (!is_null($limit) && $count > $limit) {
                        fclose($handle);
                        pg_end_copy($pg);

                        throw new ErrorException(sprintf(
                            'Too many records: %d !',
                            $count
                        ));
                    }
                }
                if (!feof($handle)) {
                    throw new ErrorException('Function fgets() failed!');
                }
                fclose($handle);
            }

            pg_end_copy($pg);

            $sql = new Sql($this->adapter, $this->table);

            // Create primary key
            $alter = new Ddl\AlterTable($this->table);
            $alter->addConstraint(new Ddl\Constraint\PrimaryKey('id'));
            $this->adapter->query(
                $sql->getSqlStringForSqlObject($alter),
                $this->adapter::QUERY_MODE_EXECUTE
            );

            // Trim all values
            $trim = $sql->update();
            $trim->set([
                'streetname'  => new Expression('trim("streetname")'),
                'housenumber' => new Expression('trim("housenumber")'),
                'postalcode'  => new Expression('trim("postalcode")'),
                'locality'    => new Expression('trim("locality")'),
            ]);
            $this->adapter->query(
                $sql->getSqlStringForSqlObject($trim),
                $this->adapter::QUERY_MODE_EXECUTE
            );

            if ($doublePass === true) {
                $alter = new Ddl\AlterTable($this->table);
                $alter->addColumn(new AppDdl\Column\Hstore('process_doublepass', true));
                $this->adapter->query(
                    $sql->getSqlStringForSqlObject($alter),
                    $this->adapter::QUERY_MODE_EXECUTE
                );
            }
        } catch (InvalidQueryException $e) {
            throw new ErrorException($e->getMessage());
        }

        return $this->table;
    }

    /**
     * If there is an error, delete the current file and the current table in the database (if it exists).
     */
    private function deleteFile()
    {
        if (!is_null($this->path) && file_exists($this->path)) {
            unlink($this->path);
        }
    }

    private function deleteTable()
    {
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
