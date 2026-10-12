# 验证与复现

本说明区分离线回归、本地实验库和一次性的华为云公有云验收。所有测试都只对**由使用者明确指定的专用测试库**执行；不要把生产实例和业务 schema 当作默认测试目标。

## 版本与结果

| 环境 | PHP 客户端架构 | Eloquent 合同 | Artisan |
| --- | --- | --- | --- |
| 公有云 GaussDB Kernel 507.0.0，集中式 A | arm64 / x86_64 | 各 29/29 | 各 5 步迁移/回滚 + 清理通过 |
| 公有云 GaussDB Kernel 507.0.0，分布式 ORA，2 分片、1 CN | arm64 / x86_64 | 各 32/32 | 各 5 步迁移/回滚 + 清理通过 |
| 本地双形态实验库 | arm64 / x86_64 | 早期基线集中式各 28/28、分布式各 31/31 | 四组各 5 步 + 清理通过 |

PHP 均为 8.3.33。x86_64 容器在 arm64 宿主机上以 Docker 模拟执行。公有云测试实例、临时 EIP 和访问规则均已删除。结果对应表中的版本、部署形态和测试用例。

公有云合同覆盖 CRUD、自增主键、条件查询、分页、聚合、Join、子查询、事务与保存点、关系预加载、单/多行 upsert、ignore API、8 写入者并发、原子增量、分布键 DDL、重复源键拒绝和错误恢复。Artisan 验证包括 `migrate`、`migrate:status`、分步回滚、重新迁移、默认批次回滚及测试表清理。一次性云资源的标识和原始测试输出仅保留在本地验收记录，**不属于公开交付物**。

## 离线回归

在具备 PHP 8.2+、Composer 和 `pdo_sqlite` 的本包目录执行：

```bash
composer install --no-interaction
php tests/offline.php
# 预期：offline regression checks PASS
```

这只检验 SQL 编译、配置校验与部分保存点逻辑，不能替代真实 GaussDB。

## 真实数据库合同与 Artisan

`scripts/run_matrix.py` 和 `scripts/run_artisan.py` 需要 Docker、已构建的 `gaussdb-eloquent:8.3-arm64` / `gaussdb-eloquent:8.3-x86_64` 镜像，以及可连接的集中式、分布式**专用测试库**。当前 Dockerfile 依赖本地 ODBC 基础镜像和另行取得的官方驱动；本仓库不分发它们。必须先按目标系统准备对应架构的 PHP 与 ODBC 环境。

运行器读取 `GAUSSDB_TEST_ENV_FILE` 指向的权限受控文件，使用 `CENTRAL_` / `DIST_` 前缀分别配置 `DOCKER_HOST`、`PORT`、`DATABASE`、`USER`、`PASSWORD`、`SCHEMA`。密码只存在于私有环境文件或进程环境中，不写入仓库；运行器会把凭据传入 Docker 子进程。首次运行前检查脚本中的默认端口和用户名是否与实际测试库一致。

```bash
GAUSSDB_TEST_ENV_FILE=/absolute/path/to/private-test.env python3 scripts/run_matrix.py
GAUSSDB_TEST_ENV_FILE=/absolute/path/to/private-test.env python3 scripts/run_artisan.py
python3 scripts/summarize_acceptance.py
```

合同测试用随机 `elq_t_` 表，Artisan 测试用随机 `elq_art_` 表及专用迁移表；正常结束及部分异常路径会清理。测试后应在**目标测试库**复核这些前缀无残留。Artisan 回归不会执行 `migrate:fresh`，不应删除已有业务表。各脚本的本地原始报告写入 `reports/`，对外发布前须审核脱敏、证据范围和是否包含客户环境信息。
