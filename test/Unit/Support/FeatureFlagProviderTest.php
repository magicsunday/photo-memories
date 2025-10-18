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
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterNotFoundException;

use function array_key_exists;

/**
 * @covers \MagicSunday\Memories\Support\FeatureFlagProvider
 */
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
            'memories.features.saliency_cropping.enabled'     => true,
            'memories.features.storyline_generator.enabled' => '0',
        ]);

        $provider = new FeatureFlagProvider($bag);

        self::assertTrue($provider->isEnabled('saliency_cropping'));
        self::assertFalse($provider->isEnabled('storyline_generator'));
    }

    public function testItCachesResolvedValuesPerFlag(): void
    {
        /** @var ParameterBagInterface&object{getCallCount(): int} $bag */
        $bag = $this->createCountingParameterBag([
            'memories.features.saliency_cropping.enabled' => true,
        ]);

        $provider = new FeatureFlagProvider($bag);

        self::assertTrue($provider->isEnabled('saliency_cropping'));
        self::assertTrue($provider->isEnabled('saliency_cropping'));
        self::assertSame(1, $bag->getCallCount());
    }

    /**
     * @param array<string, mixed> $parameters
     *
     * @return ParameterBagInterface&object{getCallCount(): int}
     */
    private function createCountingParameterBag(array $parameters): ParameterBagInterface
    {
        return new class($parameters) implements ParameterBagInterface {
            private int $getCalls = 0;

            public function __construct(private array $parameters)
            {
            }

            public function clear(): void
            {
                $this->parameters = [];
            }

            public function add(array $parameters): void
            {
                $this->parameters = $parameters + $this->parameters;
            }

            public function all(): array
            {
                return $this->parameters;
            }

            public function get(string $name): array|bool|string|int|float|\UnitEnum|null
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

            public function set(string $name, array|bool|string|int|float|\UnitEnum|null $value): void
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
        };
    }
}
