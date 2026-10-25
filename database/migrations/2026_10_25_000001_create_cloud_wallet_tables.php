<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('v2_cloud_wallet_ledger')) {
            Schema::create('v2_cloud_wallet_ledger', function (Blueprint $table) {
                $table->id();
                $table->string('idempotency_key', 128)->unique();
                $table->char('request_hash', 64);
                $table->unsignedInteger('user_id');
                $table->string('order_no', 64);
                $table->string('kind', 16);
                $table->unsignedInteger('amount_cents');
                $table->char('currency', 3)->default('CNY');
                $table->integer('balance_after_cents');
                $table->unsignedBigInteger('debit_id')->nullable()->unique();
                $table->string('source', 16)->default('cloud');
                $table->string('reason', 255)->nullable();
                $table->integer('created_at');
                $table->index(['created_at', 'id'], 'cloud_wallet_ledger_day_id');
                $table->index(['user_id', 'id'], 'cloud_wallet_ledger_user_id');
                $table->unique(['source', 'order_no', 'kind'], 'cloud_wallet_order_kind_unique');
            });
        }

        // A decline is an immutable idempotency result, too: a delayed duplicate
        // must not start debiting after a top-up while cloud has moved to a new attempt.
        if (!Schema::hasTable('v2_cloud_wallet_debit_decisions')) {
            Schema::create('v2_cloud_wallet_debit_decisions', function (Blueprint $table) {
                $table->string('idempotency_key', 128)->primary();
                $table->char('request_hash', 64);
                $table->string('outcome', 16);
                $table->string('error_code', 64)->nullable();
                $table->unsignedBigInteger('ledger_id')->nullable()->unique();
                $table->integer('created_at');
            });
        }

        if (!Schema::hasTable('v2_cloud_wallet_nonce')) {
            Schema::create('v2_cloud_wallet_nonce', function (Blueprint $table) {
                $table->char('nonce', 32)->primary();
                $table->string('key_id', 128);
                $table->integer('created_at');
                $table->integer('expires_at');
                $table->index('expires_at', 'cloud_wallet_nonce_expires_at');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('v2_cloud_wallet_ledger') && DB::table('v2_cloud_wallet_ledger')->exists()) {
            throw new RuntimeException(
                'Refusing to roll back cloud wallet migration: ledger entries exist. Archive the ledger explicitly instead of deleting it.'
            );
        }

        if (Schema::hasTable('v2_cloud_wallet_debit_decisions') && DB::table('v2_cloud_wallet_debit_decisions')->exists()) {
            throw new RuntimeException('Refusing to erase persisted wallet idempotency decisions.');
        }
        Schema::dropIfExists('v2_cloud_wallet_debit_decisions');
        Schema::dropIfExists('v2_cloud_wallet_nonce');
        Schema::dropIfExists('v2_cloud_wallet_ledger');
    }
};
