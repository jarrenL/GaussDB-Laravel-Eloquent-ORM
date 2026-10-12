# GaussDB Laravel Eloquent ORM 适配

[![License: Apache-2.0](https://img.shields.io/badge/License-Apache--2.0-blue.svg)](LICENSE)
[![PHP: 8.2+](https://img.shields.io/badge/PHP-8.2%2B-777BB4.svg)](composer.json)
[![Laravel: 12.x](https://img.shields.io/badge/Laravel-12.x-FF2D20.svg)](composer.json)

本项目为 GaussDB Kernel 507 提供 Laravel 12 Eloquent 数据库适配层，让模型、查询构造器和 Migration 通过 PHP PDO_ODBC 与**官方 GaussDB Unicode ODBC 驱动**访问集中式 A 模式和分布式 ORA 模式数据库。适合需要在现有 Laravel 应用中接入 GaussDB 的 PHP 开发者。

本项目是[jarrenL 维护的独立适配包](https://github.com/jarrenL/GaussDB-Laravel-Eloquent-ORM)，Composer 名称 `gaussdb.local/eloquent-adapter`；并非华为云或 Laravel 官方组件，也不包含厂商 ODBC 二进制。示例用于学习和集成验证，生产使用须在目标版本、网络与负载条件下完成评估。

## 目录

- [架构与能力](#架构与能力)
- [前置条件](#前置条件)
- [快速开始](#快速开始)
- [使用与验证](#使用与验证)
- [已验证范围](#已验证范围)
- [兼容边界](#兼容边界)
- [云服务与费用](#云服务与费用)
- [清理资源](#清理资源)
- [详细文档与依赖](#详细文档与依赖)
- [常见问题](#常见问题)
- [贡献与安全](#贡献与安全)
- [许可证](#许可证)
- [维护者与反馈](#维护者与反馈)

## 架构与能力

```text
Laravel Eloquent / Query Builder / Schema Builder / Artisan Migration
                    ↓
GaussDB Service Provider + Connection + Query/Schema Grammar
                    ↓
               PHP PDO_ODBC
                    ↓
          官方 GaussDB Unicode ODBC
                    ↓
        GaussDB 507：集中式 A / 分布式 ORA
```

适配层通过 Laravel 的数据库连接扩展机制注册 `gaussdb` 驱动，不修改 `vendor/laravel`。已覆盖模型 CRUD、自增主键回填、条件查询、分页、聚合、Join、关系预加载、事务与保存点、Schema 操作和 Artisan Migration。`upsert()` 使用 `MERGE INTO`；`insertOrIgnore()` 与 `insertOrIgnoreUsing()` 通过逐行插入及保存点处理唯一键冲突。分布式建表可按配置的列生成 `DISTRIBUTE BY HASH`。

## 前置条件

1. PHP **8.2+**、Composer、`pdo`、`pdo_odbc`；运行离线回归还需 `pdo_sqlite`。本包依赖 Laravel/Illuminate 12.x，版本以 [composer.json](composer.json) 为准。
2. 与操作系统、CPU 架构和目标数据库匹配的**官方 GaussDB Unicode ODBC 驱动**及 ODBC 管理器。驱动需由使用者从有授权的渠道取得并安装；本包不提供驱动文件。
3. 已存在且允许访问的 GaussDB 507 数据库、账号和 schema。分布式示例使用 ORA 兼容数据库；建表账号须具有相应 DDL 权限。仅做连接验证不需要建表权限。
4. 从应用主机到数据库端口的受控网络访问。若使用 TLS 证书校验，需准备可信 CA 和匹配的服务器证书；不要在代码或仓库中保存密码、AK/SK、客户数据或私钥。

## 快速开始

包尚未发布到 Packagist。从[源码仓库](https://github.com/jarrenL/GaussDB-Laravel-Eloquent-ORM)克隆本包后，在 **Laravel 12 应用根目录**通过 Composer path repository 安装；将示例路径替换为克隆目录的绝对路径：

```bash
composer config repositories.gaussdb-eloquent path /absolute/path/to/gaussdb-eloquent
composer require 'gaussdb.local/eloquent-adapter:@dev'
```

包已声明 Laravel Service Provider 自动发现。若应用关闭自动发现，在 `bootstrap/providers.php` 注册 `GaussDb\Eloquent\GaussDBServiceProvider::class`。在应用的 `config/database.php` 的 `connections` 中加入：

```php
'gaussdb' => [
    'driver' => 'gaussdb',
    'host' => env('GAUSS_HOST'),
    'port' => (int) env('GAUSS_PORT', 8000),
    'database' => env('GAUSS_DATABASE'),
    'username' => env('GAUSS_USER'),
    'password' => env('GAUSS_PASSWORD'),
    'schema' => env('GAUSS_SCHEMA', 'public'),
    'mode' => env('GAUSS_MODE', 'A'),
    'distribution_key' => env('GAUSS_DISTRIBUTION_KEY', ''),
    'sslmode' => env('GAUSS_SSLMODE', 'prefer'),
    'odbc_driver' => 'GaussDB Unicode',
],
```

在应用的私有环境配置中设置连接参数及 `DB_CONNECTION=gaussdb`。集中式使用 `GAUSS_MODE=A`、空分布键；分布式使用 `GAUSS_MODE=ORA`，并将 `GAUSS_DISTRIBUTION_KEY` 设为建表使用的列，例如 `id`。**分布式数据库的兼容模式以实际 `pg_database.datcompatibility` 为准**；本轮公有云实例要求 `ORA`，不能简单套用集中式的 `A`。

## 使用与验证

最小模型示例；表由有 DDL 权限的账号事先创建，或由 Migration 创建：

```php
use Illuminate\Database\Eloquent\Model;

final class Order extends Model
{
    protected $table = 'public.orders';
    public $timestamps = false;
    protected $guarded = [];
}

$order = Order::create(['buyer' => 'alice', 'total' => '12.50']);
$found = Order::find($order->id);
```

从 Laravel 应用根目录执行**只读连接验证**：

```bash
php -r 'require "vendor/autoload.php"; $app = require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); echo $app["db"]->connection("gaussdb")->selectOne("select version() as version")->version, PHP_EOL;'
# 预期输出包含 GaussDB Kernel 版本；连接失败时命令返回非零状态。
```

如要验证本包的 SQL 编译逻辑，在本包目录安装依赖后运行离线回归；它不连接云数据库：

```bash
composer install --no-interaction
php tests/offline.php
# 预期：offline regression checks PASS
```

真实数据库契约与 Artisan 回归位于 `tests/`、`scripts/run_matrix.py` 和 `scripts/run_artisan.py`，需要**专用测试库**、两架构容器镜像及已授权的官方 ODBC 驱动。测试会创建并清理随机前缀表；运行前须确认账号权限和测试库隔离。镜像 Dockerfile 依赖本地的 GaussDB ODBC 基础镜像，不能只靠公共 PHP 镜像直接复现。环境变量和验证细节见[测试说明](docs/validation.md)。

## 已验证范围

2026-10-12 在 GaussDB Kernel 507.0.0 公有云实例上完成下列验收。arm64 和 x86_64 指 **PHP 8.3.33 容器运行架构**；x86_64 容器在 arm64 宿主机上模拟运行，尚未据此认证实体 x86 服务器。

| 部署形态 | arm64 Eloquent 合同 | x86_64 Eloquent 合同 | Artisan Migration 与清理 |
| --- | ---: | ---: | --- |
| 集中式 A、单副本 | 29/29 通过 | 29/29 通过 | 两架构均 5 步迁移/回滚 + 清理通过 |
| 分布式 ORA、2 分片 × 3 DN 副本、1 CN | 32/32 通过 | 32/32 通过 | 两架构均 5 步迁移/回滚 + 清理通过 |

验证包含并发唯一键竞争、8 个写入者的 `upsert`/ignore、原子增量、重复源键拒绝、分布键 DDL、关系加载及迁移回滚；测试表残留为 0。公网实例、临时 EIP 和访问规则在验收后已删除。测试结果适用于表中所列的版本、部署形态和测试用例。复现方法见[测试说明](docs/validation.md)。

## 兼容边界

- 分布式主键与唯一索引必须包含分布列；每个连接当前配置一套分布键。分布式数据库外键约束未支持，模型关系需要应用维护引用完整性。
- 分布式 `FOR UPDATE` 与 `LIMIT/OFFSET` 组合会明确抛错，不会悄悄取消锁。需要行锁时须按实际分布键和 SQL 计划单独验证。
- `insertOrIgnoreUsing()` 会先物化源查询，再逐行处理；默认最多 10,000 行，适合有界输入，不是服务器端单条 `INSERT SELECT`。
- `MERGE` 的并发验收覆盖 8 个写入者的测试场景；部署时应结合业务负载设置重试与异常处理策略。
- `sslmode=prefer` 的成功连接**不证明**服务器证书身份已核验；生产环境应依据目标驱动、证书和网络策略验证 `verify-ca` 或 `verify-full`。

ODBC 参数类型、NULL/空字符串、`lastInsertId()`、JSON、日期、读写路由和 TLS 的详细行为见[兼容说明](docs/compatibility.md)。

## 云服务与费用

本包的安装与只读验证**不会创建 GaussDB、EIP、ECS 或安全组**。若自行创建公有云资源，实例、存储、备份和公网带宽可能持续计费；以所选区域和配置的实际报价为准。验收时优先使用受控测试环境、最小权限账号和最小来源地址范围，并为按需资源预留删除时间。

## 清理资源

不再使用本包时，从 Laravel 应用根目录执行 `composer remove gaussdb.local/eloquent-adapter`，并删除该应用中自行添加的 `gaussdb` 连接配置及环境变量。Composer 卸载**不会删除业务数据或停止云资源计费**。

合同测试和 Artisan 测试会尝试清理各自创建的随机前缀表；若测试异常退出，应在**专用测试库**先核对 `elq_t_%` 和 `elq_art_%` 残留的创建来源，再由有权限的人员删除，不能按前缀盲删。自行创建的实例、EIP 和安全组规则须按资源 ID 在云控制台或授权 API 中确认归属后释放，并复查删除任务终态。

## 详细文档与依赖

- [兼容说明](docs/compatibility.md)：SQL、类型、分布式、读写路由和 TLS 的具体边界。
- [测试说明](docs/validation.md)：版本矩阵、可运行的测试入口与结果解释。
- [composer.json](composer.json)：PHP 扩展和 Illuminate 依赖。`composer.lock` 用于本项目复现；接入应用由应用自己的锁文件固定版本。
- Laravel/Illuminate、PHP PDO_ODBC 与官方 GaussDB Unicode ODBC 驱动分别提供 ORM、PHP 接口和数据库连接能力。本包仅提供适配代码，不重新分发这些上游组件或厂商驱动。

## 常见问题

**为什么 `PDO::lastInsertId()` 不可用？** 官方 ODBC 驱动对它返回 `IM001`；适配层通过 `INSERT ... RETURNING` 回填 Eloquent 自增主键。不要用分布式序列的全局值推断本次插入 ID。

**为什么分布式建表或唯一索引失败？** 核对 `GAUSS_MODE=ORA`、分布键配置及主键/唯一索引是否包含分布列，并确认目标库和 schema 的权限。

**为什么安装后找不到 ODBC 驱动？** 本包不安装官方二进制。确认驱动已按操作系统及 CPU 架构安装，并核对 ODBC 注册名称是否为 `GaussDB Unicode`。

## 贡献与安全

欢迎通过[本仓库的 Issues](https://github.com/jarrenL/GaussDB-Laravel-Eloquent-ORM/issues)反馈问题，或提交 PR。若计划将适配代码提交到 `huaweicloud-samples`，须另行遵循目标仓库的贡献规范、DCO 签名、代码来源、许可及维护者审核要求；**不能把本地验收报告、凭据、客户数据或厂商二进制直接打包上传**。发现凭据泄露或安全问题时，请私下联系维护者，不要在公开 Issue 中粘贴秘密。

## 许可证

本适配包源码按 [Apache License 2.0](LICENSE) 授权。Laravel/Illuminate 和官方 ODBC 驱动各自遵循其许可证与分发条件；本包许可证不改变它们的授权。

## 维护者与反馈

维护者：[jarrenL](https://github.com/jarrenL)。源码与反馈：[GaussDB-Laravel-Eloquent-ORM](https://github.com/jarrenL/GaussDB-Laravel-Eloquent-ORM) · [Issues](https://github.com/jarrenL/GaussDB-Laravel-Eloquent-ORM/issues)。
