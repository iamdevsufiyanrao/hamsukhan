<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    protected $fillable = ['key', 'value', 'is_secret', 'updated_by'];

    protected function casts(): array
    {
        return ['is_secret' => 'boolean'];
    }
}
