<?php
require_once 'config.php';
checkAuth();

$msg = '';
$msgType = 'success';
try {
    $db = getDB();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? '';
        if ($action === 'save') {
            $id          = intval($_POST['id'] ?? 0);
            $pageKey     = trim($_POST['page_key'] ?? '');
            $pageName    = trim($_POST['page_name'] ?? '');
            $shareTitle  = trim($_POST['share_title'] ?? '');
            $shareImg    = trim($_POST['share_img'] ?? '');
            $status      = intval($_POST['status'] ?? 1);
            $sort        = intval($_POST['sort'] ?? 0);

            if (!$pageKey) {
                $msg = '页面标识不能为空';
                $msgType = 'error';
            } elseif ($shareImg && (strpos($shareImg, 'data:') === 0 || strpos($shareImg, 'base64') !== false)) {
                $msg = '分享图上传失败，请重试';
                $msgType = 'error';
            } else {
                if ($id > 0) {
                    $db->prepare("UPDATE share_configs SET page_key=?, page_name=?, share_title=?, share_img=?, status=?, sort=? WHERE id=?")
                       ->execute([$pageKey, $pageName, $shareTitle, $shareImg, $status, $sort, $id]);
                } else {
                    $db->prepare("INSERT INTO share_configs (page_key, page_name, share_title, share_img, status, sort) VALUES (?, ?, ?, ?, ?, ?)")
                       ->execute([$pageKey, $pageName, $shareTitle, $shareImg, $status, $sort]);
                }
                header("Location: share.php?msg=保存成功");
                exit;
            }
        } elseif ($action === 'delete') {
            $db->prepare("DELETE FROM share_configs WHERE id = ?")->execute([intval($_POST['id'])]);
            header("Location: share.php?msg=已删除");
            exit;
        } elseif ($action === 'toggle') {
            $db->prepare("UPDATE share_configs SET status = 1 - status WHERE id = ?")->execute([intval($_POST['id'])]);
            header("Location: share.php?msg=已切换状态");
            exit;
        }
    }

    if (!empty($_GET['msg'])) $msg = $_GET['msg'];
    $list = $db->query("SELECT * FROM share_configs ORDER BY sort, id")->fetchAll();

} catch (Exception $e) {
    $msg = '数据库错误: ' . $e->getMessage();
    $msgType = 'error';
    $list = [];
}

