<?php
require_once 'config.php';
checkAuth();

$msg = '';
$msgType = 'success';
try {
    $db = getDB();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? '';
        if ($action === 'add') {
            $imgUrl = $_POST['image_url'] ?? '';
            if (strpos($imgUrl, 'data:') === 0 || strpos($imgUrl, 'base64') !== false) {
                header("Location: banners.php?msg=错误：图片上传失败，请重试");
                exit;
            }
            $bannerId = intval($_POST['banner_id'] ?? 0);
            if ($bannerId > 0) {
                $db->prepare("UPDATE banners SET title=?, image=?, link=?, sort=? WHERE id=?")
                   ->execute([$_POST['title'], $imgUrl, $_POST['link'] ?? '', $bannerId, $bannerId]);
                header("Location: banners.php?msg=添加成功");
                exit;
            }
            $maxId = $db->query("SELECT MAX(id) FROM banners")->fetchColumn() ?: 0;
            $db->prepare("INSERT INTO banners (id, title, image, link, sort) VALUES (?, ?, ?, ?, ?)")
               ->execute([$maxId + 1, $_POST['title'], $imgUrl, $_POST['link'] ?? '', $maxId + 1]);
            $check = $db->query("SELECT image FROM banners ORDER BY id DESC LIMIT 1")->fetchColumn();
            if ($check && strpos($check, 'data:') === 0) {
                $db->prepare("DELETE FROM banners ORDER BY id DESC LIMIT 1")->execute();
                header("Location: banners.php?msg=错误：图片上传失败，请重试");
                exit;
            }
            header("Location: banners.php?msg=添加成功");
            exit;
        } elseif ($action === 'delete') {
            $db->prepare("DELETE FROM banners WHERE id = ?")->execute([intval($_POST['id'])]);
            header("Location: banners.php?msg=已删除");
            exit;
        } elseif ($action === 'toggle') {
            $id = intval($_POST['id']);
            $cur = $db->prepare("SELECT status FROM banners WHERE id = ?");
            $cur->execute([$id]);
            $s = $cur->fetchColumn();
            $db->prepare("UPDATE banners SET status = ? WHERE id = ?")->execute([$s ? 0 : 1, $id]);
            header("Location: banners.php?msg=已切换状态");
            exit;
        }
    }

    if (!empty($_GET['msg'])) $msg = $_GET['msg'];
    $banners = $db->query("SELECT * FROM banners ORDER BY sort, id")->fetchAll();

} catch (Exception $e) {
    $msg = '数据库错误: ' . $e->getMessage();
    $msgType = 'error';
    $banners = [];
}

