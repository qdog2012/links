# 织联部署指南

本文对应版本 1.1.0，包含本机试用、Linux 团队部署、现有数据迁移与升级备份。后端目标运行版本为 PHP 7.4，本机已在 PHP 7.4.33 下通过接口验证。服务器示例使用 Nginx、PHP 7.4-FPM 和 MySQL 8.0，路径及服务名采用 Ubuntu / Debian 的习惯；尚未在你的目标服务器上执行。

## 1. 本机试用

本机访问地址为 `http://127.0.0.1:8765/`，数据库为 `127.0.0.1:13306` 上的 `zhilian`。配置在 `backend/.env`，数据位于当前工作目录的 `work/mysql-data`。

在 `relation-library` 目录重新启动：

```powershell
powershell -ExecutionPolicy Bypass -File .\start-local.ps1
```

在另一台 Windows 电脑运行，需要先准备 PHP 7.4、`pdo_mysql` 和 MySQL 8.0，然后按 README 的「在其它电脑运行」配置。源码包包含 `dist`，可以直接由 PHP 提供页面；包中没有本机 MySQL 程序、数据库文件或 `.env`。修改前端后才需要 Node.js 22.12+，运行 `npm ci` 和 `npm run build`。

## 2. 正式部署结构

建议前端和 API 使用同一个 HTTPS 域名，并部署在域名根路径，例如 `https://relations.example.com/`。将下面所有示例域名、路径和密码替换为自己的值。

```text
浏览器 → Nginx HTTPS
           ├─ /           → /var/www/zhilian/dist
           └─ /api/*      → PHP-FPM → MySQL 8.0

/var/www/zhilian/
  dist/                   公开的前端构建文件
  backend/public/index.php API 入口
  backend/dispatch.php     API 路由
  backend/projects.php     项目权限与数据库迁移
  backend/schema.sql       数据表结构
  backend/seed.json        示例数据
  backend/.env             服务器数据库配置
  backend/storage/         PHP 可写的注册 / 登录限流记录
```

Nginx 的 `server` 根目录指向 `backend/public`，使现有 `php7.inc` 中的 `$document_root$fastcgi_script_name` 能定位到 API 入口。前端各个静态 `location` 单独使用 `dist`，`/api/...` 内部改写到 `/index.php` 后交给 PHP 7.4。不要把整个项目设为网站根目录。当前配置面向单台 PHP 服务；注册 / 登录限流保存在本机文件中。生产运行无需 Vite、Node.js 或 Composer，也不需要启动 `backend/router.php` 或 PHP 内置服务器。

### 2.1 安装服务与放置文件

已有 PHP 7.4 的服务器或面板环境可直接使用现有 PHP-FPM。Ubuntu 24.04 的默认软件源不提供 PHP 7.4；下面的 PHP 安装命令只适用于已经配置了可提供该版本软件源的环境，不能只在原 8.3 安装命令中替换版本号。先确认软件包可用，并确认 MySQL 软件包提供的是 8.0：

```bash
apt-cache policy php7.4-fpm php7.4-mysql mysql-server
```

软件包可用时安装；已有对应服务可跳过：

```bash
sudo apt update
sudo apt install nginx mysql-server php7.4-fpm php7.4-mysql unzip
sudo systemctl enable --now nginx mysql php7.4-fpm
php7.4 -v
php-fpm7.4 -v
mysql --version
```

确认 CLI 和 FPM 均为 PHP 7.4，建议使用 7.4.33。FPM 需要启用 `PDO`、`pdo_mysql` 和 `json`；本项目不依赖 `mbstring`。若安装了多个 PHP 版本，命令行的 `php` 和网站选择的 PHP-FPM 版本可能不同，Nginx 必须连接 7.4 的进程。使用服务器面板时，在站点配置中选择 PHP 7.4，并确认现有 `php7-handler` upstream 指向该版本的 FPM。

把源码包中的 `relation-library` 内容放到 `/var/www/zhilian`。至少保留上述 `dist` 和完整 `backend` 目录。若需要重新构建，在开发机或构建机执行 `npm ci`、`npm run build`，再上传新的 `dist`。

若从 `git@github.com:qdog2012/links.git` 克隆，仓库不包含 `dist`。先在具备 Node.js 22.12+ 的构建机执行 `npm ci` 和 `npm run build`，再部署构建得到的 `dist` 及完整 `backend`，按本文创建服务器自己的 `.env`。

代码和配置由部署管理员拥有，仅 `backend/storage` 允许 PHP 写入。Ubuntu 默认的 Nginx / PHP-FPM 用户为 `www-data`；使用其他用户时相应替换：

