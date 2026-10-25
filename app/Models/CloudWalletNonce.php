<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CloudWalletNonce extends Model
{
    protected $table = 'v2_cloud_wallet_nonce';
    public $timestamps = false;
    public $incrementing = false;
    protected $primaryKey = 'nonce';
    protected $keyType = 'string';
    protected $guarded = [];
    protected $casts = [
        'expires_at' => 'integer',
        'created_at' => 'integer',
    ];
}