ob_start();
?>
<style>
.upload-area {
  border: 2px dashed #ccc;
  border-radius: 10px;
  padding: 24px;
  text-align: center;
  cursor: pointer;
  transition: border-color 0.2s;
  background: #fafafa;
  min-height: 80px;
}
.upload-area:hover { border-color: #e64340; }
.upload-area input[type=file] { display: none; }
.upload-preview {
  max-width: 200px;
  max-height: 80px;
  border-radius: 8px;
  margin-top: 8px;
}
.upload-hint { font-size: 12px; color: #999; margin-top: 4px; word-break: break-all; }
</style>

<div class="header"><h1>🎠 轮播图管理</h1></div>

<div class="card">
    <?php if ($msg): ?><div class="msg-box msg-<?= $msgType ?>"><?= $msgType === 'error' ? '✗' : '✓' ?> <?= htmlspecialchars($msg) ?></div><?php endif; ?>

    <form method="POST" id="addForm">
        <input type="hidden" name="action" value="add">
        <input type="hidden" name="banner_id" id="bannerId" value="">
        <div style="font-weight:600;margin-bottom:12px;">+ 添加轮播图</div>
        <div class="form-row"><label class="form-label">标题</label><input type="text" name="title" class="form-input" placeholder="例：全场满59包邮" required></div>
        <div class="form-row"><label class="form-label">跳转链接</label><input type="text" name="link" class="form-input" placeholder="/pages/product/product?id=1 或 https://..."></div>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary" id="submitBtn" disabled>添加</button>
        </div>
    </form>

    <div style="margin-top:24px;padding:16px;background:#fff;border:1px solid #f0f0f0;border-radius:10px;">
        <div style="font-size:13px;font-weight:600;color:#333;margin-bottom:12px;">📸 轮播图图片 <span style="color:#e64340;">*</span></div>
        <div class="upload-area" id="uploadArea" onclick="document.getElementById('fileInput').click()">
            <input type="file" id="fileInput" accept="image/jpeg,image/png,image/gif,image/webp">
            <div id="uploadText">点击上传图片</div>
            <div id="uploadHint" class="upload-hint">支持 JPG/PNG/GIF/WEBP，不超过 5MB</div>
            <img id="uploadPreview" class="upload-preview" style="display:none;">
        </div>
        <input type="hidden" name="image_url" id="imageUrl" required>
    </div>

    <div style="background:#f5f9ff;border:1px solid #d4e8ff;border-radius:8px;padding:10px 14px;margin:16px 0;font-size:13px;color:#1a6fba;">
        💡 分享到微信群/朋友圈的标题和图片，请在 <a href="share.php" style="color:#1a6fba;font-weight:600;">分享图管理</a> 中配置
    </div>

    <div style="margin-bottom:12px;font-weight:600;">已有轮播图（<?= count($banners) ?>张）</div>
    <?php foreach ($banners as $b): ?>
    <div style="display:flex;align-items:center;gap:16px;padding:16px;border:1px solid #f0f0f0;border-radius:10px;margin-bottom:12px;<?= $b['status'] ? '' : 'opacity:0.5;' ?>">
        <img src="<?= htmlspecialchars($b['image']) ?>" style="width:200px;height:100px;object-fit:cover;border-radius:8px;background:#f5f5f5;" onerror="this.src='data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 width=%22200%22 height=%22100%22><rect fill=%22%23f5f5f5%22 width=%22200%22 height=%22100%22/><text x=%2250%25%22 y=%2250%25%22 text-anchor=%22middle%22 dominant-baseline=%22middle%22 fill=%22%23999%22 font-size=%2212%22>无图</text></svg>'">
        <div style="flex:1;">
            <div style="font-weight:600;color:#333;margin-bottom:4px;"><?= htmlspecialchars($b['title']) ?></div>
            <div style="font-size:12px;color:#999;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= htmlspecialchars($b['link']) ?: '（无跳转）' ?></div>
            <?php if ($b['status']): ?>
                <div style="margin-top:6px;font-size:12px;color:#2e7d32;">● 显示中</div>
            <?php else: ?>
                <div style="margin-top:6px;font-size:12px;color:#999;">● 已隐藏</div>
            <?php endif; ?>
        </div>
        <form method="POST" style="display:inline;">
            <input type="hidden" name="action" value="toggle">
            <input type="hidden" name="id" value="<?= $b['id'] ?>">
            <button type="submit" class="btn btn-sm btn-secondary"><?= $b['status'] ? '隐藏' : '显示' ?></button>
        </form>
        <form method="POST" style="display:inline;">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?= $b['id'] ?>">
            <button type="submit" class="btn btn-sm btn-danger" onclick="return confirm('确认删除？')">删除</button>
        </form>
    </div>
    <?php endforeach; ?>
    <?php if (empty($banners)): ?><div style="text-align:center;padding:40px;color:#999;">暂无轮播图</div><?php endif; ?>
</div>

<script>
const fileInput = document.getElementById('fileInput');
const uploadArea = document.getElementById('uploadArea');
const uploadText = document.getElementById('uploadText');
const uploadHint = document.getElementById('uploadHint');
const uploadPreview = document.getElementById('uploadPreview');
const imageUrlInput = document.getElementById('imageUrl');
const submitBtn = document.getElementById('submitBtn');

fileInput.addEventListener('change', function () {
    const file = this.files[0];
    if (!file) return;

    const reader = new FileReader();
    reader.onload = function (e) {
        uploadPreview.src = e.target.result;
        uploadPreview.style.display = 'block';
        uploadText.textContent = '上传中...';
    };
    reader.readAsDataURL(file);

    const formData = new FormData();
    formData.append('file', file);
    formData.append('dir', 'banners');

    submitBtn.disabled = true;
    fetch('/api/upload.php', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(res => {
            if (res.code === 0) {
                imageUrlInput.value = res.url;
                uploadText.textContent = '✓ 上传成功';
                uploadHint.textContent = res.url;
                submitBtn.disabled = false;
            } else {
                alert('上传失败：' + res.msg);
                uploadText.textContent = '点击上传图片';
                uploadPreview.style.display = 'none';
            }
        })
        .catch(() => {
            alert('上传失败，请检查网络');
            uploadText.textContent = '点击上传图片';
            uploadPreview.style.display = 'none';
        });
});
</script>

<?php
$pageContent = ob_get_clean();
$pageTitle = '轮播图管理 - 桃之后台';
include 'layout.php';
