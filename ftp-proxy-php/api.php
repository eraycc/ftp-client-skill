<?php
/**
 * FTP Proxy API
 * 
 * 通过 HTTP 接口代理 FTP 操作
 * 支持 FTP / FTPS (Explicit & Implicit)
 * 
 * 所有请求均为 POST，参数通过 JSON body 或 multipart/form-data（上传时）传入
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-API-Key');

// 处理 CORS 预检
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ============================================================
// 配置：可选的 API 密钥验证（防止未授权访问）
// 设为空字符串则不验证
// ============================================================
define('API_KEY', getenv('FTP_PROXY_API_KEY') ?: '');

// 验证 API Key
if (API_KEY !== '') {
    $providedKey = $_SERVER['HTTP_X_API_KEY'] ?? ($_REQUEST['api_key'] ?? '');
    if ($providedKey !== API_KEY) {
        respond(403, 'Invalid or missing API key');
    }
}

// 仅接受 POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(405, 'Only POST method is allowed');
}

// 解析请求参数
$isMultipart = strpos($_SERVER['CONTENT_TYPE'] ?? '', 'multipart/form-data') !== false;
if ($isMultipart) {
    $params = $_POST;
} else {
    $rawBody = file_get_contents('php://input');
    $params = json_decode($rawBody, true);
    if (!is_array($params)) {
        $params = $_POST;
    }
}

// 必需参数
$action = $params['action'] ?? '';
$host = $params['host'] ?? '';
$port = intval($params['port'] ?? 21);
$username = $params['username'] ?? '';
$password = $params['password'] ?? '';
$mode = strtolower($params['mode'] ?? 'passive');       // active / passive
$protocol = strtolower($params['protocol'] ?? 'ftp');    // ftp / ftps
$tlsMode = strtolower($params['tls_mode'] ?? '');        // explicit / implicit

// 也支持一行式连接字符串（与 skill 环境变量格式一致）
if (empty($host) && !empty($params['connection'])) {
    $parsed = parseConnectionString($params['connection']);
    $host = $parsed['host'];
    $port = $parsed['port'];
    $username = $parsed['username'];
    $password = $parsed['password'];
    $mode = $parsed['mode'];
    $protocol = $parsed['protocol'];
    $tlsMode = $parsed['tls_mode'];
}

if (empty($action)) {
    respond(400, 'Missing required parameter: action');
}
if (empty($host) || empty($username)) {
    respond(400, 'Missing FTP connection info (host, username required)');
}

// ============================================================
// 路由 action
// ============================================================
try {
    switch ($action) {
        case 'list':
            doList($params);
            break;
        case 'download':
            doDownload($params);
            break;
        case 'upload':
            doUpload($params);
            break;
        case 'delete':
            doDelete($params);
            break;
        case 'move':
        case 'rename':
            doMove($params);
            break;
        case 'copy':
            doCopy($params);
            break;
        case 'mkdir':
            doMkdir($params);
            break;
        case 'read':
            doRead($params);
            break;
        case 'info':
            doInfo($params);
            break;
        case 'test':
            doTest();
            break;
        default:
            respond(400, "Unknown action: {$action}");
    }
} catch (Throwable $e) {
    respond(500, 'FTP Error: ' . $e->getMessage());
}

// ============================================================
// FTP 连接
// ============================================================
function ftpConnect() {
    global $host, $port, $username, $password, $mode, $protocol, $tlsMode;

    // implicit FTPS 需要用 curl 方式处理，PHP 原生 ftp_ssl_connect 只支持 explicit
    if ($protocol === 'ftps' && $tlsMode === 'implicit') {
        // 返回一个标记，后续操作用 curl
        return ['type' => 'curl_implicit', 'host' => $host, 'port' => $port, 
                'username' => $username, 'password' => $password];
    }

    if ($protocol === 'ftps') {
        // Explicit FTPS
        if (!function_exists('ftp_ssl_connect')) {
            throw new Exception('ftp_ssl_connect is not available. PHP needs OpenSSL + FTP extension.');
        }
        $conn = @ftp_ssl_connect($host, $port, 30);
    } else {
        // Plain FTP
        $conn = @ftp_connect($host, $port, 30);
    }

    if (!$conn) {
        throw new Exception("Cannot connect to {$host}:{$port} (protocol: {$protocol})");
    }

    $loginOk = @ftp_login($conn, $username, $password);
    if (!$loginOk) {
        @ftp_close($conn);
        throw new Exception("Login failed for user '{$username}'");
    }

    // 被动模式
    if ($mode === 'passive') {
        ftp_pasv($conn, true);
    }

    return ['type' => 'native', 'conn' => $conn];
}

function ftpClose($ftpObj) {
    if ($ftpObj['type'] === 'native' && isset($ftpObj['conn'])) {
        @ftp_close($ftpObj['conn']);
    }
}

// ============================================================
// cURL 辅助函数（用于 implicit FTPS）
// ============================================================
function curlFtpExec($ftpObj, $path = '/', $opts = []) {
    $ch = curl_init();
    $url = "ftps://{$ftpObj['host']}:{$ftpObj['port']}" . $path;
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_USERPWD, "{$ftpObj['username']}:{$ftpObj['password']}");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_FTP_SSL, CURLFTPSSL_ALL);
    curl_setopt($ch, CURLOPT_FTPSSLAUTH, CURLFTPAUTH_TLS);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);

    foreach ($opts as $key => $val) {
        curl_setopt($ch, $key, $val);
    }

    $result = curl_exec($ch);
    $error = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($result === false) {
        throw new Exception("cURL FTP error: {$error}");
    }

    return $result;
}

// ============================================================
// Actions 实现
// ============================================================

/** 列出目录 */
function doList($params) {
    $path = $params['path'] ?? '/';
    $detailed = !empty($params['detailed']);
    $ftp = ftpConnect();

    if ($ftp['type'] === 'curl_implicit') {
        // 确保路径以 / 结尾
        if (substr($path, -1) !== '/') $path .= '/';
        if ($detailed) {
            $raw = curlFtpExec($ftp, $path, [CURLOPT_FTPLISTONLY => false]);
        } else {
            $raw = curlFtpExec($ftp, $path, [CURLOPT_FTPLISTONLY => true]);
        }
        $lines = array_filter(explode("\n", trim($raw)), 'strlen');
        if ($detailed) {
            $items = array_map(function($line) { return parseFtpListLine($line); }, $lines);
        } else {
            $items = array_map(function($name) { return ['name' => trim($name)]; }, $lines);
        }
        respond(200, 'OK', ['path' => $path, 'items' => $items]);
    }

    $conn = $ftp['conn'];

    if ($detailed) {
        $rawList = @ftp_rawlist($conn, $path);
        if ($rawList === false) {
            ftpClose($ftp);
            throw new Exception("Cannot list directory: {$path}");
        }
        $items = [];
        foreach ($rawList as $line) {
            $items[] = parseFtpListLine($line);
        }
    } else {
        $list = @ftp_nlist($conn, $path);
        if ($list === false) {
            ftpClose($ftp);
            throw new Exception("Cannot list directory: {$path}");
        }
        $items = [];
        foreach ($list as $name) {
            $items[] = ['name' => basename($name)];
        }
    }

    ftpClose($ftp);
    respond(200, 'OK', ['path' => $path, 'items' => $items]);
}

