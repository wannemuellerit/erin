<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const DELIVERIES_TABLE = 'support_zammad_webhook_deliveries';

    private const INBOX_TABLE = 'support_zammad_webhook_inbox';

    public function up(): void
    {
        $hasInbox = Schema::hasTable(self::INBOX_TABLE);
        $hasDeliveries = Schema::hasTable(self::DELIVERIES_TABLE);
        if ($hasDeliveries && ! $hasInbox) {
            throw new RuntimeException(
                'Die Zammad-Delivery-Zuordnungen existieren ohne die zugehörige Webhook-Inbox.',
            );
        }

        if ($hasInbox) {
            $this->assertInboxShape();
        } else {
            $this->createInboxTable();
            $this->assertInboxShape();
        }

        if ($hasDeliveries) {
            $this->assertDeliveriesShape();
        } else {
            $this->createDeliveriesTable();
            $this->assertDeliveriesShape();
        }

        $this->backfillDeliveries();
    }

    public function down(): void
    {
        $hasInbox = Schema::hasTable(self::INBOX_TABLE);
        $hasDeliveries = Schema::hasTable(self::DELIVERIES_TABLE);
        if ($hasDeliveries && ! $hasInbox) {
            throw new RuntimeException(
                'Die Zammad-Delivery-Zuordnungen können ohne ihre Webhook-Inbox nicht sicher entfernt werden.',
            );
        }

        if ($hasDeliveries) {
            $this->assertDeliveriesShape();
            Schema::drop(self::DELIVERIES_TABLE);
        }

        if ($hasInbox) {
            $this->assertInboxShape();
            Schema::drop(self::INBOX_TABLE);
        }
    }

    private function createInboxTable(): void
    {
        Schema::create(self::INBOX_TABLE, function (Blueprint $table): void {
            $table->id();
            $table->string('delivery_id', 255)
                ->collation('utf8mb4_bin')
                ->unique();
            $table->string('external_ticket_id', 120)->index();
            $table->char('payload_sha256', 64)->unique();
            $table->longText('raw_payload');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('available_at')->useCurrent();
            $table->timestamp('locked_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('terminal_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->index(
                ['processed_at', 'terminal_at', 'available_at', 'locked_at'],
                'support_zammad_webhook_inbox_pending_index',
            );
        });
    }

    private function createDeliveriesTable(): void
    {
        Schema::create(
            self::DELIVERIES_TABLE,
            function (Blueprint $table): void {
                $table->id();
                $table->foreignId('support_zammad_webhook_inbox_id')
                    ->constrained(
                        self::INBOX_TABLE,
                        'id',
                        'zammad_webhook_delivery_inbox_fk',
                    )
                    ->cascadeOnDelete();
                $table->string('delivery_id', 255)
                    ->collation('utf8mb4_bin')
                    ->unique();
            },
        );
    }

    private function backfillDeliveries(): void
    {
        DB::table(self::DELIVERIES_TABLE)->insertOrIgnoreUsing(
            ['support_zammad_webhook_inbox_id', 'delivery_id'],
            DB::table(self::INBOX_TABLE)->select(['id', 'delivery_id']),
        );

        $hasInvalidMapping = DB::table(self::INBOX_TABLE.' as inbox')
            ->leftJoin(
                self::DELIVERIES_TABLE.' as delivery',
                'delivery.delivery_id',
                '=',
                'inbox.delivery_id',
            )
            ->where(function ($query): void {
                $query->whereNull('delivery.id')
                    ->orWhereColumn(
                        'delivery.support_zammad_webhook_inbox_id',
                        '<>',
                        'inbox.id',
                    );
            })
            ->exists();
        if ($hasInvalidMapping) {
            throw new RuntimeException(
                'Die bestehenden Zammad-Delivery-IDs konnten nicht widerspruchsfrei übernommen werden.',
            );
        }
    }

    private function assertInboxShape(): void
    {
        $actualColumns = [];
        foreach (Schema::getColumns(self::INBOX_TABLE) as $column) {
            $actualColumns[$column['name']] = [
                strtolower($column['type']),
                (bool) $column['nullable'],
                $column['default'] === null
                    ? null
                    : (string) $column['default'],
                (bool) $column['auto_increment'],
                $column['generation'],
                $column['collation'] ?? null,
            ];
        }
        $expectedColumns = [
            'attempts' => [
                'smallint unsigned',
                false,
                '0',
                false,
                null,
                null,
            ],
            'available_at' => [
                'timestamp',
                false,
                'CURRENT_TIMESTAMP',
                false,
                null,
                null,
            ],
            'created_at' => [
                'timestamp',
                true,
                null,
                false,
                null,
                null,
            ],
            'delivery_id' => [
                'varchar(255)',
                false,
                null,
                false,
                null,
                'utf8mb4_bin',
            ],
            'external_ticket_id' => [
                'varchar(120)',
                false,
                null,
                false,
                null,
                'utf8mb4_unicode_ci',
            ],
            'id' => [
                'bigint unsigned',
                false,
                null,
                true,
                null,
                null,
            ],
            'last_error' => [
                'text',
                true,
                null,
                false,
                null,
                'utf8mb4_unicode_ci',
            ],
            'locked_at' => [
                'timestamp',
                true,
                null,
                false,
                null,
                null,
            ],
            'payload_sha256' => [
                'char(64)',
                false,
                null,
                false,
                null,
                'utf8mb4_unicode_ci',
            ],
            'processed_at' => [
                'timestamp',
                true,
                null,
                false,
                null,
                null,
            ],
            'raw_payload' => [
                'longtext',
                false,
                null,
                false,
                null,
                'utf8mb4_unicode_ci',
            ],
            'terminal_at' => [
                'timestamp',
                true,
                null,
                false,
                null,
                null,
            ],
            'updated_at' => [
                'timestamp',
                true,
                null,
                false,
                null,
                null,
            ],
        ];
        ksort($actualColumns);
        ksort($expectedColumns);
        if ($actualColumns !== $expectedColumns) {
            throw new RuntimeException(
                'Die Zammad-Webhook-Inbox besitzt eine abweichende Spaltendefinition.',
            );
        }

        $actualIndexes = array_map(
            fn (array $index): string => $this->schemaSignature([
                $index['columns'],
                (bool) $index['unique'],
                (bool) $index['primary'],
                strtolower($index['type']),
            ]),
            Schema::getIndexes(self::INBOX_TABLE),
        );
        $expectedIndexes = array_map(
            fn (array $index): string => $this->schemaSignature($index),
            [
                [['id'], true, true, 'btree'],
                [['delivery_id'], true, false, 'btree'],
                [['payload_sha256'], true, false, 'btree'],
                [['external_ticket_id'], false, false, 'btree'],
                [
                    [
                        'processed_at',
                        'terminal_at',
                        'available_at',
                        'locked_at',
                    ],
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
                'Die Zammad-Webhook-Inbox besitzt nicht alle Sicherheitsindizes.',
            );
        }
    }

    private function assertDeliveriesShape(): void
    {
        $actualColumns = [];
        foreach (Schema::getColumns(self::DELIVERIES_TABLE) as $column) {
            $actualColumns[$column['name']] = [
                strtolower($column['type']),
                (bool) $column['nullable'],
                $column['default'] === null
                    ? null
                    : (string) $column['default'],
                (bool) $column['auto_increment'],
                $column['generation'],
                $column['collation'] ?? null,
            ];
        }
        $expectedColumns = [
            'delivery_id' => [
                'varchar(255)',
                false,
                null,
                false,
                null,
                'utf8mb4_bin',
            ],
            'id' => [
                'bigint unsigned',
                false,
                null,
                true,
                null,
                null,
            ],
            'support_zammad_webhook_inbox_id' => [
                'bigint unsigned',
                false,
                null,
                false,
                null,
                null,
            ],
        ];
        ksort($actualColumns);
        ksort($expectedColumns);
        if ($actualColumns !== $expectedColumns) {
            throw new RuntimeException(
                'Die Zammad-Delivery-Zuordnungen besitzen eine abweichende Spaltendefinition.',
            );
        }

        $actualIndexes = array_map(
            fn (array $index): string => $this->schemaSignature([
                $index['columns'],
                (bool) $index['unique'],
                (bool) $index['primary'],
                strtolower($index['type']),
            ]),
            Schema::getIndexes(self::DELIVERIES_TABLE),
        );
        $expectedIndexes = array_map(
            fn (array $index): string => $this->schemaSignature($index),
            [
                [['id'], true, true, 'btree'],
                [['delivery_id'], true, false, 'btree'],
                [
                    ['support_zammad_webhook_inbox_id'],
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
                'Die Zammad-Delivery-Zuordnungen besitzen eine abweichende Indexdefinition.',
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
            Schema::getForeignKeys(self::DELIVERIES_TABLE),
        );
        $expectedForeignKeys = [
            $this->schemaSignature([
                ['support_zammad_webhook_inbox_id'],
                self::INBOX_TABLE,
                ['id'],
                'no action',
                'cascade',
            ]),
        ];
        sort($actualForeignKeys);
        sort($expectedForeignKeys);
        if ($actualForeignKeys !== $expectedForeignKeys) {
            throw new RuntimeException(
                'Die Zammad-Delivery-Zuordnungen besitzen eine abweichende Fremdschlüsseldefinition.',
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