```bash
sudo chown -R root:www-data /var/www/zhilian
sudo chmod -R u=rwX,g=rX,o= /var/www/zhilian
sudo install -d -o www-data -g www-data -m 700 /var/www/zhilian/backend/storage
```

### 2.2 创建数据库

通过 `sudo mysql` 进入 MySQL，执行以下 SQL。示例密码必须替换：

```sql
CREATE DATABASE zhilian CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
CREATE USER 'zhilian'@'127.0.0.1' IDENTIFIED BY 'REPLACE_WITH_A_RANDOM_PASSWORD';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, REFERENCES
ON zhilian.* TO 'zhilian'@'127.0.0.1';
```

应用在首次 `/api/state` 请求中创建表或迁移旧表，因此此版本的数据库账号需要上述建表与修改表权限。`/api/health` 只检查数据库连接，不执行迁移。MySQL 与 PHP 同机时仅需监听本机地址，无需对外开放数据库端口。

复制 `backend/.env.example` 为 `backend/.env`，填写：

```dotenv
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=zhilian
DB_USERNAME=zhilian
DB_PASSWORD=REPLACE_WITH_A_RANDOM_PASSWORD
SESSION_SECURE=1
```

```bash
sudo chown root:www-data /var/www/zhilian/backend/.env
sudo chmod 640 /var/www/zhilian/backend/.env
```

此处的 `3306` 是服务器示例；本机试用的 `13306` 不必复制到服务器。`.env` 使用逐行 `KEY=VALUE` 格式，不支持变量替换或行尾注释。若配置了同名 PHP-FPM 环境变量，环境变量会覆盖 `.env`。

### 2.3 准备 HTTPS 证书

正式站点仅监听 HTTPS 的 443 端口。先将域名指向服务器，放行 443，再通过现有服务器面板、证书管理服务或域名服务商的 DNS-01 验证申请证书。DNS-01 通过 DNS 记录验证域名，适用于不开放 80 端口的部署；优先使用支持自动续期的 DNS 插件或证书服务。

下面的配置假设证书文件已经放在以下位置；如果面板已有证书，直接将配置改为其实际路径：

```text
/etc/nginx/ssl/relations.example.com/fullchain.pem  站点证书与中间证书链
/etc/nginx/ssl/relations.example.com/privkey.pem    与证书配对的私钥
```

证书必须包含实际使用的域名；私钥限制为 Nginx 主进程可读，不放入项目目录或源码包。启用站点前确认两份文件已存在，`nginx -t` 会检查是否能加载。内网域名使用团队已有的可信证书与证书管理方式。

服务器的 `backend/.env` 设置 `SESSION_SECURE=1`，使会话 Cookie 通过 HTTPS 发送。本机 PHP 内置服务器的纯 HTTP 试用仍设置为 `0`。

证书续期后执行以下命令加载新证书；自动续期工具应配置相同的成功续期操作：

```bash
sudo nginx -t
sudo systemctl reload nginx
```

### 2.4 配置 Nginx（HTTPS）

配置模板见 [deploy/nginx.conf.example](deploy/nginx.conf.example)。替换域名、证书与项目路径后，在现有站点的 `server` 配置中使用；Ubuntu / Debian 可保存为 `/etc/nginx/sites-available/zhilian`。配置使用 `listen 443 ssl;`，启用 TLS 1.2 / 1.3。已有站点配置中的 `listen 80;` 也应移除。

站点沿用 `include php7.inc;`。它引用已有的 `php7-handler` upstream，需由服务器现有 Nginx 的 `http` 配置定义，并指向 PHP 7.4-FPM；不要在 `server` 内定义或重复添加同名 upstream。相对 `include` 路径以 Nginx 主配置目录为基准，确认该目录中有 `php7.inc` 和 `fastcgi_params`。

`php7.inc` 保持现有内容，副本见 [deploy/php7.inc.example](deploy/php7.inc.example)：

```nginx
location ~ \.php$ {
            add_header Hitserver "1";
            fastcgi_pass   php7-handler;
            fastcgi_index  index.php;
            fastcgi_param  SCRIPT_FILENAME  $document_root$fastcgi_script_name;
            #fastcgi_param  HTTPS off;
            #fastcgi_intercept_errors on;
            include        fastcgi_params;
        }
```

完整站点配置如下。已有站点中只保留一次 `include php7.inc;`，并替换原来的 `/api/` 和前端静态路由：

