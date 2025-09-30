<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Utility;

use MagicSunday\Memories\Test\TestCase;
use MagicSunday\Memories\Utility\DefaultPoiNormalizer;
use PHPUnit\Framework\Attributes\Test;

final class DefaultPoiNormalizerTest extends TestCase
{
    #[Test]
    public function returnsNullWhenNoLabelInformationPresent(): void
    {
        $normalizer = new DefaultPoiNormalizer();

        $result = $normalizer->normalise([
            'tags' => [
                'tourism' => 'museum',
            ],
        ]);

        self::assertNull($result);
    }

    #[Test]
    public function normalisesNamesAndTags(): void
    {
        $normalizer = new DefaultPoiNormalizer();

        $result = $normalizer->normalise([
            'name'        => '',
            'names'       => [
                'default'    => ' Central Park ',
                'localized'  => [
                    'EN'        => 'Central Park',
                    'de-DE'     => 'Zentralpark',
                    'invalid '  => '',
                ],
                'alternates' => ['  Central ', 'Park', 42],
            ],
            'categoryKey'   => 'leisure',
            'categoryValue' => 'park',
            'tags'          => [
                'leisure'  => 'park',
                'wikidata' => 'Q160538',
                'invalid'  => '',
                12         => 'ignored',
            ],
        ]);

        self::assertNotNull($result);
        self::assertSame(' Central Park ', $result['name']);
        self::assertSame('leisure', $result['categoryKey']);
        self::assertSame('park', $result['categoryValue']);
        self::assertSame(
            [
                'default'    => ' Central Park ',
                'localized'  => [
                    'de-de' => 'Zentralpark',
                    'en'    => 'Central Park',
                ],
                'alternates' => ['Central', 'Park'],
            ],
            $result['names'],
        );
        self::assertSame(
            [
                'leisure'  => 'park',
                'wikidata' => 'Q160538',
            ],
            $result['tags'],
        );
    }
}
