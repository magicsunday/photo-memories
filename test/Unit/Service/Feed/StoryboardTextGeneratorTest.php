<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Service\Feed;

use MagicSunday\Memories\Service\Feed\StoryboardTextGenerator;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 *
 * @covers \MagicSunday\Memories\Service\Feed\StoryboardTextGenerator
 */
final class StoryboardTextGeneratorTest extends TestCase
{
    public function testGenerateBuildsGermanTextsWithLocationAndPersons(): void
    {
        $generator = new StoryboardTextGenerator();
        $entries   = [
            [
                'personen'      => ['Anna', 'Ben'],
                'ort'           => 'Brandenburger Tor',
                'szenen'        => ['Nacht', 'Stadt'],
                'schlagwoerter' => ['Berlin', 'Reise'],
            ],
            [
                'personen'      => ['Anna'],
                'ort'           => 'Berlin',
                'szenen'        => ['Stadt'],
                'schlagwoerter' => ['Brandenburg'],
            ],
        ];
        $params = [
            'poi_label'    => 'Brandenburger Tor',
            'place_city'   => 'Berlin',
            'place_country'=> 'Deutschland',
        ];

        $result = $generator->generate($entries, $params, 'de-DE');

        self::assertSame('Mit Anna und Ben in Brandenburger Tor', $result['title']);
        self::assertSame(
            'Gemeinsam mit Anna und Ben in Brandenburger Tor. Szenen: Nacht und Stadt. Tags: Berlin und Reise.',
            $result['description']
        );
    }

    public function testGenerateBuildsEnglishTexts(): void
    {
        $generator = new StoryboardTextGenerator();
        $entries   = [
            [
                'personen'      => ['Chris', 'Dana', 'Alex'],
                'ort'           => 'Central Park',
                'szenen'        => ['Picnic', 'Sunset'],
                'schlagwoerter' => ['New York'],
            ],
        ];
        $params = [
            'poi_label'    => 'Central Park',
            'place_country'=> 'USA',
        ];

        $result = $generator->generate($entries, $params, 'en-US');

        self::assertSame('With Chris, Dana and Alex in Central Park', $result['title']);
        self::assertSame(
            'Together with Chris, Dana and Alex in Central Park. Scenes: Picnic and Sunset. Tags: New York.',
            $result['description']
        );
    }

    public function testGenerateFallsBackToGenericWhenNoContext(): void
    {
        $generator = new StoryboardTextGenerator('de');

        $result = $generator->generate([], []);

        self::assertSame('Besondere Erinnerungen', $result['title']);
        self::assertSame('Unvergessliche Augenblicke.', $result['description']);
    }
}
