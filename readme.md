# FTP Client Skill for OpenClaw

[![OpenClaw Skill](https://img.shields.io/badge/OpenClaw-Skill-blue)](https://github.com/eraycc/ftp-client-skill)
[![Node.js](https://img.shields.io/badge/Node.js-18+-green)](https://nodejs.org)
[![License](https://img.shields.io/badge/License-MIT-yellow)](LICENSE)

这是一个为 OpenClaw 智能体系统开发的 FTP/FTPS 客户端技能。通过自然语言对话，让 AI 助手直接管理您的服务器文件——上传生成的网页代码、下载日志文件、管理目录结构，一切都可以通过聊天完成。

### 注意：如果你部署openclaw的服务器防火墙屏蔽了FTP协议或其他原因导致无法连接FTP（如：huggingface等平台），可以使用[ftp-client-php](./ftp-client-php.md)，实现PHP代理转发连接FTP。

## ✨ 核心价值

- **🤖 AI 驱动的文件管理**：让 OpenClaw 智能体直接操作 FTP 服务器，实现自动化部署
- **🚀 一键部署**：AI 生成的网页/代码可立即上传到服务器
- **📊 智能分析**：下载并读取服务器文件，让 AI 为您分析日志、配置文件
- **🔄 全功能支持**：完整的文件操作命令，满足日常管理需求

## 📦 功能特性

| 命令 | 描述 | 典型场景 |
|------|------|----------|
| 📂 **list** | 列出目录内容 | "看看服务器上有什么文件" |
| ⬇️ **download** | 下载文件/目录 | "下载昨天的日志让我分析" |
| ⬆️ **upload** | 上传文件/目录 | "把我刚写的网页部署到服务器" |
| 🗑️ **delete** | 删除文件/目录 | "清理过期的备份文件" |
| 🔄 **move** | 移动/重命名 | "把报告文件归档到旧文件夹" |
| 📋 **copy** | 复制文件 | "复制配置文件作为模板" |
| 📁 **mkdir** | 创建目录 | "为新产品创建目录结构" |
| 📖 **read** | 读取文件内容 | "看看配置文件里的数据库地址" |
| ℹ️ **info** | 查看文件信息 | "检查这个文件有多大" |

## 🚀 快速开始

### 安装方法

#### 方法一：自动安装（推荐）
在 OpenClaw 对话框中发送：
```
请帮我安装 FTP 客户端技能：https://github.com/eraycc/ftp-client-skill/releases/download/v1.0/ftp-client.zip
并设置连接信息：ftp.example.com:21,用户名,密码
```

#### 方法二：手动安装
```bash
# 下载并解压
wget https://github.com/eraycc/ftp-client-skill/releases/download/v1.0/ftp-client.zip
unzip ftp-client.zip -d ~/.openclaw/skills/

# 安装依赖
cd ~/.openclaw/skills/ftp-client
npm install
```

### 配置说明

在 OpenClaw 技能管理面板中设置环境变量 `FTP_CONNECTION`：

```
服务器地址:端口,用户名,密码,连接模式,协议,TLS模式
```

**配置示例：**

| 场景 | 配置字符串 |
|------|------------|
| 普通 FTP（默认被动模式） | `ftp.example.com:21,myuser,mypassword` |
| 主动模式 FTP | `ftp.example.com:21,myuser,mypassword,active` |
| 显式 FTPS（标准） | `ftp.example.com:21,myuser,mypassword,passive,ftps,explicit` |
| 隐式 FTPS（端口 990） | `ftp.example.com:990,myuser,mypassword,passive,ftps,implicit` |

> 💡 **小贴士**：如果密码中包含逗号，请用 `%2C` 代替（例如 `my,password` → `my%2Cpassword`）

## 💬 使用示例

### 在 OpenClaw 中使用

在对话中你可以直接说：
- "列出 FTP 服务器根目录的文件"
- "把 FTP 上的 /www/index.html 下载到本地"
- "把本地的 /tmp/test.txt 上传到 FTP 的 /www/ 目录"
- "删除 FTP 上的 /www/old-backup.zip"
- "把 FTP 上的 /data/report.csv 移动到 /archive/report.csv"

### 日常对话场景

**场景 1：部署网站**
```
用户：我刚写完个人网站的首页，帮我通过 FTP 上传到服务器
AI：好的，请提供本地文件路径和服务器目标位置
用户：文件在 ./index.html，上传到 FTP 服务器 /var/www/html/
AI：✅ 上传成功！文件已部署到服务器
```

**场景 2：日志分析**
```
用户：帮我通过 FTP 看看服务器今天的访问日志
AI：正在读取 /var/log/nginx/access.log ...
[AI 分析日志内容并提供 insights]
```

**场景 3：文件管理**
```
用户：清理一下 FTP 服务器 备份文件夹，只保留最近3天的
AI：正在检查 /backup 目录...
已删除 5 个旧备份文件，剩余 3 个
```

### 命令行直接使用

```bash
# 列出目录（详细模式）
node scripts/list.mjs "/www" --long

# 递归下载整个目录
node scripts/download.mjs "/backup/2024" --dir --out "./backup"

# 上传并显示进度
node scripts/upload.mjs "./dist" --dir --to "/www/app" --verbose

# 查看文件内容（指定编码）
node scripts/read.mjs "/config/settings.json" --encoding utf8
```

## 🛠️ 项目结构

```
ftp-client/
├── SKILL.md          # OpenClaw 技能元数据
├── package.json      # 依赖配置
└── scripts/
    ├── ftp-utils.mjs    # 🔧 核心工具（连接管理、参数解析）
    ├── list.mjs         # 📂 列出目录
    ├── download.mjs     # ⬇️ 下载文件/目录
    ├── upload.mjs       # ⬆️ 上传文件/目录
    ├── delete.mjs       # 🗑️ 删除文件/目录
    ├── move.mjs         # 🔄 移动/重命名
    ├── copy.mjs         # 📋 复制文件
    ├── mkdir.mjs        # 📁 创建目录
    ├── read.mjs         # 📖 读取文件
    └── info.mjs         # ℹ️ 文件信息
```

## 🔧 技术细节

### 环境变量设计
采用单环境变量设计，所有连接信息压缩在一个字符串中，简化 OpenClaw 界面配置：
```javascript
metadata: {
  "openclaw": {
    "primaryEnv": "FTP_CONNECTION"  // 界面只显示这一个输入框
  }
}
```

### 协议支持
- **FTP**：标准文件传输协议
- **FTPS (Explicit)**：显式 TLS，默认端口 21
- **FTPS (Implicit)**：隐式 TLS，默认端口 990

### 安全特性
- 支持自签名证书（默认启用）
- 密码中的逗号自动转义
- 连接超时保护（30秒）

### 已知限制
- ⚠️ 主动模式（Active Mode）暂不支持（使用 basic-ftp 库限制）
- ⚠️ 服务器端复制通过下载再上传实现，大文件较慢

## 🤝 贡献指南

欢迎贡献代码或提出建议！

1. Fork 本仓库
2. 创建特性分支 (`git checkout -b feature/AmazingFeature`)
3. 提交更改 (`git commit -m 'Add some AmazingFeature'`)
4. 推送到分支 (`git push origin feature/AmazingFeature`)
5. 提交 Pull Request

## 📄 许可证

MIT License © [eraycc]

## 🙏 致谢

- [basic-ftp](https://github.com/patrickjuchli/basic-ftp) - 优秀的 FTP 客户端库
- [OpenClaw](https://github.com/openclaw) 团队 - 提供智能体技能框架