<?php
require __DIR__ . '/Support.php';
$boot=cw_boot();
try {
 cw_init_database(); cw_seed_user(1,1000);
 $file=tempnam(sys_get_temp_dir(),'wallet-ingress-test-');file_put_contents($file,str_repeat('x',64));chmod($file,0600);
 config(['cloud_wallet.ingress_key_file'=>$file]);
 $r=cw_send($boot['app'],cw_path('balance'),['userId'=>1]);
 cw_assert_status($r,404,'not_found','public listener rejects even valid wallet HMAC without private ingress proof');
 $r=cw_send($boot['app'],cw_path('balance'),['userId'=>1],null,null,['headers'=>['X-Tianji-Wallet-Ingress'=>str_repeat('z',64)]]);
 cw_assert_status($r,404,'not_found','forged private ingress proof rejected');
 $r=cw_send($boot['app'],cw_path('balance'),['userId'=>1],null,null,['headers'=>['X-Tianji-Wallet-Ingress'=>str_repeat('x',64)]]);
 cw_assert_success($r,'private ingress plus caller signature accepted');
 $r=cw_send($boot['app'],cw_path('balance'),['userId'=>1],null,null,['signature'=>str_repeat('0',64),'headers'=>['X-Tianji-Wallet-Ingress'=>str_repeat('x',64)]]);
 cw_assert_status($r,401,'invalid_signature','private ingress never bypasses HMAC');
 config(['cloud_wallet.enabled'=>false]);
 cw_assert_status(cw_send($boot['app'],cw_path('balance'),['userId'=>1]),404,'not_found','public route hidden while wallet disabled');
 $r=cw_send($boot['app'],cw_path('balance'),['userId'=>1],null,null,['headers'=>['X-Tianji-Wallet-Ingress'=>str_repeat('x',64)]]);
 cw_assert_status($r,503,'wallet_disabled','private closed-mode response');
 config(['cloud_wallet.ingress_key_file'=>'/nonexistent/wallet-ingress']);
 cw_assert_status(cw_send($boot['app'],cw_path('balance'),['userId'=>1]),404,'not_found','missing ingress key fails closed');
 echo "private ingress probe: ALL PASS\n";
} finally { if(isset($file))unlink($file);cw_cleanup($boot); }
