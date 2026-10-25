# Xboard 统一钱包 P1：Cloud Wallet 内部接口

本分支只实现 Xboard 侧的统一钱包内部接口。主余额仍是 `v2_user.balance`，佣金
`v2_user.commission_balance` 未改动；没有新增独立余额表；没有修改订阅、充值、
订单、佣金、`tianjiv3` 或任何既有业务路径。

## 1. 接口

固定前缀：`POST /api/v1/internal/cloud-wallet/*`

| 路由 | 作用 |
| --- | --- |
| `/balance` | 查询 `v2_user.balance` |
| `/debit` | 从主余额扣款并写 cloud 流水 |
| `/refund` | 对原 cloud debit 全额原路退款 |
| `/lookup` | 按业务幂等键查询流水 |
| `/reconcile` | 按 Asia/Shanghai 自然日对账 |

所有路由都经过 `cloud-wallet` 中间件：开关、签名、时间窗、query 拒绝、nonce
防重放。默认关闭，关闭时统一返回 HTTP 503 和 `wallet_disabled`。

### 1.1 签名头

```text
X-Tianji-Wallet-Key-Id: <key id>
X-Tianji-Wallet-Timestamp: <epoch 秒>
X-Tianji-Wallet-Nonce: <32 位 hex>
X-Tianji-Wallet-Signature: <hex HMAC SHA256>
```

canonical 字符串（`\n` 连接，原始 request body 必须逐字节一致）：

```text
tianji-wallet-v1
POST
<pathname>
<sha256(rawBody) 的 hex>
<timestamp>
<nonce>
<keyId>
```

规则：

- 不允许任何 query string；带 query 直接 400 `invalid_request`。
- 服务端时钟与请求时间差不得超过 60 秒。
- nonce 必须是 32 位 hex，写入 `v2_cloud_wallet_nonce` 并由主键保证全局唯一，
  TTL 300 秒；同一个 nonce 重放返回 409 `nonce_reused`，必须换新 nonce。
- 即使请求来自内网也必须签名；服务端完全不使用来源 IP 或转发 IP 做鉴权。
- 签名失败返回 401 `invalid_signature`，时间窗失败返回 401
  `invalid_timestamp`，不输出 SQL、bindings 或密钥。

### 1.2 密钥配置

密钥不写在代码、数据库或 `.env` 明文值中，而是从配置文件路径读取：

```dotenv
CLOUD_WALLET_ENABLED=false
CLOUD_WALLET_KEY_ID=tianji-cloud-wallet-2026
CLOUD_WALLET_KEY_FILE=/opt/xboard-secrets/cloud-wallet.key
# 可选
CLOUD_WALLET_CLOCK_SKEW=60
CLOUD_WALLET_NONCE_TTL=300
```

文件支持三种格式：

1. 纯文本 secret（至少 32 bytes），要求 `CLOUD_WALLET_KEY_ID` 非空；
2. JSON keyring：`{"<keyId>": "<secret>", ...}`；
3. JSON 单 key：`{"keyId": "...", "secret": "..."}`。

secret 可写成 `base64:...`。服务端在读取后校验解码长度不少于 32 bytes。该 key
必须是 cloud wallet 专用 key，禁止复用 `PAY_ADMIN`、Xboard `APP_KEY` 或支付
密钥。建议文件权限 `0600`/`0640`，只给 PHP 运行用户读权限。

### 1.3 请求与响应

成功统一：

```json
{"status":"success","data":{...}}
```

失败统一：

```json
{"status":"fail","code":"...","message":"...","data":null}
```

金额字段必须是 JSON 正整数，不能是字符串、浮点、0 或负数，上限
`2147483647`（分）。余额加总超过上限时退款返回 409 `balance_overflow`。

#### balance

```json
{"userId":123}
```

```json
{"status":"success","data":{"userId":123,"balanceCents":1000,"currency":"CNY"}}
```

#### debit

```json
{
  "userId": 123,
  "orderNo": "TJ202610250001",
  "idempotencyKey": "cloud-order-TJ202610250001",
  "amountCents": 300,
  "currency": "CNY"
}
```

