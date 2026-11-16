<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Support;

use MagicSunday\Memories\Support\FeatureFlagProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterNotFoundException;
use UnitEnum;

use function array_key_exists;

/**
 * @covers \MagicSunday\Memories\Support\FeatureFlagProvider
 */
#[CoversClass(FeatureFlagProvider::class)]
final class FeatureFlagProviderTest extends TestCase
{
    public function testItReturnsFalseWhenFlagIsNotConfigured(): void
    {
        $provider = new FeatureFlagProvider(new ParameterBag());

        self::assertFalse($provider->isEnabled('unknown_flag'));
    }

    public function testItResolvesBooleanValuesFromParameters(): void
    {
        $bag = new ParameterBag([
            'memories.features.saliency_cropping.enabled'   => true,
            'memories.features.storyline_generator.enabled' => '0',
        ]);

        $provider = new FeatureFlagProvider($bag);

        self::assertTrue($provider->isEnabled('saliency_cropping'));
        self::assertFalse($provider->isEnabled('storyline_generator'));
    }

    public function testItCachesResolvedValuesPerFlag(): void
    {
        $bag = new CountingParameterBag([
            'memories.features.saliency_cropping.enabled' => true,
        ]);

        $provider = new FeatureFlagProvider($bag);

        self::assertTrue($provider->isEnabled('saliency_cropping'));
        self::assertTrue($provider->isEnabled('saliency_cropping'));
        self::assertSame(1, $bag->getCallCount());
    }
}

/**
 * Parameter bag that counts how often a parameter is read.
 */
final class CountingParameterBag implements ParameterBagInterface
{
    private int $getCalls = 0;

    /**
     * @param array<string, mixed> $parameters
     */
    public function __construct(private array $parameters)
    {
    }

    public function clear(): void
    {
        $this->parameters = [];
    }

    /**
     * @param array<string, mixed> $parameters
     */
    public function add(array $parameters): void
    {
        $this->parameters = $parameters + $this->parameters;
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->parameters;
    }

    /**
     * @return array<mixed>|bool|string|int|float|UnitEnum|null
     */
    public function get(string $name): array|bool|string|int|float|UnitEnum|null
    {
        ++$this->getCalls;

        if (!array_key_exists($name, $this->parameters)) {
            throw new ParameterNotFoundException($name);
        }

        return $this->parameters[$name];
    }

    public function remove(string $name): void
    {
        unset($this->parameters[$name]);
    }

    /**
     * @param array<mixed>|bool|string|int|float|UnitEnum|null $value
     */
    public function set(string $name, array|bool|string|int|float|UnitEnum|null $value): void
    {
        $this->parameters[$name] = $value;
    }

    public function has(string $name): bool
    {
        return array_key_exists($name, $this->parameters);
    }

    public function resolve(): void
    {
    }

    public function resolveValue(mixed $value): mixed
    {
        return $value;
    }

    public function escapeValue(mixed $value): mixed
    {
        return $value;
    }

    public function unescapeValue(mixed $value): mixed
    {
        return $value;
    }

    public function getCallCount(): int
    {
        return $this->getCalls;
    }
}
