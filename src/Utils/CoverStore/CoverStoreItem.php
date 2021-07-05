<?php

/**
 * @file
 * Wrapper for information returned from cover store.
 */

namespace App\Utils\CoverStore;

class CoverStoreItem
{
    private $id;
    private $url;
    private $vendor;
    private $size;
    private $width;
    private $height;
    private $originalFile;
    private $imageFormat;
    private $crc;

    public function __toString()
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

    /**
     * @return string
     */
    public function getId(): string
    {
        return $this->id;
    }

    /**
     * @param string $id
     *
     * @return static
     */
    public function setId(string $id): self
    {
        $this->id = $id;

        return $this;
    }

    /**
     * @return string
     */
    public function getUrl(): string
    {
        return $this->url;
    }

    /**
     * @param string $url
     *
     * @return static
     */
    public function setUrl(string $url): self
    {
        $this->url = $url;

        return $this;
    }

    /**
     * @return string
     */
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
     * @param int $size
     *
     * @return static
     */
    public function setSize(int $size): self
    {
        $this->size = $size;

        return $this;
    }

    /**
     * @return int
     */
    public function getWidth()
    {
        return $this->width;
    }

    /**
     * @param int $width
     *
     * @return static
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
     *
     * @return static
     */
    public function setHeight(int $height): self
    {
        $this->height = $height;

        return $this;
    }

    /**
     * @return string
     */
    public function getCrc()
    {
        return $this->crc;
    }

    /**
     * @param string $crc
     *
     * @return static
     */
    public function setCrc(string $crc): self
    {
        $this->crc = $crc;

        return $this;
    }

    /**
     * @return mixed
     */
    public function getOriginalFile()
    {
        return $this->originalFile;
    }

    /**
     * @param string $originalFile
     *
     * @return static
     */
    public function setOriginalFile(string $originalFile): self
    {
        $this->originalFile = $originalFile;

        return $this;
    }

    /**
     * @return mixed
     */
    public function getImageFormat()
    {
        return $this->imageFormat;
    }

    /**
     * @param string $imageFormat
     *
     * @return static
     */
    public function setImageFormat(string $imageFormat): self
    {
        $this->imageFormat = $imageFormat;

        return $this;
    }
}
