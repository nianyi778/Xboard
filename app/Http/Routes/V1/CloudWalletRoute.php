<?php

namespace App\Http\Routes\V1;

use App\Http\Controllers\V1\Internal\CloudWalletController;
use Illuminate\Contracts\Routing\Registrar;

class CloudWalletRoute
{
    public function map(Registrar $router)
    {
        $router->group([
            'prefix' => 'internal/cloud-wallet',
            'middleware' => 'cloud-wallet',
        ], function ($router) {
            $router->post('/balance', [CloudWalletController::class, 'balance']);
            $router->post('/debit', [CloudWalletController::class, 'debit']);
            $router->post('/refund', [CloudWalletController::class, 'refund']);
            $router->post('/lookup', [CloudWalletController::class, 'lookup']);
            $router->post('/reconcile', [CloudWalletController::class, 'reconcile']);
        });
    }
}
