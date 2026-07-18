<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function erinZammadWebhookInboxMigration(): object
{
    $migration = require database_path(
        'migrations/2026_07_18_200000_create_support_zammad_webhook_inbox.php',
    );
    if (! is_object($migration)) {
        throw new RuntimeException(
            'Die Zammad-Webhook-Inbox-Migration konnte nicht geladen werden.',
        );
    }

    return $migration;
}

it('accepts a complete inbox after a missing migration record and rejects partial DDL', function () {
    $migration = erinZammadWebhookInboxMigration();
    $migration->up();
    $migration->up();
    $historicalInboxId = DB::table(
        'support_zammad_webhook_inbox',
    )->insertGetId([
        'delivery_id' => 'historical-delivery-before-alias-table',
        'external_ticket_id' => '7654321',
        'payload_sha256' => hash('sha256', '{"ticket":{"id":7654321}}'),
        'raw_payload' => '{"ticket":{"id":7654321}}',
        'available_at' => now(),
    ]);
    Schema::drop('support_zammad_webhook_deliveries');
    $migration->up();

    expect(Schema::hasTable(
        'support_zammad_webhook_deliveries',
    ))->toBeTrue()
        ->and(DB::table('support_zammad_webhook_deliveries')
            ->where(
                'delivery_id',
                'historical-delivery-before-alias-table',
            )
            ->value('support_zammad_webhook_inbox_id'))
        ->toBe($historicalInboxId);

    try {
        $migration->down();
        Schema::create(
            'support_zammad_webhook_inbox',
            function (Blueprint $table): void {
                $table->id();
                $table->string('delivery_id', 255)->unique();
            },
        );

        expect(fn () => $migration->up())
            ->toThrow(RuntimeException::class, 'Spaltendefinition');
    } finally {
        Schema::dropIfExists('support_zammad_webhook_inbox');
        $migration->up();
    }

    expect(Schema::hasIndex(
        'support_zammad_webhook_inbox',
        'support_zammad_webhook_inbox_delivery_id_unique',
        'unique',
    ))->toBeTrue()
        ->and(Schema::hasIndex(
            'support_zammad_webhook_inbox',
            'support_zammad_webhook_inbox_payload_sha256_unique',
            'unique',
        ))->toBeTrue()
        ->and(Schema::hasIndex(
            'support_zammad_webhook_inbox',
            'support_zammad_webhook_inbox_pending_index',
        ))->toBeTrue()
        ->and(Schema::hasIndex(
            'support_zammad_webhook_deliveries',
            'support_zammad_webhook_deliveries_delivery_id_unique',
            'unique',
        ))->toBeTrue();
});

it('rejects a same-column-count inbox with a drifted varchar length', function () {
    $migration = erinZammadWebhookInboxMigration();
    $migration->up();

    try {
        Schema::table(
            'support_zammad_webhook_inbox',
            fn (Blueprint $table) => $table
                ->string('external_ticket_id', 119)
                ->change(),
        );

        expect(fn () => $migration->up())
            ->toThrow(RuntimeException::class, 'Spaltendefinition');
    } finally {
        Schema::table(
            'support_zammad_webhook_inbox',
            fn (Blueprint $table) => $table
                ->string('external_ticket_id', 120)
                ->change(),
        );
        $migration->up();
    }
});

it('rejects a same-column-count inbox with a signed attempts column', function () {
    $migration = erinZammadWebhookInboxMigration();
    $migration->up();

    try {
        Schema::table(
            'support_zammad_webhook_inbox',
            fn (Blueprint $table) => $table
                ->smallInteger('attempts')
                ->default(0)
                ->change(),
        );

        expect(fn () => $migration->up())
            ->toThrow(RuntimeException::class, 'Spaltendefinition');
    } finally {
        Schema::table(
            'support_zammad_webhook_inbox',
            fn (Blueprint $table) => $table
                ->unsignedSmallInteger('attempts')
                ->default(0)
                ->change(),
        );
        $migration->up();
    }
});

it('rejects a wrong inbox index type even when all columns exist', function () {
    $migration = erinZammadWebhookInboxMigration();
    $migration->up();
    $indexName = 'support_zammad_webhook_inbox_payload_sha256_unique';
    $fullTextName = 'support_zammad_webhook_inbox_payload_sha256_fulltext';

    try {
        Schema::table(
            'support_zammad_webhook_inbox',
            function (Blueprint $table) use (
                $indexName,
                $fullTextName,
            ): void {
                $table->dropUnique($indexName);
                $table->fullText('payload_sha256', $fullTextName);
            },
        );

        expect(fn () => $migration->up())
            ->toThrow(RuntimeException::class, 'Sicherheitsindizes');
    } finally {
        Schema::table(
            'support_zammad_webhook_inbox',
            function (Blueprint $table) use (
                $indexName,
                $fullTextName,
            ): void {
                if (Schema::hasIndex(
                    'support_zammad_webhook_inbox',
                    $fullTextName,
                )) {
                    $table->dropFullText($fullTextName);
                }
                if (! Schema::hasIndex(
                    'support_zammad_webhook_inbox',
                    $indexName,
                    'unique',
                )) {
                    $table->unique('payload_sha256', $indexName);
                }
            },
        );
        $migration->up();
    }
});
