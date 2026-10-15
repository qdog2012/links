# GitHub 自动更新（Ubuntu 24.04）

推荐让服务器通过 systemd 每分钟检查 GitHub 的 `main` 分支。你向 `git@github.com:qdog2012/links.git` 推送后，服务器通常在约一分钟后开始更新，再加上构建时间。无需开放 Webhook 接口，也无需在 GitHub 保存服务器登录私钥。仓库中的模板不会自行启用，必须在服务器完成以下配置。

沿用 `/serverdata/wwwroot/links`、`/serverdata/runenv/node/bin`、MySQL 8.0、PHP 7.4 和现有 HTTPS Nginx。无需修改 `include php7.inc;`。

## 1. 确定部署账号与 PHP 服务

Git、npm 和自动更新使用同一个非 root 部署账号，不使用 PHP 的运行账号。已有专用账号就继续使用；下文以 `links-deploy` 为例，Web 组以 `www-data` 为例。面板环境实际可能是 `www`，PHP 服务名也可能不同，先确认：

```bash
stat -c '%U:%G' /serverdata/wwwroot/links/.git
systemctl list-units --all --type=service | grep -Ei 'php.*fpm|php7'
```

以下初始化命令由 root 或有 sudo 权限的管理员执行。若已有部署账号，将变量改为原账号；不要新建重复账号。

```bash
TASK_DEPLOY_USER=links-deploy
TASK_WEB_GROUP=www-data
TASK_APP=/serverdata/wwwroot/links

id "$TASK_DEPLOY_USER" >/dev/null 2>&1 || sudo useradd --create-home --shell /bin/bash "$TASK_DEPLOY_USER"
sudo usermod -aG "$TASK_WEB_GROUP" "$TASK_DEPLOY_USER"
sudo apt-get install -y git curl python3 util-linux
sudo install -d -o "$TASK_DEPLOY_USER" -g "$(id -gn "$TASK_DEPLOY_USER")" -m 700 /serverdata/deploy/links
```

如果更换了部署账号，例如之前一直使用 root 拉取代码，需要一次性转交 checkout、Git、缓存和构建文件的所有权。以下命令不改变 PHP 在 `backend/storage` 中已有运行文件的所有权，也不跟随符号链接改变站点外文件：

```bash
sudo find "$TASK_APP" -path "$TASK_APP/backend/storage" -prune -o -exec chown -h "$TASK_DEPLOY_USER:$TASK_WEB_GROUP" {} +
sudo chown "$TASK_DEPLOY_USER:$TASK_WEB_GROUP" "$TASK_APP/backend/storage"
sudo chmod 2750 "$TASK_APP"
sudo chmod 2770 "$TASK_APP/backend/storage"
```

保留服务器现有 `backend/.env` 和 MySQL 数据。若仍未完成首次手动部署，先按 [部署指南](DEPLOYMENT.md) 完成构建、权限及 HTTPS 验证；自动更新脚本要求已有 `dist/index.html` 和 `backend/.env`。

## 2. 让部署账号能读取 GitHub

如果该账号已经能够免交互执行 `git fetch`，可以复用现有 SSH 配置。新账号需建立只读 Deploy key。下例不会覆盖已有的默认私钥：

```bash
TASK_DEPLOY_HOME=$(getent passwd "$TASK_DEPLOY_USER" | cut -d: -f6)
sudo install -d -o "$TASK_DEPLOY_USER" -g "$(id -gn "$TASK_DEPLOY_USER")" -m 700 "$TASK_DEPLOY_HOME/.ssh"
if ! sudo test -f "$TASK_DEPLOY_HOME/.ssh/id_ed25519"; then
    sudo -H -u "$TASK_DEPLOY_USER" ssh-keygen -t ed25519 -N '' \
      -C links-read-only-deploy -f "$TASK_DEPLOY_HOME/.ssh/id_ed25519"
fi
sudo cat "$TASK_DEPLOY_HOME/.ssh/id_ed25519.pub"
```

把输出的**公钥**添加到 GitHub 仓库 **Settings → Deploy keys → Add deploy key**，不要勾选 **Allow write access**。已有密钥文件若用于其他仓库，请为本站使用独立密钥并在该账号的 SSH config 中配置，不要覆盖原文件。

```bash
sudo -H -u "$TASK_DEPLOY_USER" ssh -T git@github.com
```

