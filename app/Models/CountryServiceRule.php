<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CountryServiceRule extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['requirements' => 'array', 'approved_at' => 'datetime'];
    }
}
