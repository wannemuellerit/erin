<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdEvent extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['event_date' => 'date'];
    }
}
