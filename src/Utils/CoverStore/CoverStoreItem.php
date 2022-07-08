<?php

/**
 * @file
 * Wrapper for information returned from cover store.
 */

namespace App\Utils\CoverStore;

class CoverStoreItem implements \Stringable
{
    private string $id;
    private string $url;
    private string $vendor;
    private int $size;
    private int $width;
    private int $height;
    private string $originalFile;
    private string $imageFormat;
    private string $crc;

    public function __toString(): string
    {
        $output = [];

        $output[] = str_repeat('-', 42);
        $output[] = 'ID: '.$this->getId();
        $output[] = 'URL: '.$this->getUrl();
        $output[] = 'Vendor: '.$this->getVendor();
        $output[] = 'Size: '.$this->getSize().' Bytes';
        $output[] = 'Width: '.$this->getWidth();
        $output[] = 'Height: '.$this->getHeight();
        $output[] = 'CRC: '.$this->getCrc();
        $output[] = 'Original file: '.$this->getOriginalFile();
        $output[] = 'Original image format: '.$this->getImageFormat();
        $output[] = str_repeat('-', 42);

        return implode("\n", $output);
    }

    public function getId(): string
    {
        return $this->id;
    }

    /**
     * @return static
     */
    public function setId(string $id): self
    {
        $this->id = $id;

        return $this;
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    /**
     * @return static
     */
    public function setUrl(string $url): self
    {
        $this->url = $url;

        return $this;
    }

    public function getVendor(): string
    {
        return $this->vendor;
    }

    /**
     * @param $vendor
     *
     * @return static
     */
    public function setVendor(string $vendor): self
    {
        $this->vendor = $vendor;

        return $this;
    }

    /**
     * @return mixed
     */
    public function getSize(): int
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

    public function getWidth(): int
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

    public function getHeight(): int
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

    public function getCrc(): string
    {
        return $this->crc;
    }

    /**
     * @return static
     */
    public function setCrc(string $crc): self
    {
        $this->crc = $crc;

        return $this;
    }

    public function getOriginalFile(): string
    {
        return $this->originalFile;
    }

    /**
     * @return static
     */
    public function setOriginalFile(string $originalFile): self
    {
        $this->originalFile = $originalFile;

        return $this;
    }

    public function getImageFormat(): string
    {
        return $this->imageFormat;
    }

    /**
     * @return static
     */
    public function setImageFormat(string $imageFormat): self
    {
        $this->imageFormat = $imageFormat;

        return $this;
    }
}
