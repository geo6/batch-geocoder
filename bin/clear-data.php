<?php

declare(strict_types=1);

chdir(dirname(__DIR__));

require 'vendor/autoload.php';

use Zend\ConfigAggregator\ConfigAggregator;
use Zend\ConfigAggregator\ZendConfigProvider;
use Zend\Db\Adapter\Adapter;
use Zend\Db\Metadata\Metadata;
use Zend\Db\Sql\Ddl\DropTable;
use Zend\Db\Sql\Sql;

/**
 * Delete remaining uploaded files
 */
$directory = realpath('data/upload');
$error = false;

if ($directory !== false && file_exists($directory) && is_dir($directory)) {
    if ($handle = opendir($directory)) {
        while (false !== ($file = readdir($handle))) {
            if (!in_array($file, ['.','..']) && !is_dir($directory.'/'.$file)) {
                $unlink = unlink($directory.'/'.$file);

                if ($unlink === true) {
                    printf(
                        'File "%s" deleted.%s',
                        $directory.'/'.$file,
                        PHP_EOL
                    );
                } else {
                    $error = true;
                    printf(
                        'Error deleting file "%s".%s',
                        $directory.'/'.$file,
                        PHP_EOL
                    );
                }
            }
        }
        closedir($handle);
    }
}

/**
 * Delete PostgreSQL tables
 */
$config = (new ConfigAggregator([
    new ZendConfigProvider('config/application/*.{php,ini,xml,json,yaml}'),
]))->getMergedConfig();

$adapter = new Adapter(array_merge(['driver' => 'Pdo_Pgsql'], $config['postgresql']));

$sql = new Sql($adapter);
$metadata = new Metadata($adapter);

$tables = $metadata->getTableNames();
foreach ($tables as $table) {
    if (preg_match('/^[0-9]{4}[0-9]{2}[0-9]{2}_[a-z0-9]+(?:_[0-9]+)?$/', $table, $matches) === 1) {
        try {
            $drop = new DropTable($table);

            $adapter->query(
                $sql->getSqlStringForSqlObject($drop),
                $adapter::QUERY_MODE_EXECUTE
            );

            printf(
                'Table "%s" deleted.%s',
                $table,
                PHP_EOL
            );
        } catch (Exception $e) {
            $error = true;

            printf(
                'Error deleting table "%s": %s.%s',
                $table,
                $e->getMessage(),
                PHP_EOL
            );
        }
    }
}

exit($error === true ? 1 : 0);
