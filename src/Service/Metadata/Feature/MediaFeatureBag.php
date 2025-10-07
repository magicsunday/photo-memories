<?php

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Metadata\Feature;

use InvalidArgumentException;

/**
 * Provides a typed accessor facade for metadata feature payloads persisted on a media entity.
 */
final class MediaFeatureBag
{
    public const NAMESPACE_CALENDAR = 'calendar';
    public const NAMESPACE_SOLAR = 'solar';
    public const NAMESPACE_FILE = 'file';
    public const NAMESPACE_CLASSIFICATION = 'classification';
    /**
     * @var array<string, array<string, scalar|array|null>>
     */
    private array $values;

    /**
     * @param array<string, array<string, scalar|array|null>> $values
     */
    private function __construct(array $values)
    {
        $this->values = $values;
    }

    public static function create(): self
    {
        return new self([]);
    }

    /**
     * @param array<string, array<string, scalar|array|null>>|null $features
     */
    public static function fromArray(?array $features): self
    {
        if ($features === null) {
            return self::create();
        }

        if ($features === []) {
            return self::create();
        }

        if (self::isNamespacedFormat($features) === false) {
            throw new InvalidArgumentException('Media features must be provided in namespaced format.');
        }

        /** @var array<string, array<string, scalar|array|null>> $typed */
        $typed = $features;

        return new self($typed);
    }

    /**
     * @return array<string, array<string, scalar|array|null>>
     */
    public function toArray(): array
    {
        return $this->values;
    }

    public function calendarDaypart(): ?string
    {
        $value = $this->get(self::NAMESPACE_CALENDAR, 'daypart');

        return $value === null ? null : (string) $value;
    }

    public function setCalendarDaypart(?string $value): void
    {
        $this->set(self::NAMESPACE_CALENDAR, 'daypart', $value);
    }

    public function calendarDayOfWeek(): ?int
    {
        $value = $this->get(self::NAMESPACE_CALENDAR, 'dow');

        if ($value === null) {
            return null;
        }

        if (is_int($value) === false) {
            throw new InvalidArgumentException('Calendar day-of-week expects an integer payload.');
        }

        return $value;
    }

    public function setCalendarDayOfWeek(?int $value): void
    {
        $this->set(self::NAMESPACE_CALENDAR, 'dow', $value);
    }

    public function calendarIsWeekend(): ?bool
    {
        $value = $this->get(self::NAMESPACE_CALENDAR, 'isWeekend');

        if ($value === null) {
            return null;
        }

        if (is_bool($value) === false) {
            throw new InvalidArgumentException('Calendar weekend feature expects a boolean payload.');
        }

        return $value;
    }

    public function setCalendarIsWeekend(?bool $value): void
    {
        $this->set(self::NAMESPACE_CALENDAR, 'isWeekend', $value);
    }

    public function calendarSeason(): ?string
    {
        $value = $this->get(self::NAMESPACE_CALENDAR, 'season');

        return $value === null ? null : (string) $value;
    }

    public function setCalendarSeason(?string $value): void
    {
        $this->set(self::NAMESPACE_CALENDAR, 'season', $value);
    }

    public function calendarIsHoliday(): ?bool
    {
        $value = $this->get(self::NAMESPACE_CALENDAR, 'isHoliday');

        if ($value === null) {
            return null;
        }

        if (is_bool($value) === false) {
            throw new InvalidArgumentException('Calendar holiday flag expects a boolean payload.');
        }

        return $value;
    }

    public function setCalendarIsHoliday(?bool $value): void
    {
        $this->set(self::NAMESPACE_CALENDAR, 'isHoliday', $value);
    }

    public function calendarHolidayId(): ?string
    {
        $value = $this->get(self::NAMESPACE_CALENDAR, 'holidayId');

        return $value === null ? null : (string) $value;
    }

    public function setCalendarHolidayId(?string $value): void
    {
        $this->set(self::NAMESPACE_CALENDAR, 'holidayId', $value);
    }

