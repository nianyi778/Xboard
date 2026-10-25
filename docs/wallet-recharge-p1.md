# Xboard 平台余额主动充值 P1

契约版本：`wallet-recharge-v1`。状态：**代码与隔离测试完成，未部署、未开启生产开关、未做真实支付**。

余额主账仍是 `v2_user.balance`；本功能不建第二份余额，不修改佣金、订阅订单、
云钱包扣款/退款或公开站 AI 代充 `/recharge`。

## 1. 用户接口

前缀：`/api/v1/user/wallet/recharge`，全部使用 Xboard 现有 `user` 中间件
（Sanctum 登录态）。客户端提交的 `userId` 不参与授权，服务端只用登录用户 ID。

| 方法 | 路径 | 作用 |
| --- | --- | --- |
| GET | `/capability` | 是否可创建新单、限额、手续费、实时余额 |
| POST | `/orders` | 创建/幂等回放充值订单 |
| GET | `/orders?limit=20` | 本人充值记录 |
| GET | `/orders/{orderNo}` | 本人订单详情 |
| POST | `/orders/{orderNo}/payment` | 创建/继续受控付款尝试 |
| POST | `/orders/{orderNo}/refresh` | 服务端查单补账 |

创建请求：

```json
{
  "amountCents": 5000,
  "idempotencyKey": "panel-20261025-<随机>",
  "returnPath": "/cloud/orders/TJ20261025..."
}
```

- `amountCents` 必须是 JSON 正整数（分），不接受字符串/浮点/0/负数/超上限。
- `idempotencyKey`：8–128 位 `[A-Za-z0-9._:-]`。同用户同键同请求摘要回放原单；
  同键不同金额/币种/返回路径返回 409 `idempotency_conflict`。
- `returnPath` 可空；只接受受控站内相对路径（`/wallet/recharge`、
  `/cloud/orders/`、`/cloud/instances/`、`/orders/`、`/subscription`），
  拒绝外链、协议相对地址、`..`、反斜杠、片段和控制字符。
- 订单号由服务端随机生成（`WR` + UTC 日期 + 16 hex），不接受客户端指定。

`payment` 返回：

```json
{
  "state": "payment_required",
  "orderNo": "WR20261025...",
  "checkoutUrl": "https://pay.tianjiyun.org/checkout/pay_...",
  "paymentId": "pay_...",
  "attemptNo": 1,
  "payableCents": 5000,
  "expiresAt": "2026-10-25T10:00:00Z"
}
```

`checkoutUrl` 仅来自服务端对 tianji-pay 建单响应的校验结果，协议与主机必须与
配置的支付域一致；不接受客户端传入。已付款时返回 `state=paid`，不返回收银台。

## 2. 支付回调

独立端点：`POST /api/v1/guest/wallet-recharge/notify/tianji`。不进入订阅
`OrderService::open()`，也不复用 `/guest/payment/notify/*`。

请求体（tianji-pay 现有契约）：

```json
{
  "orderNo": "<充值尝试的 out_trade_no>",
  "amountCents": 5000,
  "txnId": "pi_...",
  "method": "stripe",
  "timestamp": 1791608284,
  "sign": "hex HMAC-SHA256(app_secret, `${orderNo}.${amountCents}.${txnId}.${timestamp}`)"
}
```

处理顺序：

1. 校验时间戳（默认 ±900 秒）和 `sign`，使用独立 `pay.secret_file`，`hash_equals`。
2. 按 `orderNo` 找到充值尝试；未知订单 404，不入账。
3. 校验回调金额与本地应付快照完全一致。
4. **服务端再带签名查单** `GET /api/payments/:id`，要求 `payment_id`、`out_trade_no`、
   `amount`、`currency=CNY`、`status=paid` 全部匹配。浏览器 return、前端成功字段、
   截图和 2xx 均不作为付款证据。
5. 在同一事务内锁定充值订单、尝试和用户，检查唯一入账流水，增加
   `v2_user.balance`，写充值流水，标记完成。
6. 已完成后收到 pending/failed 等乱序通知：fail-closed，不回退、不扣款。
7. 重复/并发通知只入账一次；额外付款写入异常队列，保留资金事实。

