# 快智 CMS Community 公开发行操作

## 产品边界

公开的是本仓库白名单内的 Community CMS 源码。快智引擎、中央 AI 服务、客户数据、平台
凭据、发行私钥、商业控制面和客户专属主题都不进入 CMS 源码包。源码包使用 Apache-2.0；
快智品牌和商业服务保持独立。

> 当前例外：`0.1.x-dev` 经用户明确授权，使用联网开发机 macOS 登录钥匙串中的本机根签名，
> 机器可读保障等级为 `online-local-keychain-developer-preview`。私钥不落入仓库、服务器或
> 普通文件，但该根不具备离线隔离，只能发布 Developer Preview；发布工具会拒绝用它提升
> Release Candidate 或 Supported。正式版本仍执行下述离线仪式。

## 为什么生产私钥不能在服务器上生成

下载服务器和 `cms.kuaiz.net` 同时被攻破时，攻击者如果还能取得发行私钥，就可以替换安装
文件、签名和官网指纹，使客户无法识别。因此生产私钥必须在不运行网站、不部署总台、也不
保存客户数据的离线环境生成和使用。服务器只保存公钥、指纹和已经签好的发行摘要。

## 首次离线签名仪式

1. 在一台断网、全盘加密、没有检出本仓库的受控设备上准备 Python 和签名工具的只读副本。
2. 运行 `php_cms_release_control.py keygen`，私钥必须是 `0600` 普通文件；公钥可为 `0644`。
3. 将私钥制作两个加密离线备份，分别保存在不同地点；验证备份可恢复后，删除工作目录中的
   多余副本。不要把私钥放入服务器、Git、CI artifact、聊天、邮箱或云盘同步目录。
4. 在联网构建机运行 `build_php_cms_installer.py --emit-envelope`，只把规范 JSON 摘要带入
   离线设备。
5. 离线设备核对版本、文件数、字节数、模板哈希和载荷哈希后签名；只带出公钥和签名令牌。
6. 联网构建机使用公钥重新验证签名，再生成官网公开下载的通用 `install.php`。构建机不接触私钥。
7. 由发布门禁生成官网版本记录、SHA-256 和公钥指纹；下载页显示的指纹必须来自同一公钥。

## 社区版源码包

```bash
uv run python scripts/build_php_cms_source_release.py \
  --output artifacts/kuaiz-cms-community-0.1.0-dev.zip \
  --emit-envelope artifacts/cms-source-envelope.json
```

构建器只接受 `php_cms_distribution.py` 的显式白名单，拒绝符号链接和越界路径，并在 ZIP 内
写入 `source-manifest.json` 与 `SHA256SUMS`。相同源码会生成逐字节一致的归档。新增 CMS
文件时测试会要求明确更新白名单，避免把临时文件或秘密意外打包。

源码 ZIP 使用独立的 `kuaiz-cms-source-archive-signature/v1` 摘要，由同一 CMS 发行根签名，
但签名令牌前缀与安装器不同，避免两种载荷混用。联网发布机最后运行
`promote_php_cms_release.py`，同时验证源码包签名、安装器签名和外部固定的公钥指纹，再生成
通用 `install.php` 并把其 SHA-256 写入版本记录；任何一项不一致都不会生成可部署的官网发行目录。

## 一版发行的完整命令

联网构建机先生成可复现源码包和两份待签名摘要。`ISSUED_AT` 必须是已记录的 Unix 时间，
不要在重复构建时无意改变：

```bash
ISSUED_AT=1785900000
uv run python scripts/build_php_cms_source_release.py \
  --output artifacts/kuaiz-cms-community-0.1.0-dev.zip \
  --emit-envelope artifacts/source-envelope.json \
  --issued-at "$ISSUED_AT"
uv run python scripts/build_php_cms_installer.py \
  --emit-envelope artifacts/installer-envelope.json \
  --issued-at "$ISSUED_AT"
```

只把两个规范 JSON 摘要带入离线签名设备。在离线设备上分别使用不同的签名域生成令牌：

```bash
python scripts/php_cms_release_control.py sign-source \
  --private-key /offline/cms-release-private.pem \
  --input source-envelope.json --output source-envelope.token
python scripts/php_cms_release_control.py sign \
  --private-key /offline/cms-release-private.pem \
  --input installer-envelope.json --output installer-envelope.token
```

联网发布机只接收公钥和两个令牌。先用外部记录的固定指纹提升到一个全新目录，再独立验证
目录。除第一次发行外，必须提供上一版完整公开目录；门禁会重验全部旧版本、拒绝版本回退，
并把新版本追加到索引首位：

