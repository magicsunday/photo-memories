<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

/**
 * Test helper script that simulates a slower slideshow:generate command.
 */

declare(strict_types=1);

use const JSON_THROW_ON_ERROR;
use const LOCK_EX;

use function file_get_contents;
use function file_put_contents;
use function fwrite;
use function is_file;
use function is_string;
use function json_decode;
use function usleep;
use function unlink;

$jobFile = $argv[2] ?? null;

if ($jobFile === null || $jobFile === '') {
    fwrite(STDERR, "Missing job file argument\n");

    exit(1);
}

if (!is_file($jobFile)) {
    fwrite(STDERR, "Job file not found\n");

    exit(1);
}

$payload = json_decode((string) file_get_contents($jobFile), true, 512, JSON_THROW_ON_ERROR);

$outputPath = $payload['output'] ?? null;
$lockPath   = $payload['lock'] ?? null;

usleep(300_000);

if (is_string($outputPath) && $outputPath !== '') {
    file_put_contents($outputPath, 'delayed stub video content', LOCK_EX);
}

if (is_string($lockPath) && $lockPath !== '' && is_file($lockPath)) {
    unlink($lockPath);
}

if (is_file($jobFile)) {
    unlink($jobFile);
}

exit(0);
