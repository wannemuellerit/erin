<?php

use Laravel\Cashier\Console\WebhookCommand;
use Laravel\Cashier\Invoices\DompdfInvoiceRenderer;

return [
    /*
    |--------------------------------------------------------------------------
    | Stripe / Cashier
    |--------------------------------------------------------------------------
    |
    | Erin intentionally keeps the public environment variable names supplied
    | by the project. Cashier reads those names through this mapping.
    |
    */

    'key' => env('STRIPE_PUBLISHABLE_KEY'),

    'secret' => env('STRIPE_SECRET_KEY'),

    'path' => env('CASHIER_PATH', 'billing'),

    'webhook' => [
        'secret' => env('STRIPE_WEBHOOK_SECRET'),
        'tolerance' => (int) env('STRIPE_WEBHOOK_TOLERANCE', 300),
        'events' => WebhookCommand::DEFAULT_EVENTS,
    ],

    'currency' => env('CASHIER_CURRENCY', 'eur'),

    'currency_locale' => env('CASHIER_CURRENCY_LOCALE', 'de_DE'),

    'payment_notification' => env('CASHIER_PAYMENT_NOTIFICATION'),

    'invoices' => [
        'renderer' => env('CASHIER_INVOICE_RENDERER', DompdfInvoiceRenderer::class),
        'options' => [
            'paper' => env('CASHIER_PAPER', 'A4'),
            'remote_enabled' => (bool) env('CASHIER_REMOTE_ENABLED', false),
        ],
    ],

    'logger' => env('CASHIER_LOGGER'),
];