```bash
PUBLISHED_AT=1785900100
FINGERPRINT=<离线仪式中人工记录的64位小写十六进制指纹>
uv run python scripts/promote_php_cms_release.py \
  --output artifacts/cms-public-0.1.0-dev \
  --public-key artifacts/cms-release-public.pem \
  --expected-fingerprint "$FINGERPRINT" \
  --source-archive artifacts/kuaiz-cms-community-0.1.0-dev.zip \
  --source-envelope artifacts/source-envelope.json \
  --source-token artifacts/source-envelope.token \
  --installer-envelope artifacts/installer-envelope.json \
  --installer-token artifacts/installer-envelope.token \
  --published-at "$PUBLISHED_AT" \
  --support-status developer-preview \
  --signing-assurance offline-production
uv run python scripts/verify_php_cms_public_release.py \
  artifacts/cms-public-0.1.0-dev
```

后续版本在提升命令末尾增加：

```bash
--previous-public-root artifacts/cms-public-0.1.0-dev
```

提升目录只允许 `releases/` 和 `trust/`，不允许符号链接、私钥、未登记文件或索引外版本。
它应作为发行证据保留，不能只依赖官网服务器上的副本。

## 官网发布、复核与回滚

官网部署先把页面、协议、许可证与已经提升的公开发行目录合并到本地临时目录，运行 HTML、
站内链接、站点地图、JavaScript 和发行签名门禁后再上传。服务器使用不可变时间戳目录和
`current` 软链接原子切换；Nginx 配置或任一公网地址检查失败会恢复上一软链接：

```bash
REMOTE_HOST=root@example.com \
CMS_RELEASE_DIR=artifacts/cms-public-0.1.0-dev \
  ./deploy/deploy-cms-site.sh
```

尚无生产信任根时可以不传 `CMS_RELEASE_DIR` 发布产品页、文档和安全中心；下载页会明确显示
“尚无签名版”，不会把 GitHub 开发快照伪装成正式下载。正式发行后至少从另一台联网设备
重新下载 `releases/current.json`、`install.php`、公钥和版本记录，核对安装文件 SHA-256，
并运行同一独立验证器。需要
人工回滚官网页面时，把 `/var/www/cms.kuaiz.net/current` 指回保留的上一时间戳目录并执行
`nginx -t`；发行记录本身不能覆盖或原地修改。

## 公钥发布与轮换

- 官网首次出现指纹前，下载页必须保持“尚未公开发行”，不能放测试密钥或占位指纹。
- 公钥文件、DER SHA-256 指纹、启用时间和状态要同时发布；安装器内嵌值必须完全一致。
- 正常轮换先用旧钥签署新钥声明，保留过渡期；旧钥泄漏则发布独立安全公告并暂停下载。
- 指纹变化必须进入人工可见的发布审计，不能由普通网站部署自动改写。

## 每版发布门禁

1. 社区版源码包可复现且白名单完整；
2. 安装器摘要由离线私钥签名，联网环境只做公钥验证；
3. PHP 单元、真实 Apache、OpenLiteSpeed 和篡改拒绝测试通过；
4. 升级、备份、恢复和上一正式版迁移演练通过；
5. 官网版本、SHA-256、公钥指纹、支持状态和安全公告同步完成；
6. Playwright 桌面、手机、长数据、复杂状态、空数据和 RTL 基准通过；
7. 下载发布后，从公网重新下载并做一次独立验签和黑盒安装。

本地可重复执行的浏览器与跨版本黑盒命令：

```bash
npm ci
npx playwright install chromium
npm run test:browser
uv run python scripts/test_php_cms_release_upgrade.py
```

稳定支持版还必须满足 [稳定支持版范围与门禁](stable-release-scope.md) 中的真实主机观察、
恢复演练、分支保护、支持承诺和离线生产信任根要求。自动测试全绿是候选版的必要条件，
不是单独提升为 Supported 的充分条件。

## 客户站运行闭环

安装完成后，日常人工编辑和公开访问不依赖快智总台。主机维护至少保留下面的固定顺序：

1. 变更前运行 `php bin/cms-doctor.php` 并生成 `php bin/cms-backup.php`；
2. 在测试副本验证新版本会从当前 schema 自动升级，并确认升级前迁移快照存在；
3. 切换应用版本，再次运行 doctor、登录后台、发布一条测试修订并检查公开页；
4. 核对媒体、Canonical、站点地图和 Extension 插槽；
5. 失败时恢复上一应用版本，并用 `php bin/cms-restore.php <备份目录> --yes` 恢复数据；
6. 只有升级、备份恢复和公网黑盒复核都通过，候选版才能改为 `supported`。