/** 下载文件（返回 base64 或直接输出） */
function doDownload($params) {
    $path = $params['path'] ?? '';
    if (empty($path)) respond(400, 'Missing parameter: path');

    $raw = !empty($params['raw']); // 如果 raw=true，直接输出文件流
    $ftp = ftpConnect();

    if ($ftp['type'] === 'curl_implicit') {
        $content = curlFtpExec($ftp, $path);
        if ($raw) {
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . basename($path) . '"');
            header('Content-Length: ' . strlen($content));
            echo $content;
            exit;
        }
        respond(200, 'OK', [
            'path' => $path,
            'filename' => basename($path),
            'size' => strlen($content),
            'content_base64' => base64_encode($content)
        ]);
    }

    $conn = $ftp['conn'];
    $tmpFile = tempnam(sys_get_temp_dir(), 'ftp_dl_');

    $ok = @ftp_get($conn, $tmpFile, $path, FTP_BINARY);
    ftpClose($ftp);

    if (!$ok) {
        @unlink($tmpFile);
        throw new Exception("Download failed: {$path}");
    }

    $content = file_get_contents($tmpFile);
    $size = filesize($tmpFile);
    @unlink($tmpFile);

    if ($raw) {
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . basename($path) . '"');
        header('Content-Length: ' . $size);
        echo $content;
        exit;
    }

    respond(200, 'OK', [
        'path' => $path,
        'filename' => basename($path),
        'size' => $size,
        'content_base64' => base64_encode($content)
    ]);
}

