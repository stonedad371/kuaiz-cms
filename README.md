# Kuaiz CMS Community

一个运行在你自己 PHP 主机上的轻量 CMS。网站可以独立编辑、发布和备份；需要持续运营时，
再选择连接快智 AI 自动运营服务。

> 当前版本是 Developer Preview，适合开发、审查和测试，不建议直接承载正式业务。正式签名
> 安装器和受支持版本只会在 [cms.kuaiz.net/download/](https://cms.kuaiz.net/download/) 发布。

English summary: Kuaiz CMS Community is a lightweight, self-hosted PHP and SQLite CMS with
local editing, publishing, version history, backups, a machine-readable Theme SDK and a
restricted Extension SDK. The current branch is a developer preview, not a supported release.

## 为什么做这个 CMS

- 网站运行在自己的域名和主机，不被建站平台锁住；
- 公开网站、人工编辑和备份不依赖快智总台在线；
- 每个网站只使用一种内容语言，另一个市场建立另一个网站；
- Theme 只负责页面表现，不能执行 PHP、读取数据库或绕过 SEO 规则；
- AI 自动运营是可选商业服务，不是 CMS 正常运行的前置条件。

## 已包含的能力

- 本地管理员、编辑和只读账号，登录限流、安全会话与操作审计；
- 结构化内容、不可变修订、发布、下线、归档和历史恢复；
- 图片重新编码、缩略图、引用关系和本地媒体库；
- 单站单语言设置、Canonical、结构化数据、`robots.txt` 和站点地图；
- Theme SDK v2、参考主题和长数据、复杂状态、空数据、RTL 验收种子；
- Extension SDK v1 与不执行 PHP 的参考内容目录扩展；
- 带 SHA-256 清单的备份恢复、维护锁和数据库升级失败回滚。

预约、会员、支付和 AI 自动运营连接器尚未包含在当前开发预览中。

## 运行要求

- PHP 8.1 或更高版本；
- PDO SQLite、Fileinfo、GD（含 WebP）和 Sodium；
- EXIF 可选；
- 正式网站必须使用 HTTPS，并把数据库和备份放在 Web 根目录之外。

## 本地启动

```bash
php bin/cms-init.php
php -S 127.0.0.1:8080 -t public public/index.php
```

初始化命令会创建本地数据库，安装参考主题和参考目录扩展，并显示一次性后台启用码。打开
`http://127.0.0.1:8080/admin` 创建首个管理员，然后进入“网站设置”填写网站名称、唯一语言和
正式 HTTPS 网址。新网站默认禁止搜索引擎收录，内容准备好以后再手动开启。

默认数据目录是仓库内的 `var/`，可以通过环境变量放到其他私有位置：

```bash
KUAIZ_CMS_DATA_DIR=/absolute/private/path php bin/cms-init.php
KUAIZ_CMS_DATA_DIR=/absolute/private/path php -S 127.0.0.1:8080 -t public public/index.php
```

## 正式服务器

开发者自行部署时，应把 Web 根目录指向 `public/`，其余源码和数据目录不得公开访问。Apache
是当前首版验证最完整的路径；OpenLiteSpeed 安装新 `.htaccess` 后通常需要刷新 URL 重写或
执行 graceful restart。Nginx 与 IIS 需要自行编写等价路由和点文件保护规则，目前不列为已
验证的一键安装环境。

`installer-template.php` 只是官方发行系统使用的签名模板，不能直接运行。不要从第三方网盘
获取所谓“正式安装器”，也不要把未签名源码快照当作受支持版本。

## 备份与恢复

```bash
php bin/cms-backup.php
php bin/cms-restore.php /absolute/path/to/backup-directory --yes
```

备份只包含数据库已登记的 SQLite、媒体和 Theme 资产，不包含密钥或不明文件。恢复前会校验
文件大小与 SHA-256，并自动备份当前站点。数据库升级前也会创建一致性快照；升级或完整性
检查失败时自动恢复旧库。

旧 `thin-php-sqlite-v1` 只用于快智内部测试，不属于兼容范围。正式产品从 Community CMS
全新安装开始，后续只维护同一产品线内的升级、备份与恢复。

## Theme 与 Extension

- [`contracts/theme-manifest.schema.json`](contracts/theme-manifest.schema.json) 定义 AI 和开发者
  可生成的 Theme v2 组件树；
- [`themes/kuaiz-studio/theme.json`](themes/kuaiz-studio/theme.json) 是不执行代码的参考主题；
- [`contracts/extension-manifest.schema.json`](contracts/extension-manifest.schema.json) 定义扩展
  权限、路由、事件、数据和网络边界；
- [`extensions/kuaiz-directory/extension.json`](extensions/kuaiz-directory/extension.json) 是
  声明式内容目录示例。

当前普通管理员不能上传执行第三方 PHP。预约、会员等可执行能力必须经过单独的官方签名、
最小权限、数据迁移和隐私审查。

## 安全

发现安全问题请先阅读 [SECURITY.md](SECURITY.md)，不要在公开 Issue 中提交密码、安装码、
Cookie、API Key、客户正文或真实个人信息。

## 许可证

社区版源码使用 [Apache License 2.0](LICENSE)。许可证允许使用、修改和分发源码，但不额外
授予快智名称、标志、中央 AI 自动运营服务、客户专属主题或单独签约服务的使用权，具体说明
见 [NOTICE](NOTICE)。
