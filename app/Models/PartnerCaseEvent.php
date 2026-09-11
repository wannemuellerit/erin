<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PartnerCaseEvent extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['metadata' => 'array', 'visible_to_candidate' => 'boolean', 'visible_to_company' => 'boolean'];
    }
}
