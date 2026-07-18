<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

function erinZammadEventWatermarkMigration(): object
{
    $migration = require database_path(
        'migrations/2026_07_18_160000_add_zammad_event_watermark_to_support_tickets.php',
    );
    if (! is_object($migration)) {
        throw new RuntimeException(
            'Die Zammad-Event-Watermark-Migration konnte nicht geladen werden.',
        );
    }

    return $migration;
}

it('resumes after the natural MySQL DDL commit boundary', function () {
    $migration = erinZammadEventWatermarkMigration();

    try {
        $migration->up();
        $migration->up();

        $column = collect(Schema::getColumns('support_tickets'))
            ->firstWhere('name', 'external_updated_at_ms');

        expect($column)->toBeArray()
            ->and(strtolower($column['type']))->toBe('bigint unsigned')
            ->and($column['nullable'])->toBeTrue()
            ->and($column['default'])->toBeNull();
    } finally {
        $migration->up();
    }
});

it('supports repeated rollback and a clean restart', function () {
    $migration = erinZammadEventWatermarkMigration();

    try {
        $migration->up();
        $migration->down();
        $migration->down();

        expect(Schema::hasColumn(
            'support_tickets',
            'external_updated_at_ms',
        ))->toBeFalse();

        $migration->up();
    } finally {
        $migration->up();
    }
});

it('fails closed when an existing watermark column has drifted', function () {
    $migration = erinZammadEventWatermarkMigration();
    $migration->up();

    try {
        Schema::table('support_tickets', function (Blueprint $table): void {
            $table->bigInteger('external_updated_at_ms')
                ->nullable()
                ->change();
        });

        expect(fn () => $migration->up())
            ->toThrow(RuntimeException::class, 'nicht das erwartete Schema')
            ->and(Schema::hasColumn(
                'support_tickets',
                'external_updated_at_ms',
            ))->toBeTrue();
    } finally {
        Schema::table('support_tickets', function (Blueprint $table): void {
            $table->unsignedBigInteger('external_updated_at_ms')
                ->nullable()
                ->change();
        });
        $migration->up();
    }
});
