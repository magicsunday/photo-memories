<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'location')]
#[ORM\UniqueConstraint(name: 'uniq_loc_provider', columns: ['provider', 'providerPlaceId'])]
#[ORM\Index(name: 'idx_loc_cell', columns: ['cell'])]
class Location
{
    #[ORM\Id]
    #[ORM\Column(type: Types::BIGINT)]
    #[ORM\GeneratedValue]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING, length: 32)]
    private string $provider;

    #[ORM\Column(type: Types::STRING, length: 64)]
    private string $providerPlaceId;

    #[ORM\Column(type: Types::FLOAT)]
    private float $lat;

    #[ORM\Column(type: Types::FLOAT)]
    private float $lon;

    #[ORM\Column(type: Types::STRING, length: 32)]
    private string $cell; // coarse cell key for dedupe

    #[ORM\Column(type: Types::STRING, length: 512)]
    private string $displayName;

    #[ORM\Column(type: Types::STRING, length: 2, nullable: true)]
    private ?string $countryCode = null;

    #[ORM\Column(type: Types::STRING, length: 128, nullable: true)]
    private ?string $country = null;

    #[ORM\Column(type: Types::STRING, length: 128, nullable: true)]
    private ?string $state = null;

    #[ORM\Column(type: Types::STRING, length: 128, nullable: true)]
    private ?string $county = null;

    #[ORM\Column(type: Types::STRING, length: 128, nullable: true)]
    private ?string $city = null;

    #[ORM\Column(type: Types::STRING, length: 128, nullable: true)]
    private ?string $suburb = null;

    #[ORM\Column(type: Types::STRING, length: 16, nullable: true)]
    private ?string $postcode = null;

    #[ORM\Column(type: Types::STRING, length: 128, nullable: true)]
    private ?string $road = null;

    #[ORM\Column(type: Types::STRING, length: 32, nullable: true)]
    private ?string $houseNumber = null;

    #[ORM\Column(type: Types::STRING, length: 64, nullable: true)]
    private ?string $category = null;

    #[ORM\Column(type: Types::STRING, length: 64, nullable: true)]
    private ?string $type = null;

    /** @var list<float>|null */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $boundingBox = null;

    /**
     * @var list<array<string,mixed>>|null nearby Points of Interest enriched via Overpass API
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $pois = null;

    public function __construct(
        string $provider,
        string $providerPlaceId,
        string $displayName,
        float $lat,
        float $lon,
        string $cell,
    ) {
        $this->provider        = $provider;
        $this->providerPlaceId = $providerPlaceId;
        $this->displayName     = $displayName;
        $this->lat             = $lat;
        $this->lon             = $lon;
        $this->cell            = $cell;
    }

    /**
     * Managed entities have an ID; new (unflushed) entities return null.
     *
     * @return int|null
     */
    public function getId(): ?int
    {
        return $this->id;
    }

    /**
     * @return string
     */
    public function getProvider(): string
    {
        return $this->provider;
    }

    /**
     * @return string
     */
    public function getProviderPlaceId(): string
    {
        return $this->providerPlaceId;
    }

    /**
     * @return float
     */
    public function getLat(): float
    {
        return $this->lat;
    }

    /**
     * @return float
     */
    public function getLon(): float
    {
        return $this->lon;
    }

    /**
     * @return string
     */
    public function getCell(): string
    {
        return $this->cell;
    }

    /**
     * @return string
     */
    public function getDisplayName(): string
    {
        return $this->displayName;
    }

    /**
     * @param string $displayName
     *
     * @return Location
     */
    public function setDisplayName(string $displayName): Location
    {
        $this->displayName = $displayName;

        return $this;
    }

    /**
     * @return string|null
     */
    public function getCountryCode(): ?string
    {
        return $this->countryCode;
    }

    /**
     * @param string|null $countryCode
     *
     * @return Location
     */
    public function setCountryCode(?string $countryCode): Location
    {
        $this->countryCode = $countryCode;

        return $this;
    }

    /**
     * @return string|null
     */
    public function getCountry(): ?string
    {
        return $this->country;
    }

    /**
     * @param string|null $country
     *
     * @return Location
     */
    public function setCountry(?string $country): Location
    {
        $this->country = $country;

        return $this;
    }

    /**
     * @return string|null
     */
    public function getState(): ?string
    {
        return $this->state;
    }

    /**
     * @param string|null $state
     *
     * @return Location
     */
    public function setState(?string $state): Location
    {
        $this->state = $state;

        return $this;
    }

    /**
     * @return string|null
     */
    public function getCounty(): ?string
    {
        return $this->county;
    }

    /**
     * @param string|null $county
     *
     * @return Location
     */
    public function setCounty(?string $county): Location
    {
        $this->county = $county;

        return $this;
    }

    /**
     * @return string|null
     */
    public function getCity(): ?string
    {
        return $this->city;
    }

    /**
     * @param string|null $city
     *
     * @return Location
     */
    public function setCity(?string $city): Location
    {
        $this->city = $city;

        return $this;
    }

    /**
     * @return string|null
     */
    public function getSuburb(): ?string
    {
        return $this->suburb;
    }

    /**
     * @param string|null $suburb
     *
     * @return Location
     */
    public function setSuburb(?string $suburb): Location
    {
        $this->suburb = $suburb;

        return $this;
    }

    /**
     * @return string|null
     */
    public function getPostcode(): ?string
    {
        return $this->postcode;
    }

    /**
     * @param string|null $postcode
     *
     * @return Location
     */
    public function setPostcode(?string $postcode): Location
    {
        $this->postcode = $postcode;

        return $this;
    }

    /**
     * @return string|null
     */
    public function getRoad(): ?string
    {
        return $this->road;
    }

    /**
     * @param string|null $road
     *
     * @return Location
     */
    public function setRoad(?string $road): Location
    {
        $this->road = $road;

        return $this;
    }

    /**
     * @return string|null
     */
    public function getHouseNumber(): ?string
    {
        return $this->houseNumber;
    }

    /**
     * @param string|null $houseNumber
     *
     * @return Location
     */
    public function setHouseNumber(?string $houseNumber): Location
    {
        $this->houseNumber = $houseNumber;

        return $this;
    }

    /**
     * @return string|null
     */
    public function getCategory(): ?string
    {
        return $this->category;
    }

    /**
     * @param string|null $category
     *
     * @return Location
     */
    public function setCategory(?string $category): Location
    {
        $this->category = $category;

        return $this;
    }

    /**
     * @return string|null
     */
    public function getType(): ?string
    {
        return $this->type;
    }

    /**
     * @param string|null $type
     *
     * @return Location
     */
    public function setType(?string $type): Location
    {
        $this->type = $type;

        return $this;
    }

    /**
     * @return array|null
     */
    public function getBoundingBox(): ?array
    {
        return $this->boundingBox;
    }

    /**
     * @param array|null $boundingBox
     *
     * @return Location
     */
    public function setBoundingBox(?array $boundingBox): Location
    {
        $this->boundingBox = $boundingBox;

        return $this;
    }

    /**
     * @return list<array<string,mixed>>|null
     */
    public function getPois(): ?array
    {
        return $this->pois;
    }

    /**
     * @param list<array<string,mixed>>|null $pois
     */
    public function setPois(?array $pois): Location
    {
        $this->pois = $pois;

        return $this;
    }
}
