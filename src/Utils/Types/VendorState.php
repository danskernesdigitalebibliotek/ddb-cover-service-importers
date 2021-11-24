<?php

namespace App\Utils\Types;

/**
 * Class VendorState.
 *
 * The different operations that a given vendor message can be in.
 */
class VendorState
{
    public const INSERT = 'insert';
    public const UPDATE = 'update';
    public const DELETE = 'delete';
    public const DELETE_AND_UPDATE = 'deleteAndUpdate';
    public const UNKNOWN = 'unknown';
}
