# Contributing to Kuaiz CMS Community

感谢你帮助改进快智 CMS。当前项目仍处于 Developer Preview，接口可能在首个正式版本前调整。

## 提交前

1. 先搜索现有 Issue，较大的功能请先说明问题和边界；
2. 不要提交客户数据、账号、安装码、Cookie、API Key、私钥或生产日志；
3. Theme 不得执行 PHP，声明式 Extension 不得携带可执行入口；
4. 一个网站只维护一种内容语言，多市场使用多个网站；
5. 修改数据库结构时必须包含向前升级和失败恢复测试。

## 本地检查

```bash
find . -type f -name '*.php' -not -path './var/*' -print0 | xargs -0 -n1 php -l
php tests/smoke.php
```

提交应说明用户可见的变化、测试结果和安全影响。安全漏洞请按 [SECURITY.md](SECURITY.md)
私下报告，不要先创建公开 Issue。
