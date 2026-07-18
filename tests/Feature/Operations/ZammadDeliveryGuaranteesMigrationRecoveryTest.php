<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

function erinZammadDeliveryGuaranteesMigration(): object
{
    $migration = require database_path(
        'migrations/2026_07_18_190000_harden_zammad_delivery_guarantees.php',
    );
    if (! is_object($migration)) {
        throw new RuntimeException(
            'Die Zammad-Zustellgarantie-Migration konnte nicht geladen werden.',
        );
    }

    return $migration;
}

function erinDropZammadReconciliationColumns(string $tableName): void
{
    Schema::table($tableName, function (Blueprint $table): void {
        $table->dropColumn([
            'external_reconcile_attempts',
            'external_reconcile_not_before',
        ]);
    });
}

it('resumes after every natural MySQL DDL commit boundary', function () {
    $migration = erinZammadDeliveryGuaranteesMigration();

    try {
        $migration->up();
        Schema::drop('support_zammad_article_receipts');
        erinDropZammadReconciliationColumns('support_ticket_messages');

        expect(Schema::hasColumn(
            'support_tickets',
            'external_reconcile_attempts',
        ))->toBeTrue()
            ->and(Schema::hasColumn(
                'support_ticket_messages',
                'external_reconcile_attempts',
            ))->toBeFalse()
            ->and(Schema::hasTable(
                'support_zammad_article_receipts',
            ))->toBeFalse();

        $migration->up();

        expect(Schema::hasColumn(
            'support_ticket_messages',
            'external_reconcile_attempts',
        ))->toBeTrue()
            ->and(Schema::hasTable(
                'support_zammad_article_receipts',
            ))->toBeTrue();

        Schema::drop('support_zammad_article_receipts');
        $migration->up();
        $migration->up();

        expect(Schema::hasTable(
            'support_zammad_article_receipts',
        ))->toBeTrue()
            ->and(Schema::hasIndex(
                'support_zammad_article_receipts',
                'support_zammad_article_receipts_external_article_id_unique',
                'unique',
            ))->toBeTrue()
            ->and(Schema::hasIndex(
                'support_zammad_article_receipts',
                'support_zammad_article_receipts_ticket_time_index',
            ))->toBeTrue();
    } finally {
        $migration->up();
    }
});

it('supports repeated down and a clean restart after a partially completed rollback', function () {
    $migration = erinZammadDeliveryGuaranteesMigration();

    try {
        $migration->up();
        Schema::drop('support_zammad_article_receipts');
        erinDropZammadReconciliationColumns('support_ticket_messages');

        $migration->down();
        $migration->down();

        expect(Schema::hasColumn(
            'support_tickets',
            'external_reconcile_attempts',
        ))->toBeFalse()
            ->and(Schema::hasColumn(
                'support_ticket_messages',
                'external_reconcile_attempts',
            ))->toBeFalse()
            ->and(Schema::hasTable(
                'support_zammad_article_receipts',
            ))->toBeFalse();

        $migration->up();
    } finally {
        $migration->up();
    }
});

it('fails closed on an incomplete reconciliation column pair', function () {
    $migration = erinZammadDeliveryGuaranteesMigration();
    $migration->up();

    try {
        Schema::table('support_tickets', function (Blueprint $table): void {
            $table->dropColumn('external_reconcile_not_before');
        });

        expect(fn () => $migration->up())
            ->toThrow(RuntimeException::class, 'unvollständigen')
            ->and(Schema::hasTable(
                'support_zammad_article_receipts',
            ))->toBeTrue()
            ->and(Schema::hasColumn(
                'support_tickets',
                'external_reconcile_not_before',
            ))->toBeFalse();
    } finally {
        if (! Schema::hasColumn(
            'support_tickets',
            'external_reconcile_not_before',
        )) {
            Schema::table(
                'support_tickets',
                function (Blueprint $table): void {
                    $table->timestamp('external_reconcile_not_before')
                        ->nullable()
                        ->after('external_reconcile_attempts');
                },
            );
        }
        $migration->up();
    }
});

it('rejects drifted receipt columns, indexes and foreign keys without guessing a repair', function () {
    $migration = erinZammadDeliveryGuaranteesMigration();
    $migration->up();

    try {
        Schema::table(
            'support_zammad_article_receipts',
            fn (Blueprint $table) => $table->string('unexpected_drift')->nullable(),
        );
        expect(fn () => $migration->up())
            ->toThrow(RuntimeException::class, 'Spaltendefinition');
    } finally {
        if (Schema::hasColumn(
            'support_zammad_article_receipts',
            'unexpected_drift',
        )) {
            Schema::table(
                'support_zammad_article_receipts',
                fn (Blueprint $table) => $table->dropColumn('unexpected_drift'),
            );
        }
    }

    try {
        Schema::table(
            'support_zammad_article_receipts',
            fn (Blueprint $table) => $table->dropUnique(
                'support_zammad_article_receipts_external_article_id_unique',
            ),
        );
        expect(fn () => $migration->up())
            ->toThrow(RuntimeException::class, 'Indexdefinition');
    } finally {
        if (! Schema::hasIndex(
            'support_zammad_article_receipts',
            'support_zammad_article_receipts_external_article_id_unique',
            'unique',
        )) {
            Schema::table(
                'support_zammad_article_receipts',
                fn (Blueprint $table) => $table->unique('external_article_id'),
            );
        }
    }

    try {
        Schema::table(
            'support_zammad_article_receipts',
            fn (Blueprint $table) => $table->dropForeign(
                'support_zammad_article_receipts_support_ticket_id_foreign',
            ),
        );
        expect(fn () => $migration->up())
            ->toThrow(RuntimeException::class, 'Fremdschlüsseldefinition');
    } finally {
        if (Schema::getForeignKeys(
            'support_zammad_article_receipts',
        ) === []) {
            Schema::table(
                'support_zammad_article_receipts',
                function (Blueprint $table): void {
                    $table->foreign('support_ticket_id')
                        ->references('id')
                        ->on('support_tickets')
                        ->cascadeOnDelete();
                },
            );
        }
        $migration->up();
    }
});
