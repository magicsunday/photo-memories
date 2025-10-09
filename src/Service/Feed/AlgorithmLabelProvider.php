<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Feed;

use function array_key_exists;
use function is_string;
use function mb_convert_case;
use function str_replace;
use function trim;

use const MB_CASE_TITLE;

/**
 * Provides localized labels for feed algorithms.
 */
final class AlgorithmLabelProvider
{
    /**
     * @var array<string,string>
     */
    private array $labels = [];

    /**
     * @param array<string,string> $labels
     */
    public function __construct(array $labels = [])
    {
        foreach ($labels as $algorithm => $label) {
            if (!is_string($algorithm) || $algorithm === '') {
                continue;
            }

            if (!is_string($label) || $label === '') {
                continue;
            }

            $this->labels[$algorithm] = $label;
        }
    }

    public function getLabel(string $algorithm): string
    {
        if ($algorithm === '') {
            return 'Strategie';
        }

        if (array_key_exists($algorithm, $this->labels)) {
            return $this->labels[$algorithm];
        }

        $normalized = trim(str_replace('_', ' ', $algorithm));
        if ($normalized === '') {
            return 'Strategie';
        }

        return mb_convert_case($normalized, MB_CASE_TITLE, 'UTF-8');
    }
}
