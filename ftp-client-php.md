# 部署与使用总结

## PHP 代理端部署

```bash
# 上传到你的 PHP 服务器
scp -r ftp-proxy-php/* yourserver:/var/www/html/ftp-proxy/

# 确保 PHP 开启了 ftp 和 curl 扩展
php -m | grep -E "ftp|curl"
```

部署后你会获得：
- **Web 管理界面**：`https://yourserver.com/ftp-proxy/index.html`
- **API 端点**：`https://yourserver.com/ftp-proxy/api.php`

## OpenClaw Skill 端安装

### 自动安装

把下面的内容发送给openclaw：
```
请帮我下载解压后安装这个skill：
设置FTP_PHP_CONFIG环境变量为：{"ftp_php_domain":"https://yourserver.com/ftp-proxy/api.php","ftp_php_apikey":"apikey","ftp_client_host":"ftp.example.com","ftp_client_port":"port","ftp_client_username":"username","ftp_client_password":"password","ftp_client_connect_mode":"passive","ftp_client_protocol":"ftps","ftp_client_encrypt_mode":"explicit"}
```

### 手动安装

```bash
# 复制到 OpenClaw 技能目录
cp -r ftp-client-php ~/.openclaw/skills/ftp-client-php
```

## 设置环境变量

在 OpenClaw 技能管理面板中，设置 `FTP_PHP_CONFIG` 为一行 JSON：

```json
{"ftp_php_domain":"https://yourserver.com/ftp-proxy/api.php","ftp_php_apikey":"apikey","ftp_client_host":"ftp.example.com","ftp_client_port":"port","ftp_client_username":"username","ftp_client_password":"password","ftp_client_connect_mode":"passive","ftp_client_protocol":"ftps","ftp_client_encrypt_mode":"explicit"}
```

## 使用示例

在 OpenClaw 对话中直接说：
- "列出 FTP 根目录的文件"
- "读取 FTP 上 /www/index.html 的内容"
- "把 /tmp/report.pdf 上传到 FTP 的 /documents/ 目录"
- "下载 FTP 上的 /backup/data.sql 到本地"
- "删除 FTP 上的 /old/temp.log"
- "把 FTP 上 /a.txt 重命名为 /b.txt"
- "在 FTP 上创建 /new-project/src 目录"
- "在 FTP 上创建一个 hello.txt 文件，内容写 Hello World"

## 数据流向

```
用户对话 → OpenClaw → Skill (node scripts/*.mjs)
                              ↓
                        读取 FTP_PHP_CONFIG 环境变量
                        解析出 PHP代理地址 + FTP连接信息
                              ↓
                        HTTP POST → PHP api.php
                              ↓
                        PHP 通过 ftp_connect / ftp_ssl_connect / curl 连接 FTP
                              ↓
                        执行 FTP 操作（list/get/put/delete/rename...）
                              ↓
                        JSON 响应 ← PHP api.php
                              ↓
                        Skill 解析响应，输出结果给 OpenClaw
                              ↓
                        OpenClaw 展示给用户
```