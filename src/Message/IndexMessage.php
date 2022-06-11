<?php

/**
 * @file
 */

namespace App\Message;

use App\Utils\OpenPlatform\Material;

/**
 * Class IndexMessage.
 */
class IndexMessage extends AbstractBaseMessage
{
    private Material $material;

    public function getMaterial(): ?Material
    {
        return $this->material ?? null;
    }

    public function setMaterial(Material $material): self
    {
        $this->material = $material;

        return $this;
    }
}
