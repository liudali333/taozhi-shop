<?php
require_once 'config.php';
checkAuth();

$msg = '';
try {
    $db = getDB();

    // 确保 status 字段存在（兼容旧表）
    try {
        $db->exec("ALTER TABLE categories ADD COLUMN status TINYINT NOT NULL DEFAULT 1");
    } catch (Exception $e) {
        // 字段已存在，忽略
    }

    // 清理重复数据：按 id 分组，每组只保留第一条，删除多余记录
    $allIds = $db->query("SELECT id FROM categories ORDER BY id")->fetchAll(PDO::FETCH_COLUMN, 0);
    $seen = [];
    foreach ($allIds as $cid) {
        if (isset($seen[$cid])) {
            $db->prepare("DELETE FROM categories WHERE id = ? LIMIT 1")->execute([$cid]);
        } else {
            $seen[$cid] = true;
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? '';
        if ($action === 'add_l1') {
            $name = trim($_POST['name'] ?? '');
            if ($name === '') { header('Location: categories.php?msg=' . urlencode('名称不能为空')); exit; }
            // 检查重名
            $safeName = $db->quote($name);
            $exists = $db->query("SELECT COUNT(*) FROM categories WHERE name = $safeName AND level = 1 AND parent_id = 0")->fetchColumn() > 0;
            if ($exists) { header('Location: categories.php?msg=' . urlencode('该一级分类已存在')); exit; }
            // 使用数据库自增 id，不再手动 MAX(id)+1
            $sort = ($db->query("SELECT COALESCE(MAX(sort), 0) FROM categories WHERE level = 1")->fetchColumn()) + 10;
            $db->prepare("INSERT INTO categories (name, level, parent_id, sort, status) VALUES (?, 1, 0, ?, 1)")
               ->execute([$name, $sort]);
            header('Location: categories.php?msg=' . urlencode('添加成功'));
            exit;
        } elseif ($action === 'add_l2') {
            $name = trim($_POST['name'] ?? '');
            $parentId = intval($_POST['parent_id'] ?? 0);
            if ($name === '' || $parentId <= 0) { header('Location: categories.php?msg=' . urlencode('参数错误')); exit; }
            // 检查重名
            $safeName = $db->quote($name);
            $exists = $db->query("SELECT COUNT(*) FROM categories WHERE name = $safeName AND level = 2 AND parent_id = $parentId")->fetchColumn() > 0;
            if ($exists) { header('Location: categories.php?msg=' . urlencode('该二级分类已存在')); exit; }
            $sort = ($db->query("SELECT COALESCE(MAX(sort), 0) FROM categories WHERE level = 2 AND parent_id = $parentId")->fetchColumn()) + 10;
            $db->prepare("INSERT INTO categories (name, level, parent_id, sort, status) VALUES (?, 2, ?, ?, 1)")
               ->execute([$name, $parentId, $sort]);
            header('Location: categories.php?msg=' . urlencode('添加成功'));
            exit;
        } elseif ($action === 'update') {
            $db->prepare("UPDATE categories SET name = ? WHERE id = ?")
               ->execute([trim($_POST['name']), intval($_POST['id'])]);
            header('Location: categories.php?msg=已更新');
            exit;
        } elseif ($action === 'delete') {
            $id = intval($_POST['id']);
            $db->prepare("DELETE FROM categories WHERE parent_id = ?")->execute([$id]);
            $db->prepare("DELETE FROM categories WHERE id = ?")->execute([$id]);
            header('Location: categories.php?msg=已删除');
            exit;
        } elseif ($action === 'toggle_status') {
            $id = intval($_POST['id']);
            // 切换 status：1->0 隐藏，0->1 显示
            $db->exec("UPDATE categories SET status = IF(status = 1, 0, 1) WHERE id = " . $id);
            header('Content-Type: application/json');
            echo json_encode(['code' => 0]);
            exit;
        } elseif ($action === 'sort_l1') {
            // 一级分类排序
            $ids = $_POST['ids'] ?? '';
            $arr = explode(',', $ids);
            $stmt = $db->prepare("UPDATE categories SET sort = ? WHERE id = ? AND level = 1");
            foreach ($arr as $i => $id) {
                $stmt->execute([($i + 1) * 10, intval($id)]);
            }
            header('Content-Type: application/json');
            echo json_encode(['code' => 0, 'msg' => '排序已保存']);
            exit;
        } elseif ($action === 'sort_l2') {
            // 二级分类排序
            $ids = $_POST['ids'] ?? '';
            $parentId = intval($_POST['parent_id'] ?? 0);
            $arr = explode(',', $ids);
            $stmt = $db->prepare("UPDATE categories SET sort = ? WHERE id = ? AND parent_id = ?");
            foreach ($arr as $i => $id) {
                $stmt->execute([($i + 1) * 10, intval($id), $parentId]);
            }
            header('Content-Type: application/json');
            echo json_encode(['code' => 0, 'msg' => '排序已保存']);
            exit;
        }
    }

    // AJAX GET — 返回 JSON 分类树（供前端拖拽后验证）
    if (!empty($_GET['action']) && $_GET['action'] === 'get_tree') {
        header('Content-Type: application/json');
        $allCats = $db->query("SELECT id, name, level, parent_id, sort, status FROM categories ORDER BY sort, id")->fetchAll();
        echo json_encode(['code' => 0, 'data' => $allCats]);
        exit;
    }

    if (!empty($_GET['msg'])) $msg = $_GET['msg'];

    $allCats = $db->query("SELECT * FROM categories ORDER BY sort, id")->fetchAll();

    // 去重（按 id）
    $seen = [];
    $uniqueCats = [];
    foreach ($allCats as $cat) {
        $cid = intval($cat['id']);
        if (!isset($seen[$cid])) {
            $seen[$cid] = true;
            $uniqueCats[] = $cat;
        }
    }

    // 按 name + level 去重一级分类（防止同名一级分类干扰）
    $seenNameLevel = [];
    $l1List = [];
    foreach ($uniqueCats as $cat) {
        if ($cat['level'] != 1) continue;
        $key = $cat['name'] . '|' . $cat['level'];
        if (!isset($seenNameLevel[$key])) {
            $seenNameLevel[$key] = true;
            $l1List[] = $cat;
        }
    }

    // 为每个一级分类挂载子分类
    foreach ($l1List as &$cat) {
        $cat['children'] = array_values(array_filter($uniqueCats, fn($c) => $c['parent_id'] == $cat['id'] && !empty($c['id'])));
        $cat['status'] = $cat['status'] ?? 1;
    }
    unset($cat); // ✅ 核心修复：必须解除引用，否则最后一个元素会重复或错乱

} catch (Exception $e) {
    $msg = '数据库错误: ' . $e->getMessage();
    $l1List = [];
}

ob_start();
?>
<style>
.tree-item.dragging, .tree-l2.dragging { opacity: 0.4; }
.drag-over { background: #e3f2fd !important; border-color: #1976d2 !important; }
.drag-ghost { opacity: 0.3; }

/* 拖拽手柄 */
.drag-handle {
    cursor: grab;
    color: #bbb;
    font-size: 18px;
    user-select: none;
    padding: 2px 6px;
    border-radius: 4px;
    transition: background 0.2s;
}
.drag-handle:hover { background: #eee; color: #666; }
.drag-handle:active { cursor: grabbing; }

/* 一级分类拖拽样式 */
.tree-l1 {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 12px 16px;
    background: #fff5f5;
    border-radius: 10px;
    border-left: 4px solid #e64340;
    transition: background 0.2s, border-color 0.2s;
}

/* 二级分类拖拽样式 */
.tree-l2 {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 5px 10px;
    background: #f5f5f5;
    border-radius: 6px;
    font-size: 13px;
    color: #555;
    transition: background 0.2s, border 0.2s;
    border: 1px solid transparent;
}
.tree-l2.drag-over {
    background: #e3f2fd;
    border-color: #1976d2;
}

/* 自动保存提示 */
#saveToast {
    position: fixed;
    bottom: 30px;
    left: 50%;
    transform: translateX(-50%);
    background: #333;
    color: #fff;
    padding: 10px 24px;
    border-radius: 8px;
    font-size: 14px;
    z-index: 9999;
    transition: opacity 0.3s;
    pointer-events: none;
    opacity: 0;
}


</style>

<div class="header">
    <h1>🏷️ 分类管理</h1>
</div>

<div class="card">
    <?php if ($msg): ?><div class="msg-box msg-success">✓ <?= htmlspecialchars($msg) ?></div><?php endif; ?>

    <form method="POST" style="margin-bottom:24px;display:flex;gap:8px;">
        <input type="hidden" name="action" value="add_l1">
        <input type="text" name="name" class="form-input" style="width:200px;" placeholder="一级分类名称" required>
        <button type="submit" class="btn btn-primary">+ 添加一级</button>
    </form>


    <div id="l1List">
    <?php foreach ($l1List as $cat): ?>
    <div class="tree-item" draggable="true" data-level="1" data-id="<?= $cat['id'] ?>">
        <div class="tree-l1">
            <span class="drag-handle">≡</span>
            <div class="tree-l1-name"><?= htmlspecialchars($cat['name']) ?> <small style="color:#999;font-size:11px;">#<?= $cat['id'] ?></small></div>
            <button class="btn btn-secondary btn-sm" onclick="openEdit(<?= $cat['id'] ?>, '<?= htmlspecialchars(addslashes($cat['name'])) ?>')">编辑</button>
            <form method="POST" style="display:inline;">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= $cat['id'] ?>">
                <button type="submit" class="btn btn-danger btn-sm" onclick="return confirm('删除「<?= htmlspecialchars($cat['name']) ?>」及其子分类？')">删除</button>
            </form>
        </div>
        <div class="tree-l2-wrap" data-parent="<?= $cat['id'] ?>">
            <?php foreach ($cat['children'] as $child): ?>
            <div class="tree-l2" draggable="true" data-id="<?= $child['id'] ?>" data-parent="<?= $cat['id'] ?>">
                <span class="drag-handle">≡</span>
                <?= htmlspecialchars($child['name']) ?>
                <button class="btn btn-secondary btn-sm" onclick="openEdit(<?= $child['id'] ?>, '<?= htmlspecialchars(addslashes($child['name'])) ?>')" style="padding:1px 6px;font-size:11px;">编辑</button>
                <form method="POST" style="display:inline;">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= $child['id'] ?>">
                    <button type="submit" class="btn btn-danger btn-sm" onclick="return confirm('删除？')" style="padding:1px 6px;font-size:11px;">×</button>
                </form>
            </div>
            <?php endforeach; ?>
            <form method="POST" style="display:inline-flex;gap:4px;">
                <input type="hidden" name="action" value="add_l2">
                <input type="hidden" name="parent_id" value="<?= $cat['id'] ?>">
                <input type="text" name="name" class="form-input" style="width:100px;padding:4px 8px;font-size:12px;" placeholder="+二级" required>
                <button type="submit" class="btn btn-secondary btn-sm" style="padding:4px 8px;font-size:12px;">添加</button>
            </form>
        </div>
    </div>
    <?php endforeach; ?>
    </div>
    <?php if (empty($l1List)): ?><div style="text-align:center;padding:40px;color:#999;">暂无分类</div><?php endif; ?>
</div>

<div class="modal-overlay" id="modal">
    <div class="modal">
        <div class="modal-header">
            <div class="modal-title">编辑分类</div>
            <button class="modal-close" onclick="closeModal()">×</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="id" id="editId">
            <div class="form-row"><label class="form-label">分类名称</label><input type="text" name="name" class="form-input" id="editName" required></div>
            <div class="form-actions">
                <button type="button" class="btn btn-secondary" onclick="closeModal()">取消</button>
                <button type="submit" class="btn btn-primary">保存</button>
            </div>
        </form>
    </div>
</div>

<script>
/* =========================
  拖拽排序（稳定版）
 ========================= */
let dragging = null;
let dragContainer = null;
let insertBeforeOf = null;
let isSaving = false;

/* ---- mousedown：开始拖拽 ---- */
document.addEventListener('mousedown', function (e) {
  const el = e.target.closest('.tree-item, .tree-l2');
  if (!el) return;
  if (e.target.closest('button, input, textarea, select, form')) return;

  e.preventDefault();
  dragging = el;
  el.classList.add('dragging');

  dragContainer = el.classList.contains('tree-item')
    ? document.getElementById('l1List')
    : document.querySelector('.tree-l2-wrap[data-parent="' + el.dataset.parent + '"]');

  insertBeforeOf = null;
});

/* ---- mousemove：计算插入位置 ---- */
document.addEventListener('mousemove', function (e) {
  if (!dragging) return;
  e.preventDefault();

  const over = e.target.closest('.tree-item, .tree-l2');
  document.querySelectorAll('.drag-over').forEach(d => d.classList.remove('drag-over'));

  if (!over || over === dragging) return;

  const sameLevel =
    over.classList.contains('tree-item') === dragging.classList.contains('tree-item');

  if (!sameLevel) return;

  if (dragging.classList.contains('tree-l2') && over.dataset.parent !== dragging.dataset.parent) {
    return;
  }

  over.classList.add('drag-over');

  const rect = over.getBoundingClientRect();
  insertBeforeOf = e.clientX < rect.left + rect.width / 2
    ? over
    : over.nextElementSibling;
});

/* ---- mouseup：执行移动（先移除再插入，杜绝重复） ---- */
document.addEventListener('mouseup', function (e) {
  if (!dragging) return;

  dragging.classList.remove('dragging');
  document.querySelectorAll('.drag-over').forEach(d => d.classList.remove('drag-over'));

  if (dragContainer) {
    const children = Array.from(dragContainer.children);
    const oldIndex = children.indexOf(dragging);

    dragContainer.removeChild(dragging);
    dragContainer.insertBefore(dragging, insertBeforeOf);

    const newChildren = Array.from(dragContainer.children);
    const newIndex = newChildren.indexOf(dragging);

    if (oldIndex !== newIndex) {
      saveSort();
    }
  }

  dragging = null;
  dragContainer = null;
  insertBeforeOf = null;
});

/* =========================
  保存排序（fetch 版）
 ========================= */
function saveSort() {
  if (isSaving) return;
  isSaving = true;

  const l1Ids = Array.from(document.querySelectorAll('#l1List > .tree-item'))
    .map(el => el.dataset.id)
    .join(',');
  if (l1Ids) saveSortAjax('sort_l1', l1Ids);

  document.querySelectorAll('.tree-l2-wrap').forEach(wrap => {
    const ids = Array.from(wrap.querySelectorAll('.tree-l2'))
      .map(el => el.dataset.id)
      .join(',');
    if (ids) saveSortAjax('sort_l2', ids, wrap.dataset.parent);
  });

  showToast('排序已保存');
  setTimeout(() => isSaving = false, 800);
}

function saveSortAjax(action, ids, parentId = 0) {
  const params = new URLSearchParams();
  params.append('action', action);
  params.append('ids', ids);
  if (parentId) params.append('parent_id', parentId);

  fetch(window.location.href, {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: params.toString()
  });
}

/* =========================
  Toast 提示
 ========================= */
function showToast(msg) {
  let el = document.getElementById('saveToast');
  if (!el) {
    el = document.createElement('div');
    el.id = 'saveToast';
    document.body.appendChild(el);
  }
  el.textContent = msg;
  el.style.opacity = '1';
  clearTimeout(el._timer);
  el._timer = setTimeout(() => el.style.opacity = '0', 1500);
}

/* =========================
  编辑弹窗
 ========================= */
function openEdit(id, name) {
  document.getElementById('editId').value = id;
  document.getElementById('editName').value = name;
  document.getElementById('modal').classList.add('active');
}

function closeModal() {
  document.getElementById('modal').classList.remove('active');
}

document.getElementById('modal').addEventListener('click', function (e) {
  if (e.target === this) closeModal();
});
</script>

<?php
$pageContent = ob_get_clean();
$pageTitle = '分类管理 - 桃之后台';
include 'layout.php';