/** 上传文件 */
function doUpload($params) {
    $remotePath = $params['remote_path'] ?? '';
    if (empty($remotePath)) respond(400, 'Missing parameter: remote_path');

    // 方式1：multipart 文件上传
    if (!empty($_FILES['file'])) {
        $tmpFile = $_FILES['file']['tmp_name'];
        $filename = $_FILES['file']['name'];
        if (empty($remotePath) || substr($remotePath, -1) === '/') {
            $remotePath = rtrim($remotePath, '/') . '/' . $filename;
        }
    }
    // 方式2：base64 编码内容
    elseif (!empty($params['content_base64'])) {
        $tmpFile = tempnam(sys_get_temp_dir(), 'ftp_up_');
        file_put_contents($tmpFile, base64_decode($params['content_base64']));
    }
    // 方式3：纯文本内容
    elseif (isset($params['content'])) {
        $tmpFile = tempnam(sys_get_temp_dir(), 'ftp_up_');
        file_put_contents($tmpFile, $params['content']);
    }
    else {
        respond(400, 'Missing file data. Provide file (multipart), content_base64, or content');
    }

    $ftp = ftpConnect();

    if ($ftp['type'] === 'curl_implicit') {
        $fileData = file_get_contents($tmpFile);
        $fh = fopen($tmpFile, 'r');
        $ch = curl_init();
        $url = "ftps://{$ftp['host']}:{$ftp['port']}{$remotePath}";
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_USERPWD, "{$ftp['username']}:{$ftp['password']}");
        curl_setopt($ch, CURLOPT_UPLOAD, true);
        curl_setopt($ch, CURLOPT_INFILE, $fh);
        curl_setopt($ch, CURLOPT_INFILESIZE, filesize($tmpFile));
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_FTP_SSL, CURLFTPSSL_ALL);
        curl_setopt($ch, CURLOPT_FTPSSLAUTH, CURLFTPAUTH_TLS);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $result = curl_exec($ch);
        $error = curl_error($ch);
        fclose($fh);
        curl_close($ch);

        if (!empty($_FILES['file'])) { /* don't delete uploaded tmp */ } 
        else { @unlink($tmpFile); }

        if ($result === false) {
            throw new Exception("Upload failed (curl): {$error}");
        }
        respond(200, 'OK', ['remote_path' => $remotePath, 'message' => 'File uploaded successfully']);
    }

    $conn = $ftp['conn'];
    $ok = @ftp_put($conn, $remotePath, $tmpFile, FTP_BINARY);
    ftpClose($ftp);

    if (!empty($_FILES['file'])) { /* don't delete uploaded tmp */ } 
    else { @unlink($tmpFile); }

    if (!$ok) {
        throw new Exception("Upload failed: {$remotePath}");
    }

    respond(200, 'OK', ['remote_path' => $remotePath, 'message' => 'File uploaded successfully']);
}

