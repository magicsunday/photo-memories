<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Clusterer\Title;

use DateTimeImmutable;
use DateTimeZone;
use IntlDateFormatter;
use Locale;

use function array_key_exists;
use function explode;
use function in_array;
use function sprintf;
use function str_starts_with;
use function trim;

/**
 * Formats date and range strings using locale aware short month representations.
 */
final class LocalizedDateFormatter
{
    /** @var array<int, string> */
    private const array DE_SHORT_MONTHS = [
        1  => 'Jan.',
        2  => 'Feb.',
        3  => 'Mär.',
        4  => 'Apr.',
        5  => 'Mai',
        6  => 'Jun.',
        7  => 'Jul.',
        8  => 'Aug.',
        9  => 'Sep.',
        10 => 'Okt.',
        11 => 'Nov.',
        12 => 'Dez.',
    ];

    public function __construct(private readonly string $timezone = 'Europe/Berlin')
    {
    }

    public function formatDate(mixed $timestamp, string $locale = 'de'): string
    {
        $ts = $this->scalarToInt($timestamp);
        if ($ts <= 0) {
            return '';
        }

        $date = (new DateTimeImmutable('@' . $ts))->setTimezone(new DateTimeZone($this->timezone));

        if ($this->isGermanLocale($locale)) {
            return $this->formatGermanDate($date, true);
        }

        return $this->formatIntlDate($date, $locale, 'd. MMM yyyy');
    }

    public function formatRange(mixed $range, string $locale = 'de'): string
    {
        if (!is_array($range) || !array_key_exists('from', $range) || !array_key_exists('to', $range)) {
            return '';
        }

        $from = $this->scalarToInt($range['from']);
        $to   = $this->scalarToInt($range['to']);
        if ($from <= 0 || $to <= 0) {
            return '';
        }

        $fromDate = (new DateTimeImmutable('@' . $from))->setTimezone(new DateTimeZone($this->timezone));
        $toDate   = (new DateTimeImmutable('@' . $to))->setTimezone(new DateTimeZone($this->timezone));

        if ($fromDate > $toDate) {
            [$fromDate, $toDate] = [$toDate, $fromDate];
        }

        if ($this->isGermanLocale($locale)) {
            return $this->formatGermanRange($fromDate, $toDate);
        }

        return $this->formatIntlRange($fromDate, $toDate, $locale);
    }

    private function formatGermanRange(DateTimeImmutable $from, DateTimeImmutable $to): string
    {
        if ($from->format('Y-m-d') === $to->format('Y-m-d')) {
            return $this->formatGermanDate($from, true);
        }

        if ($from->format('Y') === $to->format('Y')) {
            if ($from->format('m') === $to->format('m')) {
                $month = $this->germanMonthName($from);

                return sprintf('%d.–%d. %s %d', (int) $from->format('j'), (int) $to->format('j'), $month, (int) $from->format('Y'));
            }

            return sprintf(
                '%s – %s',
                $this->formatGermanDate($from, false),
                $this->formatGermanDate($to, true),
            );
        }

        return sprintf(
            '%s – %s',
            $this->formatGermanDate($from, true),
            $this->formatGermanDate($to, true),
        );
    }

    private function formatGermanDate(DateTimeImmutable $date, bool $includeYear): string
    {
        $month = $this->germanMonthName($date);
        $day   = (int) $date->format('j');
        $base  = sprintf('%d. %s', $day, $month);

        return $includeYear ? $base . ' ' . $date->format('Y') : $base;
    }

    private function germanMonthName(DateTimeImmutable $date): string
    {
        $index = (int) $date->format('n');

        return self::DE_SHORT_MONTHS[$index] ?? $date->format('M');
    }

    private function formatIntlRange(DateTimeImmutable $from, DateTimeImmutable $to, string $locale): string
    {
        $locale = $this->normalizeLocale($locale);

        if ($from->format('Y-m-d') === $to->format('Y-m-d')) {
            return $this->formatIntlDate($from, $locale, 'd. MMM yyyy');
        }

        if ($from->format('Y') === $to->format('Y')) {
            if ($from->format('m') === $to->format('m')) {
                $dayFormatter   = $this->createFormatter($locale, 'd.');
                $monthFormatter = $this->createFormatter($locale, 'MMM yyyy');

                $fromDay = $dayFormatter->format($from);
                $toDay   = $dayFormatter->format($to);
                $month   = $monthFormatter->format($to);

                if ($fromDay === false || $toDay === false || $month === false) {
                    return '';
                }

                return trim(sprintf('%s–%s %s', $fromDay, $toDay, $month));
            }

            return sprintf(
                '%s – %s',
                $this->formatIntlDate($from, $locale, 'd. MMM'),
                $this->formatIntlDate($to, $locale, 'd. MMM yyyy'),
            );
        }

        return sprintf(
            '%s – %s',
            $this->formatIntlDate($from, $locale, 'd. MMM yyyy'),
            $this->formatIntlDate($to, $locale, 'd. MMM yyyy'),
        );
    }

    private function formatIntlDate(DateTimeImmutable $date, string $locale, string $pattern): string
    {
        $formatter = $this->createFormatter($locale, $pattern);
        $result    = $formatter->format($date);

        return $result === false ? '' : trim($result);
    }

    private function createFormatter(string $locale, string $pattern): IntlDateFormatter
    {
        $formatter = new IntlDateFormatter(
            $this->normalizeLocale($locale),
            IntlDateFormatter::NONE,
            IntlDateFormatter::NONE,
            $this->timezone,
            null,
            $pattern,
        );

        return $formatter;
    }

    private function isGermanLocale(string $locale): bool
    {
        $normalized = $this->normalizeLocale($locale);

        if (str_starts_with($normalized, 'de')) {
            return true;
        }

        $primary = Locale::getPrimaryLanguage($normalized);

        return $primary === 'de';
    }

    private function normalizeLocale(string $locale): string
    {
        $canonical = Locale::canonicalize($locale);
        if ($canonical === null || $canonical === '') {
            $canonical = 'de_DE';
        }

        $segments = explode('_', str_replace('-', '_', $canonical));
        if (count($segments) === 1) {
            $lang = $segments[0];
            if (in_array($lang, ['de', 'en', 'fr', 'it', 'es'], true)) {
                return $lang . '_' . strtoupper($lang);
            }

            return $lang;
        }

        $lang = trim($segments[0]);
        $region = trim($segments[1] ?? '');

        if ($region === '') {
            $region = strtoupper($lang);
        }

        return $lang . '_' . strtoupper($region);
    }

    private function scalarToInt(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && $value !== '') {
            return (int) $value;
        }

        if (is_float($value)) {
            return (int) $value;
        }

        return 0;
    }
}
