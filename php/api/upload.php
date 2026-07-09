<?php
/**
 * 图片上传接口 v5
 *
 * POST 参数：
 *   barcode  — 条形码（优先，用于商品图片目录 uploads/products/{barcode}/）
 *   dir      — 子目录（barcode 未传时使用，如 avatars / banners / common）
 *   img_type — 图片类型前缀（配合 barcode 使用）：主图 / 详情图
 *   file     — 图片文件（multipart/form-data）
 *
 * 返回：{ code: 0, url: '/uploads/...', path: '...', seq: 1 }
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit(0); }

ini_set('display_errors', '0');
error_reporting(E_ERROR);

function out($code, $msg, $extra = []) {
    echo json_encode(array_merge(['code' => $code, 'msg' => $msg], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

$barcode  = trim($_POST['barcode'] ?? '');
$dirKey   = $_POST['dir'] ?? '';
$imgType  = trim($_POST['img_type'] ?? '');
$validTypes = ['主图', '详情图'];

// ---------- 确定存储目录 ----------
if ($barcode !== '') {
    if (!preg_match('/^[A-Za-z0-9_\-]+$/', $barcode)) {
        out(1, '条形码格式不正确（仅限字母、数字、_-）');
    }
    $subDir = 'products/' . $barcode . '/';
    $typePrefix = (in_array($imgType, $validTypes, true) && $imgType !== '') ? $imgType : '主图';
} elseif (in_array($dirKey, ['avatars', 'banners', 'products', 'details', 'common'], true)) {
    $subDir = $dirKey . '/';
    $typePrefix = '';
} else {
    out(1, '缺少条形码或 dir 参数');
}

$uploadDir = __DIR__ . '/../uploads/' . $subDir;
if (!is_dir($uploadDir)) {
    @mkdir($uploadDir, 0755, true);
}
if (!is_dir($uploadDir) || !is_writable($uploadDir)) {
    out(1, '上传目录不可写');
}

// ---------- 文件上传 ----------
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { out(1, '仅支持 POST'); }

if (!isset($_FILES['file']) || !is_array($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    $err = $_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE;
    $errors = [
        UPLOAD_ERR_INI_SIZE   => '文件超过服务器限制（2MB）',
        UPLOAD_ERR_FORM_SIZE  => '文件超过表单限制',
        UPLOAD_ERR_PARTIAL    => '文件仅上传了一部分',
        UPLOAD_ERR_NO_FILE    => '没有文件被上传',
    ];
    out(1, $errors[$err] ?? '上传失败（错误码 ' . $err . '）');
}

$file = $_FILES['file'];
$allowExt = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
$maxSize  = 5 * 1024 * 1024;

if ($file['size'] > $maxSize) { out(1, '图片大小不能超过 5MB'); }
if ($file['size'] <= 0)       { out(1, '文件为空'); }

$ext = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
if (!in_array($ext, $allowExt, true)) { out(1, '仅支持 JPG / PNG / GIF / WEBP 格式'); }

if (function_exists('finfo_open')) {
    try {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime  = @finfo_file($finfo, $file['tmp_name']) ?: '';
        @finfo_close($finfo);
        if ($mime && !in_array($mime, ['image/jpeg','image/png','image/gif','image/webp'], true)) {
            out(1, '文件类型不允许（' . $mime . '）');
        }
    } catch (Throwable $e) { /* skip */ }
}

$imgInfo = @getimagesize($file['tmp_name']);
if ($imgInfo === false) { out(1, '文件不是合法图片'); }

// ---------- 找下一个序号 ----------
$pattern = $typePrefix !== ''
    ? $uploadDir . $typePrefix . '*.{jpg,jpeg,png,gif,webp}'
    : $uploadDir . '*.{jpg,jpeg,png,gif,webp}';

$existing = glob($pattern, GLOB_BRACE);
$nums = [0];
foreach ($existing as $f) {
    $bn = basename($f, '.' . pathinfo($f, PATHINFO_EXTENSION));
    if ($typePrefix !== '') {
        $bn = preg_replace('/^' . preg_quote($typePrefix, '/') . '/', '', $bn);
    }
    if (preg_match('/^\d+$/', $bn)) $nums[] = intval($bn);
}
$nextNum = max($nums) + 1;
$newName = $typePrefix . str_pad((string)$nextNum, 2, '0', STR_PAD_LEFT) . '.' . $ext;
$dest    = $uploadDir . $newName;

if (!@move_uploaded_file($file['tmp_name'], $dest)) {
    $last = error_get_last();
    out(1, '文件保存失败' . ($last ? '：' . $last['message'] : ''));
}

$url = '/uploads/' . $subDir . $newName;
out(0, '上传成功', ['url' => $url, 'path' => $url, 'size' => $file['size'], 'seq' => $nextNum]);
