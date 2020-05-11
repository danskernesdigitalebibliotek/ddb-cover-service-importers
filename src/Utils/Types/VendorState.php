<?php

namespace App\Utils\Types;

class VendorState
{
    const INSERT = 'insert';
    const UPDATE = 'update';
    const DELETE = 'delete';
    const DELETE_AND_UPDATE = 'deleteAndUpdate';
    const UNKNOWN = 'unknown';
}
