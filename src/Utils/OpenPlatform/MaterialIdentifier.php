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
    private string $type;
    private string $id;

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
    public function __construct(string $type, string $id)
    {
        // Build types array.
        $obj = new \ReflectionClass(IdentifierType::class);
        $types = array_values($obj->getConstants());

        // Validate type.
        if (!in_array($type, $types)) {
            throw new MaterialTypeException('Unknown material type: '.$type, 0, null, $type);
        }

        $this->type = $type;
        $this->id = $id;
    }

    /**
     * Get the identifier.
     *
     * @return string
     */
    public function getId(): string
    {
        return $this->id;
    }

    /**
     * Get the type of identifier.
     *
     * @return string
     */
    public function getType(): string
    {
        return $this->type;
    }
}
