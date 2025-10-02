<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories;

use function MagicSunday\Memories\Bootstrap\requireComposerAutoload;

require_once __DIR__ . '/../autoload/runtime.php';

requireComposerAutoload();

$factory = new DependencyContainerFactory();
$factory->ensure();
