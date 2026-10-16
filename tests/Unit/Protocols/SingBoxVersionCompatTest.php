<?php

namespace Tests\Unit\Protocols;

use App\Protocols\SingBox;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * sing-box 订阅生成器的版本兼容回归。
 *
 * 🔴 2026-10-16：`route.default_domain_resolver` 是 1.12.0 才引入的字段。
 *    1.11 对未知字段是 FATAL（实测 sing-box 1.11.15：`route.default_domain_resolver:
 *    json: unknown field`），1.13 缺它反而 FATAL。所以生成器必须按版本条件化：
 *    >=1.12 保留、<1.12 移除。
 *
 * 用反射直接驱动 `adaptConfigForVersion`（不依赖 DB/容器），只验版本变换本身。
 */
class SingBoxVersionCompatTest extends TestCase
{
    private function adapt(string $version, array $config): array
    {
        $rc = new ReflectionClass(SingBox::class);
        $obj = $rc->newInstanceWithoutConstructor();
        foreach ([
            'userAgent'     => "sing-box/{$version}",
            'clientName'    => 'sing-box',
            'clientVersion' => $version,
            'config'        => $config,
        ] as $name => $value) {
            $p = $rc->getProperty($name);
            $p->setAccessible(true);
            $p->setValue($obj, $value);
        }
        $m = $rc->getMethod('adaptConfigForVersion');
        $m->setAccessible(true);
        $m->invoke($obj);

        $p = $rc->getProperty('config');
        $p->setAccessible(true);
        return $p->getValue($obj);
    }

    private function fixture(): array
    {
        return [
            'inbounds' => [
                ['type' => 'tun', 'sniff' => true, 'domain_strategy' => 'prefer_ipv4'],
                ['type' => 'socks', 'sniff' => true],
            ],
            'dns' => ['servers' => [['type' => 'https', 'server' => '1.1.1.1', 'tag' => 'remote']]],
            'outbounds' => [
                ['type' => 'block', 'tag' => 'block-x'],
                ['type' => 'dns', 'tag' => 'dns-x'],
                ['type' => 'selector', 'tag' => 's', 'outbounds' => []],
            ],
            'route' => [
                'rules' => [['outbound' => 'block-x'], ['outbound' => 'dns-x']],
                'default_domain_resolver' => ['server' => 'local'],
            ],
        ];
    }

    public function test_1_11_removes_default_domain_resolver(): void
    {
        $c = $this->adapt('1.11.15', $this->fixture());
        $this->assertArrayNotHasKey('default_domain_resolver', $c['route']);
        // <1.12：DNS server 转旧 address 格式
        $this->assertArrayHasKey('address', $c['dns']['servers'][0]);
        $this->assertArrayNotHasKey('type', $c['dns']['servers'][0]);
    }

    public function test_1_12_keeps_default_domain_resolver(): void
    {
        $c = $this->adapt('1.12.0', $this->fixture());
        $this->assertArrayHasKey('default_domain_resolver', $c['route']);
        $this->assertArrayHasKey('type', $c['dns']['servers'][0]);
    }

    public function test_1_13_keeps_resolver_and_upgrades_outbounds(): void
    {
        $c = $this->adapt('1.13.0', $this->fixture());
        $this->assertArrayHasKey('default_domain_resolver', $c['route']);
        $actions = array_column($c['route']['rules'], 'action');
        $this->assertContains('reject', $actions);
        $this->assertContains('hijack-dns', $actions);
        $this->assertNotContains('block', array_column($c['outbounds'], 'type'));
    }
}