扣款在同一个事务中完成：条件原子更新
`UPDATE v2_user SET balance = balance - ? WHERE id = ? AND banned = 0 AND balance >= ?`，
随后插入 ledger。余额不足返回 409 `insufficient_funds`；封禁用户返回 403
`user_banned`；用户不存在返回 404 `not_found`。

#### refund

```json
{
  "debitId": "17",
  "idempotencyKey": "cloud-refund-TJ202610250001",
  "reason": "customer request"
}
```

只能对 `source=cloud` 的 debit 做全额原路退。金额、用户、orderNo 全部取自原
debit，不接受调用方指定金额或用户。`v2_cloud_wallet_ledger.debit_id` 有唯一
约束，同一 debit 跨不同幂等键也不能多退，第二次返回 409 `already_refunded`。
被封禁用户仍允许退款入账。

#### lookup

```json
{"idempotencyKey":"cloud-order-TJ202610250001"}
```

不存在返回 404 `not_found`；存在返回与 debit/refund 相同的 ledger。

三个写接口成功后的 ledger：

```json
{
  "id": "17",
  "idempotencyKey": "cloud-order-TJ202610250001",
  "userId": 123,
  "orderNo": "TJ202610250001",
  "kind": "debit",
  "amountCents": 300,
  "currency": "CNY",
  "balanceAfterCents": 700,
  "debitId": null,
  "createdAt": "2026-10-25T09:32:21Z"
}
```

同一个幂等键 + 相同业务 payload 重放返回同一条 ledger；同一个键换成不同
payload（包括退款 `reason`）返回 409 `idempotency_conflict`。请求超时后可以先用
同一个 `idempotencyKey` 调 `/lookup`，不要猜测结果。

#### reconcile

```json
{"day":"2026-10-24","limit":2}
```

```json
{
  "status": "success",
  "data": {
    "day": "2026-10-24",
    "timezone": "Asia/Shanghai",
    "asOfId": 42,
    "rows": [
      {"id":"41","idempotencyKey":"...","userId":123,"orderNo":"...","kind":"debit","amountCents":300,"currency":"CNY","balanceAfterCents":700,"debitId":null,"createdAt":"2026-10-24T01:00:00Z"}
    ],
    "nextAfterId": 41
  }
}
```

- `day` 必须是合法的 `YYYY-MM-DD`，日区间固定为 `Asia/Shanghai`。
- 首次调用如果不传 `asOfId`，服务端冻结当前最大 ledger id；后续翻页必须原样
  带上同一个 `asOfId`，并递增 `afterId=nextAfterId`，避免新流水插入造成漂移。
- `limit` 最大 500；不传时默认 100。`nextAfterId` 为 `null` 表示当前 `asOfId`
  范围内没有更多行。

### 1.4 错误码

| HTTP | code | 含义 |
| --- | --- | --- |
| 400 | `invalid_request` | 字段、JSON、query、日期或 limit 非法 |
| 401 | `invalid_signature` | key id、签名、nonce 格式或密钥不可用 |
| 401 | `invalid_timestamp` | 时间窗超限 |
| 403 | `user_banned` | 封禁用户扣款 |
| 404 | `not_found` | 用户、ledger 或 debit 不存在 |
| 409 | `nonce_reused` | nonce 重放 |
| 409 | `idempotency_conflict` | 同幂等键不同 payload |
| 409 | `insufficient_funds` | 余额不足 |
| 409 | `already_refunded` | 原 debit 已退款 |
| 409 | `balance_overflow` | 退款后余额超过 2147483647 |
| 500 | `internal_error` | 未预期错误，不输出内部细节 |
| 503 | `wallet_disabled` | 总开关关闭 |
| 503 | `wallet_unavailable` | 密钥文件配置不可用 |
| 503 | `wallet_busy` | SQLite/数据库写锁等待超时，可按原幂等键重试/查询 |

## 2. 数据与事务

新增表（`2026_10_25_000001_create_cloud_wallet_tables.php`）：

