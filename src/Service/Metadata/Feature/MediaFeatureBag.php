<?php

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Metadata\Feature;

use InvalidArgumentException;
use MagicSunday\Memories\Entity\Enum\ContentKind;
use ValueError;

use function array_is_list;
use function array_key_exists;
use function array_values;
use function count;
use function explode;
use function implode;
use function in_array;
use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_string;
use function preg_match;
use function sprintf;
use function str_contains;

/**
 * Provides a typed accessor facade for metadata feature payloads persisted on a media entity.
 *
 * @phpstan-type FeatureScalar bool|int|float|string
 * @phpstan-type FeatureArray array<int|string, FeatureScalar|FeatureArray|null>
 * @phpstan-type FeatureValue FeatureScalar|FeatureArray|null
 */
final class MediaFeatureBag
{
    public const NAMESPACE_CALENDAR = 'calendar';
    public const NAMESPACE_SOLAR = 'solar';
    public const NAMESPACE_FILE = 'file';
    public const NAMESPACE_CLASSIFICATION = 'classification';

    private const CALENDAR_DAYPART_VALUES = ['morning', 'noon', 'evening', 'night'];

    private const CALENDAR_SEASON_VALUES = ['winter', 'spring', 'summer', 'autumn'];

    private const FILENAME_HINT_VALUES = ['normal', 'pano', 'edited', 'timelapse', 'slowmo'];

    /**
     * @var array<string, string>
     */
    private const LEGACY_NAMESPACE_FALLBACKS = [
        'daypart'      => self::NAMESPACE_CALENDAR,
        'dow'          => self::NAMESPACE_CALENDAR,
        'isWeekend'    => self::NAMESPACE_CALENDAR,
        'season'       => self::NAMESPACE_CALENDAR,
        'isHoliday'    => self::NAMESPACE_CALENDAR,
        'holidayId'    => self::NAMESPACE_CALENDAR,
        'isGoldenHour' => self::NAMESPACE_SOLAR,
        'isPolarDay'   => self::NAMESPACE_SOLAR,
        'isPolarNight' => self::NAMESPACE_SOLAR,
        'pathTokens'   => self::NAMESPACE_FILE,
        'filenameHint' => self::NAMESPACE_FILE,
        'kind'         => self::NAMESPACE_CLASSIFICATION,
        'confidence'   => self::NAMESPACE_CLASSIFICATION,
        'shouldHide'   => self::NAMESPACE_CLASSIFICATION,
    ];
    /** @var array<string, array<string, FeatureValue>> */
    private array $values;

    /**
     * @param array<string, array<string, FeatureValue>> $values
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
     * @param array<int|string, mixed>|null $features
     */
    public static function fromArray(?array $features): self
    {
        if ($features === null) {
            return self::create();
        }

        if ($features === []) {
            return self::create();
        }

        $features = self::normaliseFeatureNamespaces($features);

        self::assertNamespacedFormat($features);

        /** @var array<string, array<string, FeatureValue>> $typed */
        $typed = [];
        foreach ($features as $namespace => $payload) {
            /** @var array<string, FeatureValue> $typedPayload */
            $typedPayload            = $payload;
            $typed[(string) $namespace] = $typedPayload;
        }

        return new self($typed);
    }

    /**
     * @param array<int|string, mixed> $features
     *
     * @return array<int|string, mixed>
     */
    private static function normaliseFeatureNamespaces(array $features): array
    {
        $normalised = [];

        foreach ($features as $key => $value) {
            if (is_string($key) && array_key_exists($key, self::LEGACY_NAMESPACE_FALLBACKS)) {
                $namespace = self::LEGACY_NAMESPACE_FALLBACKS[$key];

                if (array_key_exists($namespace, $normalised) === false || !is_array($normalised[$namespace])) {
                    $normalised[$namespace] = [];
                }

                $normalised[$namespace][$key] = $value;

                continue;
            }

            if (is_string($key) && str_contains($key, '.')) {
                $segments = explode('.', $key, 2);

                if (count($segments) === 2 && $segments[0] !== '' && $segments[1] !== '') {
                    [$namespace, $subKey] = $segments;

                    if (array_key_exists($namespace, $normalised) === false || !is_array($normalised[$namespace])) {
                        $normalised[$namespace] = [];
                    }

                    $normalised[$namespace][$subKey] = $value;

                    continue;
                }
            }

            $normalised[(string) $key] = $value;
        }

        return $normalised;
    }

