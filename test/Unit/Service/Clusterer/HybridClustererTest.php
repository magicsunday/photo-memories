<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Service\Clusterer;

use Doctrine\ORM\EntityManagerInterface;
use MagicSunday\Memories\Clusterer\Contract\ProgressAwareClusterStrategyInterface;
use MagicSunday\Memories\Clusterer\Support\ProgressAwareClusterTrait;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Service\Clusterer\Contract\ProgressHandleInterface;
use MagicSunday\Memories\Service\Clusterer\HybridClusterer;
use MagicSunday\Memories\Service\Clusterer\Scoring\CompositeClusterScorer;
use MagicSunday\Memories\Service\Clusterer\TitleGeneratorInterface;
use MagicSunday\Memories\Test\TestCase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

final class HybridClustererTest extends TestCase
{
    #[Test]
    public function buildCreatesPerStrategyProgressHandle(): void
    {
        $progressAwareStrategy = new class implements ProgressAwareClusterStrategyInterface {
            use ProgressAwareClusterTrait;

            public function name(): string
            {
                return 'progress-aware';
            }

            public function cluster(array $items): array
            {
                return [];
            }

            public function clusterWithProgress(array $items, callable $update): array
            {
                return $this->runWithDefaultProgress(
                    $items,
                    $update,
                    static fn (array $items): array => [],
                );
            }
        };

        $scorer = new CompositeClusterScorer(
            $this->createMock(EntityManagerInterface::class),
            [],
        );

        $titleGenerator = $this->createMock(TitleGeneratorInterface::class);
        $titleGenerator->method('makeTitle')->willReturn('Titel');
        $titleGenerator->method('makeSubtitle')->willReturn('Untertitel');

        $clusterer = new HybridClusterer([$progressAwareStrategy], $scorer, $titleGenerator);

        $handles = [];

        $drafts = $clusterer->build(
            [new Media('foo.jpg', 'checksum', 1)],
            null,
            null,
            function (string $strategy, int $index, int $total) use (&$handles): ProgressHandleInterface {
                $handle = new class implements ProgressHandleInterface {
                    /** @var list<array{string, mixed}> */
                    public array $events = [];

                    public function advance(int $step = 1): void
                    {
                        $this->events[] = ['advance', $step];
                    }

                    public function setPhase(?string $message): void
                    {
                        $this->events[] = ['phase', $message];
                    }

                    public function setDetail(?string $message): void
                    {
                        $this->events[] = ['detail', $message];
                    }

                    public function setRate(?string $message): void
                    {
                        $this->events[] = ['rate', $message];
                    }

                    public function setProgress(int $current): void
                    {
                        $this->events[] = ['progress', $current];
                    }

                    public function setMax(int $max): void
                    {
                        $this->events[] = ['max', $max];
                    }

                    public function createChildHandle(string $sectionTitle, string $headline, int $max): ProgressHandleInterface
                    {
                        throw new RuntimeException('Child handle not expected in test');
                    }

                    public function finish(): void
                    {
                        $this->events[] = ['finish'];
                    }
                };

                $handles[] = $handle;

                return $handle;
            },
        );

        self::assertSame([], $drafts);
        self::assertCount(1, $handles);

        $events = $handles[0]->events;

        $maxEvents = array_filter($events, static fn (array $event): bool => $event[0] === 'max');
        self::assertNotEmpty($maxEvents);
        self::assertContains(['max', 1], $events);

        self::assertContains(['detail', 'Schritt 0/1'], $events);
        self::assertContains(['rate', 'Fortschritt: 0/1 Schritte'], $events);
        self::assertContains(['progress', 0], $events);
        self::assertContains(['phase', 'Filtern (1)'], $events);

        self::assertContains(['detail', 'Schritt 1/1'], $events);
        self::assertContains(['rate', 'Fortschritt: 1/1 Schritte'], $events);
        self::assertContains(['progress', 1], $events);
        self::assertContains(['phase', 'Scoring & Metadaten'], $events);

        self::assertContains(['phase', 'Abgeschlossen (0 Memories)'], $events);
        self::assertContains(['phase', 'Abgeschlossen'], $events);
        self::assertContains(['detail', '0 Cluster erzeugt'], $events);
        self::assertContains(['rate', '–'], $events);
        self::assertContains(['finish'], $events);
    }
}
