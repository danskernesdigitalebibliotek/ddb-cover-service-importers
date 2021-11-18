<?php

/**
 * @file
 */

namespace App\Event;

use App\Utils\OpenPlatform\Material;
use Symfony\Contracts\EventDispatcher\Event;

class IndexReadyEvent extends Event
{
    public const NAME = 'app.index.ready';

    private $operation;
    private $is;
    private $vendorId;
    private $imageId;
    private $material;

    /**
     * @return mixed
     */
    public function getOperation()
    {
        return $this->operation;
    }

    /**
     * @param mixed $operation
     *
     * @return static
     */
    public function setOperation($operation): self
    {
        $this->operation = $operation;

        return $this;
    }

    /**
     * @return mixed
     */
    public function getIs()
    {
        return $this->is;
    }

    /**
     * @param mixed $is
     *
     * @return static
     */
    public function setIs($is): self
    {
        $this->is = $is;

        return $this;
    }

    /**
     * @return mixed
     */
    public function getVendorId()
    {
        return $this->vendorId;
    }

    /**
     * @param mixed $vendorId
     *
     * @return static
     */
    public function setVendorId($vendorId): self
    {
        $this->vendorId = $vendorId;

        return $this;
    }

    /**
     * @return mixed
     */
    public function getImageId()
    {
        return $this->imageId;
    }

    /**
     * @param mixed $imageId
     *
     * @return static
     */
    public function setImageId($imageId): self
    {
        $this->imageId = $imageId;

        return $this;
    }

    /**
     * @return Material
     */
    public function getMaterial()
    {
        return $this->material;
    }

    /**
     * @param Material $material
     *
     * @return static
     */
    public function setMaterial(Material $material): self
    {
        $this->material = $material;

        return $this;
    }
}
