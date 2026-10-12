# GaussDB Eloquent 兼容说明

本文记录 `gaussdb.local/eloquent-adapter` 在 GaussDB Kernel 507、Laravel/Illuminate 12 和官方 Unicode ODBC 驱动上的具体行为。范围和验收矩阵见[项目 README](../README.md)。未列出的 Laravel API 不自动视为已验证。

## 部署模式与 Schema

`ConnectionConfig` 接受 `A` 和 `ORA`；`O`、`ORACLE` 会归一化为 `ORA`。当前验收分别使用集中式 A 与分布式 ORA。连接前应读取目标数据库的 `pg_database.datcompatibility`，不要根据实例形态猜测数据库模式。模型表名建议显式写成 `schema.table`。

分布式连接应设置 `distribution_key`，建表时自动追加 `DISTRIBUTE BY HASH`。同一连接当前共用一个分布键；复杂业务表若分布策略不同，应由业务自行规划。主键与每个唯一索引须包含分布列；适配层在相应 DDL 前预校验。GaussDB 分布式物理外键约束不受支持，Eloquent 的 hasMany、belongsTo 等逻辑关系不替代数据库引用完整性。

## 查询与写入

- Eloquent 自增主键通过 `INSERT ... RETURNING` 回填。官方 PDO_ODBC 的 `lastInsertId()` 报 `IM001`；集中式可回退 `lastval()`，分布式不能将全局序列值当成本连接插入 ID。
- GaussDB 当前环境不接受 Laravel PostgreSQL 方言的 `ON CONFLICT`。`upsert()` 改写为 `MERGE INTO`，支持已测的单行、多行插入与更新。重复的非 NULL 源键会在执行前拒绝，不隐式丢行；并发唯一键竞争可有限重试，最终失败仍抛异常。
- `insertOrIgnore()` 逐行执行真实插入，仅忽略唯一键 SQLSTATE `23505`；批次用事务及每行保存点保护。其他错误导致批次回滚，外层事务仍可继续。返回成功插入的行数；冲突可能消耗序列值。
- `insertOrIgnoreUsing()` 要求显式目标列，先在写连接上物化源查询，再复用逐行忽略策略。默认最多 10,000 行，可用 Laravel 连接配置 `gaussdb_ignore_using_max_rows` 调整；它不是单条服务器端 `INSERT SELECT`。
- 分布式 `FOR UPDATE` 与 `LIMIT/OFFSET` 组合可能无法由 CN 下推，适配层明确抛 `LogicException`，不删除锁子句。分布键等值条件下的锁行为仍应按目标查询计划验证阻塞与释放。

## ODBC 类型与错误

ODBC 参数主要以文本到达数据库，不能假定 PostgreSQL 的自动推断与 `PDO::quote()` 可用。优先使用参数绑定；模型需要 JSON 数组时配置对应 cast。`integer()` 是 int4；需容纳 int64 极值时使用 `bigInteger()`/`id()`。ODBC 读出的数值、布尔可能是字符串，模型应声明 cast。ORA/O 兼容模式下空字符串与 NULL 的数据库语义需按业务数据设计验证。

ODBC `getServerVersion()` 可能返回驱动兼容口径，真实内核版本以 `SELECT version()` 为准。唯一键、非空、语法和除零等错误保留五位 SQLSTATE。异常后的连接恢复、嵌套保存点和闭包事务已在验收套件覆盖，但应用不能在不确定的提交结果后盲目重放事务。

## 读写路由与 TLS

Laravel 连接支持 `read`、`write`、`sticky` 配置，端点列表按顺序尝试；读写端点必须使用相同的数据库、schema、兼容模式和分布键。事务内读取走写连接，显式 `useWritePdo()` 也走写连接。连接回退和独立 PDO 已在本地实验环境验证。当前官方 ODBC 对本地实验的物理 hot standby 连接曾拒绝，不能把读路由支持解释为任意备用节点均可读取。

`sslmode` 接受 `disable`、`allow`、`prefer`、`require`、`verify-ca`、`verify-full`。证书路径通过 ODBC 的 `Pqopt`，集群角色选择通过 `TargetServerType`，不要重复配置相同参数。本地证书测试覆盖正确/错误 CA、主机名不匹配及关闭 TLS 时的拒绝；公有云验收确认 SSL 协商和连接成功。CA 与私钥应存于受控目录并使用最小文件权限。
