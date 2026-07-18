<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $this->assertMySql();
        $this->addToTable('entitlement_ledgers');
    }

    private function addToTable(string $tableName): void
    {
        if ($this->schemaState($tableName) === 'installed') {
            return;
        }

        /*
         * This deliberately is one ALTER TABLE statement. MySQL 8 executes DDL
         * atomically, so existing invalid or duplicate purchases leave neither
         * a partially backfilled column nor a window in which a concurrent
         * writer can bypass the unique key.
         *
         * The stored generated column makes the JSON reference the single
         * source of truth. Its ASCII binary collation keeps Stripe identifiers
         * case-sensitive and the unique index is global across all companies.
         */
        /** @var literal-string $statement */
        $statement = str_replace(
            ['__TABLE__', '__UNIQUE_INDEX__', '__CHECK_CONSTRAINT__'],
            [
                $this->quoteIdentifier($tableName),
                $this->quoteIdentifier($this->schemaObjectName(
                    'entitlement_ledgers_stripe_payment_intent_unique',
                    $tableName,
                )),
                $this->quoteIdentifier($this->schemaObjectName(
                    'entitlement_ledgers_stripe_purchase_check',
                    $tableName,
                )),
            ],
            <<<'SQL'
            ALTER TABLE __TABLE__
                ADD COLUMN `stripe_payment_intent_id` VARCHAR(255)
                    CHARACTER SET ascii COLLATE ascii_bin
                    GENERATED ALWAYS AS (
                        CASE
                            WHEN `source` = 'stripe_purchase'
                            THEN JSON_UNQUOTE(JSON_EXTRACT(`metadata`, '$.stripe_reference'))
                            ELSE NULL
                        END
                    ) STORED
                    AFTER `reference_id`,
                ADD UNIQUE INDEX __UNIQUE_INDEX__
                    (`stripe_payment_intent_id`),
                ADD CONSTRAINT __CHECK_CONSTRAINT__
                    CHECK (
                        COALESCE(
                            (
                                `source` = 'stripe_purchase'
                                AND `resource` = 'visa'
                                AND `amount` > 0
                                AND `reference_type` = 'stripe_payment'
                                AND `reference_id` IS NULL
                                AND `expires_at` IS NULL
                                AND JSON_TYPE(
                                    JSON_EXTRACT(`metadata`, '$.stripe_reference')
                                ) = 'STRING'
                                AND CHAR_LENGTH(
                                    JSON_UNQUOTE(
                                        JSON_EXTRACT(`metadata`, '$.stripe_reference')
                                    )
                                ) BETWEEN 4 AND 255
                                AND JSON_UNQUOTE(
                                    JSON_EXTRACT(`metadata`, '$.stripe_reference')
                                ) REGEXP BINARY '^pi_[A-Za-z0-9_]+$'
                                AND BINARY `stripe_payment_intent_id`
                                    = BINARY JSON_UNQUOTE(
                                        JSON_EXTRACT(`metadata`, '$.stripe_reference')
                                    )
                            )
                            OR
                            (
                                `source` <> 'stripe_purchase'
                                AND `stripe_payment_intent_id` IS NULL
                            ),
                            FALSE
                        ) = TRUE
                    )
            SQL,
        );
        DB::unprepared($statement);
        if ($this->schemaState($tableName) !== 'installed') {
            throw new RuntimeException(
                'Die Stripe-Idempotenzstruktur wurde nicht vollständig installiert.',
            );
        }
    }

    public function down(): void
    {
        $this->assertMySql();
        $this->removeFromTable('entitlement_ledgers');
    }

    private function removeFromTable(string $tableName): void
    {
        if ($this->schemaState($tableName) === 'absent') {
            return;
        }

        /*
         * No purchase row is rewritten during up(), therefore rollback only
         * removes derived schema objects and cannot erase or alter a Stripe
         * payment identity stored in metadata.
         */
        /** @var literal-string $statement */
        $statement = str_replace(
            ['__TABLE__', '__UNIQUE_INDEX__', '__CHECK_CONSTRAINT__'],
            [
                $this->quoteIdentifier($tableName),
                $this->quoteIdentifier($this->schemaObjectName(
                    'entitlement_ledgers_stripe_payment_intent_unique',
                    $tableName,
                )),
                $this->quoteIdentifier($this->schemaObjectName(
                    'entitlement_ledgers_stripe_purchase_check',
                    $tableName,
                )),
            ],
            <<<'SQL'
            ALTER TABLE __TABLE__
                DROP CHECK __CHECK_CONSTRAINT__,
                DROP INDEX __UNIQUE_INDEX__,
                DROP COLUMN `stripe_payment_intent_id`
            SQL,
        );
        DB::unprepared($statement);
        if ($this->schemaState($tableName) !== 'absent') {
            throw new RuntimeException(
                'Die Stripe-Idempotenzstruktur wurde nicht vollständig entfernt.',
            );
        }
    }

    /**
     * Return only for the two safe terminal states. Every crash fragment or
     * schema drift fails closed before another ALTER TABLE can run.
     *
     * @return 'absent'|'installed'
     *
     * @phpstan-impure
     */
    private function schemaState(string $tableName): string
    {
        $column = DB::selectOne(
            <<<'SQL'
                SELECT
                    COLUMN_TYPE,
                    CHARACTER_SET_NAME,
                    COLLATION_NAME,
                    IS_NULLABLE,
                    EXTRA,
                    GENERATION_EXPRESSION
                FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = ?
                  AND COLUMN_NAME = 'stripe_payment_intent_id'
                SQL,
            [$tableName],
        );
        $uniqueIndexName = $this->schemaObjectName(
            'entitlement_ledgers_stripe_payment_intent_unique',
            $tableName,
        );
        $indexRows = DB::select(
            <<<'SQL'
                SELECT
                    NON_UNIQUE,
                    SEQ_IN_INDEX,
                    COLUMN_NAME,
                    SUB_PART,
                    INDEX_TYPE
                FROM information_schema.STATISTICS
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = ?
                  AND INDEX_NAME = ?
                ORDER BY SEQ_IN_INDEX
                SQL,
            [$tableName, $uniqueIndexName],
        );
        $checkName = $this->schemaObjectName(
            'entitlement_ledgers_stripe_purchase_check',
            $tableName,
        );
        $check = DB::selectOne(
            <<<'SQL'
                SELECT checks.CHECK_CLAUSE
                FROM information_schema.TABLE_CONSTRAINTS AS constraints_table
                INNER JOIN information_schema.CHECK_CONSTRAINTS AS checks
                    ON checks.CONSTRAINT_SCHEMA =
                        constraints_table.CONSTRAINT_SCHEMA
                    AND checks.CONSTRAINT_NAME =
                        constraints_table.CONSTRAINT_NAME
                WHERE constraints_table.TABLE_SCHEMA = DATABASE()
                  AND constraints_table.TABLE_NAME = ?
                  AND constraints_table.CONSTRAINT_NAME = ?
                  AND constraints_table.CONSTRAINT_TYPE = 'CHECK'
                SQL,
            [$tableName, $checkName],
        );

        $present = [
            $column !== null,
            $indexRows !== [],
            $check !== null,
        ];
        if ($present === [false, false, false]) {
            return 'absent';
        }
        if ($present !== [true, true, true]) {
            throw new RuntimeException(
                'Die Stripe-Idempotenzstruktur ist nur teilweise installiert.',
            );
        }

        $index = $indexRows[0] ?? null;
        $columnExpression = is_string(
            $column->GENERATION_EXPRESSION ?? null,
        )
            ? $this->expressionHash($column->GENERATION_EXPRESSION)
            : null;
        $checkExpression = is_string($check->CHECK_CLAUSE ?? null)
            ? $this->expressionHash($check->CHECK_CLAUSE)
            : null;
        if (
            count($indexRows) !== 1
            || strtolower((string) ($column->COLUMN_TYPE ?? ''))
                !== 'varchar(255)'
            || strtolower((string) ($column->CHARACTER_SET_NAME ?? ''))
                !== 'ascii'
            || strtolower((string) ($column->COLLATION_NAME ?? ''))
                !== 'ascii_bin'
            || strtoupper((string) ($column->IS_NULLABLE ?? '')) !== 'YES'
            || strtoupper((string) ($column->EXTRA ?? ''))
                !== 'STORED GENERATED'
            || $columnExpression
                !== 'a0803aae3e3da5794867d18a8100ca78bfb4a9b79be292d175f953a99bd84125'
            || (int) ($index->NON_UNIQUE ?? 1) !== 0
            || (int) ($index->SEQ_IN_INDEX ?? 0) !== 1
            || ($index->COLUMN_NAME ?? null)
                !== 'stripe_payment_intent_id'
            || ($index->SUB_PART ?? null) !== null
            || strtoupper((string) ($index->INDEX_TYPE ?? '')) !== 'BTREE'
            || $checkExpression
                !== '90f1ead365ce6ad7d32706ef8556da57560d33d672ad480198a73f8fe3020625'
        ) {
            throw new RuntimeException(
                'Die installierte Stripe-Idempotenzstruktur weicht von der erwarteten Definition ab.',
            );
        }

        return 'installed';
    }

    private function expressionHash(string $expression): string
    {
        $normalized = preg_replace('/\s+/', '', strtolower($expression));
        if (! is_string($normalized)) {
            throw new RuntimeException(
                'Die MySQL-Ausdrucksdefinition konnte nicht geprüft werden.',
            );
        }

        return hash('sha256', $normalized);
    }

    private function assertMySql(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            throw new RuntimeException(
                'Stripe purchase idempotency requires MySQL 8 atomic DDL.',
            );
        }
    }

    private function quoteIdentifier(string $identifier): string
    {
        if (preg_match('/\A[A-Za-z0-9_]+\z/', $identifier) !== 1) {
            throw new InvalidArgumentException('Invalid MySQL identifier.');
        }

        return '`'.$identifier.'`';
    }

    private function schemaObjectName(string $baseName, string $tableName): string
    {
        if ($tableName === 'entitlement_ledgers') {
            return $baseName;
        }

        return substr($baseName, 0, 55).'_'.substr(
            hash('sha256', $tableName),
            0,
            8,
        );
    }
};
