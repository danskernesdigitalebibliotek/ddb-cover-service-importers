<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;

/**
 * @ORM\Table(name="search",
 *    uniqueConstraints={
 *        @ORM\UniqueConstraint(name="record_unique",
 *            columns={"is_type", "is_identifier"})
 *    },
 *     indexes={
 *        @ORM\Index(name="is_identifier_type_idx", columns={"is_identifier", "is_type"}),
 *    }
 * )
 *
 * @ORM\Entity(repositoryClass="App\Repository\SearchRepository")
 */
class Search
{
    /**
     * @ORM\Id()
     * @ORM\GeneratedValue()
     * @ORM\Column(type="integer")
     */
    private int $id;

    /**
     * @ORM\Column(type="string", length=50)
     *
     * @Groups({"read"})
     */
    private ?string $isIdentifier = null;

    /**
     * @ORM\Column(type="string", length=5)
     *
     * @Groups({"read"})
     */
    private ?string $isType = null;

    /**
     * @ORM\Column(type="text")
     *
     * @Groups({"read"})
     */
    private ?string $imageUrl = null;

    /**
     * @ORM\Column(type="string", length=255)
     *
     * @Groups({"read"})
     */
    private ?string $imageFormat = null;

    /**
     * @ORM\Column(type="integer")
     *
     * @Groups({"read"})
     */
    private int $width;

    /**
     * @ORM\Column(type="integer")
     *
     * @Groups({"read"})
     */
    private int $height;

    /**
     * @ORM\Column(type="boolean", options={"default" : false})
     */
    private bool $collection = false;

    /**
     * @ORM\ManyToOne(targetEntity="App\Entity\Source", inversedBy="searches")
     */
    private ?Source $source = null;

    public function getId(): int
    {
        return $this->id;
    }

    public function getIsIdentifier(): ?string
    {
        return $this->isIdentifier;
    }

    public function setIsIdentifier(string $isIdentifier): self
    {
        $this->isIdentifier = $isIdentifier;

        return $this;
    }

    public function getIsType(): ?string
    {
        return $this->isType;
    }

    public function setIsType(string $isType): self
    {
        $this->isType = $isType;

        return $this;
    }

    public function getImageUrl(): ?string
    {
        return $this->imageUrl;
    }

    public function setImageUrl(string $imageUrl): self
    {
        $this->imageUrl = $imageUrl;

        return $this;
    }

    public function getImageFormat(): ?string
    {
        return $this->imageFormat;
    }

    public function setImageFormat(string $imageFormat): self
    {
        $this->imageFormat = $imageFormat;

        return $this;
    }

    public function getWidth(): int
    {
        return $this->width;
    }

    public function setWidth(int $width): self
    {
        $this->width = $width;

        return $this;
    }

    public function getHeight(): int
    {
        return $this->height;
    }

    public function setHeight(int $height): self
    {
        $this->height = $height;

        return $this;
    }

    public function isCollection(): bool
    {
        return $this->collection;
    }

    public function setCollection(bool $collection): self
    {
        $this->collection = $collection;

        return $this;
    }

    public function getSource(): ?Source
    {
        return $this->source;
    }

    public function setSource(?Source $source): self
    {
        $this->source = $source;

        return $this;
    }
}