回调失败返回非 2xx，让 tianji-pay 重试；绝不返回伪成功。

## 3. 状态机

订单：

```text
pending ──发起付款──▶ paying ──已核验付款──▶ paid
   │                    │
   │                    ├──上游明确 expired/failed──▶ expired / failed
   │                    └──已付款但不能安全归属──────▶ review
   └──创建失败──────────────────────────────────────▶ failed
```

- `paid` 是终态，不会被迟到回调改回 pending/failed/cancelled。
- `review` 表示钱的事实已收到但不能安全入账（封禁/删除用户、取消后迟到付款、
  跨订单重复交易号、余额溢出等），只能人工核对；本期不提供人工调账入口。
- 换渠道：只有上游查单明确返回 `expired`/`failed` 后才允许新付款尝试；结果未知时
  阻止重建。旧尝试延迟成功、两渠道都成功时，最多一条入账流水，额外付款进入
  `duplicate_payment` 异常。

付款尝试：`creating → created → paid`，另可进入 `unknown`（建单超时/网络未知，
同 `out_trade_no` 可重试）、`expired`、`failed`、`duplicate`。

## 4. 数据与唯一约束

迁移：`database/migrations/2026_10_25_000002_create_wallet_recharge_tables.php`

- `v2_wallet_recharge_orders`
  - `unique(user_id, idempotency_key)`
  - `unique(payment_provider, upstream_txn_id)`（canonical 上游支付单号）
  - 订单本金、手续费、应付总额、币种、状态、返回路径、支付渠道、时间。
- `v2_wallet_recharge_attempts`
  - `unique(out_trade_no)`
  - `unique(order_id, attempt_no)`
  - `unique(provider, payment_id)`
  - `unique(provider, upstream_txn_id)` 与 `unique(provider, channel_txn_id)`
  - 记录每次付款尝试、收银台、核验时间。
- `v2_wallet_recharge_ledger`
  - `unique(recharge_order_id)`：每个充值订单最多一条有效入账。
  - `unique(provider, upstream_txn_id)`、`unique(provider, channel_txn_id)`：
    同一支付单/渠道交易不能跨订单重复入账。
  - 记录本金、手续费、余额前/后值、`source=recharge`、操作者类型 `system`、
    证据付款尝试 ID。
- `v2_wallet_recharge_exceptions`
  - `unique(provider, upstream_txn_id, kind)`、
    `unique(provider, channel_txn_id, kind)` 防重复异常。

金额全部为整数分；手续费用整数 bps + 固定分计算（四舍五入到分），不经过浮点。
`source=recharge` 与云钱包 `source=cloud` 分离，不影响云扣款/退款统计。

事务与锁：

- SQLite 使用 `WalletRechargeTransaction`：事务内先执行零行
  `UPDATE v2_wallet_recharge_orders SET id=id WHERE 0` 取得写保留锁，
  `busy_timeout=3000ms`，避免 `SQLITE_BUSY_SNAPSHOT`。
- MySQL 使用普通事务，依赖 `lockForUpdate()`、条件 UPDATE 和唯一约束。
- 锁顺序：充值订单 → 付款尝试 → 用户。死锁只做最多 3 次有界重试，写入本身仍由
  幂等键和唯一约束保证只生效一次。
- 未修改既有云扣款/退款 `CloudWalletTransaction` 与订阅扣款路径；多进程探针覆盖
  充值入账与云扣款同时发生。

## 5. 配置与开关

`config/wallet_recharge.php`，全部默认关闭/空：

```dotenv
WALLET_RECHARGE_ENABLED=false
# 生产限额必须由运营/Codex 按支付配置定版，代码不提供业务默认值
WALLET_RECHARGE_MIN_CENTS=
WALLET_RECHARGE_MAX_CENTS=
WALLET_RECHARGE_FEE_BPS=0
WALLET_RECHARGE_FEE_FIXED_CENTS=0
WALLET_RECHARGE_FEE_BEARER=customer
WALLET_RECHARGE_PAY_BASE_URL=
WALLET_RECHARGE_PAY_APP_ID=
WALLET_RECHARGE_PAY_SECRET_FILE=/opt/xboard-secrets/wallet-recharge-pay.key
WALLET_RECHARGE_PAY_NOTIFY_URL=https://api.example/api/v1/guest/wallet-recharge/notify/tianji
WALLET_RECHARGE_PANEL_URL=https://panel.example
WALLET_RECHARGE_PAY_TIMEOUT=10
WALLET_RECHARGE_EXPIRES_MINUTES=40
WALLET_RECHARGE_CALLBACK_SKEW=900
```

