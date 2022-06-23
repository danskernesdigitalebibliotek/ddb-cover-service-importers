<?php

/**
 * @file
 */

namespace App\Message;

use App\Exception\UninitializedPropertyException;
use App\Utils\OpenPlatform\Material;

/**
 * Class IndexMessage.
 */
class IndexMessage extends AbstractBaseMessage
{
    private ?Material $material;

    public function getMaterial(): Material
    {
        if (isset($this->material)) {
            return $this->material;
        }

        throw new UninitializedPropertyException('Material is not initialized');
    }

    public function setMaterial(Material $material): self
    {
        $this->material = $material;

        return $this;
    }
}
