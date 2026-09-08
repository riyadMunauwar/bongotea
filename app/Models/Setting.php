<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * The stored row for one Settings group.
 *
 * Used only by `SettingsGroup`: the group class is the schema of record for
 * its row, and everything else reads a typed group from the container rather
 * than this model. No cast encrypts the column, because only the properties a
 * group marks as secret are encrypted, and they are encrypted one at a time
 * inside the JSON before it reaches here (ADR-0012).
 */
#[Fillable(['key', 'value'])]
class Setting extends Model
{
    protected $primaryKey = 'key';

    protected $keyType = 'string';

    public $incrementing = false;

    protected function casts(): array
    {
        return [
            'value' => 'array',
        ];
    }
}