/** 删除文件或目录 */
function doDelete($params) {
    $path = $params['path'] ?? '';
    $isDir = !empty($params['is_dir']);
    if (empty($path)) respond(400, 'Missing parameter: path');

    $ftp = ftpConnect();

    if ($ftp['type'] === 'curl_implicit') {
        $cmd = $isDir ? "RMD {$path}" : "DELE {$path}";
        curlFtpExec($ftp, '/', [
            CURLOPT_QUOTE => [$cmd]
        ]);
        respond(200, 'OK', ['path' => $path, 'message' => 'Deleted successfully']);
    }

    $conn = $ftp['conn'];

    if ($isDir) {
        // 递归删除
        deleteDir($conn, $path);
    } else {
        $ok = @ftp_delete($conn, $path);
        if (!$ok) {
            ftpClose($ftp);
            throw new Exception("Delete failed: {$path}");
        }
    }

    ftpClose($ftp);
    respond(200, 'OK', ['path' => $path, 'message' => 'Deleted successfully']);
}

/** 递归删除 FTP 目录 */
function deleteDir($conn, $dir) {
    $list = @ftp_nlist($conn, $dir);
    if ($list !== false) {
        foreach ($list as $item) {
            $basename = basename($item);
            if ($basename === '.' || $basename === '..') continue;
            $fullPath = rtrim($dir, '/') . '/' . $basename;
            // 尝试当目录删，失败则当文件删
            if (@ftp_rmdir($conn, $fullPath) === false) {
                if (@ftp_delete($conn, $fullPath) === false) {
                    // 可能是子目录，递归
                    deleteDir($conn, $fullPath);
                }
            }
        }
    }
    if (!@ftp_rmdir($conn, $dir)) {
        throw new Exception("Cannot remove directory: {$dir}");
    }
}

/** 移动/重命名 */
function doMove($params) {
    $from = $params['from'] ?? '';
    $to = $params['to'] ?? '';
    if (empty($from) || empty($to)) respond(400, 'Missing parameters: from, to');

    $ftp = ftpConnect();

    if ($ftp['type'] === 'curl_implicit') {
        curlFtpExec($ftp, '/', [
            CURLOPT_QUOTE => ["RNFR {$from}", "RNTO {$to}"]
        ]);
        respond(200, 'OK', ['from' => $from, 'to' => $to, 'message' => 'Moved successfully']);
    }

    $conn = $ftp['conn'];
    $ok = @ftp_rename($conn, $from, $to);
    ftpClose($ftp);

    if (!$ok) throw new Exception("Move/rename failed: {$from} → {$to}");

    respond(200, 'OK', ['from' => $from, 'to' => $to, 'message' => 'Moved successfully']);
}

/** 复制（FTP 不支持原生复制，下载再上传） */
function doCopy($params) {
    $from = $params['from'] ?? '';
    $to = $params['to'] ?? '';
    if (empty($from) || empty($to)) respond(400, 'Missing parameters: from, to');

    $ftp = ftpConnect();
    $tmpFile = tempnam(sys_get_temp_dir(), 'ftp_cp_');

    if ($ftp['type'] === 'curl_implicit') {
        $content = curlFtpExec($ftp, $from);
        file_put_contents($tmpFile, $content);

        $fh = fopen($tmpFile, 'r');
        $ch = curl_init();
        $url = "ftps://{$ftp['host']}:{$ftp['port']}{$to}";
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_USERPWD, "{$ftp['username']}:{$ftp['password']}");
        curl_setopt($ch, CURLOPT_UPLOAD, true);
        curl_setopt($ch, CURLOPT_INFILE, $fh);
        curl_setopt($ch, CURLOPT_INFILESIZE, filesize($tmpFile));
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_FTP_SSL, CURLFTPSSL_ALL);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_exec($ch);
        $error = curl_error($ch);
        fclose($fh);
        curl_close($ch);
        @unlink($tmpFile);

        if (!empty($error)) throw new Exception("Copy upload failed: {$error}");
        respond(200, 'OK', ['from' => $from, 'to' => $to, 'message' => 'Copied successfully']);
    }

    $conn = $ftp['conn'];
    $ok = @ftp_get($conn, $tmpFile, $from, FTP_BINARY);
    if (!$ok) {
        ftpClose($ftp);
        @unlink($tmpFile);
        throw new Exception("Copy download phase failed: {$from}");
    }

    $ok = @ftp_put($conn, $to, $tmpFile, FTP_BINARY);
    ftpClose($ftp);
    @unlink($tmpFile);

    if (!$ok) throw new Exception("Copy upload phase failed: {$to}");

    respond(200, 'OK', ['from' => $from, 'to' => $to, 'message' => 'Copied successfully']);
}

