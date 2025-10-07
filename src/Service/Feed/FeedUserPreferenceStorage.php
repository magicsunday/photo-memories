<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Feed;

use RuntimeException;

use function array_filter;
use function array_key_exists;
use function array_values;
use function dirname;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function in_array;
use function is_array;
use function is_dir;
use function is_string;
use function json_decode;
use function json_encode;
use function mkdir;
use function array_unique;

use const JSON_THROW_ON_ERROR;

/**
 * Persists feed feedback such as favourites and opt-outs to a JSON document.
 */
final class FeedUserPreferenceStorage
{
    public function __construct(private readonly string $storagePath)
    {
    }

    public function getPreferences(string $userId, string $profileKey): FeedUserPreferences
    {
        $data = $this->load();

        $userData = $data['users'][$userId]['profiles'][$profileKey] ?? [
            'favourites' => [],
            'hidden_algorithms' => [],
        ];

        $favourites      = $this->normaliseList($userData['favourites'] ?? []);
        $hiddenAlgorithms = $this->normaliseList($userData['hidden_algorithms'] ?? []);

        return new FeedUserPreferences($userId, $profileKey, $favourites, $hiddenAlgorithms);
    }

    public function markFavourite(string $userId, string $profileKey, string $itemId, bool $favourite): void
    {
        $data = $this->load();

        $preferences = $this->initialiseProfile($data, $userId, $profileKey);
        $list        = $this->normaliseList($preferences['favourites']);

        if ($favourite) {
            if (!in_array($itemId, $list, true)) {
                $list[] = $itemId;
            }
        } else {
            $list = array_values(array_filter(
                $list,
                static fn (string $candidate): bool => $candidate !== $itemId,
            ));
        }

        $data['users'][$userId]['profiles'][$profileKey]['favourites'] = $list;

        $this->persist($data);
    }

    public function setAlgorithmOptOut(string $userId, string $profileKey, string $algorithm, bool $optOut): void
    {
        $data = $this->load();

        $preferences = $this->initialiseProfile($data, $userId, $profileKey);
        $list        = $this->normaliseList($preferences['hidden_algorithms']);

        if ($optOut) {
            if (!in_array($algorithm, $list, true)) {
                $list[] = $algorithm;
            }
        } else {
            $list = array_values(array_filter(
                $list,
                static fn (string $candidate): bool => $candidate !== $algorithm,
            ));
        }

        $data['users'][$userId]['profiles'][$profileKey]['hidden_algorithms'] = $list;

        $this->persist($data);
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function initialiseProfile(array &$data, string $userId, string $profileKey): array
    {
        if (!array_key_exists('users', $data) || !is_array($data['users'])) {
            $data['users'] = [];
        }

        if (!array_key_exists($userId, $data['users']) || !is_array($data['users'][$userId])) {
            $data['users'][$userId] = ['profiles' => []];
        }

        if (!array_key_exists('profiles', $data['users'][$userId]) || !is_array($data['users'][$userId]['profiles'])) {
            $data['users'][$userId]['profiles'] = [];
        }

        if (!array_key_exists($profileKey, $data['users'][$userId]['profiles'])
            || !is_array($data['users'][$userId]['profiles'][$profileKey])
        ) {
            $data['users'][$userId]['profiles'][$profileKey] = [
                'favourites' => [],
                'hidden_algorithms' => [],
            ];
        }

        return $data['users'][$userId]['profiles'][$profileKey];
    }

    /**
     * @return array<string, mixed>
     */
    private function load(): array
    {
        if (!file_exists($this->storagePath)) {
            return ['users' => []];
        }

        $raw = file_get_contents($this->storagePath);
        if ($raw === false) {
            throw new RuntimeException('Konnte Präferenzdatei nicht lesen: ' . $this->storagePath);
        }

        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            return ['users' => []];
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function persist(array $data): void
    {
        $directory = dirname($this->storagePath);
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('Konnte Verzeichnis für Präferenzen nicht anlegen: ' . $directory);
        }

        $payload = json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
        if (@file_put_contents($this->storagePath, $payload) === false) {
            throw new RuntimeException('Konnte Präferenzen nicht speichern: ' . $this->storagePath);
        }
    }

    /**
     * @param mixed $value
     *
     * @return list<string>
     */
    private function normaliseList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $result = [];
        foreach ($value as $entry) {
            if (is_string($entry) && $entry !== '') {
                $result[] = $entry;
            }
        }

        return array_values(array_unique($result));
    }
}
