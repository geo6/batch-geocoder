<?php

namespace App\Db\Sql\Ddl\Column;

use Laminas\Db\Sql\Ddl\Column\Column;

class Hstore extends Column
{
    /**
     * @var string
     */
    protected $type = 'hstore';
}