/** 创建目录 */
function doMkdir($params) {
    $path = $params['path'] ?? '';
    if (empty($path)) respond(400, 'Missing parameter: path');

    $ftp = ftpConnect();

    if ($ftp['type'] === 'curl_implicit') {
        curlFtpExec($ftp, '/', [
            CURLOPT_QUOTE => ["MKD {$path}"]
        ]);
        respond(200, 'OK', ['path' => $path, 'message' => 'Directory created']);
    }

    $conn = $ftp['conn'];

    // 递归创建目录
    $parts = explode('/', trim($path, '/'));
    $current = '';
    foreach ($parts as $dir) {
        $current .= '/' . $dir;
        @ftp_mkdir($conn, $current); // 忽略已存在错误
    }

    ftpClose($ftp);
    respond(200, 'OK', ['path' => $path, 'message' => 'Directory created (or already exists)']);
}

/** 读取文件内容 */
function doRead($params) {
    $path = $params['path'] ?? '';
    $encoding = $params['encoding'] ?? 'utf-8';
    if (empty($path)) respond(400, 'Missing parameter: path');

    $ftp = ftpConnect();

    if ($ftp['type'] === 'curl_implicit') {
        $content = curlFtpExec($ftp, $path);
        respond(200, 'OK', [
            'path' => $path,
            'size' => strlen($content),
            'content' => $content,
            'content_base64' => base64_encode($content)
        ]);
    }

    $conn = $ftp['conn'];
    $tmpFile = tempnam(sys_get_temp_dir(), 'ftp_rd_');

    $ok = @ftp_get($conn, $tmpFile, $path, FTP_BINARY);
    ftpClose($ftp);

    if (!$ok) {
        @unlink($tmpFile);
        throw new Exception("Cannot read file: {$path}");
    }

    $content = file_get_contents($tmpFile);
    $size = filesize($tmpFile);
    @unlink($tmpFile);

    respond(200, 'OK', [
        'path' => $path,
        'size' => $size,
        'content' => $content,
        'content_base64' => base64_encode($content)
    ]);
}

/** 文件信息（大小、修改时间） */
function doInfo($params) {
    $path = $params['path'] ?? '';
    if (empty($path)) respond(400, 'Missing parameter: path');

    $ftp = ftpConnect();

    if ($ftp['type'] === 'curl_implicit') {
        // curl 方式获取文件大小
        $ch = curl_init();
        $url = "ftps://{$ftp['host']}:{$ftp['port']}{$path}";
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_USERPWD, "{$ftp['username']}:{$ftp['password']}");
        curl_setopt($ch, CURLOPT_NOBODY, true);
        curl_setopt($ch, CURLOPT_FILETIME, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_FTP_SSL, CURLFTPSSL_ALL);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_exec($ch);
        $size = curl_getinfo($ch, CURLINFO_CONTENT_LENGTH_DOWNLOAD);
        $time = curl_getinfo($ch, CURLINFO_FILETIME);
        curl_close($ch);

        respond(200, 'OK', [
            'path' => $path,
            'size' => ($size >= 0) ? intval($size) : null,
            'modified' => ($time > 0) ? date('Y-m-d H:i:s', $time) : null
        ]);
    }

    $conn = $ftp['conn'];
    $size = @ftp_size($conn, $path);
    $mdtm = @ftp_mdtm($conn, $path);
    ftpClose($ftp);

    respond(200, 'OK', [
        'path' => $path,
        'size' => ($size >= 0) ? $size : null,
        'modified' => ($mdtm >= 0) ? date('Y-m-d H:i:s', $mdtm) : null
    ]);
}

