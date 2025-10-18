<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Support;

use InvalidArgumentException;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

use function array_key_exists;
use function is_bool;
use function is_float;
use function is_int;
use function is_string;
use function sprintf;
use function strtolower;
use function trim;

/**
 * Resolves feature toggle states from container parameters with optional caching.
 */
final class FeatureFlagProvider implements FeatureFlagProviderInterface
{
    private const string PARAMETER_TEMPLATE = 'memories.features.%s.enabled';

    /**
     * @var array<string, bool>
     */
    private array $cache = [];

    public function __construct(private readonly ParameterBagInterface $parameterBag)
    {
    }

    public function isEnabled(string $flag): bool
    {
        $normalized = trim($flag);
        if ($normalized === '') {
            throw new InvalidArgumentException('Feature flag name must not be empty.');
        }

        if (array_key_exists($normalized, $this->cache)) {
            return $this->cache[$normalized];
        }

        $parameterName = sprintf(self::PARAMETER_TEMPLATE, $normalized);
        if (!$this->parameterBag->has($parameterName)) {
            return $this->cache[$normalized] = false;
        }

        $rawValue = $this->parameterBag->get($parameterName);
        $enabled  = $this->normaliseToBool($rawValue);

        return $this->cache[$normalized] = $enabled;
    }

    private function normaliseToBool(array|bool|string|int|float|\UnitEnum|null $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if ($value instanceof \UnitEnum) {
            return true;
        }

        if (is_int($value)) {
            return $value !== 0;
        }

        if (is_float($value)) {
            return $value !== 0.0;
        }

        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            if ($normalized === '' || $normalized === '0' || $normalized === 'false' || $normalized === 'off' || $normalized === 'no') {
                return false;
            }

            return true;
        }

        return $value !== null;
    }
}