ob_start();
?>
<style>
.upload-area {
  border: 2px dashed #ccc;
  border-radius: 10px;
  padding: 20px;
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
  max-height: 120px;
  border-radius: 8px;
  margin-top: 8px;
  object-fit: contain;
  background: #fff;
  border: 1px solid #eee;
}
.upload-hint { font-size: 12px; color: #999; margin-top: 4px; }
.share-img-thumb {
  width: 80px;
  height: 80px;
  object-fit: cover;
  border-radius: 8px;
  background: #f5f5f5;
  border: 1px solid #eee;
}
.share-img-empty {
  width: 80px;
  height: 80px;
  border-radius: 8px;
  background: #fafafa;
  border: 1px dashed #ddd;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  color: #ccc;
  font-size: 12px;
}
</style>

<div class="header"><h1>📤 分享图管理</h1></div>

<div class="card">
    <?php if ($msg): ?><div class="msg-box msg-<?= $msgType ?>"><?= $msgType === 'error' ? '✗' : '✓' ?> <?= htmlspecialchars($msg) ?></div><?php endif; ?>

    <div style="background:#fffbe6;border:1px solid #ffe58f;border-radius:8px;padding:12px 16px;margin-bottom:16px;font-size:13px;color:#614700;">
        💡 提示：每个页面的分享图和分享标题独立配置。分享图建议尺寸 5:4（如 500×400px），不超过 5MB。
    </div>

    <div style="font-weight:600;margin-bottom:12px;">已有分享配置（<?= count($list) ?>项）</div>

    <div style="display:flex;flex-direction:column;gap:12px;">
        <?php foreach ($list as $s): ?>
        <div style="display:flex;align-items:center;gap:16px;padding:16px;border:1px solid #f0f0f0;border-radius:10px;background:#fff;">
            <?php if (!empty($s['share_img'])): ?>
                <img src="<?= htmlspecialchars($s['share_img']) ?>" class="share-img-thumb" onerror="this.outerHTML='<div class=\'share-img-empty\'>无图</div>'">
            <?php else: ?>
                <div class="share-img-empty">未设置</div>
            <?php endif; ?>
            <div style="flex:1;min-width:0;">
                <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px;">
                    <span style="font-weight:600;color:#333;font-size:15px;"><?= htmlspecialchars($s['page_name']) ?></span>
                    <span style="font-size:12px;color:#999;font-family:monospace;background:#f5f5f5;padding:2px 8px;border-radius:4px;"><?= htmlspecialchars($s['page_key']) ?></span>
                    <?php if ($s['status']): ?>
                        <span class="tag tag-on">已启用</span>
                    <?php else: ?>
                        <span class="tag tag-off">已禁用</span>
                    <?php endif; ?>
                </div>
                <div style="font-size:13px;color:#666;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                    标题：<?= htmlspecialchars($s['share_title'] ?: '（默认）') ?>
                </div>
            </div>
            <div style="display:flex;gap:6px;">
                <button class="btn btn-sm btn-primary" onclick='editShare(<?= json_encode($s, JSON_UNESCAPED_UNICODE) ?>)'>编辑</button>
                <form method="POST" style="display:inline;">
                    <input type="hidden" name="action" value="toggle">
                    <input type="hidden" name="id" value="<?= $s['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-secondary"><?= $s['status'] ? '禁用' : '启用' ?></button>
                </form>
            </div>
        </div>
        <?php endforeach; ?>
        <?php if (empty($list)): ?>
            <div style="text-align:center;padding:40px;color:#999;">暂无分享配置，请联系开发者初始化</div>
        <?php endif; ?>
    </div>
</div>

<!-- 编辑弹窗 -->
<div class="modal-overlay" id="editModal">
    <div class="modal" style="width:560px;">
        <div class="modal-header">
            <div class="modal-title" id="modalTitle">编辑分享配置</div>
            <button class="modal-close" onclick="closeEdit()">×</button>
        </div>
        <form method="POST" id="editForm">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" id="editId">
            <div class="form-row">
                <label class="form-label">页面标识 <span>*</span></label>
                <input type="text" name="page_key" id="editPageKey" class="form-input" required placeholder="如 home、product">
            </div>
            <div class="form-row">
                <label class="form-label">页面名称 <span>*</span></label>
                <input type="text" name="page_name" id="editPageName" class="form-input" required placeholder="如 首页、商品详情">
            </div>
            <div class="form-row">
                <label class="form-label">分享标题</label>
                <input type="text" name="share_title" id="editShareTitle" class="form-input" maxlength="100" placeholder="分享到微信时显示的标题">
            </div>
            <div class="form-row">
                <label class="form-label">分享图片</label>
                <div class="upload-area" id="shareUploadArea" onclick="document.getElementById('shareFileInput').click()">
                    <input type="file" id="shareFileInput" accept="image/jpeg,image/png,image/gif,image/webp">
                    <div id="shareUploadText">点击上传分享图</div>
                    <div id="shareUploadHint" class="upload-hint">支持 JPG/PNG/GIF/WEBP，不超过 5MB，建议 5:4</div>
                    <img id="shareUploadPreview" class="upload-preview" style="display:none;">
                </div>
                <input type="hidden" name="share_img" id="editShareImg">
            </div>
            <div class="form-row-inline-2">
                <div>
                    <label class="form-label">状态</label>
                    <select name="status" id="editStatus" class="form-select">
                        <option value="1">启用</option>
                        <option value="0">禁用</option>
                    </select>
                </div>
                <div>
                    <label class="form-label">排序</label>
                    <input type="number" name="sort" id="editSort" class="form-input" value="0">
                </div>
            </div>
            <div class="form-actions">
                <button type="button" class="btn btn-secondary" onclick="closeEdit()">取消</button>
                <button type="submit" class="btn btn-primary">保存</button>
            </div>
        </form>
    </div>
</div>

<script>
function editShare(s) {
    document.getElementById('editId').value = s.id;
    document.getElementById('editPageKey').value = s.page_key;
    document.getElementById('editPageName').value = s.page_name;
    document.getElementById('editShareTitle').value = s.share_title || '';
    document.getElementById('editShareImg').value = s.share_img || '';
    document.getElementById('editStatus').value = s.status;
    document.getElementById('editSort').value = s.sort;
    const preview = document.getElementById('shareUploadPreview');
    if (s.share_img) {
        preview.src = s.share_img;
        preview.style.display = 'block';
        document.getElementById('shareUploadText').textContent = '✓ 已设置';
    } else {
        preview.style.display = 'none';
        document.getElementById('shareUploadText').textContent = '点击上传分享图';
    }
    document.getElementById('editModal').classList.add('active');
}
function closeEdit() {
    document.getElementById('editModal').classList.remove('active');
}

document.getElementById('shareFileInput').addEventListener('change', function () {
    const file = this.files[0];
    if (!file) return;
    const reader = new FileReader();
    reader.onload = e => {
        const p = document.getElementById('shareUploadPreview');
        p.src = e.target.result;
        p.style.display = 'block';
        document.getElementById('shareUploadText').textContent = '上传中...';
    };
    reader.readAsDataURL(file);

    const formData = new FormData();
    formData.append('file', file);
    formData.append('dir', 'shares');
    fetch('/api/upload.php', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(res => {
            if (res.code === 0) {
                document.getElementById('editShareImg').value = res.url;
                document.getElementById('shareUploadText').textContent = '✓ 上传成功';
                document.getElementById('shareUploadHint').textContent = res.url;
            } else {
                alert('上传失败：' + res.msg);
                document.getElementById('shareUploadText').textContent = '点击上传分享图';
            }
        })
        .catch(() => {
            alert('上传失败，请重试');
            document.getElementById('shareUploadText').textContent = '点击上传分享图';
        });
});

document.getElementById('editModal').addEventListener('click', function (e) {
    if (e.target === this) closeEdit();
});
</script>

<?php
$pageContent = ob_get_clean();
$pageTitle = '分享图管理 - 桃之后台';
include 'layout.php';
