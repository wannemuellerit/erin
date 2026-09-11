<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SupportChatPrompt extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'allowed_tools' => 'array',
            'safety_rules' => 'array',
            'active' => 'boolean',
        ];
    }
}