首次连接时，对照 [GitHub 官方 SSH 指纹](https://docs.github.com/en/authentication/keeping-your-account-and-data-secure/githubs-ssh-key-fingerprints) 确认服务器身份，再接受。出现 `successfully authenticated` 即成功（GitHub 不提供 shell，退出码可能为 1）。自动任务启用严格主机密钥检查，不会自动接受未知主机。

检查实际仓库读取权限：

```bash
sudo -H -u "$TASK_DEPLOY_USER" git -C "$TASK_APP" ls-remote origin refs/heads/main
```

## 3. 安装脚本、配置和定时器

先用上述部署账号拉取包含自动更新模板的版本。安装文件在站点之外，由 root 拥有；后续只有更新脚本或 unit 模板本身时才需重新安装。

```bash
sudo -H -u "$TASK_DEPLOY_USER" git -C "$TASK_APP" pull --ff-only origin main
sudo install -d -o root -g root -m 755 /usr/local/lib/links
sudo install -o root -g root -m 755 "$TASK_APP/deploy/auto-update.sh" /usr/local/lib/links/auto-update.sh
sudo install -o root -g root -m 644 "$TASK_APP/deploy/links-auto-update.service" /etc/systemd/system/links-auto-update.service
sudo install -o root -g root -m 644 "$TASK_APP/deploy/links-auto-update.timer" /etc/systemd/system/links-auto-update.timer
if ! sudo test -f /etc/links-auto-update.env; then
    sudo install -o root -g root -m 600 "$TASK_APP/deploy/links-auto-update.env.example" /etc/links-auto-update.env
fi
sudo nano /etc/systemd/system/links-auto-update.service
sudo nano /etc/links-auto-update.env
```

在 service 中，将 `User=links-deploy` 和 `Group=links-deploy` 改为实际部署账号及其**主组**（`id -gn "$TASK_DEPLOY_USER"` 可查看）。配置文件中重点修改：

```ini
WEB_GROUP=www-data
PHP_FPM_SERVICE=php7.4-fpm
HEALTH_URL=https://你的实际域名/api/health
```

路径默认与你的服务器一致。`HEALTH_URL` 必须使用正式 HTTPS 域名及可信证书；检查同时要求 API 返回 `database: true`。本配置不存数据库密码，数据库连接仍由已有 `backend/.env` 管理。

为部署账号授权**仅重载指定 PHP 服务**。使用 `visudo`，不要授权任意 sudo 命令：

```bash
sudo visudo -f /etc/sudoers.d/links-auto-update
```

写入以下一行，将账号和服务名与上面的设置保持一致：

```sudoers
links-deploy ALL=(root) NOPASSWD: /usr/bin/systemctl reload php7.4-fpm
```

```bash
sudo chmod 440 /etc/sudoers.d/links-auto-update
sudo visudo -c
sudo systemctl daemon-reload
sudo systemctl start links-auto-update.service
sudo journalctl -u links-auto-update.service -n 80 --no-pager
```

第一次运行会构建当前版本并做一次完整发布，即使当前 `HEAD` 已与 GitHub 一致。日志出现 `Deployed ...; HTTPS and database health check passed.` 后，再启用定时器：

```bash
sudo systemctl enable --now links-auto-update.timer
systemctl list-timers links-auto-update.timer
```

以后向 `main` 推送，服务器会自动执行；其他分支不会自动部署。手动立即触发：

```bash
sudo systemctl start links-auto-update.service
sudo journalctl -u links-auto-update.service -n 80 --no-pager
```

## 4. 发布行为与排查

- 每次先检查 Git 工作区、拉取远端提交，只允许 `main` 快进更新。存在本机修改或分叉时直接停止，不执行强制覆盖。
- 在站点外的临时目录安装依赖并构建，使用项目下 `.npm-cache`。构建失败时，线上代码和入口 HTML 保持原版本。
- 发布前把当前源码、`dist` 和 `.env` 备份到 `/serverdata/deploy/links/backups/` 的私有目录；**此备份不包含 MySQL 数据**。备份和旧哈希资源不会自动清理，请定期按容量与保留策略整理。
- 构建成功后复制资源、快进更新 PHP 源码、重载实际 PHP 7.4 服务，再通过同一文件系统的 rename 替换入口 HTML。保留旧哈希资源，供已打开页面使用。锁防止自动任务重叠执行。
- HTTPS 和数据库健康检查成功后才写入发布成功标记。重载或健康检查失败时标记不会更新，下次检查会重试；发布已经开始后，部分文件可能已更新，**不会自动回滚代码或数据库**。通过 journal 检查并修复，必要时暂停定时器。
- PHP 源码在现有目录内更新，此方案不承诺零停机。前后端或数据库存在不兼容升级时，先暂停自动更新、备份 MySQL 并手动维护；数据库迁移仍按应用原有逻辑触发。详见 [备份、升级与回退](DEPLOYMENT.md#4-备份升级与回退)。

查看日志与暂停：

```bash
sudo journalctl -u links-auto-update.service -n 100 --no-pager
sudo systemctl disable --now links-auto-update.timer
```

暂停 timer 不会中断已经开始的发布；需要维护时先等待当前 service 结束。恢复使用 `sudo systemctl enable --now links-auto-update.timer`。

若日志提示部署账号不存在、目录不可写、Git SSH 失败或 PHP 服务不存在，应修正实际账号、所有权、只读 Deploy key 或服务名。不要关闭 SSH/HTTPS 校验，不要以 root 运行 npm，不要用 `git reset --hard` 消除未审查的服务器修改。

## 本地验证

脚本已在 Ubuntu 24.04 下通过 Bash 语法检查、systemd unit 静态检查和 7 项隔离检查，覆盖正常更新与无变更跳过、备份和配置保留、构建失败、本地修改、分支分叉、发布锁、PHP 重载失败重试及数据库未连接时拒绝标记成功。检查使用临时 Git 仓库，并模拟 npm、PHP 服务和 HTTPS 响应；尚未登录目标服务器启用任务。

如需复验，在 Linux 下使用非 root 账号执行（无需第三方 Python 包）：

```bash
bash -n deploy/auto-update.sh
python3 tests/auto-update-test.py
```
