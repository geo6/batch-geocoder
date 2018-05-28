<?php

declare(strict_types=1);

define('DURATION', 60 * 60 * 24);

clear(realpath('data/upload'));

exit(0);

function clear(string $directory)
{
    $glob = glob($directory.'/*');

    foreach ($glob as $g) {
        if (is_dir($g)) {
            clear($g);

            $count = count(glob($g.'/*'));
            if ($count === 0) {
                rmdir($g);

                printf(
                    'Empty directory "%s" deleted.%s',
                    $g,
                    PHP_EOL
                );
            }
        } else {
            $ctime = filectime($g);

            if ($ctime < (time() - DURATION)) {
                unlink($g);

                printf(
                    'File "%s" deleted (%s).%s',
                    $g,
                    date('Y-m-d H:i:s', $ctime),
                    PHP_EOL
                );
            }
        }
    }
}
