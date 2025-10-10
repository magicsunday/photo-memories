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
            'sel-min-spacing',
            null,
            InputOption::VALUE_REQUIRED,
            'Mindestabstand in Sekunden zwischen kuratierten Medien'
        );
    }

    /**
     * @return array<string, int>
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

        $minSpacing = $this->parseIntOption($input->getOption('sel-min-spacing'), 0, 'sel-min-spacing');
        if ($minSpacing !== null) {
            $overrides['min_spacing_seconds'] = $minSpacing;
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
}
