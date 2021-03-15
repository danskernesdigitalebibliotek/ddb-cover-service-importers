<?php

namespace App\Utils\Types;

/**
 * Class VendorState.
 *
 * The different operations that a given vendor message can be in.
 */
class VendorState
{
    const INSERT = 'insert';
    const UPDATE = 'update';
    const DELETE = 'delete';
    const DELETE_AND_UPDATE = 'deleteAndUpdate';
    const UNKNOWN = 'unknown';
}