语义：

- `WALLET_RECHARGE_ENABLED` 只控制**创建新充值订单/新付款尝试**。
- 关闭后，已创建真实付款的回调和查单仍可验签入账；不会因关新单丢资金。
- 支付凭据缺失、金额上下限为空/非法时，能力接口返回不可用，创建请求 503；
  不使用假参数启动真实入口。
- 支付 secret 只从独立文件读取（≥32 bytes），不进环境变量明文/日志/响应；
  不复用 `APP_KEY`、`PAY_ADMIN` 或 cloud wallet 密钥。
- 回调路径必须加入 tianji-pay 对应 `app` 的 `notify_url_allowlist`，并把
  `WALLET_RECHARGE_PAY_NOTIFY_URL` 指向公网可达的 Xboard 入口。
  生产启用前由 Codex 核对商户、限额、手续费和回调白名单。

## 6. 对账

```bash
php artisan wallet-recharge:reconcile --day=2026-10-25
```

输出脱敏 JSON（仅用户 ID/订单号/金额，不含邮箱、签名、令牌、回调原文）：

- 当日已核验付款本金合计（按核验时间）
- 当日充值入账合计（按流水时间，只统计 `source=recharge`）
- 手续费合计（单列，不混入本金）
- 已核验但未入账的差异订单（含双付/人工核实）
- 未决异常队列
- 超过 1 小时的 pending/paying 订单

日边界固定 `Asia/Shanghai`。云钱包退款/扣款不计入充值本金。

## 7. 迁移、备份、关闭与回滚

上线顺序（由 Codex 审核后执行，OpenCode 未执行）：

1. 备份数据库；执行增量迁移。
2. 配置支付凭据、通知 URL 白名单、金额上下限；先保持总开关关闭。
3. 部署代码，验证能力接口返回关闭、公开回调可验签但无单可入账。
4. 打开 `WALLET_RECHARGE_ENABLED`，先由用户做真实小额充值验收。

关闭与回滚：

- 先关 `WALLET_RECHARGE_ENABLED`，停止新单；保留回调、查单、流水表和对账能力。
- 代码回滚必须保留充值回调处理通路；否则会出现“钱到账、余额永远不入账”。
- 迁移 `down()` 在任一充值表存在数据时主动抛错，禁止删表/清库回滚。
  `paid` 订单、入账流水和异常记录只能先导出并由人工审批后处理。

## 8. 隔离测试

```bash
cd /home/nianyi778/.cache/tianji-wallet-recharge-xboard
tests/WalletRecharge/run.sh
```

- 使用真实 Laravel 12、SQLite 临时文件、假支付端点（`Http::fake`），不监听
  生产端口、不访问外网、不发真实付款/邮件。
- 功能探针 234 PASS：幂等、金额边界、签名/金额/币种/状态 fail-closed、
  跨账号归属、重复回调、查单补账、换渠道双付、封禁/删除用户、取消后迟到付款、
  事务故障注入（流水插入前/订单更新前）、上游超时/坏响应、数据库不可写、
  开关/凭据缺失、手续费与日对账、迁移回退保护。
- 多进程探针 45 PASS：8 个并发重复回调、4 回调 + 4 查单、两单同时充值同用户、
  充值入账与云扣款同时发生；每次只有一条流水，余额不丢更新、不负数。
- 既有 CloudWallet 回归：310 PASS（含 HTTP、并发、旧余额写保护、私有入口）。
- 未验证：生产 PHP 8.2/Octane、MySQL 实例、真实 tianji-pay 商户回调、生产网关联通。

实测日期：2026-10-25。报告与原始输出随 tianjiv3 主报告提交。
