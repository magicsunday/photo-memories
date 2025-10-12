<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Command;

use InvalidArgumentException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

/**
 * Shared helper for console commands that expose selection override options.
 */
trait SelectionOverrideInputTrait
{
    private function configureSelectionOverrideOptions(): void
    {
        $this->addOption(
            'sel-target-total',
            null,
            InputOption::VALUE_REQUIRED,
            'Zielanzahl kuratierter Medien für die Auswahl'
        );

        $this->addOption(
            'sel-max-per-day',
            null,
            InputOption::VALUE_REQUIRED,
            'Maximale Anzahl kuratierter Medien pro Tag'
        );

        $this->addOption(
            'sel-time-slot',
            null,
            InputOption::VALUE_REQUIRED,
            'Zeitfenster in Stunden zur Verteilung der kuratierten Medien'
        );

        $this->addOption(
            'sel-min-spacing',
            null,
            InputOption::VALUE_REQUIRED,
            'Mindestabstand in Sekunden zwischen kuratierten Medien'
        );

        $this->addOption(
            'sel-phash-hamming',
            null,
            InputOption::VALUE_REQUIRED,
            'Minimaler pHash-Hamming-Abstand für kuratierte Medien'
        );

        $this->addOption(
            'sel-max-staypoint',
            null,
            InputOption::VALUE_REQUIRED,
            'Maximale Anzahl kuratierter Medien pro Aufenthaltsort (0 = ohne Limit)'
        );

        $this->addOption(
            'sel-video-bonus',
            null,
            InputOption::VALUE_REQUIRED,
            'Zusätzlicher Punktebonus für Videos in der Auswahl'
        );

        $this->addOption(
            'sel-face-bonus',
            null,
            InputOption::VALUE_REQUIRED,
            'Zusätzlicher Punktebonus für Medien mit erkannten Gesichtern'
        );

        $this->addOption(
            'sel-selfie-penalty',
            null,
            InputOption::VALUE_REQUIRED,
            'Malus für Selfie-ähnliche Szenen in der Auswahl'
        );
    }

    /**
     * @return array<string, int|float|null>
     */
    private function resolveSelectionOverrides(InputInterface $input): array
    {
        $overrides = [];

        $targetTotal = $this->parseIntOption($input->getOption('sel-target-total'), 1, 'sel-target-total');
        if ($targetTotal !== null) {
            $overrides['target_total'] = $targetTotal;
        }

        $maxPerDay = $this->parseIntOption($input->getOption('sel-max-per-day'), 1, 'sel-max-per-day');
        if ($maxPerDay !== null) {
            $overrides['max_per_day'] = $maxPerDay;
        }

        $timeSlot = $this->parseFloatOption($input->getOption('sel-time-slot'), 0.1, 'sel-time-slot');
        if ($timeSlot !== null) {
            $overrides['time_slot_hours'] = $timeSlot;
        }

        $minSpacing = $this->parseIntOption($input->getOption('sel-min-spacing'), 0, 'sel-min-spacing');
        if ($minSpacing !== null) {
            $overrides['min_spacing_seconds'] = $minSpacing;
        }

        $phash = $this->parseIntOption($input->getOption('sel-phash-hamming'), 0, 'sel-phash-hamming');
        if ($phash !== null) {
            $overrides['phash_min_hamming'] = $phash;
        }

        $maxStaypointRaw = $input->getOption('sel-max-staypoint');
        if ($maxStaypointRaw !== null && $maxStaypointRaw !== '') {
            $maxStaypoint = $this->parseIntOption($maxStaypointRaw, 0, 'sel-max-staypoint');
            if ($maxStaypoint === 0) {
                $overrides['max_per_staypoint']         = null;
                $overrides['max_per_staypoint_relaxed'] = null;
            } elseif ($maxStaypoint !== null) {
                $overrides['max_per_staypoint']         = $maxStaypoint;
                $overrides['max_per_staypoint_relaxed'] = $maxStaypoint;
            }
        }

        $videoBonus = $this->parseFloatOption($input->getOption('sel-video-bonus'), 0.0, 'sel-video-bonus');
        if ($videoBonus !== null) {
            $overrides['video_bonus'] = $videoBonus;
        }

        $faceBonus = $this->parseFloatOption($input->getOption('sel-face-bonus'), 0.0, 'sel-face-bonus');
        if ($faceBonus !== null) {
            $overrides['face_bonus'] = $faceBonus;
        }

        $selfiePenalty = $this->parseFloatOption($input->getOption('sel-selfie-penalty'), 0.0, 'sel-selfie-penalty');
        if ($selfiePenalty !== null) {
            $overrides['selfie_penalty'] = $selfiePenalty;
        }

        return $overrides;
    }

    private function parseIntOption(mixed $rawValue, int $minValue, string $optionName): ?int
    {
        if ($rawValue === null || $rawValue === '') {
            return null;
        }

        if (is_int($rawValue)) {
            $intValue = $rawValue;
        } elseif (is_string($rawValue)) {
            if (!is_numeric($rawValue)) {
                throw new InvalidArgumentException(
                    sprintf('Option "--%s" requires a numeric value.', $optionName)
                );
            }

            $intValue = (int) $rawValue;
        } elseif (is_numeric($rawValue)) {
            $intValue = (int) $rawValue;
        } else {
            throw new InvalidArgumentException(
                sprintf('Option "--%s" requires a numeric value.', $optionName)
            );
        }

        if ($intValue < $minValue) {
            $comparison = $minValue === 0
                ? 'greater than or equal to 0'
                : sprintf('at least %d', $minValue);

            throw new InvalidArgumentException(
                sprintf('Option "--%s" must be %s.', $optionName, $comparison)
            );
        }

        return $intValue;
    }

    private function parseFloatOption(mixed $rawValue, float $minValue, string $optionName): ?float
    {
        if ($rawValue === null || $rawValue === '') {
            return null;
        }

        if (is_float($rawValue) || is_int($rawValue)) {
            $floatValue = (float) $rawValue;
        } elseif (is_string($rawValue)) {
            if (!is_numeric($rawValue)) {
                throw new InvalidArgumentException(
                    sprintf('Option "--%s" requires a numeric value.', $optionName)
                );
            }

            $floatValue = (float) $rawValue;
        } elseif (is_numeric($rawValue)) {
            $floatValue = (float) $rawValue;
        } else {
            throw new InvalidArgumentException(
                sprintf('Option "--%s" requires a numeric value.', $optionName)
            );
        }

        if ($floatValue < $minValue) {
            $comparison = $minValue === 0.0
                ? 'greater than or equal to 0'
                : sprintf('at least %.1f', $minValue);

            throw new InvalidArgumentException(
                sprintf('Option "--%s" must be %s.', $optionName, $comparison)
            );
        }

        return $floatValue;
    }
}
