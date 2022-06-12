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

    /**
     * @return int
     */
    public function getId(): int
    {
        return $this->id;
    }

    /**
     * @param int $id
     */
    public function setId(int $id): self
    {
        $this->id = $id;

        return $this;
    }

    /**
     * @return string
     */
    public function getIsIdentifier(): string
    {
        return $this->isIdentifier;
    }

    /**
     * @param string $isIdentifier
     */
    public function setIsIdentifier(string $isIdentifier): self
    {
        $this->isIdentifier = $isIdentifier;

        return $this;
    }

    /**
     * @return string
     */
    public function getIsType(): string
    {
        return $this->isType;
    }

    /**
     * @param string $isType
     */
    public function setIsType(string $isType): self
    {
        $this->isType = $isType;

        return $this;
    }

    /**
     * @return string
     */
    public function getImageUrl(): string
    {
        return $this->imageUrl;
    }

    /**
     * @param string $imageUrl
     */
    public function setImageUrl(string $imageUrl): self
    {
        $this->imageUrl = $imageUrl;

        return $this;
    }

    /**
     * @return string
     */
    public function getImageFormat(): string
    {
        return $this->imageFormat;
    }

    /**
     * @param string $imageFormat
     */
    public function setImageFormat(string $imageFormat): self
    {
        $this->imageFormat = $imageFormat;

        return $this;
    }

    /**
     * @return int
     */
    public function getWidth(): int
    {
        return $this->width;
    }

    /**
     * @param int $width
     */
    public function setWidth(int $width): self
    {
        $this->width = $width;

        return $this;
    }

    /**
     * @return int
     */
    public function getHeight(): int
    {
        return $this->height;
    }

    /**
     * @param int $height
     */
    public function setHeight(int $height): self
    {
        $this->height = $height;

        return $this;
    }

    /**
     * Format the item as an array.
     *
     * @return array
     */
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
