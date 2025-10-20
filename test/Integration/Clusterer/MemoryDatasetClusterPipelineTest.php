<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Integration\Clusterer;

use MagicSunday\Memories\Test\Support\Memories\MemoryDatasetLoader;
use MagicSunday\Memories\Test\Support\Memories\MemoryDatasetPipeline;
use MagicSunday\Memories\Test\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

final class MemoryDatasetClusterPipelineTest extends TestCase
{
    private MemoryDatasetLoader $loader;

    private MemoryDatasetPipeline $pipeline;

    protected function setUp(): void
    {
        parent::setUp();

        $this->loader = new MemoryDatasetLoader(__DIR__ . '/../../../fixtures/memories');
        $this->pipeline = new MemoryDatasetPipeline();
    }

    /**
     * @return array<int, array{string}>
     */
    public static function datasetProvider(): array
    {
        return [
            ['kurztrip'],
            ['familienevent'],
            ['monatsmix'],
        ];
    }

    #[Test]
    #[DataProvider('datasetProvider')]
    public function itMatchesExpectedGoldStandard(string $datasetName): void
    {
        $dataset = $this->loader->load($datasetName);
        $result = $this->pipeline->run($dataset);

        self::assertSame($dataset->getExpected(), $result);
    }
}