```nginx
server {
    listen 443 ssl;
    server_name relations.example.com;

    ssl_certificate /etc/nginx/ssl/relations.example.com/fullchain.pem;
    ssl_certificate_key /etc/nginx/ssl/relations.example.com/privkey.pem;
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_session_cache shared:zhilianSSL:10m;
    ssl_session_timeout 1d;
    ssl_session_tickets off;

    root /var/www/zhilian/backend/public;
    index index.html;
    client_max_body_size 5m;

    access_log /var/log/nginx/zhilian-access.log;
    error_log /var/log/nginx/zhilian-error.log;

    location = /api {
        rewrite ^ /index.php last;
    }
    location ^~ /api/ {
        rewrite ^ /index.php last;
    }

    location ~ /\.(?!well-known(?:/|$)) { return 404; }
    location ~* ^/(backend|src|tests|node_modules|deploy)(/|$) { return 404; }
    location ~ ^/(?!index\.php$).*\.php$ { return 404; }

    include php7.inc;

    location /assets/ {
        root /var/www/zhilian/dist;
        try_files $uri =404;
        add_header Cache-Control "public, max-age=604800, immutable";
    }
    location = /index.html {
        root /var/www/zhilian/dist;
        add_header Cache-Control "no-cache";
    }
    location / {
        root /var/www/zhilian/dist;
        try_files $uri $uri/ /index.html;
    }
}
```

`rewrite ... last` 会重新匹配 `php7.inc` 的 PHP `location`；不要改为 `break`，也不要把服务器根目录改回 `dist`，否则 API 无法进入正确入口。原始请求方法、JSON 请求体和查询参数会保留。后端根据原始 `REQUEST_URI` 分派接口，因此 `fastcgi_params` 必须包含 `fastcgi_param REQUEST_URI $request_uri;`，不能把它改成重写后的 `$uri`。

常规 Nginx `fastcgi_params` 已包含 `QUERY_STRING`、`REQUEST_METHOD`、`CONTENT_TYPE` 和 `CONTENT_LENGTH`；已有文件被定制时确认这些参数仍在。`SCRIPT_FILENAME` 由 `php7.inc` 设置，避免在 `fastcgi_params` 中重复设置成另一个路径。Nginx 默认会把 `Host`、Cookie、Origin、`X-Project-Id` 和 `X-Zhilian-Request` 等请求头传给 PHP，保留默认 `fastcgi_pass_request_headers on` 和 `fastcgi_pass_request_body on`。前后端需同域并保留原始 Host，否则写入请求可能被跨站校验拒绝。

`fastcgi_params` 中应保留 `fastcgi_param HTTPS $https if_not_empty;`，向 PHP 传递 HTTPS 状态。`php7.inc` 中的 `#fastcgi_param HTTPS off;` 保持注释。

此 HTTPS 模板已在本机 Nginx 1.28.0 + PHP 7.4.33 FastCGI 隔离环境通过 `nginx -t` 和 23 项检查，覆盖 TLS 1.2 / 1.3 握手、PHP 收到的 HTTPS 状态、API 原始路径、查询参数、POST / PUT 请求体、会话与项目请求头、静态资源和私有路径拦截。验证使用临时测试证书及 PHP 入口；目标服务器的真实域名证书、FPM upstream 和文件权限仍需实际验证。

```bash
sudo ln -s /etc/nginx/sites-available/zhilian /etc/nginx/sites-enabled/zhilian
sudo nginx -t
sudo systemctl reload nginx
```

### 2.5 验证与首次使用

```bash
curl --fail https://relations.example.com/api/health
curl --fail https://relations.example.com/api/state
```

检查健康接口的 `database` 为 `true`，MySQL 版本为 8.0；HTTP 200 本身不表示数据库已连接。未登录的 `/api/state` 应返回 `demo: true`，不会返回他人的项目数据。

用浏览器注册后应自动登录并进入自己的项目。再用另一个账号验证：默认看不到第一个账号的项目；被添加为协作者后，只能访问所加入的项目。手机访问相同 HTTPS 地址即可使用。

目前任何能访问网站的人都可以自助注册；没有邀请制注册或站点管理员审核流程。若只面向内部团队，将网站放在团队现有的内网、VPN 或访问控制后。

## 3. 从本机迁移已有数据

源码包不包含数据库内容。迁移完整用户、项目、关系和变更记录，应导出 MySQL；JSON 只包含当前项目的对象和关系。

本机 Windows 导出示例，在当前工作目录执行；命令会提示输入数据库密码，`--result-file` 避免 PowerShell 重定向改变文件编码：

```powershell
& .\work\mysql-runtime\mysql-8.0.40-winx64\bin\mysqldump.exe --host=127.0.0.1 --port=13306 --user=zhilian --password --single-transaction --no-tablespaces --set-gtid-purged=OFF --skip-add-drop-table --default-character-set=utf8mb4 --result-file=zhilian-backup.sql zhilian
```

若本机 `DB_USERNAME` 并非 `zhilian`，替换为 `.env` 中的用户名。把备份安全传到服务器，用有导入权限的账号导入一个新的空数据库：

