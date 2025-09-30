<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

use MagicSunday\Memories\DependencyContainer;
use MagicSunday\Memories\Http\Controller\FeedController;
use MagicSunday\Memories\Http\Request;
use MagicSunday\Memories\Http\Response\BinaryFileResponse;
use MagicSunday\Memories\Http\Response\JsonResponse;
use MagicSunday\Memories\Http\Response\Response;
use Throwable;

use function is_file;
use function preg_match;
use function realpath;
use function str_ends_with;
use function str_starts_with;
use function substr;

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/Dependencies.php';
require_once __DIR__ . '/../var/cache/DependencyContainer.php';

$request   = Request::fromGlobals();
$container = new DependencyContainer();

$method = $request->getMethod();
$path   = $request->getPath();

$response = null;

try {
    if ($method === 'GET') {
        $static = tryStaticFile($path);
        if ($static instanceof Response) {
            $response = $static;
        }
    }

    if ($response === null && $method === 'GET' && $path === '/api/feed') {
        /** @var FeedController $controller */
        $controller = $container->get(FeedController::class);
        $response   = $controller->feed($request);
    }

    if ($response === null && $method === 'GET' && preg_match('#^/api/media/(\d+)/thumbnail$#', $path, $matches) === 1) {
        $mediaId = (int) $matches[1];

        /** @var FeedController $controller */
        $controller = $container->get(FeedController::class);
        $response   = $controller->thumbnail($request, $mediaId);
    }

    if ($response === null && $method === 'GET' && shouldServeAppShell($path)) {
        $response = serveAppShell();
    }

    if ($response === null) {
        $response = new JsonResponse([
            'error' => 'Not Found',
        ], 404);
    }
} catch (Throwable $exception) {
    $response = new JsonResponse([
        'error'   => 'Internal Server Error',
        'message' => $exception->getMessage(),
    ], 500);
}

$response->send();

function tryStaticFile(string $path): ?Response
{
    $publicDir = realpath(__DIR__);
    if ($publicDir === false) {
        return null;
    }

    if ($path === '/' || $path === '') {
        return null;
    }

    $normalizedPath = $path;
    if (str_starts_with($normalizedPath, '/')) {
        $normalizedPath = substr($normalizedPath, 1);
    }

    $candidate = $publicDir . DIRECTORY_SEPARATOR . $normalizedPath;
    $real      = realpath($candidate);

    if ($real === false) {
        return null;
    }

    if (!str_starts_with($real, $publicDir)) {
        return null;
    }

    if (!is_file($real)) {
        return null;
    }

    return new BinaryFileResponse($real);
}

function shouldServeAppShell(string $path): bool
{
    if ($path === '/' || $path === '/app' || $path === '/app/') {
        return true;
    }

    return str_starts_with($path, '/app') && !str_ends_with($path, '.js') && !str_ends_with($path, '.css');
}

function serveAppShell(): Response
{
    $distIndex = __DIR__ . '/app/dist/index.html';
    if (is_file($distIndex)) {
        return new BinaryFileResponse($distIndex);
    }

    $devIndex = __DIR__ . '/app/index.html';
    if (is_file($devIndex)) {
        return new BinaryFileResponse($devIndex);
    }

    return new JsonResponse([
        'error' => 'SPA entry point not found.',
    ], 404);
}
