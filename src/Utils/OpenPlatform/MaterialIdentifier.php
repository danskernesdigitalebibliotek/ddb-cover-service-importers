<?php
/**
 * @file
 * Data model the identifiers found for a material in the open platform.
 */

namespace App\Utils\OpenPlatform;

use App\Exception\MaterialTypeException;
use App\Utils\Types\IdentifierType;

/**
 * Class MaterialIdentifier.
 */
class MaterialIdentifier
{
    private readonly string $type;

    /**
     * MaterialIdentifier constructor.
     *
     * @param string $type
     *   The material type
     * @param string $id
     *   The identifier for this material
     *
     * @throws MaterialTypeException
     */
    public function __construct(
        string $type,
        private readonly string $id
    ) {
        // Build types array.
        $obj = new \ReflectionClass(IdentifierType::class);
        $types = array_values($obj->getConstants());

        // Validate type.
        if (!in_array($type, $types)) {
            throw new MaterialTypeException('Unknown material type: '.$type, 0, null, $type);
        }

        $this->type = $type;
    }

    /**
     * Get the identifier.
     */
    public function getId(): string
    {
        return $this->id;
    }

    /**
     * Get the type of identifier.
     */
    public function getType(): string
    {
        return $this->type;
    }
}