```bash
mysql --host=127.0.0.1 --user=zhilian --password --default-character-set=utf8mb4 zhilian < zhilian-backup.sql
```

此导出命令不生成删表语句，配合上文应用账号的权限导入新空库；恢复到已有库应由数据库管理员另行处理。迁移期间暂停源站和目标站的写入，完成后在目标站重新登录。已有数据库不要直接覆盖；保留备份，先在独立库中验证。浏览器中的未登录示例数据不会进入 SQL 备份，需要另行导出 JSON。

## 4. 备份、升级与回退

定期使用 MySQL 备份整个 `zhilian` 库，并备份服务器 `.env`；备份放在网站目录之外并限制访问。`backend/storage` 只保存限流记录，不存业务数据。定期把备份恢复到独立数据库，确认能够读取项目和变更记录。

升级时：

1. 暂停网站访问 / 写入，备份数据库、旧代码和 `.env`。
2. 在测试环境验证新版本；替换 `dist` 和后端代码，保留服务器 `.env`、`backend/storage` 及其权限。
3. 重新加载 PHP-FPM，避免 OPcache 继续使用旧代码：`sudo systemctl reload php7.4-fpm`。
4. 请求 `/api/state` 触发迁移，检查日志并验证注册、登录、项目内容、权限和变更记录，再恢复访问。

从原共享空间版本升级到 1.1.0 时，会保留原数据和成员角色为一个项目，并为没有个人项目的旧用户创建个人项目。数据库标记升级为版本 2；JSON 导出格式仍为版本 1。MySQL 的部分表结构变更不能通过业务事务整体回滚，因此迁移前必须保留完整备份。

回退涉及数据库结构变化时，应在暂停写入的情况下同时恢复匹配版本的代码与升级前数据库；仅替换旧代码不能完成数据库回退。恢复备份会失去备份时间之后的新增数据，操作前先保存当前数据库并确认恢复范围。

## 5. 常见问题

| 现象 | 检查位置 |
| --- | --- |
| `database: false` / MySQL 连接失败 | `.env` 的地址、端口、账号和密码；MySQL 服务；PHP-FPM 是否启用 `pdo_mysql`。CLI PHP 的扩展配置可能与 FPM 不同。 |
| `nginx -t` 报找不到 `php7.inc` 或 upstream | 确认 Nginx 主配置目录中的 include 文件，以及 `http` 中已有的 `php7-handler` 定义。 |
| `nginx -t` 报证书或私钥加载失败 | `ssl_certificate` 和 `ssl_certificate_key` 的实际路径、配对关系与读取权限；先准备证书再启用站点。 |
| 浏览器提示证书无效 | 证书中的域名、有效期和完整中间证书链；内网证书的签发机构是否受设备信任。 |
| 502 | `php7-handler` 指向的 PHP 7.4-FPM 服务、监听地址或 socket 路径与访问权限。 |
| 页面能开，但 `/api/…` 返回 HTML | `/api/` 是否使用 `rewrite ^ /index.php last;`，以及是否存在冲突的旧路由。 |
| API 报 `Primary script unknown` / `File not found` | `server` 的 `root` 应为 `backend/public`，该目录中应有 `index.php`；检查 `SCRIPT_FILENAME` 和读取权限。 |
| API 返回接口不存在或路径错误 | `fastcgi_params` 中 `REQUEST_URI` 应使用 `$request_uri`，保留原始 `/api/...` 路径。 |
| 拒绝跨站写入请求 | 前端与 API 域名 / 端口不一致；代理修改了 `Host`；浏览器仍访问旧域名。 |
| 登录后仍未登录 | HTTP 站点误设 `SESSION_SECURE=1`，或代理没有保留 Cookie。 |
| 限流存储不可用 / 注册或登录失败 | PHP-FPM 用户不能写 `backend/storage`；5 分钟内注册 / 登录尝试过多时会返回 429。 |
| 首次访问报数据库权限错误 | 建库账号授权不足，缺少 `CREATE`、`ALTER`、`INDEX`、`REFERENCES`；检查 PHP-FPM 日志。 |
| JSON 导入返回 413 / 内容过大 | Nginx 请求上限与应用的 5,000,000 字节限制；最多 2000 个对象 / 10000 条关系。 |
| 更新后仍显示旧页面 | 确认上传的是新 `dist`，清理代理对 `index.html` 的缓存；刷新页面。 |

日志命令：`sudo journalctl -u php7.4-fpm -n 100 --no-pager`，以及 Nginx 的 `zhilian-error.log`。密码不应写进排查日志或分享给他人。邮件验证、找回密码、单对象权限和企业单点登录尚未实现，详见 README。