    public function solarIsGoldenHour(): ?bool
    {
        $value = $this->get(self::NAMESPACE_SOLAR, 'isGoldenHour');

        if ($value === null) {
            return null;
        }

        if (is_bool($value) === false) {
            throw new InvalidArgumentException('Solar golden hour flag expects a boolean payload.');
        }

        return $value;
    }

    public function setSolarIsGoldenHour(?bool $value): void
    {
        $this->set(self::NAMESPACE_SOLAR, 'isGoldenHour', $value);
    }

    public function solarIsPolarDay(): ?bool
    {
        $value = $this->get(self::NAMESPACE_SOLAR, 'isPolarDay');

        if ($value === null) {
            return null;
        }

        if (is_bool($value) === false) {
            throw new InvalidArgumentException('Solar polar day flag expects a boolean payload.');
        }

        return $value;
    }

    public function setSolarIsPolarDay(?bool $value): void
    {
        $this->set(self::NAMESPACE_SOLAR, 'isPolarDay', $value);
    }

    public function solarIsPolarNight(): ?bool
    {
        $value = $this->get(self::NAMESPACE_SOLAR, 'isPolarNight');

        if ($value === null) {
            return null;
        }

        if (is_bool($value) === false) {
            throw new InvalidArgumentException('Solar polar night flag expects a boolean payload.');
        }

        return $value;
    }

    public function setSolarIsPolarNight(?bool $value): void
    {
        $this->set(self::NAMESPACE_SOLAR, 'isPolarNight', $value);
    }

    /**
     * @return list<string>|null
     */
    public function filePathTokens(): ?array
    {
        $value = $this->get(self::NAMESPACE_FILE, 'pathTokens');

        if ($value === null) {
            return null;
        }

        if (is_array($value) === false) {
            throw new InvalidArgumentException('Filename tokens expect a string list payload.');
        }

        return array_values(array_map(static fn ($token): string => (string) $token, $value));
    }

    /**
     * @param list<string>|null $tokens
     */
    public function setFilePathTokens(?array $tokens): void
    {
        if ($tokens === null) {
            $this->set(self::NAMESPACE_FILE, 'pathTokens', null);

            return;
        }

        $this->set(self::NAMESPACE_FILE, 'pathTokens', array_values($tokens));
    }

    public function fileNameHint(): ?string
    {
        $value = $this->get(self::NAMESPACE_FILE, 'filenameHint');

        return $value === null ? null : (string) $value;
    }

    public function setFileNameHint(?string $value): void
    {
        $this->set(self::NAMESPACE_FILE, 'filenameHint', $value);
    }

    /**
     * @return array<string, scalar|array|null>
     */
    public function namespaceValues(string $namespace): array
    {
        if (array_key_exists($namespace, $this->values) === false) {
            return [];
        }

        return $this->values[$namespace];
    }

    private function set(string $namespace, string $key, bool|int|float|string|array|null $value): void
    {
        if ($value === null) {
            if (array_key_exists($namespace, $this->values) === false) {
                return;
            }

            unset($this->values[$namespace][$key]);

            if (array_key_exists($namespace, $this->values) && count($this->values[$namespace]) === 0) {
                unset($this->values[$namespace]);
            }

            return;
        }

        if (array_key_exists($namespace, $this->values) === false) {
            $this->values[$namespace] = [];
        }

        $this->values[$namespace][$key] = $value;
    }

    private function get(string $namespace, string $key): scalar|array|null
    {
        if (array_key_exists($namespace, $this->values) === false) {
            return null;
        }

        if (array_key_exists($key, $this->values[$namespace]) === false) {
            return null;
        }

        return $this->values[$namespace][$key];
    }

    /**
     * @param array<string, array<string, scalar|array|null>>|array<string, scalar|array|null> $features
     */
    private static function isNamespacedFormat(array $features): bool
    {
        foreach ($features as $value) {
            if (is_array($value) === false) {
                return false;
            }
        }

        return true;
    }
}
