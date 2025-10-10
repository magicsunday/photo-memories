<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

use MagicSunday\Memories\Bootstrap\ComposerAutoload;
use MagicSunday\Memories\DependencyContainer;
use MagicSunday\Memories\DependencyContainerFactory;
use MagicSunday\Memories\Http\Controller\FeedController;
use MagicSunday\Memories\Http\Request;
use MagicSunday\Memories\Http\Response\BinaryFileResponse;
use MagicSunday\Memories\Http\Response\JsonResponse;
use MagicSunday\Memories\Http\Response\Response;

//require_once __DIR__ . '/../autoload/ComposerAutoload.php';

ComposerAutoload::require();

$request   = Request::fromGlobals();
$factory   = new DependencyContainerFactory();
$container = $factory->create();

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

    if ($response === null && $method === 'GET' && preg_match('#^/api/feed/([a-f0-9]{40})$#', $path, $matches) === 1) {
        $itemId = $matches[1];

        /** @var FeedController $controller */
        $controller = $container->get(FeedController::class);
        $response   = $controller->item($request, $itemId);
    }

    if ($response === null && $method === 'POST' && preg_match('#^/api/feed/([a-f0-9]{40})/video$#', $path, $matches) === 1) {
        $itemId = $matches[1];

        /** @var FeedController $controller */
        $controller = $container->get(FeedController::class);
        $response   = $controller->triggerSlideshow($request, $itemId);
    }

    if ($response === null && $method === 'GET' && preg_match('#^/api/feed/([a-f0-9]{40})/video$#', $path, $matches) === 1) {
        $itemId = $matches[1];

        /** @var FeedController $controller */
        $controller = $container->get(FeedController::class);
        $response   = $controller->slideshow($itemId);
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
