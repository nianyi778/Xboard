<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CloudWalletLedger extends Model
{
    public const KIND_DEBIT = 'debit';
    public const KIND_REFUND = 'refund';
    public const SOURCE_CLOUD = 'cloud';
    public const CURRENCY_CNY = 'CNY';

    protected $table = 'v2_cloud_wallet_ledger';
    public $timestamps = false;
    protected $guarded = ['id'];
    protected $casts = [
        'user_id' => 'integer',
        'amount_cents' => 'integer',
        'balance_after_cents' => 'integer',
        'debit_id' => 'integer',
        'created_at' => 'integer',
    ];

    public function toContract(): array
    {
        return [
            'id' => (string) $this->id,
            'idempotencyKey' => (string) $this->idempotency_key,
            'userId' => (int) $this->user_id,
            'orderNo' => (string) $this->order_no,
            'kind' => (string) $this->kind,
            'amountCents' => (int) $this->amount_cents,
            'currency' => (string) $this->currency,
            'balanceAfterCents' => (int) $this->balance_after_cents,
            'debitId' => $this->debit_id === null ? null : (string) $this->debit_id,
            'createdAt' => gmdate('Y-m-d\TH:i:s\Z', (int) $this->created_at),
        ];
    }
}
