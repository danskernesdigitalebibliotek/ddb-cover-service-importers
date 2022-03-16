<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity(repositoryClass="App\Repository\ImageRepository")
 */
class Image
{
    /**
     * @ORM\Id()
     * @ORM\GeneratedValue()
     * @ORM\Column(type="integer")
     */
    private int $id;

    /**
     * @ORM\Column(type="string", length=4, nullable=true)
     */
    private ?string $imageFormat;

    /**
     * @ORM\Column(type="integer")
     */
    private ?int $size;

    /**
     * @ORM\Column(type="integer")
     */
    private ?int $width;

    /**
     * @ORM\Column(type="integer")
     */
    private ?int $height;

    /**
     * @ORM\Column(type="text", nullable=false)
     */
    private ?string $coverStoreURL;

    /**
     * @ORM\OneToOne(targetEntity="Source", mappedBy="image", cascade={"persist", "remove"})
     */
    private ?Source $source;

    public function getId(): int
    {
        return $this->id;
    }

    public function getImageFormat(): ?string
    {
        return $this->imageFormat;
    }

    /**
     * @return static
     */
    public function setImageFormat(?string $imageFormat): self
    {
        $this->imageFormat = $imageFormat;

        return $this;
    }

    public function getSize(): ?int
    {
        return $this->size;
    }

    /**
     * @return static
     */
    public function setSize(int $size): self
    {
        $this->size = $size;

        return $this;
    }

    public function getWidth(): ?int
    {
        return $this->width;
    }

    /**
     * @return static
     */
    public function setWidth(int $width): self
    {
        $this->width = $width;

        return $this;
    }

    public function getHeight(): ?int
    {
        return $this->height;
    }

    /**
     * @return static
     */
    public function setHeight(int $height): self
    {
        $this->height = $height;

        return $this;
    }

    public function getCoverStoreURL(): ?string
    {
        return $this->coverStoreURL;
    }

    /**
     * @return static
     */
    public function setCoverStoreURL(string $coverStoreURL): self
    {
        $this->coverStoreURL = $coverStoreURL;

        return $this;
    }

    public function getSource(): ?Source
    {
        return $this->source;
    }

    /**
     * @return static
     */
    public function setSource(?Source $source): self
    {
        $this->source = $source;

        // set (or unset) the owning side of the relation if necessary
        $newImage = null === $source ? null : $this;
        if ($newImage !== $source->getImage()) {
            $source->setImage($newImage);
        }

        return $this;
    }
}
