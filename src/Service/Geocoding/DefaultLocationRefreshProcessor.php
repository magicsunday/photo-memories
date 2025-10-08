<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Geocoding;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use MagicSunday\Memories\Entity\Location;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Output\OutputInterface;

use function count;
use function function_exists;
use function is_array;
use function is_string;
use function iterator_to_array;
use function mb_strimwidth;
use function preg_replace;
use function strlen;
use function substr;
use function trim;

/**
 * Class DefaultLocationRefreshProcessor.
 */
final readonly class DefaultLocationRefreshProcessor implements LocationRefreshProcessorInterface
{
    /**
     * @param positive-int $batchSize
     */
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ReverseGeocoderInterface $reverseGeocoder,
        private LocationResolver $locationResolver,
        private string $locale,
        private int $batchSize = 25,
    ) {
    }

    /**
     * @param iterable<Location> $locations
     */
    public function process(iterable $locations, bool $refreshPois, bool $dryRun, OutputInterface $output): LocationRefreshSummary
    {
        $items = $this->normalizeIterable($locations);
        $count = count($items);

        $progressBar = new ProgressBar($output, $count);
        $progressBar->setFormat('%current%/%max% [%bar%] %percent:3s%% | Dauer: %elapsed:6s% | ETA: %estimated:-6s% | %message%');
        $progressBar->setMessage('Starte …');
        $progressBar->start();

        $processed       = 0;
        $metadataUpdated = 0;
        $poisUpdated     = 0;
        $geocodeCalls    = 0;
        $poiNetworkCalls = 0;

        foreach ($items as $location) {
            $label = $this->resolveProgressLabel($location);
            $progressBar->setMessage($this->formatProgressLabel($label));

            $metadataBefore = $this->snapshotMetadata($location);
            $poisBefore     = $location->getPois();

            $result = $this->reverseGeocoder->reverse($location->getLat(), $location->getLon(), $this->locale);

            if ($result instanceof GeocodeResult) {
                ++$geocodeCalls;

                $this->locationResolver->refreshMetadata($location, $result);
                $metadataAfter = $this->snapshotMetadata($location);

                if ($metadataBefore !== $metadataAfter) {
                    ++$metadataUpdated;
                }

                $this->locationResolver->ensurePois($location, $refreshPois);
                if ($this->locationResolver->consumeLastUsedNetwork()) {
                    ++$poiNetworkCalls;
                }

                if ($poisBefore !== $location->getPois()) {
                    ++$poisUpdated;
                }
            }

            ++$processed;
            $progressBar->advance();

            if (($processed % $this->batchSize) === 0 && !$dryRun) {
                $this->entityManager->flush();
            }
        }

        if (!$dryRun) {
            $this->entityManager->flush();
        }

        $progressBar->finish();
        $output->writeln('');
        $output->writeln('');

        return new LocationRefreshSummary($processed, $metadataUpdated, $poisUpdated, $geocodeCalls, $poiNetworkCalls);
    }

    /**
     * @param iterable<Location> $locations
     *
     * @return list<Location>
     */
    private function normalizeIterable(iterable $locations): array
    {
        if (is_array($locations)) {
            return $locations;
        }

        return iterator_to_array($locations, false);
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshotMetadata(Location $location): array
    {
        return [
            'displayName'          => $location->getDisplayName(),
            'countryCode'          => $location->getCountryCode(),
            'country'              => $location->getCountry(),
            'state'                => $location->getState(),
            'county'               => $location->getCounty(),
            'city'                 => $location->getCity(),
            'suburb'               => $location->getSuburb(),
            'postcode'             => $location->getPostcode(),
            'road'                 => $location->getRoad(),
            'houseNumber'          => $location->getHouseNumber(),
            'category'             => $location->getCategory(),
            'type'                 => $location->getType(),
            'boundingBox'          => $location->getBoundingBox(),
            'attribution'          => $location->getAttribution(),
            'licence'              => $location->getLicence(),
            'refreshedAt'          => $this->formatDate($location->getRefreshedAt()),
            'confidence'           => $location->getConfidence(),
            'accuracyRadiusMeters' => $location->getAccuracyRadiusMeters(),
            'timezone'             => $location->getTimezone(),
            'osmType'              => $location->getOsmType(),
            'osmId'                => $location->getOsmId(),
            'wikidataId'           => $location->getWikidataId(),
            'wikipedia'            => $location->getWikipedia(),
            'altNames'             => $location->getAltNames(),
            'extraTags'            => $location->getExtraTags(),
        ];
    }

    private function formatDate(?DateTimeImmutable $value): ?string
    {
        return $value?->format(DateTimeImmutable::ATOM);
    }

    private function formatProgressLabel(string $label): string
    {
        $normalized = preg_replace('/\s+/u', ' ', trim($label));
        if (!is_string($normalized) || $normalized === '') {
            $normalized = $label;
        }

        if (function_exists('mb_strimwidth')) {
            return mb_strimwidth($normalized, 0, 70, '…', 'UTF-8');
        }

        return strlen($normalized) > 70
            ? substr($normalized, 0, 69) . '…'
            : $normalized;
    }

    private function resolveProgressLabel(Location $location): string
    {
        $displayName = trim($location->getDisplayName());
        if ($displayName !== '') {
            return $displayName;
        }

        $city = $location->getCity();
        if (is_string($city)) {
            $city = trim($city);
            if ($city !== '') {
                return $city;
            }
        }

        return 'Unbenannter Ort';
    }
}
