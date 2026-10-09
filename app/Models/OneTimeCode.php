<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

/**
 * The One-Time Code last sent to a number, as a hash: four digits are
 * guessed in ten thousand tries, so the code itself is never kept where a
 * leaked table or log could hand it over. Deleted the moment it is used.
 */
#[Fillable(['phone', 'code_hash', 'expires_at', 'failed_attempts'])]
#[Hidden(['code_hash'])]
class OneTimeCode extends Model
{
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'failed_attempts' => 'integer',
        ];
    }
}
