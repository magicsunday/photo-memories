<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Slideshow;

use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;
use Throwable;

use function array_filter;
use function array_fill_keys;
use function array_intersect;
use function array_key_exists;
use function array_keys;
use function array_map;
use function array_merge;
use function array_search;
use function array_values;
use function ceil;
use function count;
use function dirname;
use function hash;
use function implode;
use function in_array;
use function is_dir;
use function is_file;
use function is_float;
use function is_readable;
use function is_string;
use function ltrim;
use function max;
use function min;
use function mkdir;
use function preg_match;
use function preg_split;
use function round;
use function rtrim;
use function sprintf;
use function str_replace;
use function strtolower;
use function trim;

/**
 * @internal
 */
final class SlideshowTransitionCache
{
    /**
     * @var list<string>|null
     */
    public ?array $whitelist = null;

    /**
     * @var array<string, bool>|null
     */
    public ?array $lookup = null;

    public ?string $lookupKey = null;
}

/**
 * FFmpeg based slideshow generator.
 */
final readonly class SlideshowVideoGenerator implements SlideshowVideoGeneratorInterface
{
    private const float MIN_TRANSITION_DURATION = 0.6;

    private const float MAX_TRANSITION_DURATION = 1.0;

    private const float MINIMUM_SLIDE_DURATION = 0.1;

    private const array TRANSITION_WHITELIST = [
        'fade',
        'dissolve',
        'fadeblack',
        'fadewhite',
        'wipeleft',
        'wiperight',
        'wipeup',
        'wipedown',
        'slideleft',
        'slideright',
        'slideup',
        'slidedown',
        'smoothleft',
        'smoothright',
        'smoothup',
        'smoothdown',
        'circleopen',
        'circleclose',
        'radial',
        'rectcrop',
        'pixelize',
        'diagtl',
        'diagtr',
        'diagbl',
        'diagbr',
    ];

    /**
     * Default list of transition names used when no custom set is provided.
     */
    private const array DEFAULT_TRANSITIONS = self::TRANSITION_WHITELIST;

    private const int ZOOMPAN_FPS = 30;

    private const float PAN_OFFSET_LIMIT = 0.05;

    /**
     * @param list<string> $transitions
     */
    public function __construct(
        private readonly string $ffmpegBinary = 'ffmpeg',
        private readonly float $slideDuration = 3.0,
        private readonly float $transitionDuration = 0.75,
        private readonly int $width = 1920,
        private readonly int $height = 1080,
        private readonly array $transitions = self::DEFAULT_TRANSITIONS,
        private readonly ?string $audioTrack = null,
        private readonly ?string $fontFile = null,
        private readonly string $fontFamily = 'DejaVu Sans',
        private readonly float $backgroundBlurSigma = 20.0,
        private readonly bool $kenBurnsEnabled = true,
        private readonly float $zoomStart = 1.0,
        private readonly float $zoomEnd = 1.08,
        private readonly float $introFadeDuration = 1.0,
        private readonly float $outroFadeDuration = 1.0,
    ) {
    }

    public function generate(SlideshowJob $job): void
    {
        $slides = $job->slides();
        if ($slides === []) {
            throw new RuntimeException('Cannot render slideshow without images.');
        }

        foreach ($slides as $slide) {
            if (!is_file($slide['image']) || !is_readable($slide['image'])) {
                throw new RuntimeException(sprintf('Slideshow image file "%s" is not readable.', $slide['image']));
            }
        }

        $directory = dirname($job->outputPath());
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException(sprintf('Could not create video directory "%s".', $directory));
        }

        $command = $this->buildCommand($job, $slides);

        $process = new Process($command);
        $process->setTimeout(null);
        $process->disableOutput();

        try {
            $process->mustRun();
        } catch (ProcessFailedException $exception) {
            $message = trim($exception->getProcess()->getErrorOutput());
            if ($message === '') {
                $message = trim($exception->getProcess()->getOutput());
            }

            if ($message === '') {
                $message = $exception->getMessage();
            }

            throw new RuntimeException($message, 0, $exception);
        }
    }

    /**
     * @param list<array{image:string,mediaId:int|null,duration:float,transition:string|null}> $slides
     *
     * @return list<string>
     */
    private function buildCommand(SlideshowJob $job, array $slides): array
    {
        $transitionDuration = $this->resolveTransitionDuration($job->transitionDuration());
        $audioTrack         = $job->audioTrack() ?? $this->audioTrack;

        if (count($slides) === 1) {
            return $this->buildSingleImageCommand(
                $slides[0],
                $job->outputPath(),
                $audioTrack,
                $job->title(),
                $job->subtitle(),
            );
        }

        return $this->buildMultiImageCommand(
            $slides,
            $job->images(),
            $transitionDuration,
            $job->transitionDurations(),
            $job->outputPath(),
            $audioTrack,
            $job->title(),
            $job->subtitle(),
        );
    }

    /**
     * @param array{image:string,mediaId:int|null,duration:float,transition:string|null} $slide
     *
     * @return list<string>
     */
    private function buildSingleImageCommand(
        array $slide,
        string $output,
        ?string $audioTrack,
        ?string $title,
        ?string $subtitle,
    ): array
    {
        $duration     = $this->resolveCoverDuration($slide);
        $clipDuration = max(0.1, $duration);
        $filter       = $this->buildBlurredSlideFilter(
            0,
            $clipDuration,
            $duration,
            $slide,
            $title,
            $subtitle,
        );

        $filter = $this->appendIntroOverlayFilter($filter, $title, $subtitle);

        $fadeChain = $this->buildVideoFadeChain($clipDuration);
        if ($fadeChain !== '') {
            $filter = sprintf('%s,%s', $filter, $fadeChain);
        }

        $filter .= sprintf(',trim=duration=%1$.3f,setpts=PTS-STARTPTS[vout]', $clipDuration);

        $command = [
            $this->ffmpegBinary,
            '-y',
            '-loglevel',
            'error',
            '-loop',
            '1',
            '-framerate',
            '30',
            '-t',
            sprintf('%0.3f', max(0.1, $duration)),
            '-i',
            $slide['image'],
            '-filter_complex',
            $filter,
        ];

        return $this->appendAudioOptions($command, 1, $output, $audioTrack, $title, $subtitle, $clipDuration);
    }

    /**
     * @param list<array{image:string,mediaId:int|null,duration:float,transition:string|null}> $slides
     * @param list<string>                                                                      $imagePaths
     * @param list<float>                                                                       $transitionDurations
     *
     * @return list<string>
     */
    private function buildMultiImageCommand(
        array $slides,
        array $imagePaths,
        float $transitionDuration,
        array $transitionDurations,
        string $output,
        ?string $audioTrack,
        ?string $title,
        ?string $subtitle,
    ): array
    {
        $command = [$this->ffmpegBinary, '-y', '-loglevel', 'error'];

        $transitionWhitelist   = $this->getTransitionWhitelist();
        $transitionCandidates  = $this->transitions;
        if ($transitionCandidates === self::DEFAULT_TRANSITIONS) {
            $transitionCandidates = $transitionWhitelist;
        }

        $availableTransitions = $this->filterAllowedTransitions($transitionCandidates);
        $requiredTransitions  = max(0, count($slides) - 1);
        $fallbackTransitions  = $this->buildDeterministicTransitionSequence(
            $availableTransitions,
            $imagePaths,
            $title,
            $subtitle,
            $requiredTransitions,
        );

        $transitionDurations = $this->resolveTransitionDurationsForSlides(
            $slides,
            $transitionDurations,
            $title,
            $subtitle,
        );

        $coverDuration = $this->resolveCoverDuration($slides[0]);

        $visibleDurations  = [];
        $overlapDurations  = [];

        foreach ($slides as $index => $slide) {
            if ($index === 0) {
                $duration = $coverDuration;
            } else {
                $duration = $this->resolveSlideDuration($slide['duration']);
            }

            $nextTransition  = max(0.0, $transitionDurations[$index] ?? 0.0);
            $visibleDuration = max(self::MINIMUM_SLIDE_DURATION, $duration);

            if ($index === 0) {
                $visibleDuration = max($coverDuration, $visibleDuration);
            }

            $overlapDuration          = min($visibleDuration, $nextTransition);
            $visibleDurations[$index] = $visibleDuration;
            $overlapDurations[$index] = $overlapDuration;

            $command = array_merge($command, [
                '-loop',
                '1',
                '-framerate',
                '30',
                '-t',
                sprintf('%0.3f', $visibleDuration),
                '-i',
                $slide['image'],
            ]);
        }

        $filters             = [];
        $introOverlayFilter  = $this->buildIntroTextOverlayFilterChain($title, $subtitle);

        foreach ($slides as $index => $slide) {
            $visibleDuration     = $visibleDurations[$index];
            $overlapDuration     = $overlapDurations[$index];
            $durationWithOverlap = max(self::MINIMUM_SLIDE_DURATION, $visibleDuration + $overlapDuration);

            $filter = $this->buildBlurredSlideFilter(
                $index,
                $durationWithOverlap,
                $visibleDuration,
                $slide,
                $title,
                $subtitle,
            );

            if ($index === 0 && $introOverlayFilter !== '') {
                $filter = sprintf('%s,%s', $filter, $introOverlayFilter);
            }

            $filter .= sprintf(',trim=duration=%1$.3f,setpts=PTS-STARTPTS[s%2$d]', $visibleDuration, $index);
            $filters[] = $filter;
        }

        $current        = '[s0]';
        $timeline       = $visibleDurations[0];
        $fallbackIndex  = 0;
        $previousChoice = null;

        for ($index = 1; $index < count($slides); ++$index) {
            $preferred = $this->normaliseTransitionName($slides[$index - 1]['transition'] ?? null);
            if ($preferred === null) {
                $transition = 'fade';
                $fallbackCount = count($fallbackTransitions);
                while ($fallbackIndex < $fallbackCount) {
                    $candidate = $fallbackTransitions[$fallbackIndex];
                    ++$fallbackIndex;
                    if ($candidate !== $previousChoice || $fallbackCount === 1) {
                        $transition = $candidate;
                        break;
                    }
                }
            } else {
                $transition = $preferred;
            }

            $transitionDurationValue     = $transitionDurations[$index - 1] ?? $transitionDuration;
            $effectiveTransitionDuration = max(self::MINIMUM_SLIDE_DURATION, $transitionDurationValue);
            $transitionOffset            = max(0.0, $timeline - $effectiveTransitionDuration);
            $effectiveSlideDuration      = $visibleDurations[$index] ?? $this->resolveSlideDuration($slides[$index]['duration']);

            $targetLabel = $index === count($slides) - 1 ? '[vout]' : sprintf('[tmp%d]', $index);
            $filters[]   = sprintf(
                '%s[s%d]xfade=transition=%s:duration=%0.3f:offset=%0.3f %s',
                $current,
                $index,
                $transition,
                $effectiveTransitionDuration,
                $transitionOffset,
                $targetLabel
            );
            $current        = $targetLabel;
            $timeline      += $effectiveSlideDuration - $effectiveTransitionDuration;
            $previousChoice = $transition;
        }

        $totalDuration = $timeline;
        $filterComplex = implode(';', $filters);

        $fadeChain = $this->buildVideoFadeChain($totalDuration);
        if ($fadeChain !== '') {
            $filterComplex = sprintf('%s;[vout]%s[vout]', $filterComplex, $fadeChain);
        }

        $command[] = '-filter_complex';
        $command[] = $filterComplex;
        return $this->appendAudioOptions(
            $command,
            count($slides),
            $output,
            $audioTrack,
            $title,
            $subtitle,
            $totalDuration,
        );
    }

    private function buildBlurredSlideFilter(
        int $index,
        float $clipDuration,
        float $visibleDuration,
        array $slide,
        ?string $title,
        ?string $subtitle,
    ): string
    {
        $background = sprintf('[%1$d:v]split=2[bg%1$d][fg%1$d];', $index);
        $background .= sprintf(
            '[bg%1$d]scale=%2$d:%3$d:force_original_aspect_ratio=increase',
            $index,
            $this->width,
            $this->height,
        );
        $background .= sprintf(',crop=%1$d:%2$d', $this->width, $this->height);

        if ($this->backgroundBlurSigma > 0.0) {
            $background .= sprintf(',gblur=sigma=%s', $this->formatFloat($this->backgroundBlurSigma));
        }

        $background .= sprintf('[bg%1$dout];', $index);

        $clipSecondsValue = max(0.1, $clipDuration);
        $visibleDuration  = max(0.1, min($visibleDuration, $clipSecondsValue));
        $frameCount       = max(2, (int) round($visibleDuration * self::ZOOMPAN_FPS));
        $progressExpr     = $this->escapeFilterExpression(sprintf('min(on/%s,1)', $frameCount));

        $kenBurns = $this->resolveKenBurnsParameters($index, $slide, $title, $subtitle);

        if ($this->kenBurnsEnabled) {
            $animatedZoomExpr = sprintf(
                'max(1,%1$s+(%2$s-%1$s)*%3$s)',
                $this->formatFloat($kenBurns['zoomStart']),
                $this->formatFloat($kenBurns['zoomEnd']),
                $progressExpr,
            );
            $zoomExpr         = $this->escapeFilterExpression($animatedZoomExpr);

            $panXAnimatedExpr = sprintf(
                'clip((iw-(iw/zoom))/2 + %1$s*(iw-(iw/zoom))/2*%2$s,0,max(iw-(iw/zoom),0))',
                $this->formatFloat($kenBurns['panX']),
                $progressExpr,
            );
            $panYAnimatedExpr = sprintf(
                'clip((ih-(ih/zoom))/2 + %1$s*(ih-(ih/zoom))/2*%2$s,0,max(ih-(ih/zoom),0))',
                $this->formatFloat($kenBurns['panY']),
                $progressExpr,
            );

            $panXExpr = $this->escapeFilterExpression(sprintf('if(eq(%1$s,1),0,%2$s)', $animatedZoomExpr, $panXAnimatedExpr));
            $panYExpr = $this->escapeFilterExpression(sprintf('if(eq(%1$s,1),0,%2$s)', $animatedZoomExpr, $panYAnimatedExpr));
        } else {
            $zoomExpr = '1';
            $panXExpr = '0';
            $panYExpr = '0';
        }

        $zoompanFps = $this->formatFloat((float) self::ZOOMPAN_FPS);

        $foreground = sprintf(
            '[fg%1$d]scale=%2$d:%3$d:force_original_aspect_ratio=decrease',
            $index,
            $this->width,
            $this->height,
        );

        if ($this->kenBurnsEnabled) {
            $foreground .= sprintf(
                ',zoompan=z=%1$s:x=%2$s:y=%3$s:d=%4$d:fps=%5$s,scale=ceil(iw/2)*2:ceil(ih/2)*2',
                $this->quoteFilterExpression($zoomExpr),
                $this->quoteFilterExpression($panXExpr),
                $this->quoteFilterExpression($panYExpr),
                $frameCount,
                $zoompanFps,
            );
        }

        $foreground .= sprintf('[fg%1$dout];', $index);

        return sprintf(
            '%1$s%2$s[bg%3$dout][fg%3$dout]overlay=(main_w-overlay_w)/2:(main_h-overlay_h)/2,fps=%4$s,format=yuv420p,setsar=1',
            $background,
            $foreground,
            $index,
            '%memories.slideshow.fps%',
        );
    }

    /**
     * @param array{image:string,mediaId:int|null,duration:float,transition:string|null} $slide
     *
     * @return array{zoomStart: float, zoomEnd: float, panX: float, panY: float}
     */
    private function resolveKenBurnsParameters(
        int $index,
        array $slide,
        ?string $title,
        ?string $subtitle,
    ): array {
        $minimumZoom = max(1.0, min($this->zoomStart, $this->zoomEnd));
        $maximumZoom = max(1.0, max($this->zoomStart, $this->zoomEnd));

        $seedPayload = implode('|', [
            (string) $index,
            $title ?? '',
            $subtitle ?? '',
            $slide['image'],
        ]);

        $panXSeed = hash('sha256', $seedPayload . '|panX', true);
        $panYSeed = hash('sha256', $seedPayload . '|panY', true);

        $panXRandomizer = new Randomizer(new Xoshiro256StarStar($panXSeed));
        $panYRandomizer = new Randomizer(new Xoshiro256StarStar($panYSeed));

        $panXValue = $panXRandomizer->getFloat(-self::PAN_OFFSET_LIMIT, self::PAN_OFFSET_LIMIT);
        $panYValue = $panYRandomizer->getFloat(-self::PAN_OFFSET_LIMIT, self::PAN_OFFSET_LIMIT);

        if (($index % 2) === 0) {
            return [
                'zoomStart' => $minimumZoom,
                'zoomEnd'   => $maximumZoom,
                'panX'      => $panXValue,
                'panY'      => $panYValue,
            ];
        }

        return [
            'zoomStart' => $maximumZoom,
            'zoomEnd'   => $minimumZoom,
            'panX'      => $panXValue !== 0.0 ? -$panXValue : 0.0,
            'panY'      => $panYValue !== 0.0 ? -$panYValue : 0.0,
        ];
    }

    private function resolveSlideDuration(float $duration): float
    {
        $value = $duration > 0.0 ? $duration : $this->slideDuration;

        return max(0.1, $value);
    }

    /**
     * @param array{image:string,mediaId:int|null,duration:float,transition:string|null} $slide
     */
    private function resolveCoverDuration(array $slide): float
    {
        if (!array_key_exists('duration', $slide) || $slide['duration'] <= 0.0) {
            return max(2.5, 4.0);
        }

        return max(2.5, $this->resolveSlideDuration($slide['duration']));
    }

    private function resolveTransitionDuration(?float $duration): float
    {
        $candidate = $duration ?? $this->transitionDuration;

        if ($candidate <= 0.0) {
            $candidate = $this->transitionDuration;
        }

        if ($candidate <= 0.0) {
            $candidate = self::MIN_TRANSITION_DURATION;
        }

        return $this->clampTransitionDuration($candidate);
    }

    /**
     * Builds deterministic transition durations for every slide overlap.
     *
     * @param list<array{image:string,mediaId:int|null,duration:float,transition:string|null}> $slides
     * @param list<float>                                                                       $requestedDurations
     *
     * @return list<float>
     */
    private function resolveTransitionDurationsForSlides(
        array $slides,
        array $requestedDurations,
        ?string $title,
        ?string $subtitle,
    ): array
    {
        $count = count($slides);
        if ($count <= 1) {
            return [];
        }

        $durations = [];
        for ($index = 0; $index < $count - 1; ++$index) {
            $currentDuration  = $this->resolveSlideDuration($slides[$index]['duration']);
            $nextDuration     = $this->resolveSlideDuration($slides[$index + 1]['duration']);
            $maxOverlap       = min($currentDuration, $nextDuration);
            $randomDuration   = $this->resolveDeterministicTransitionDuration($slides, $index, $title, $subtitle);
            $hasRequested     = array_key_exists($index, $requestedDurations);
            $candidate        = $hasRequested ? $requestedDurations[$index] : $randomDuration;

            if (!is_float($candidate)) {
                $candidate = (float) $candidate;
            }

            if ($candidate <= 0.0) {
                $candidate = $randomDuration;
            }

            $transitionLength = $this->clampTransitionDuration($candidate);

            if ($maxOverlap < self::MIN_TRANSITION_DURATION) {
                $transitionLength = $maxOverlap;
            } else {
                $transitionLength = min($transitionLength, $maxOverlap);
            }

            $durations[$index] = $transitionLength;
        }

        return $durations;
    }

    private function clampTransitionDuration(float $duration): float
    {
        return max(self::MIN_TRANSITION_DURATION, min(self::MAX_TRANSITION_DURATION, $duration));
    }

    /**
     * @param list<array{image:string,mediaId:int|null,duration:float,transition:string|null}> $slides
     */
    private function resolveDeterministicTransitionDuration(
        array $slides,
        int $index,
        ?string $title,
        ?string $subtitle,
    ): float {
        $seedPayload = implode('|', [
            (string) $index,
            trim((string) $slides[$index]['image']),
            trim((string) $slides[$index + 1]['image']),
            trim((string) ($title ?? '')),
            trim((string) ($subtitle ?? '')),
        ]);

        $seed      = hash('sha256', $seedPayload, true);
        $randomizer = new Randomizer(new Xoshiro256StarStar($seed));

        return $randomizer->getFloat(0.6, 1.0);
    }

    /**
     * @param list<string> $transitions
     *
     * @return list<string>
     */
    private function filterAllowedTransitions(array $transitions): array
    {
        $filtered = [];
        $lookup   = $this->getTransitionLookup();

        foreach ($transitions as $transition) {
            if (!is_string($transition)) {
                continue;
            }

            $normalised = $this->normaliseTransitionName($transition);
            if ($normalised === null) {
                continue;
            }

            if (!array_key_exists($normalised, $lookup)) {
                continue;
            }

            $filtered[$normalised] = true;
        }

        if ($filtered === []) {
            $whitelist = $this->getTransitionWhitelist();
            $fallback  = $whitelist[0] ?? 'fade';
            $filtered[$fallback] = true;
        }

        return array_keys($filtered);
    }

    private function normaliseTransitionName(?string $transition): ?string
    {
        if ($transition === null) {
            return null;
        }

        $trimmed = trim($transition);
        if ($trimmed === '') {
            return null;
        }

        $normalised = strtolower($trimmed);

        if (!array_key_exists($normalised, $this->getTransitionLookup())) {
            return null;
        }

        return $normalised;
    }

    /**
     * @param list<string> $transitions
     * @param list<string> $imagePaths
     *
     * @return list<string>
     */
    private function buildDeterministicTransitionSequence(
        array $transitions,
        array $imagePaths,
        ?string $title,
        ?string $subtitle,
        int $requiredTransitions,
    ): array {
        if ($requiredTransitions === 0 || $transitions === []) {
            return [];
        }

        $seed      = $this->buildTransitionSeed($imagePaths, $title, $subtitle, $transitions);
        $randomizer = new Randomizer(new Xoshiro256StarStar($seed));

        $pool = $randomizer->shuffleArray($transitions);
        $count = count($pool);

        $sequence = [];
        $previous = null;
        $index    = 0;

        while (count($sequence) < $requiredTransitions) {
            if ($index >= $count) {
                $pool  = $randomizer->shuffleArray($transitions);
                $count = count($pool);
                $index = 0;
            }

            $candidate = $pool[$index];
            ++$index;

            if ($candidate === $previous && $count > 1) {
                continue;
            }

            $sequence[] = $candidate;
            $previous   = $candidate;
        }

        return $sequence;
    }

    /**
     * @param list<string> $imagePaths
     * @param list<string> $transitions
     */
    private function buildTransitionSeed(array $imagePaths, ?string $title, ?string $subtitle, array $transitions): string
    {
        $normalisedPaths = array_map(
            static fn (string $path): string => trim($path),
            $imagePaths,
        );

        $payload = implode('|', $normalisedPaths)
            . '|' . trim((string) ($title ?? ''))
            . '|' . trim((string) ($subtitle ?? ''))
            . '|' . implode('|', $transitions);

        return hash('sha256', $payload, true);
    }

    /**
     * @return list<string>
     */
    private function getTransitionWhitelist(): array
    {
        $cache = self::transitionCache();

        if ($cache->whitelist !== null) {
            return $cache->whitelist;
        }

        $discovered = $this->discoverAvailableTransitions();
        $filtered   = [];

        if ($discovered !== []) {
            $filtered = array_values(array_intersect(self::TRANSITION_WHITELIST, $discovered));
        }

        if ($filtered === []) {
            $filtered = ['fade'];
        }

        $cache->lookup    = null;
        $cache->lookupKey = null;
        $cache->whitelist = $filtered;

        return $cache->whitelist;
    }

    /**
     * @return array<string, bool>
     */
    private function getTransitionLookup(): array
    {
        $cache = self::transitionCache();

        $whitelist = $this->getTransitionWhitelist();
        $lookupKey = hash('sha256', implode('|', $whitelist));

        if ($cache->lookup !== null && $cache->lookupKey === $lookupKey) {
            return $cache->lookup;
        }

        $cache->lookup    = array_fill_keys($whitelist, true);
        $cache->lookupKey = $lookupKey;

        return $cache->lookup;
    }

    private static function transitionCache(): SlideshowTransitionCache
    {
        static $cache = null;

        if ($cache === null) {
            $cache = new SlideshowTransitionCache();
        }

        return $cache;
    }

    private static function resetTransitionCache(): void
    {
        $cache = self::transitionCache();

        $cache->whitelist = null;
        $cache->lookup    = null;
        $cache->lookupKey = null;
    }

    /**
     * @return list<string>
     */
    private function discoverAvailableTransitions(): array
    {
        try {
            $process = new Process([
                $this->ffmpegBinary,
                '-hide_banner',
                '-h',
                'filter=xfade',
            ]);
            $process->run();

            if (!$process->isSuccessful()) {
                return [];
            }

            $output   = $process->getOutput();
            $error    = $process->getErrorOutput();
            $combined = trim($output . "\n" . $error);
            if ($combined === '') {
                return [];
            }

            return $this->parseXfadeHelpOutput($combined);
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @return list<string>
     */
    private function parseXfadeHelpOutput(string $output): array
    {
        $lines      = preg_split('/\r\n|\r|\n/', $output) ?: [];
        $collecting = false;
        $names      = [];

        foreach ($lines as $line) {
            $trimmed = trim($line);

            if ($collecting) {
                if ($trimmed === '' || preg_match('/^[a-z0-9_]+\s+<[^>]+>/', $trimmed) === 1) {
                    $collecting = false;
                } elseif (preg_match('/\b(?:possible|available)\s+(?:values|transitions)\b/i', $trimmed) === 1) {
                    if (preg_match('/:\s*(.*)$/', $trimmed, $matches) === 1) {
                        $content = trim($matches[1]);
                        if ($content !== '') {
                            $names = array_merge(
                                $names,
                                $this->extractTransitionNamesFromLine($content, true),
                            );
                        }
                    }

                    continue;
                } else {
                    $names = array_merge(
                        $names,
                        $this->extractTransitionNamesFromLine($trimmed, false),
                    );

                    continue;
                }
            }

            if (preg_match('/\b(?:possible|available)\s+(?:values|transitions)\b/i', $trimmed) !== 1) {
                continue;
            }

            $collecting = true;

            if (preg_match('/:\s*(.*)$/', $trimmed, $matches) === 1) {
                $content = trim($matches[1]);
                if ($content !== '') {
                    $names = array_merge(
                        $names,
                        $this->extractTransitionNamesFromLine($content, true),
                    );
                }
            }
        }

        $unique = [];
        $result = [];
        foreach ($names as $name) {
            if ($name === '') {
                continue;
            }

            if (array_key_exists($name, $unique)) {
                continue;
            }

            $unique[$name] = true;
            $result[]      = $name;
        }

        return $result;
    }

    /**
     * @return list<string>
     */
    private function extractTransitionNamesFromLine(string $line, bool $allowMultipleTokens): array
    {
        $clean = trim($line);
        if ($clean === '') {
            return [];
        }

        $clean = ltrim($clean, "-•*");
        $clean = trim($clean);
        if ($clean === '') {
            return [];
        }

        if (preg_match('/[,|]/', $clean) === 1) {
            $parts = preg_split('/[,|]/', $clean) ?: [];
            $names = [];
            foreach ($parts as $part) {
                $name = $this->sanitizeTransitionToken($part);
                if ($name !== null) {
                    $names[] = $name;
                }
            }

            return $names;
        }

        $tokens = preg_split('/\s+/', $clean) ?: [];
        if ($tokens === []) {
            return [];
        }

        if ($allowMultipleTokens) {
            $names = [];
            foreach ($tokens as $token) {
                $name = $this->sanitizeTransitionToken($token);
                if ($name !== null) {
                    $names[] = $name;
                }
            }

            return $names;
        }

        $name = $this->sanitizeTransitionToken($tokens[0]);
        if ($name === null) {
            return [];
        }

        return [$name];
    }

    private function sanitizeTransitionToken(string $token): ?string
    {
        $normalised = strtolower(trim($token));
        $normalised = trim($normalised, '.,;:()[]{}"\'');
        if ($normalised === '') {
            return null;
        }

        if (preg_match('/^[a-z0-9_]+$/', $normalised) !== 1) {
            return null;
        }

        return $normalised;
    }

    /**
     * @param list<string> $command
     * @param float        $totalDuration
     *
     * @return list<string>
     */
    private function appendAudioOptions(
        array $command,
        int $videoInputs,
        string $output,
        ?string $audioTrack,
        ?string $title,
        ?string $subtitle,
        float $totalDuration,
    ): array
    {
        $hasAudio = is_string($audioTrack) && $audioTrack !== '';

        if ($hasAudio) {
            $command[] = '-i';
            $command[] = $audioTrack;
        }

        $command[] = '-map';
        $command[] = '[vout]';
        $command[] = '-r';
        $command[] = '%memories.slideshow.fps%';
        $command[] = '-c:v';
        $command[] = 'libx264';
        $command[] = '-preset';
        $command[] = 'medium';
        $command[] = '-crf';
        $command[] = '20';
        $command[] = '-profile:v';
        $command[] = 'high';
        $command[] = '-level';
        $command[] = '4.1';
        $command[] = '-g';
        $command[] = '60';
        $command[] = '-bf';
        $command[] = '2';
        $command[] = '-threads';
        $command[] = '0';
        $command[] = '-movflags';
        $command[] = '+faststart';
        $command[] = '-pix_fmt';
        $command[] = 'yuv420p';

        if (is_string($title) && $title !== '') {
            $command[] = '-metadata';
            $command[] = sprintf('title=%s', $title);
        }

        if (is_string($subtitle) && $subtitle !== '') {
            $command[] = '-metadata';
            $command[] = sprintf('comment=%s', $subtitle);
        }

        if ($hasAudio) {
            $filterIndex = array_search('-filter_complex', $command, true);
            if ($filterIndex !== false) {
                $filterComplexIndex = $filterIndex + 1;
                $audioInputLabel    = sprintf('[%d:a:0]', $videoInputs);
                $fadeDuration       = max(0.0, min(1.5, $totalDuration / 2));
                $fadeOutStart       = max(0.0, $totalDuration - $fadeDuration);
                $audioFilter        = sprintf(
                    '%safade=t=in:st=0:d=%s,afade=t=out:st=%s:d=%s[aout]',
                    $audioInputLabel,
                    $this->formatFloat($fadeDuration),
                    $this->formatFloat($fadeOutStart),
                    $this->formatFloat($fadeDuration),
                );

                $command[$filterComplexIndex] = sprintf(
                    '%s;%s',
                    $command[$filterComplexIndex],
                    $audioFilter,
                );
            }

            $command[] = '-map';
            $command[] = '[aout]';
            $command[] = '-shortest';
            $command[] = '-c:a';
            $command[] = 'aac';
            $command[] = '-b:a';
            $command[] = '192k';
        } else {
            $command[] = '-an';
        }

        $command[] = '-vsync';
        $command[] = 'vfr';
        $command[] = $output;

        return $command;
    }

    private function buildVideoFadeChain(float $clipDuration): string
    {
        $effectiveDuration = max(0.0, $clipDuration);

        $introFade = max(0.0, min($this->introFadeDuration, $effectiveDuration));
        $outroFade = max(0.0, min($this->outroFadeDuration, $effectiveDuration));

        if ($introFade <= 0.0 && $outroFade <= 0.0) {
            return '';
        }

        $filters = [];

        if ($introFade > 0.0) {
            $filters[] = sprintf('fade=t=in:st=0:d=%s', $this->formatFloat($introFade));
        }

        if ($outroFade > 0.0) {
            $fadeOutStart = max(0.0, $effectiveDuration - $outroFade);
            $filters[]    = sprintf(
                'fade=t=out:st=%s:d=%s',
                $this->formatFloat($fadeOutStart),
                $this->formatFloat($outroFade),
            );
        }

        return implode(',', $filters);
    }

    private function appendIntroOverlayFilter(string $filter, ?string $title, ?string $subtitle): string
    {
        $overlay = $this->buildIntroTextOverlayFilterChain($title, $subtitle);
        if ($overlay === '') {
            return $filter;
        }

        return sprintf('%s,%s', $filter, $overlay);
    }

    private function appendTextOverlayFilter(string $filter, ?string $title, ?string $subtitle): string
    {
        $overlay = $this->buildTextOverlayFilterChain($title, $subtitle);
        if ($overlay === '') {
            return $filter;
        }

        return sprintf('%s,%s', $filter, $overlay);
    }

    private function hasTextOverlay(?string $title, ?string $subtitle): bool
    {
        return (is_string($title) && $title !== '') || (is_string($subtitle) && $subtitle !== '');
    }

    private function buildTextOverlayFilterChain(?string $title, ?string $subtitle): string
    {
        if (!$this->hasTextOverlay($title, $subtitle)) {
            return '';
        }

        $filters = [];

        $subtitleText = $this->normaliseDrawText($subtitle);
        $titleText    = $this->normaliseDrawText($title);

        if ($subtitleText !== null) {
            $filters[] = $this->buildDrawTextFilter($subtitleText, 48, 'w*0.05', 'h-80');
        }

        if ($titleText !== null) {
            $titleY    = $subtitleText !== null ? 'h-80-line_h-30' : 'h-80';
            $filters[] = $this->buildDrawTextFilter($titleText, 64, 'w*0.05', $titleY);
        }

        return implode(',', array_filter($filters));
    }

    private function buildIntroTextOverlayFilterChain(?string $title, ?string $subtitle): string
    {
        if (!$this->hasTextOverlay($title, $subtitle)) {
            return '';
        }

        $filters       = [];
        $titleText     = $this->normaliseDrawText($title);
        $subtitleText  = $this->normaliseDrawText($subtitle);
        $fontDirective = $this->resolveFontDirective();
        $fontSegment   = $fontDirective !== '' ? sprintf('%s:', $fontDirective) : '';

        if ($subtitleText !== null) {
            $filters[] = sprintf(
                "drawtext=text='%s':%sfontcolor=white:fontsize=h*0.038:shadowcolor=black@0.25:shadowx=0:shadowy=6:" .
                'borderw=2:bordercolor=black@0.20:x=w*0.07:y=h*0.86',
                $subtitleText,
                $fontSegment
            );
        }

        if ($titleText !== null) {
            $filters[] = sprintf(
                "drawtext=text='%s':%sfontcolor=white:fontsize=h*0.060:shadowcolor=black@0.25:shadowx=0:shadowy=6:" .
                'borderw=2:bordercolor=black@0.20:x=w*0.07:y=h*0.92',
                $titleText,
                $fontSegment
            );
        }

        return implode(',', $filters);
    }

    private function normaliseDrawText(?string $value): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        return trim($this->escapeDrawTextValue($value));
    }

    private function buildDrawTextFilter(string $text, int $fontSize, string $positionX, string $positionY): string
    {
        $fontDirective = $this->resolveFontDirective();
        $fontSegment   = $fontDirective !== '' ? sprintf('%s:', $fontDirective) : '';

        return sprintf(
            "drawtext=text='%s':%sfontcolor=white:fontsize=%d:shadowcolor=black@0.25:shadowx=0:shadowy=6:" .
            'borderw=2:bordercolor=black@0.20:x=%s:y=%s',
            $text,
            $fontSegment,
            $fontSize,
            $positionX,
            $positionY
        );
    }

    private function resolveFontDirective(): string
    {
        if (is_string($this->fontFile)) {
            $fontFile = trim($this->fontFile);
            if ($fontFile !== '' && is_file($fontFile)) {
                return sprintf("fontfile='%s'", $this->escapeDrawTextValue($fontFile));
            }
        }

        $fontFamily = trim($this->fontFamily);
        if ($fontFamily !== '') {
            return sprintf("font='%s'", $this->escapeDrawTextValue($fontFamily));
        }

        return '';
    }

    private function escapeDrawTextValue(string $value): string
    {
        return str_replace(
            ['\\', "\n", "\r", ':', '%', "'", ',', '[', ']'],
            ['\\\\', '\\n', '\\r', '\\:', '\\%', "\\'", '\\,', '\\[', '\\]'],
            $value
        );
    }

    private function escapeFilterExpression(string $expression): string
    {
        $length  = strlen($expression);
        $escaped = '';

        for ($index = 0; $index < $length; ++$index) {
            $character = $expression[$index];
            if ($character === ',') {
                $previous = $index > 0 ? $expression[$index - 1] : null;
                if ($previous !== '\\') {
                    $escaped .= '\\';
                }
            }

            $escaped .= $character;
        }

        return $escaped;
    }

    private function quoteFilterExpression(string $expression): string
    {
        return sprintf("'%s'", str_replace("'", "\\'", $expression));
    }

    private function formatFloat(float $value): string
    {
        $formatted = sprintf('%0.3F', $value);

        return rtrim(rtrim($formatted, '0'), '.');
    }
}
