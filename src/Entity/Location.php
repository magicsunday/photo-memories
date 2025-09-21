<?php
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
    private int $id;

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

    public function __construct(
        string $provider,
        string $providerPlaceId,
        string $displayName,
        float $lat,
        float $lon,
        string $cell
    ) {
        $this->provider = $provider;
        $this->providerPlaceId = $providerPlaceId;
        $this->displayName = $displayName;
        $this->lat = $lat;
        $this->lon = $lon;
        $this->cell = $cell;
    }

    /**
     * @return int
     */
    public function getId(): int
    {
        return $this->id;
    }

    /**
     * @param int $id
     *
     * @return Location
     */
    public function setId(int $id): Location
    {
        $this->id = $id;
        return $this;
    }

    /**
     * @return string
     */
    public function getProvider(): string
    {
        return $this->provider;
    }

    /**
     * @param string $provider
     *
     * @return Location
     */
    public function setProvider(string $provider): Location
    {
        $this->provider = $provider;
        return $this;
    }

    /**
     * @return string
     */
    public function getProviderPlaceId(): string
    {
        return $this->providerPlaceId;
    }

    /**
     * @param string $providerPlaceId
     *
     * @return Location
     */
    public function setProviderPlaceId(string $providerPlaceId): Location
    {
        $this->providerPlaceId = $providerPlaceId;
        return $this;
    }

    /**
     * @return float
     */
    public function getLat(): float
    {
        return $this->lat;
    }

    /**
     * @param float $lat
     *
     * @return Location
     */
    public function setLat(float $lat): Location
    {
        $this->lat = $lat;
        return $this;
    }

    /**
     * @return float
     */
    public function getLon(): float
    {
        return $this->lon;
    }

    /**
     * @param float $lon
     *
     * @return Location
     */
    public function setLon(float $lon): Location
    {
        $this->lon = $lon;
        return $this;
    }

    /**
     * @return string
     */
    public function getCell(): string
    {
        return $this->cell;
    }

    /**
     * @param string $cell
     *
     * @return Location
     */
    public function setCell(string $cell): Location
    {
        $this->cell = $cell;
        return $this;
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
     * @return null|string
     */
    public function getCountryCode(): ?string
    {
        return $this->countryCode;
    }

    /**
     * @param null|string $countryCode
     *
     * @return Location
     */
    public function setCountryCode(?string $countryCode): Location
    {
        $this->countryCode = $countryCode;
        return $this;
    }

    /**
     * @return null|string
     */
    public function getCountry(): ?string
    {
        return $this->country;
    }

    /**
     * @param null|string $country
     *
     * @return Location
     */
    public function setCountry(?string $country): Location
    {
        $this->country = $country;
        return $this;
    }

    /**
     * @return null|string
     */
    public function getState(): ?string
    {
        return $this->state;
    }

    /**
     * @param null|string $state
     *
     * @return Location
     */
    public function setState(?string $state): Location
    {
        $this->state = $state;
        return $this;
    }

    /**
     * @return null|string
     */
    public function getCounty(): ?string
    {
        return $this->county;
    }

    /**
     * @param null|string $county
     *
     * @return Location
     */
    public function setCounty(?string $county): Location
    {
        $this->county = $county;
        return $this;
    }

    /**
     * @return null|string
     */
    public function getCity(): ?string
    {
        return $this->city;
    }

    /**
     * @param null|string $city
     *
     * @return Location
     */
    public function setCity(?string $city): Location
    {
        $this->city = $city;
        return $this;
    }

    /**
     * @return null|string
     */
    public function getSuburb(): ?string
    {
        return $this->suburb;
    }

    /**
     * @param null|string $suburb
     *
     * @return Location
     */
    public function setSuburb(?string $suburb): Location
    {
        $this->suburb = $suburb;
        return $this;
    }

    /**
     * @return null|string
     */
    public function getPostcode(): ?string
    {
        return $this->postcode;
    }

    /**
     * @param null|string $postcode
     *
     * @return Location
     */
    public function setPostcode(?string $postcode): Location
    {
        $this->postcode = $postcode;
        return $this;
    }

    /**
     * @return null|string
     */
    public function getRoad(): ?string
    {
        return $this->road;
    }

    /**
     * @param null|string $road
     *
     * @return Location
     */
    public function setRoad(?string $road): Location
    {
        $this->road = $road;
        return $this;
    }

    /**
     * @return null|string
     */
    public function getHouseNumber(): ?string
    {
        return $this->houseNumber;
    }

    /**
     * @param null|string $houseNumber
     *
     * @return Location
     */
    public function setHouseNumber(?string $houseNumber): Location
    {
        $this->houseNumber = $houseNumber;
        return $this;
    }

    /**
     * @return null|string
     */
    public function getCategory(): ?string
    {
        return $this->category;
    }

    /**
     * @param null|string $category
     *
     * @return Location
     */
    public function setCategory(?string $category): Location
    {
        $this->category = $category;
        return $this;
    }

    /**
     * @return null|string
     */
    public function getType(): ?string
    {
        return $this->type;
    }

    /**
     * @param null|string $type
     *
     * @return Location
     */
    public function setType(?string $type): Location
    {
        $this->type = $type;
        return $this;
    }

    /**
     * @return null|array
     */
    public function getBoundingBox(): ?array
    {
        return $this->boundingBox;
    }

    /**
     * @param null|array $boundingBox
     *
     * @return Location
     */
    public function setBoundingBox(?array $boundingBox): Location
    {
        $this->boundingBox = $boundingBox;
        return $this;
    }
}
