<?php

namespace App\Service\Indexing;

class IndexItem {


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
    public function setId(int $id): void
    {
        $this->id = $id;
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
    public function setIsIdentifier(string $isIdentifier): void
    {
        $this->isIdentifier = $isIdentifier;
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
    public function setIsType(string $isType): void
    {
        $this->isType = $isType;
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
    public function setImageUrl(string $imageUrl): void
    {
        $this->imageUrl = $imageUrl;
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
    public function setImageFormat(string $imageFormat): void
    {
        $this->imageFormat = $imageFormat;
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
    public function setWidth(int $width): void
    {
        $this->width = $width;
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
    public function setHeight(int $height): void
    {
        $this->height = $height;
    }


}
