<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const RECEIPTS_TABLE = 'support_zammad_article_receipts';

    public function up(): void
    {
        $ticketColumnsInstalled = $this->reconciliationColumnsInstalled(
            'support_tickets',
        );
        $messageColumnsInstalled = $this->reconciliationColumnsInstalled(
            'support_ticket_messages',
        );
        $receiptsTableInstalled = Schema::hasTable(self::RECEIPTS_TABLE);
        if ($receiptsTableInstalled) {
            $this->assertReceiptsTableShape();
        }
        $this->assertMonotonicState(
            $ticketColumnsInstalled,
            $messageColumnsInstalled,
            $receiptsTableInstalled,
        );

        if (! $ticketColumnsInstalled) {
            $this->addReconciliationColumns('support_tickets', 'last_synced_at');
            $this->assertReconciliationColumns('support_tickets');
        }

        if (! $messageColumnsInstalled) {
            $this->addReconciliationColumns(
                'support_ticket_messages',
                'delivered_at',
            );
            $this->assertReconciliationColumns('support_ticket_messages');
        }

        if (! $receiptsTableInstalled) {
            $this->createReceiptsTable();
            $this->assertReceiptsTableShape();
        }
    }

    public function down(): void
    {
        $ticketColumnsInstalled = $this->reconciliationColumnsInstalled(
            'support_tickets',
        );
        $messageColumnsInstalled = $this->reconciliationColumnsInstalled(
            'support_ticket_messages',
        );
        $receiptsTableInstalled = Schema::hasTable(self::RECEIPTS_TABLE);
        if ($receiptsTableInstalled) {
            $this->assertReceiptsTableShape();
        }
        $this->assertMonotonicState(
            $ticketColumnsInstalled,
            $messageColumnsInstalled,
            $receiptsTableInstalled,
        );

        if ($receiptsTableInstalled) {
            Schema::drop(self::RECEIPTS_TABLE);
        }

        if ($messageColumnsInstalled) {
            $this->dropReconciliationColumns('support_ticket_messages');
        }

        if ($ticketColumnsInstalled) {
            $this->dropReconciliationColumns('support_tickets');
        }
    }

    private function addReconciliationColumns(
        string $tableName,
        string $afterColumn,
    ): void {
        Schema::table(
            $tableName,
            function (Blueprint $table) use ($afterColumn): void {
                $table->unsignedTinyInteger('external_reconcile_attempts')
                    ->default(0)
                    ->after($afterColumn);
                $table->timestamp('external_reconcile_not_before')
                    ->nullable()
                    ->after('external_reconcile_attempts');
            },
        );
    }

    private function dropReconciliationColumns(string $tableName): void
    {
        $this->assertReconciliationColumns($tableName);
        Schema::table($tableName, function (Blueprint $table): void {
            $table->dropColumn([
                'external_reconcile_attempts',
                'external_reconcile_not_before',
            ]);
        });
    }

    private function reconciliationColumnsInstalled(string $tableName): bool
    {
        $attemptsInstalled = Schema::hasColumn(
            $tableName,
            'external_reconcile_attempts',
        );
        $deadlineInstalled = Schema::hasColumn(
            $tableName,
            'external_reconcile_not_before',
        );
        if ($attemptsInstalled !== $deadlineInstalled) {
            throw new RuntimeException(sprintf(
                'Die Tabelle %s besitzt einen unvollständigen Zammad-Reconciliation-Teilstaat.',
                $tableName,
            ));
        }
        if ($attemptsInstalled) {
            $this->assertReconciliationColumns($tableName);
        }

        return $attemptsInstalled;
    }

    private function assertReconciliationColumns(string $tableName): void
    {
        $actual = [];
        foreach (Schema::getColumns($tableName) as $column) {
            if (! in_array($column['name'], [
                'external_reconcile_attempts',
                'external_reconcile_not_before',
            ], true)) {
                continue;
            }

            $actual[$column['name']] = [
                strtolower($column['type']),
                (bool) $column['nullable'],
                $column['default'] === null
                    ? null
                    : (string) $column['default'],
                (bool) $column['auto_increment'],
                $column['generation'],
            ];
        }
        $expected = [
            'external_reconcile_attempts' => [
                'tinyint unsigned',
                false,
                '0',
                false,
                null,
            ],
            'external_reconcile_not_before' => [
                'timestamp',
                true,
                null,
                false,
                null,
            ],
        ];
        if ($actual !== $expected) {
            throw new RuntimeException(sprintf(
                'Die Zammad-Reconciliation-Spalten von %s weichen von der erwarteten Definition ab.',
                $tableName,
            ));
        }
    }

    private function assertMonotonicState(
        bool $ticketColumnsInstalled,
        bool $messageColumnsInstalled,
        bool $receiptsTableInstalled,
    ): void {
        if (
            ($messageColumnsInstalled && ! $ticketColumnsInstalled)
            || (
                $receiptsTableInstalled
                && (! $ticketColumnsInstalled || ! $messageColumnsInstalled)
            )
        ) {
            throw new RuntimeException(
                'Die Zammad-Zustellgarantien besitzen keinen gültigen partiellen MySQL-DDL-Zustand.',
            );
        }
    }

    private function createReceiptsTable(): void
    {
        Schema::create(self::RECEIPTS_TABLE, function (Blueprint $table): void {
            $table->id();
            $table->foreignId('support_ticket_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->string('external_article_id', 120)->unique();
            $table->boolean('is_internal');
            $table->timestamp('article_updated_at')->nullable();
            $table->timestamps();

            $table->index(
                ['support_ticket_id', 'article_updated_at'],
                'support_zammad_article_receipts_ticket_time_index',
            );
        });
    }

    private function assertReceiptsTableShape(): void
    {
        $actualColumns = [];
        foreach (Schema::getColumns(self::RECEIPTS_TABLE) as $column) {
            $actualColumns[$column['name']] = [
                strtolower($column['type']),
                (bool) $column['nullable'],
                $column['default'] === null
                    ? null
                    : (string) $column['default'],
                (bool) $column['auto_increment'],
                $column['generation'],
            ];
        }
        $expectedColumns = [
            'id' => ['bigint unsigned', false, null, true, null],
            'support_ticket_id' => [
                'bigint unsigned',
                false,
                null,
                false,
                null,
            ],
            'external_article_id' => [
                'varchar(120)',
                false,
                null,
                false,
                null,
            ],
            'is_internal' => ['tinyint(1)', false, null, false, null],
            'article_updated_at' => [
                'timestamp',
                true,
                null,
                false,
                null,
            ],
            'created_at' => ['timestamp', true, null, false, null],
            'updated_at' => ['timestamp', true, null, false, null],
        ];
        if ($actualColumns !== $expectedColumns) {
            throw new RuntimeException(
                'Die Tabelle support_zammad_article_receipts weicht von der erwarteten Spaltendefinition ab.',
            );
        }

        $actualIndexes = array_map(
            fn (array $index): string => $this->schemaSignature([
                $index['columns'],
                (bool) $index['unique'],
                (bool) $index['primary'],
                strtolower($index['type']),
            ]),
            Schema::getIndexes(self::RECEIPTS_TABLE),
        );
        $expectedIndexes = array_map(
            fn (array $index): string => $this->schemaSignature($index),
            [
                [['id'], true, true, 'btree'],
                [['external_article_id'], true, false, 'btree'],
                [
                    ['support_ticket_id', 'article_updated_at'],
                    false,
                    false,
                    'btree',
                ],
            ],
        );
        sort($actualIndexes);
        sort($expectedIndexes);
        if ($actualIndexes !== $expectedIndexes) {
            throw new RuntimeException(
                'Die Tabelle support_zammad_article_receipts weicht von der erwarteten Indexdefinition ab.',
            );
        }

        $actualForeignKeys = array_map(
            fn (array $foreignKey): string => $this->schemaSignature([
                $foreignKey['columns'],
                $foreignKey['foreign_table'],
                $foreignKey['foreign_columns'],
                strtolower((string) $foreignKey['on_update']),
                strtolower((string) $foreignKey['on_delete']),
            ]),
            Schema::getForeignKeys(self::RECEIPTS_TABLE),
        );
        $expectedForeignKeys = [
            $this->schemaSignature([
                ['support_ticket_id'],
                'support_tickets',
                ['id'],
                'no action',
                'cascade',
            ]),
        ];
        sort($actualForeignKeys);
        sort($expectedForeignKeys);
        if ($actualForeignKeys !== $expectedForeignKeys) {
            throw new RuntimeException(
                'Die Tabelle support_zammad_article_receipts weicht von der erwarteten Fremdschlüsseldefinition ab.',
            );
        }
    }

    /**
     * @param  array<mixed>  $definition
     */
    private function schemaSignature(array $definition): string
    {
        return json_encode($definition, JSON_THROW_ON_ERROR);
    }
};
