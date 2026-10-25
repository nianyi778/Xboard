<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('v2_wallet_recharge_orders')) {
            Schema::create('v2_wallet_recharge_orders', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('order_no', 64)->unique();
                $table->unsignedInteger('user_id');
                $table->unsignedInteger('amount_cents');
                $table->unsignedInteger('fee_cents')->default(0);
                $table->unsignedInteger('payable_cents');
                $table->char('currency', 3)->default('CNY');
                $table->string('status', 16)->default('pending');
                $table->string('idempotency_key', 128);
                $table->char('request_hash', 64);
                $table->string('return_path', 512)->nullable();
                $table->string('payment_provider', 32)->default('tianji-pay');
                $table->string('upstream_payment_id', 64)->nullable();
                $table->string('upstream_txn_id', 128)->nullable();
                $table->string('channel_txn_id', 128)->nullable();
                $table->string('last_error', 64)->nullable();
                $table->integer('expires_at')->nullable();
                $table->integer('paid_at')->nullable();
                $table->integer('credited_at')->nullable();
                $table->integer('created_at');
                $table->integer('updated_at');
                $table->unique(['user_id', 'idempotency_key'], 'wallet_recharge_user_idem_unique');
                $table->unique(['payment_provider', 'upstream_txn_id'], 'wallet_recharge_order_txn_unique');
                $table->index(['user_id', 'id'], 'wallet_recharge_user_id');
                $table->index(['status', 'created_at'], 'wallet_recharge_status_created');
                $table->index(['status', 'expires_at'], 'wallet_recharge_status_expires');
            });
        }

        if (!Schema::hasTable('v2_wallet_recharge_attempts')) {
            Schema::create('v2_wallet_recharge_attempts', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('order_id');
                $table->unsignedInteger('attempt_no');
                $table->string('out_trade_no', 64)->unique();
                $table->string('provider', 32)->default('tianji-pay');
                $table->string('payment_id', 64)->nullable();
                $table->text('checkout_url')->nullable();
                $table->unsignedInteger('amount_cents');
                $table->unsignedInteger('fee_cents')->default(0);
                $table->unsignedInteger('payable_cents');
                $table->char('currency', 3)->default('CNY');
                $table->string('status', 16)->default('creating');
                $table->string('upstream_txn_id', 128)->nullable();
                $table->string('channel_txn_id', 128)->nullable();
                $table->string('last_error', 64)->nullable();
                $table->integer('verified_at')->nullable();
                $table->integer('created_at');
                $table->integer('updated_at');
                $table->unique(['order_id', 'attempt_no'], 'wallet_recharge_attempt_no_unique');
                $table->unique(['provider', 'payment_id'], 'wallet_recharge_attempt_payment_unique');
                $table->unique(['provider', 'upstream_txn_id'], 'wallet_recharge_attempt_txn_unique');
                $table->unique(['provider', 'channel_txn_id'], 'wallet_recharge_attempt_channel_txn_unique');
                $table->index(['order_id', 'status'], 'wallet_recharge_attempt_order_status');
            });
        }

        if (!Schema::hasTable('v2_wallet_recharge_ledger')) {
            Schema::create('v2_wallet_recharge_ledger', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedInteger('user_id');
                $table->unsignedBigInteger('recharge_order_id')->unique('wallet_recharge_ledger_order_unique');
                $table->string('order_no', 64);
                $table->string('kind', 16)->default('recharge');
                $table->string('source', 16)->default('recharge');
                $table->unsignedInteger('amount_cents');
                $table->unsignedInteger('fee_cents')->default(0);
                $table->char('currency', 3)->default('CNY');
                $table->integer('balance_before_cents');
                $table->integer('balance_after_cents');
                $table->string('provider', 32)->default('tianji-pay');
                $table->string('upstream_txn_id', 128);
                $table->string('channel_txn_id', 128)->nullable();
                $table->string('operator_type', 16)->default('system');
                $table->unsignedBigInteger('evidence_attempt_id');
                $table->integer('created_at');
                $table->unique(['provider', 'upstream_txn_id'], 'wallet_recharge_ledger_txn_unique');
                $table->unique(['provider', 'channel_txn_id'], 'wallet_recharge_ledger_channel_txn_unique');
                $table->index(['user_id', 'id'], 'wallet_recharge_ledger_user_id');
                $table->index(['created_at', 'id'], 'wallet_recharge_ledger_day_id');
            });
        }

        if (!Schema::hasTable('v2_wallet_recharge_exceptions')) {
            Schema::create('v2_wallet_recharge_exceptions', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('kind', 32);
                $table->unsignedBigInteger('order_id')->nullable();
                $table->unsignedBigInteger('attempt_id')->nullable();
                $table->unsignedInteger('user_id')->nullable();
                $table->string('provider', 32)->default('tianji-pay');
                $table->string('upstream_txn_id', 128)->nullable();
                $table->string('channel_txn_id', 128)->nullable();
                $table->string('out_trade_no', 64)->nullable();
                $table->unsignedInteger('amount_cents')->nullable();
                $table->char('currency', 3)->default('CNY');
                $table->string('status', 16)->default('open');
                $table->text('detail_json')->nullable();
                $table->integer('created_at');
                $table->integer('resolved_at')->nullable();
                $table->unique(['provider', 'upstream_txn_id', 'kind'], 'wallet_recharge_exception_txn_kind_unique');
                $table->unique(['provider', 'channel_txn_id', 'kind'], 'wallet_recharge_exception_channel_txn_kind_unique');
                $table->index(['status', 'created_at'], 'wallet_recharge_exception_status');
                $table->index(['order_id', 'kind'], 'wallet_recharge_exception_order');
            });
        }
    }

    public function down(): void
    {
        foreach ([
            'v2_wallet_recharge_ledger',
            'v2_wallet_recharge_attempts',
            'v2_wallet_recharge_orders',
            'v2_wallet_recharge_exceptions',
        ] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException(
                    'Refusing to roll back wallet recharge migration: financial records exist in ' . $table . '. '
                    . 'Archive and manually approve removal instead of deleting payment facts.'
                );
            }
        }

        Schema::dropIfExists('v2_wallet_recharge_exceptions');
        Schema::dropIfExists('v2_wallet_recharge_ledger');
        Schema::dropIfExists('v2_wallet_recharge_attempts');
        Schema::dropIfExists('v2_wallet_recharge_orders');
    }
};
