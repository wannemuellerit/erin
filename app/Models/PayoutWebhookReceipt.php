<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PayoutWebhookReceipt extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['signature_valid' => 'boolean', 'processed_at' => 'datetime'];
    }
}
