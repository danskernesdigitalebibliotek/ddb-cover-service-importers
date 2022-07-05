<?php

namespace App\Service\Indexing;

class IndexItem
{
    private int $id;
    private string $isIdentifier;
    private string $isType;
    private string $imageUrl;
    private string $imageFormat;
    private int $width;
    private int $height;

    public function getId(): int
    {
        return $this->id;
    }

    public function setId(int $id): self
    {
        $this->id = $id;

        return $this;
    }

    public function getIsIdentifier(): string
    {
        return $this->isIdentifier;
    }

    public function setIsIdentifier(string $isIdentifier): self
    {
        $this->isIdentifier = $isIdentifier;

        return $this;
    }

    public function getIsType(): string
    {
        return $this->isType;
    }

    public function setIsType(string $isType): self
    {
        $this->isType = $isType;

        return $this;
    }

    public function getImageUrl(): string
    {
        return $this->imageUrl;
    }

    public function setImageUrl(string $imageUrl): self
    {
        $this->imageUrl = $imageUrl;

        return $this;
    }

    public function getImageFormat(): string
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

    public function toArray(): array
    {
        return [
            'isIdentifier' => $this->getIsIdentifier(),
            'isType' => $this->getIsType(),
            'imageUrl' => $this->getImageUrl(),
            'imageFormat' => $this->getImageFormat(),
            'width' => $this->getWidth(),
            'height' => $this->getHeight(),
        ];
    }
}
