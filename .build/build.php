<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

try
{
    $pharFile = "memories.phar";
    $binName  = "memories";

    if (file_exists($pharFile)) {
        unlink($pharFile);
    }

    if (file_exists($pharFile . ".gz")) {
        unlink($pharFile . ".gz");
    }

    require_once __DIR__ . '/memories/src/Dependencies.php';

    $phar = new Phar($pharFile);
    $phar->startBuffering();

    // Create the default stub
    $defaultStub = Phar::createDefaultStub('src/Memories.php');

    $phar->buildFromDirectory(__DIR__ . "/memories");
    $phar->setStub("#!/usr/bin/env php \n" . $defaultStub);
    $phar->stopBuffering();
    $phar->compressFiles(Phar::GZ);

    //  Make the file executable
    chmod($pharFile, 0755);

    echo "$pharFile successfully created" . PHP_EOL;
}
catch (Exception $e)
{
    echo $e->getMessage();
}