- `v2_cloud_wallet_ledger`：业务幂等唯一 `idempotency_key`、请求 payload hash、
  `user_id`、`order_no`、`kind`、`amount_cents`、`currency`、`balance_after_cents`、
  `debit_id`（唯一，refund 行引用原 debit）、`source=cloud`、`reason`、
  `created_at`。
- `v2_cloud_wallet_nonce`：32 位 nonce 主键、key id、`created_at`、`expires_at`。

`v2_user.balance` 与 ledger 在同一个数据库事务内提交。SQLite 上
`lockForUpdate()` 是空操作，不能当锁用，因此 `CloudWalletTransaction` 在事务内、
任何业务 SELECT 之前先执行零行
`UPDATE v2_cloud_wallet_ledger SET id = id WHERE 0` 取得写保留锁，并临时设置
`busy_timeout=3000ms`，结束后恢复原值。MySQL 分支保持普通事务，依赖条件原子
UPDATE 和唯一约束。

## 3. 开关与部署路径隔离

1. 默认保持 `CLOUD_WALLET_ENABLED=false`；开启前先配置专用 key 文件和 key id。
2. 在公网反向代理/网关层对 `/api/v1/internal/cloud-wallet/` 直接返回 404，
   只允许内网或点对点隧道来源访问。
3. 不建议把该路径挂到公网域名；如果必须跨主机，使用内网监听 + mTLS/VPN，
   并限制来源安全组。
4. 网络隔离不能替代签名。应用层仍强制校验 HMAC、时间窗和 nonce。
5. Xboard 的 `TrustProxies` 只影响 URL/客户端 IP 识别，本接口不使用转发 IP，
   因此不存在“伪造 X-Forwarded-For 绕过鉴权”的问题。
6. 不要复用支付、管理后台或 `APP_KEY` 的密钥；key 轮换时新增 key id，完成
   调用方切换后再移除旧 key。

## 4. 迁移与回退

执行：

```bash
php artisan migrate --path=database/migrations/2026_10_25_000001_create_cloud_wallet_tables.php
```

回退约束：**禁止删除已有账变**。该迁移的 `down()` 在 ledger 非空时会主动抛错
并拒绝回滚，nonce 和 ledger 都会保留。正确的回退方式是：

1. 先把 `CLOUD_WALLET_ENABLED` 置为 `false`；
2. 回退应用镜像/代码，保留两张表；
3. 如确需彻底下线，先由财务/审计导出并确认 ledger，再走人工审批删除，
   不要用 `migrate:rollback` 直接删除历史流水。

## 5. 测试（离线、真实 Laravel + SQLite 临时文件）

不使用 PHPUnit，不监听端口，不访问外网，不读取生产 `.env`。使用 Codex 已验证
的 PHP 运行时和只读 vendor：

```bash
cd /home/nianyi778/.cache/tianji-wallet-xboard

# 一键运行两个探针
tests/CloudWallet/run.sh

# 或分步
/home/nianyi778/.cache/tianji-wallet-review/php-runtime/usr/bin/php \
  -n -d extension_dir=/home/nianyi778/.cache/tianji-wallet-review/php-runtime/usr/lib/php/modules \
  -d extension=pdo_sqlite \
  tests/CloudWallet/cloud_wallet_http_probe.php

ORDER_LOCK_OVERLAY=/home/nianyi778/wsl2/github/tianjiv3/deploy/order-lock/OrderWriteTransaction.php \
/home/nianyi778/.cache/tianji-wallet-review/php-runtime/usr/bin/php \
  -n -d extension_dir=/home/nianyi778/.cache/tianji-wallet-review/php-runtime/usr/lib/php/modules \
  -d extension=pdo_sqlite \
  tests/CloudWallet/cloud_wallet_concurrency_probe.php
```

探针使用真实 `Illuminate\Foundation\Application`、HTTP Kernel 路由、中间件、
控制器、Eloquent 模型和 `DB::transaction`，数据库为 `tempnam()` 创建的独立
SQLite 文件，结束后删除；测试中只创建 cloud wallet 迁移所需的最小 `v2_user`/
`v2_plugins`/`v2_order` 表和 cloud 迁移本身，不执行完整历史迁移集。

### 实测结果（2026-10-25）