    /**
     * @return array<string, array<string, FeatureValue>>
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
        if ($value !== null && in_array($value, self::CALENDAR_DAYPART_VALUES, true) === false) {
            throw new InvalidArgumentException('Calendar daypart must be one of: ' . implode(', ', self::CALENDAR_DAYPART_VALUES) . '.');
        }

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
        if ($value !== null && ($value < 1 || $value > 7)) {
            throw new InvalidArgumentException('Calendar day-of-week expects a value between 1 (Monday) and 7 (Sunday).');
        }

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
        if ($value !== null && in_array($value, self::CALENDAR_SEASON_VALUES, true) === false) {
            throw new InvalidArgumentException('Calendar season must be one of: ' . implode(', ', self::CALENDAR_SEASON_VALUES) . '.');
        }

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
        if ($value !== null && preg_match('/^[a-z]{2}-[a-z0-9_-]+$/', $value) !== 1) {
            throw new InvalidArgumentException('Calendar holiday identifier must match the pattern ll-slug (e.g. de-weihnachten).');
        }

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

    public function classificationKind(): ?ContentKind
    {
        $value = $this->get(self::NAMESPACE_CLASSIFICATION, 'kind');

        if ($value === null) {
            return null;
        }

        if (is_string($value) === false) {
            throw new InvalidArgumentException('Classification kind expects a string payload.');
        }

        try {
            return ContentKind::from($value);
        } catch (ValueError $exception) {
            throw new InvalidArgumentException('Classification kind must be a valid ContentKind value.', 0, $exception);
        }
    }

    public function setClassificationKind(?ContentKind $kind): void
    {
        $this->set(self::NAMESPACE_CLASSIFICATION, 'kind', $kind?->value);
    }

    public function classificationConfidence(): ?float
    {
        $value = $this->get(self::NAMESPACE_CLASSIFICATION, 'confidence');

        if ($value === null) {
            return null;
        }

        if (is_float($value) === false && is_int($value) === false) {
            throw new InvalidArgumentException('Classification confidence expects a numeric payload.');
        }

        $confidence = (float) $value;
        if ($confidence < 0.0 || $confidence > 1.0) {
            throw new InvalidArgumentException('Classification confidence must be between 0.0 and 1.0.');
        }

        return $confidence;
    }

    public function setClassificationConfidence(?float $confidence): void
    {
        if ($confidence !== null && ($confidence < 0.0 || $confidence > 1.0)) {
            throw new InvalidArgumentException('Classification confidence must be between 0.0 and 1.0.');
        }

        $this->set(self::NAMESPACE_CLASSIFICATION, 'confidence', $confidence);
    }

    public function classificationShouldHide(): ?bool
    {
        $value = $this->get(self::NAMESPACE_CLASSIFICATION, 'shouldHide');

        if ($value === null) {
            return null;
        }

        if (is_bool($value) === false) {
            throw new InvalidArgumentException('Classification visibility flag expects a boolean payload.');
        }

        return $value;
    }

    public function setClassificationShouldHide(?bool $shouldHide): void
    {
        $this->set(self::NAMESPACE_CLASSIFICATION, 'shouldHide', $shouldHide);
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

        foreach ($tokens as $token) {
            if (!is_string($token)) {
                throw new InvalidArgumentException('Filename tokens must be provided as list of strings.');
            }
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
        if ($value !== null && in_array($value, self::FILENAME_HINT_VALUES, true) === false) {
            throw new InvalidArgumentException('Filename hint must be one of: ' . implode(', ', self::FILENAME_HINT_VALUES) . '.');
        }

        $this->set(self::NAMESPACE_FILE, 'filenameHint', $value);
    }

    /**
     * @return array<string, FeatureValue>
     */
    public function namespaceValues(string $namespace): array
    {
        if (array_key_exists($namespace, $this->values) === false) {
            return [];
        }

        /** @var array<string, FeatureValue> $values */
        $values = $this->values[$namespace];

        return $values;
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

        self::assertFeatureValue($value, sprintf('%s.%s', $namespace, $key));

        if (array_key_exists($namespace, $this->values) === false) {
            $this->values[$namespace] = [];
        }

        $this->values[$namespace][$key] = $value;
    }

    private function get(string $namespace, string $key): bool|int|float|string|array|null
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
     * @param array<int|string, mixed> $features
     */
    private static function assertNamespacedFormat(array $features): void
    {
        foreach ($features as $namespace => $payload) {
            if (!is_array($payload)) {
                throw new InvalidArgumentException('Media features must be provided in namespaced format.');
            }

            foreach ($payload as $key => $value) {
                self::assertFeatureValue($value, sprintf('%s.%s', (string) $namespace, (string) $key));
            }
        }
    }

    private static function assertFeatureValue(mixed $value, string $path): void
    {
        if ($value === null) {
            return;
        }

        if (self::isScalarValue($value)) {
            return;
        }

        if (!is_array($value)) {
            throw new InvalidArgumentException(sprintf('Unsupported feature value at "%s".', $path));
        }

        if (array_is_list($value)) {
            foreach ($value as $index => $item) {
                self::assertFeatureValue($item, sprintf('%s[%d]', $path, $index));
            }

            return;
        }

        foreach ($value as $key => $item) {
            self::assertFeatureValue($item, sprintf('%s.%s', $path, (string) $key));
        }
    }

    private static function isScalarValue(mixed $value): bool
    {
        return is_bool($value) || is_int($value) || is_float($value) || is_string($value);
    }
}
