<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Feed;

use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Exception;
use MagicSunday\Memories\Feed\MemoryFeedItem;

use function array_key_exists;
use function is_array;
use function is_numeric;
use function is_string;
use function preg_match;
use function sprintf;
use function strcmp;
use function trim;
use function usort;

/**
 * Generates notification schedules for feed items based on channel lead times.
 */
final class NotificationPlanner
{
    /**
     * @var array<string, array{lead_times: list<string>, send_time: ?string}>
     */
    private array $channelConfig;

    private string $defaultSendTime;

    private string $defaultTimezone;

    /**
     * @param array<string, array{lead_times?: list<string>, send_time?: string|null}> $channelConfig
     */
    public function __construct(
        array $channelConfig = [],
        string $defaultSendTime = '09:00',
        string $defaultTimezone = 'UTC',
    ) {
        $this->channelConfig   = $this->normaliseChannelConfig($channelConfig);
        $this->defaultSendTime = $this->normaliseSendTimeString($defaultSendTime) ?? '09:00';
        $this->defaultTimezone = trim($defaultTimezone) !== '' ? $defaultTimezone : 'UTC';
    }

    public function planForItem(MemoryFeedItem $item, DateTimeImmutable $reference): array
    {
        $targetUtc = $this->resolveTargetDate($item->getParams());
        if (!$targetUtc instanceof DateTimeImmutable) {
            return [];
        }

        $timezone = new DateTimeZone($this->defaultTimezone);
        $target   = $targetUtc->setTimezone($timezone);

        $notifications = [];

        foreach ($this->channelConfig as $channel => $config) {
            $sendTimeSpec = $config['send_time'] ?? $this->defaultSendTime;
            $sendTime     = $this->parseSendTime($sendTimeSpec);

            $currentHour   = $sendTime['hour'];
            $currentMinute = $sendTime['minute'];

            foreach ($config['lead_times'] as $leadSpec) {
                $interval = $this->createInterval($leadSpec);
                if (!$interval instanceof DateInterval) {
                    continue;
                }

                $sendAt = $target->sub($interval)->setTime($currentHour, $currentMinute, 0);
                if ($sendAt < $reference) {
                    continue;
                }

                $notifications[] = [
                    'kanal'            => $channel,
                    'sendeZeitpunkt'   => $sendAt->format(DateTimeInterface::ATOM),
                    'vorlauf'          => $leadSpec,
                    'triggerZeitpunkt' => $target->format(DateTimeInterface::ATOM),
                    'algorithmus'      => $item->getAlgorithm(),
                    'titel'            => $item->getTitle(),
                ];
            }
        }

        usort(
            $notifications,
            static fn (array $left, array $right): int => strcmp($left['sendeZeitpunkt'], $right['sendeZeitpunkt'])
        );

        return $notifications;
    }

    /**
     * @param array<string, scalar|array|null> $params
     */
    private function resolveTargetDate(array $params): ?DateTimeImmutable
    {
        $range = $params['time_range'] ?? null;
        if (!is_array($range)) {
            return null;
        }

        $timestamp = $range['from'] ?? $range['to'] ?? null;
        if (!is_numeric($timestamp)) {
            return null;
        }

        $value = (int) $timestamp;
        if ($value <= 0) {
            return null;
        }

        return new DateTimeImmutable('@' . $value);
    }

    /**
     * @param array<string, array{lead_times?: list<string>, send_time?: string|null}> $config
     *
     * @return array<string, array{lead_times: list<string>, send_time: ?string}>
     */
    private function normaliseChannelConfig(array $config): array
    {
        $normalised = [];

        foreach ($config as $channel => $options) {
            if (!is_string($channel)) {
                continue;
            }

            $channelName = trim($channel);
            if ($channelName === '') {
                continue;
            }

            $leadTimes = [];
            $rawLead   = $options['lead_times'] ?? null;
            if (is_array($rawLead)) {
                foreach ($rawLead as $candidate) {
                    if (!is_string($candidate)) {
                        continue;
                    }

                    $spec = trim($candidate);
                    if ($spec === '') {
                        continue;
                    }

                    if ($this->createInterval($spec) instanceof DateInterval) {
                        $leadTimes[] = $spec;
                    }
                }
            }

            if ($leadTimes === []) {
                continue;
            }

            $sendTime = null;
            if (array_key_exists('send_time', $options) && is_string($options['send_time'])) {
                $normalisedSend = $this->normaliseSendTimeString($options['send_time']);
                if ($normalisedSend !== null) {
                    $sendTime = $normalisedSend;
                }
            }

            $normalised[$channelName] = [
                'lead_times' => $leadTimes,
                'send_time'  => $sendTime,
            ];
        }

        return $normalised;
    }

    private function normaliseSendTimeString(string $value): ?string
    {
        $parsed = $this->parseSendTime($value);

        return $parsed['spec'] ?? null;
    }

    /**
     * @return array{hour: int, minute: int, spec: string}
     */
    private function parseSendTime(string $value): array
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return ['hour' => 9, 'minute' => 0, 'spec' => '09:00'];
        }

        if (preg_match('/^(\d{1,2}):(\d{2})$/', $trimmed, $matches) !== 1) {
            return ['hour' => 9, 'minute' => 0, 'spec' => '09:00'];
        }

        $hour   = (int) $matches[1];
        $minute = (int) $matches[2];

        if ($hour < 0 || $hour > 23 || $minute < 0 || $minute > 59) {
            return ['hour' => 9, 'minute' => 0, 'spec' => '09:00'];
        }

        return [
            'hour' => $hour,
            'minute' => $minute,
            'spec' => sprintf('%02d:%02d', $hour, $minute),
        ];
    }

    private function createInterval(string $spec): ?DateInterval
    {
        $trimmed = trim($spec);
        if ($trimmed === '') {
            return null;
        }

        try {
            return new DateInterval($trimmed);
        } catch (Exception) {
            return null;
        }
    }
}
