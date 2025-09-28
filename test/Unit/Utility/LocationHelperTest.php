<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Utility;

use MagicSunday\Memories\Test\TestCase;
use MagicSunday\Memories\Utility\LocationHelper;
use PHPUnit\Framework\Attributes\Test;

final class LocationHelperTest extends TestCase
{
    #[Test]
    public function displayLabelPrefersConfiguredLocale(): void
    {
        $location = $this->makeLocation('poi-1', 'Berlin', 52.52, 13.405, configure: static function ($loc): void {
            $loc->setPois([
                [
                    'id' => 'node/1',
                    'name' => 'Brandenburg Gate',
                    'categoryKey' => 'tourism',
                    'categoryValue' => 'attraction',
                    'tags' => [
                        'tourism' => 'attraction',
                    ],
                    'names' => [
                        'name' => 'Brandenburg Gate',
                        'name:de' => 'Brandenburger Tor',
                    ],
                ],
            ]);
        });

        $helper = new LocationHelper('de');

        self::assertSame('Brandenburger Tor', $helper->displayLabel($location));
    }

    #[Test]
    public function displayLabelFallsBackToDefaultNameWhenLocaleMissing(): void
    {
        $location = $this->makeLocation('poi-2', 'London', 51.5074, -0.1278, configure: static function ($loc): void {
            $loc->setPois([
                [
                    'id' => 'node/2',
                    'name' => 'Tower Bridge',
                    'categoryKey' => 'tourism',
                    'categoryValue' => 'attraction',
                    'tags' => [],
                    'names' => [
                        'name' => 'Tower Bridge',
                    ],
                ],
            ]);
        });

        $helper = new LocationHelper('fr');

        self::assertSame('Tower Bridge', $helper->displayLabel($location));
    }

    #[Test]
    public function displayLabelFallsBackToAltName(): void
    {
        $location = $this->makeLocation('poi-3', 'Paris', 48.8566, 2.3522, configure: static function ($loc): void {
            $loc->setPois([
                [
                    'id' => 'node/3',
                    'categoryKey' => 'tourism',
                    'categoryValue' => 'museum',
                    'tags' => [],
                    'names' => [
                        'alt_name' => 'Musée d\'Orsay',
                    ],
                ],
            ]);
        });

        $helper = new LocationHelper('it');

        self::assertSame("Musée d'Orsay", $helper->displayLabel($location));
    }
}
