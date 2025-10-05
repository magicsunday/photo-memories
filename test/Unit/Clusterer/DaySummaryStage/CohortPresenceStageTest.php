<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Clusterer\DaySummaryStage;

use MagicSunday\Memories\Clusterer\Contract\PersonTaggedMediaInterface;
use MagicSunday\Memories\Clusterer\DaySummaryStage\CohortPresenceStage;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Test\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * @covers \MagicSunday\Memories\Clusterer\DaySummaryStage\CohortPresenceStage
 */
final class CohortPresenceStageTest extends TestCase
{
    #[Test]
    public function computesPresenceRatioWithFallbacks(): void
    {
        $stage = new CohortPresenceStage(
            importantPersonIds: [11, 22],
            fallbackPersonIds: [22 => [220]],
        );

        $dayKey = '2024-05-01';
        $days   = [
            $dayKey => [
                'members'              => [
                    $this->makeTaggedMedia([11]),
                    $this->makeTaggedMedia([220]),
                    $this->makeTaggedMedia([999]),
                ],
                'cohortPresenceRatio'  => 0.0,
                'cohortMembers'        => [],
            ],
        ];

        $home = [
            'lat'             => 0.0,
            'lon'             => 0.0,
            'radius_km'       => 0.0,
            'country'         => null,
            'timezone_offset' => null,
        ];

        $result  = $stage->process($days, $home);
        $summary = $result[$dayKey];

        self::assertSame(1.0, $summary['cohortPresenceRatio']);
        self::assertSame([
            11 => 1,
            22 => 1,
        ], $summary['cohortMembers']);
    }

    #[Test]
    public function returnsUnmodifiedSummariesWhenNoImportantPersonsConfigured(): void
    {
        $stage = new CohortPresenceStage([]);

        $dayKey = '2024-05-02';
        $days   = [
            $dayKey => [
                'members'              => [$this->makeTaggedMedia([42, 43])],
                'cohortPresenceRatio'  => 0.25,
                'cohortMembers'        => [7 => 3],
            ],
        ];

        $home = [
            'lat'             => 0.0,
            'lon'             => 0.0,
            'radius_km'       => 0.0,
            'country'         => null,
            'timezone_offset' => null,
        ];

        $result = $stage->process($days, $home);

        self::assertSame($days, $result);
    }

    /**
     * @param list<int> $personIds
     */
    private function makeTaggedMedia(array $personIds): Media
    {
        static $sequence = 0;
        ++$sequence;

        return new class($personIds, $sequence) extends Media implements PersonTaggedMediaInterface {
            /**
             * @param list<int> $personIds
             */
            public function __construct(private array $personIds, int $sequence)
            {
                parent::__construct(
                    path: sprintf('/tmp/media-%d.jpg', $sequence),
                    checksum: sprintf('checksum-%d', $sequence),
                    size: 1024,
                );
            }

            public function getPersonIds(): array
            {
                return $this->personIds;
            }
        };
    }
}
