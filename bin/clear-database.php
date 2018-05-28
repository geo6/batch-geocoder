<?php

declare(strict_types=1);

require 'vendor/autoload.php';

use Zend\ConfigAggregator\ConfigAggregator;
use Zend\ConfigAggregator\ZendConfigProvider;
use Zend\Db\Adapter\Adapter;
use Zend\Db\Metadata\Metadata;
use Zend\Db\Sql\Ddl\DropTable;
use Zend\Db\Sql\Sql;

$config = (new ConfigAggregator([
    new ZendConfigProvider('composer.json'),
    new ZendConfigProvider('config/application/*.{php,ini,xml,json,yaml}'),
]))->getMergedConfig();

$adapter = new Adapter(array_merge(['driver' => 'Pdo_Pgsql'], $config['postgresql']));

$sql = new Sql($adapter);
$metadata = new Metadata($adapter);

$tables = $metadata->getTableNames();
foreach ($tables as $table) {
    if (preg_match('/^(([0-9]{4})([0-9]{2})([0-9]{2}))_[a-z0-9]+$/', $table, $matches) === 1) {
        $date = $matches[1];
        $year = $matches[2];
        $month = $matches[3];

        if ($date < date('Ymd')) {
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
        }
    }
}

exit(0);
