<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $hasWatermark = Schema::hasColumn(
            'support_tickets',
            'external_last_article_at_ms',
        );
        $hasOutbox = Schema::hasTable('support_webhook_outbox');

        if ($hasOutbox && ! $hasWatermark) {
            throw new RuntimeException(
                'Die Support-Webhook-Outbox existiert ohne den zugehörigen Artikel-Watermark.',
            );
        }

        if ($hasWatermark) {
            $this->assertWatermarkColumn();
        } else {
            Schema::table('support_tickets', function (Blueprint $table): void {
                $table->unsignedBigInteger('external_last_article_at_ms')
                    ->nullable()
                    ->after('external_updated_at_ms');
            });
            $this->assertWatermarkColumn();
        }

        if ($hasOutbox) {
            $this->assertOutboxTable();

            return;
        }

        $this->createOutboxTable();
        $this->assertOutboxTable();
    }

    public function down(): void
    {
        $hasWatermark = Schema::hasColumn(
            'support_tickets',
            'external_last_article_at_ms',
        );
        $hasOutbox = Schema::hasTable('support_webhook_outbox');

        if ($hasOutbox && ! $hasWatermark) {
            throw new RuntimeException(
                'Die Support-Webhook-Outbox kann ohne den zugehörigen Artikel-Watermark nicht sicher entfernt werden.',
            );
        }

        if ($hasOutbox) {
            $this->assertWatermarkColumn();
            $this->assertOutboxTable();
            Schema::drop('support_webhook_outbox');
        }

        if ($hasWatermark) {
            $this->assertWatermarkColumn();
            Schema::table('support_tickets', function (Blueprint $table): void {
                $table->dropColumn('external_last_article_at_ms');
            });
        }
    }

    private function createOutboxTable(): void
    {
        Schema::create('support_webhook_outbox', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('support_ticket_message_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->foreignId('support_ticket_attachment_id')
                ->nullable()
                ->constrained()
                ->cascadeOnDelete();
            $table->foreignId('recipient_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->string('effect', 40);
            $table->char('deduplication_key', 64)->unique();
            $table->uuid('notification_id')->nullable()->index();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('available_at')->useCurrent();
            $table->timestamp('locked_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->index(
                ['processed_at', 'available_at', 'locked_at'],
                'support_webhook_outbox_pending_index',
            );
        });
    }

    private function assertWatermarkColumn(): void
    {
        $column = collect(Schema::getColumns('support_tickets'))
            ->firstWhere('name', 'external_last_article_at_ms');

        if (
            ! is_array($column)
            || $column['type_name'] !== 'bigint'
            || ! str_contains(strtolower($column['type']), 'unsigned')
            || $column['nullable'] !== true
        ) {
            throw new RuntimeException(
                'Der Artikel-Watermark besitzt nicht das erwartete Schema.',
            );
        }
    }

    private function assertOutboxTable(): void
    {
        $columns = collect(Schema::getColumns('support_webhook_outbox'))
            ->keyBy('name');
        $expectedColumns = [
            'id' => ['bigint', false, true],
            'support_ticket_message_id' => ['bigint', false, false],
            'support_ticket_attachment_id' => ['bigint', true, false],
            'recipient_id' => ['bigint', true, false],
            'effect' => ['varchar', false, false],
            'deduplication_key' => ['char', false, false],
            'notification_id' => ['char', true, false],
            'attempts' => ['smallint', false, false],
            'available_at' => ['timestamp', false, false],
            'locked_at' => ['timestamp', true, false],
            'processed_at' => ['timestamp', true, false],
            'last_error' => ['text', true, false],
            'created_at' => ['timestamp', true, false],
            'updated_at' => ['timestamp', true, false],
        ];

        foreach ($expectedColumns as $name => [$type, $nullable, $autoIncrement]) {
            $column = $columns->get($name);
            if (
                ! is_array($column)
                || $column['type_name'] !== $type
                || $column['nullable'] !== $nullable
                || $column['auto_increment'] !== $autoIncrement
            ) {
                throw new RuntimeException(sprintf(
                    'Die Support-Webhook-Outbox besitzt eine abweichende Spalte: %s.',
                    $name,
                ));
            }
        }

        $this->assertIndex('primary', ['id'], unique: true, primary: true);
        $this->assertIndex(
            'support_webhook_outbox_deduplication_key_unique',
            ['deduplication_key'],
            unique: true,
        );
        $this->assertIndex(
            'support_webhook_outbox_notification_id_index',
            ['notification_id'],
        );
        $this->assertIndex(
            'support_webhook_outbox_pending_index',
            ['processed_at', 'available_at', 'locked_at'],
        );
        $this->assertForeignKey(
            ['support_ticket_message_id'],
            'support_ticket_messages',
            'cascade',
        );
        $this->assertForeignKey(
            ['support_ticket_attachment_id'],
            'support_ticket_attachments',
            'cascade',
        );
        $this->assertForeignKey(['recipient_id'], 'users', 'set null');
    }

    /**
     * @param  list<string>  $columns
     */
    private function assertIndex(
        string $name,
        array $columns,
        bool $unique = false,
        bool $primary = false,
    ): void {
        $index = collect(Schema::getIndexes('support_webhook_outbox'))
            ->firstWhere('name', $name);

        if (
            ! is_array($index)
            || $index['columns'] !== $columns
            || $index['unique'] !== $unique
            || $index['primary'] !== $primary
        ) {
            throw new RuntimeException(sprintf(
                'Der Support-Webhook-Outbox-Index %s fehlt oder weicht ab.',
                $name,
            ));
        }
    }

    /**
     * @param  list<string>  $columns
     */
    private function assertForeignKey(
        array $columns,
        string $foreignTable,
        string $onDelete,
    ): void {
        $foreignKey = collect(Schema::getForeignKeys('support_webhook_outbox'))
            ->first(
                static fn (array $candidate): bool => $candidate['columns'] === $columns,
            );

        if (
            ! is_array($foreignKey)
            || $foreignKey['foreign_table'] !== $foreignTable
            || $foreignKey['foreign_columns'] !== ['id']
            || $foreignKey['on_delete'] !== $onDelete
        ) {
            throw new RuntimeException(sprintf(
                'Der Fremdschlüssel der Support-Webhook-Outbox für %s fehlt oder weicht ab.',
                implode(', ', $columns),
            ));
        }
    }
};
