<?php

namespace App\Http\Routes\V1;

use App\Http\Controllers\V1\Guest\WalletRechargeNotifyController;
use App\Http\Controllers\V1\User\WalletRechargeController;
use Illuminate\Contracts\Routing\Registrar;

/**
 * 充值订单与回调独立命名空间：不占用 plan/order，也不进入订阅 OrderService。
 */
class WalletRechargeRoute
{
    public function map(Registrar $router): void
    {
        $router->group([
            'prefix' => 'user/wallet/recharge',
            'middleware' => 'user',
        ], function ($router) {
            $router->get('/capability', [WalletRechargeController::class, 'capability']);
            $router->get('/orders', [WalletRechargeController::class, 'index']);
            $router->post('/orders', [WalletRechargeController::class, 'create']);
            $router->get('/orders/{orderNo}', [WalletRechargeController::class, 'show']);
            $router->post('/orders/{orderNo}/payment', [WalletRechargeController::class, 'payment']);
            $router->post('/orders/{orderNo}/refresh', [WalletRechargeController::class, 'refresh']);
        });

        $router->group([
            'prefix' => 'guest/wallet-recharge',
        ], function ($router) {
            $router->post('/notify/tianji', [WalletRechargeNotifyController::class, 'notify']);
        });
    }
}
