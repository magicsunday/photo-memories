<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories;

use MagicSunday\Memories\Bootstrap\ComposerAutoload;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

require_once __DIR__ . '/../autoload/ComposerAutoload.php';

ComposerAutoload::require();

// Create the container
$factory   = new DependencyContainerFactory();
$container = $factory->create();

// Create and set the SymfonyStyle instance
$input  = new ArgvInput();
$output = new ConsoleOutput();
$io     = new SymfonyStyle($input, $output);
$container->set(SymfonyStyle::class, $io);

// Run the application

/** @var Application $application */
$application = $container->get(Application::class);
$result      = $application->run($input, $output);

exit($result);