/** 测试连接 */
function doTest() {
    $ftp = ftpConnect();

    if ($ftp['type'] === 'curl_implicit') {
        $raw = curlFtpExec($ftp, '/', [CURLOPT_FTPLISTONLY => true]);
        respond(200, 'OK', ['message' => 'Connection successful (implicit FTPS)', 'root_items' => count(array_filter(explode("\n", trim($raw))))]);
    }

    $conn = $ftp['conn'];
    $pwd = @ftp_pwd($conn);
    ftpClose($ftp);

    respond(200, 'OK', ['message' => 'Connection successful', 'pwd' => $pwd]);
}

// ============================================================
// 辅助函数
// ============================================================

function respond($code, $message, $data = null) {
    http_response_code($code);
    $resp = ['code' => $code, 'message' => $message];
    if ($data !== null) $resp['data'] = $data;
    echo json_encode($resp, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

/** 解析一行式连接字符串 */
function parseConnectionString($str) {
    $parts = explode(',', $str);
    $hostPort = trim($parts[0] ?? '');
    $username = trim($parts[1] ?? '');
    $password = str_replace('%2C', ',', trim($parts[2] ?? ''));
    $mode = strtolower(trim($parts[3] ?? 'passive'));
    $protocol = strtolower(trim($parts[4] ?? 'ftp'));
    $tlsMode = strtolower(trim($parts[5] ?? ''));

    $lastColon = strrpos($hostPort, ':');
    if ($lastColon !== false) {
        $host = substr($hostPort, 0, $lastColon);
        $port = intval(substr($hostPort, $lastColon + 1));
    } else {
        $host = $hostPort;
        $port = ($protocol === 'ftps' && $tlsMode === 'implicit') ? 990 : 21;
    }

    return compact('host', 'port', 'username', 'password', 'mode', 'protocol') + ['tls_mode' => $tlsMode];
}

/** 解析 ftp_rawlist 的一行 */
function parseFtpListLine($line) {
    $line = trim($line);
    if (empty($line)) return ['raw' => $line];

    // Unix 格式: drwxr-xr-x 2 user group 4096 Jan 01 12:00 dirname
    if (preg_match('/^([d\-l])([rwxstST\-]{9})\s+(\d+)\s+(\S+)\s+(\S+)\s+(\d+)\s+(\w+\s+\d+\s+[\d:]+)\s+(.+)$/', $line, $m)) {
        return [
            'name' => $m[8],
            'type' => $m[1] === 'd' ? 'dir' : ($m[1] === 'l' ? 'link' : 'file'),
            'permissions' => $m[1] . $m[2],
            'owner' => $m[4],
            'group' => $m[5],
            'size' => intval($m[6]),
            'date' => $m[7],
            'raw' => $line
        ];
    }

    // DOS 格式: 01-01-26 12:00AM <DIR> dirname  或  01-01-26 12:00AM 12345 filename
    if (preg_match('/^(\d{2}-\d{2}-\d{2}\s+\d{2}:\d{2}[AP]M)\s+(<DIR>|\d+)\s+(.+)$/', $line, $m)) {
        $isDir = ($m[2] === '<DIR>');
        return [
            'name' => trim($m[3]),
            'type' => $isDir ? 'dir' : 'file',
            'size' => $isDir ? 0 : intval($m[2]),
            'date' => $m[1],
            'raw' => $line
        ];
    }

    // 无法解析，返回原始行
    return ['name' => $line, 'raw' => $line];
}
?>