- HTTP 功能探针：**248 个断言全部通过**。
- 并发探针：**20 个断言全部通过**，连续运行 5 次均通过。
- 两个探针合计 **268 PASS / 0 FAIL**。
- 覆盖：足额扣款、余额不足、同 key 重放、不同 payload 冲突、并发两单不超扣、
  并发同 key 只扣一次、并发双退款只退一次、同 debit 不同 key、禁用户扣款拒绝、
  禁用户退款允许、金额 0/负数/浮点/字符串/超上限、余额溢出、事务中途触发器异常
  回滚、签名篡改、时间窗过期、nonce 重放、query 拒绝、默认关闭、lookup 404、
  Asia/Shanghai 日对账、`asOfId` 冻结和 `afterId` 翻页、迁移回滚保护、
  退款注入 user/amount 被忽略、密钥文件缺失返回受控 503。
- 现有订阅扣余额路径与 cloud 扣款的双进程竞争：使用真实
  `UserService::addBalance()` 和线上 `OrderWriteTransaction.php` 覆盖层，在同一
  SQLite 文件上与 cloud debit 同时扣 700 分（余额 1000 分）。实测每次只有一个
  成功，最终余额 300 分且永不为负；cloud 与订阅谁赢由调度决定，两种结果都
  满足不超扣。
- 未发现既有订阅写法会静默丢失余额；发现的风险是 SQLite 上
  `lockForUpdate()` 不是行锁，可能导致并发写冲突/重试，该风险由既有
  `OrderWriteTransaction` 覆盖层处理，本任务未修改原订阅业务。

### 未覆盖/缺项

- 未在 MySQL/PostgreSQL 实例上实测；代码走通用 Query Builder 和条件原子
  UPDATE，唯一约束逻辑已实现，但不能宣称 MySQL 已验证。
- 未做生产部署、未开启开关、未配置真实网关隔离规则。
- 未实现自动 key 轮换和定时 nonce 清理任务；nonce 过期行在每次签名请求时
  顺带清理。
- 没有新增对账导出脚本，只有 `/reconcile` 分页 API。
- 未执行完整历史迁移集和完整应用回归；探针聚焦本接口及与订阅扣款的竞争。

## Codex 独立复核补强

初版独立探针 8/10：发现换 key 同订单二次扣款及空退款原因，均已修正。
新增 source/order_no/kind 唯一约束，拒绝同一云订单第二笔扣款。debit 决定（含余额不足、封号、用户不存在）在事务内持久化；拒绝后充值，同一个 key 的迟到重放仍拒绝，明确新尝试须换 key。金融流水与拒绝决定分表，但余额仍仅一份。

退款原因禁止空白；nonce TTL 至少 300 秒、签名时差最多 60 秒，防止配置缩短 TTL 后在签名仍有效时重放。迁移回退也禁止删除已有幂等决定。

独立复跑 HTTP + 双进程并发探针共 268 条 PASS，独立资金探针 15/15（见 tests/CloudWallet/codex_independent_probe.php）。PHP 8.5.12 + Laravel 12.54.1 + 真实 SQLite；未验证生产 PHP 8.2/Octane 或 MySQL。并发订阅路径为真实 UserService::addBalance + 已有 OrderWriteTransaction 补丁，不代表跑过完整订阅支付链。

新增探针用法：`php tests/CloudWallet/codex_independent_probe.php <Xboard checkout> <vendor directory>`。所有样本均为隔离测试，不部署、不修改生产。
# 后续安全修订（2026-10-25）

`LegacyBalanceWriteGuard` 禁止在钱包启用或已有云账变后通过 V2/Admin/User/update 直接设置绝对余额；初始参数和插件过滤后的参数均检查。API 停用但账变仍存在时不会重新开放该路径。退款仍必须引用原 debit、原因、操作人；本期不新增任意人工加减款入口。

兼容性边界：旧后台如整表单包含 `balance`，即使金额未变也会拒绝，编辑其他资料须省略该字段，不能用提交旧值冒充“未改余额”。佣金字段、既有充值和订阅扣款不经此门禁。首次启用前须排空旧代码在途管理员写请求；生产部署和后台表单回验尚未执行。
