<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

use MagicSunday\Memories\Bootstrap\ComposerAutoload;
use MagicSunday\Memories\DependencyContainerFactory;

require_once __DIR__ . '/../../autoload/ComposerAutoload.php';

ComposerAutoload::require();

$factory = new DependencyContainerFactory();
$factory->ensure();

require_once __DIR__ . '/index.html';
