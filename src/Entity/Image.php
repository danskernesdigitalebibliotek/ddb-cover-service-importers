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
    private $id;

    /**
     * @ORM\Column(type="string", length=4, nullable=true)
     */
    private $imageFormat;

    /**
     * @ORM\Column(type="integer")
     */
    private $size;

    /**
     * @ORM\Column(type="integer")
     */
    private $width;

    /**
     * @ORM\Column(type="integer")
     */
    private $height;

    /**
     * @ORM\Column(type="text", nullable=false)
     */
    private $coverStoreURL;

    /**
     * @ORM\OneToOne(targetEntity="Source", mappedBy="image", cascade={"persist", "remove"})
     */
    private $source;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getImageFormat(): ?string
    {
        return $this->imageFormat;
    }

    public function setImageFormat(?string $imageFormat): self
    {
        $this->imageFormat = $imageFormat;

        return $this;
    }

    public function getSize(): ?int
    {
        return $this->size;
    }

    public function setSize(int $size): self
    {
        $this->size = $size;

        return $this;
    }

    public function getWidth(): ?int
    {
        return $this->width;
    }

    public function setWidth(int $width): self
    {
        $this->width = $width;

        return $this;
    }

    public function getHeight(): ?int
    {
        return $this->height;
    }

    public function setHeight(int $height): self
    {
        $this->height = $height;

        return $this;
    }

    public function getCoverStoreURL(): ?string
    {
        return $this->coverStoreURL;
    }

    public function setCoverStoreURL(string $coverStoreURL): self
    {
        $this->coverStoreURL = $coverStoreURL;

        return $this;
    }

    public function getSource(): ?Source
    {
        return $this->source;
    }

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
