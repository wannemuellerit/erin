<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $support_zammad_webhook_inbox_id
 * @property string $delivery_id
 */
class SupportZammadWebhookDelivery extends Model
{
    protected $table = 'support_zammad_webhook_deliveries';

    public $timestamps = false;

    protected $guarded = ['id'];
}
