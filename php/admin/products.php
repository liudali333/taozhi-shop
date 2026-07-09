<?php
require_once 'config.php';
checkAuth();

$msg = '';
try {
    $db = getDB();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $bc = trim($_POST['barcode'] ?? '');
        if ($bc === '') {
            header("Location: products.php?err=" . urlencode('条形码不能为空'));
            exit;
        }
        $action = $_POST['action'] ?? '';
        if ($action === 'add') {
            $stmt = $db->prepare("INSERT INTO products (barcode, name, spec, price, sale_price, stock, category_l1, category_l2, category_l1_id, category_l2_id, category_id, sku, image, status, description) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $bc,
                $_POST['name'],
                $_POST['spec'] ?? '',
                floatval($_POST['price'] ?? 0),
                floatval($_POST['sale_price'] ?? 0),
                intval($_POST['stock'] ?? 0),
                $_POST['category_l1'] ?? '',
                $_POST['category_l2'] ?? '',
                intval($_POST['category_l1_id'] ?? 0),
                intval($_POST['category_l2_id'] ?? 0),
                intval($_POST['category_l2_id'] ?? 0) ?: intval($_POST['category_l1_id'] ?? 0),
                $_POST['sku'] ?? '',
                $_POST['image'] ?? '',
                intval($_POST['status'] ?? 1),
                $_POST['description'] ?? '',
            ]);
            $msg = '商品添加成功';
        } elseif ($action === 'update') {
            $stmt = $db->prepare("UPDATE products SET barcode=?, name=?, spec=?, price=?, sale_price=?, stock=?, category_l1=?, category_l2=?, category_l1_id=?, category_l2_id=?, category_id=?, sku=?, image=?, status=?, description=? WHERE id=?");
            $stmt->execute([
                $bc,
                $_POST['name'],
                $_POST['spec'] ?? '',
                floatval($_POST['price'] ?? 0),
                floatval($_POST['sale_price'] ?? 0),
                intval($_POST['stock'] ?? 0),
                $_POST['category_l1'] ?? '',
                $_POST['category_l2'] ?? '',
                intval($_POST['category_l1_id'] ?? 0),
                intval($_POST['category_l2_id'] ?? 0),
                intval($_POST['category_l2_id'] ?? 0) ?: intval($_POST['category_l1_id'] ?? 0),
                $_POST['sku'] ?? '',
                $_POST['image'] ?? '',
                intval($_POST['status'] ?? 1),
                $_POST['description'] ?? '',
                intval($_POST['id'] ?? 0),
            ]);
            $msg = '商品更新成功';
        } elseif ($action === 'delete') {
            $db->prepare("DELETE FROM products WHERE id = ?")->execute([intval($_POST['id'] ?? 0)]);
            $msg = '商品已删除';
        }
        header("Location: products.php?msg=" . urlencode($msg));
        exit;
    }

    if (!empty($_GET['msg'])) $msg = $_GET['msg'];
    if (!empty($_GET['err'])) $msg = $_GET['err'];

    $page      = max(1, intval($_GET['page'] ?? 1));
    $pageSize  = 30;
    $catFilter = isset($_GET['cat_id']) ? intval($_GET['cat_id']) : 0;
    $kw        = trim($_GET['kw'] ?? '');
    $params    = [];
    $where     = '1=1';
    if ($catFilter > 0) {
        $where .= ' AND (category_l1_id=? OR category_l2_id=?)';
        $params[] = $catFilter; $params[] = $catFilter;
    }
    if ($kw !== '') {
        $where .= ' AND (name LIKE ? OR barcode LIKE ? OR sku LIKE ?)';
        $like = '%' . $kw . '%';
        $params[] = $like; $params[] = $like; $params[] = $like;
    }
    $countSql = "SELECT COUNT(*) FROM products WHERE $where";
    $offset   = ($page - 1) * $pageSize;
    $listSql  = "SELECT * FROM products WHERE $where ORDER BY id DESC LIMIT $offset, $pageSize";
    try {
        $total = ($s = $db->prepare($countSql)) && $s->execute($params) ? $s->fetchColumn() : 0;
        $totalPages = max(1, ceil($total / $pageSize));
        $products = ($s2 = $db->prepare($listSql)) && $s2->execute($params) ? $s2->fetchAll() : [];
    } catch (Throwable $e) {
        $total = 0; $totalPages = 1; $products = [];
    }
    $cats    = $db->query("SELECT * FROM categories WHERE level=1 ORDER BY sort, id")->fetchAll();
    $allCats = $db->query("SELECT * FROM categories ORDER BY sort, id")->fetchAll();

} catch (Exception $e) {
    $products = [];
    $cats = [];
    $allCats = [];
    $msg = '数据库错误: ' . $e->getMessage();
}

ob_start();
?>
<style>
.toolbar{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;margin-bottom:16px;}
.toolbar-left,.toolbar-right{display:flex;align-items:center;gap:8px;flex-wrap:wrap;}
.toolbar .form-input{width:200px;}
.toolbar .form-select{width:auto;min-width:130px;}
#img_gallery,#detail_gallery{display:flex;flex-wrap:wrap;gap:8px;margin-top:8px;}
.img-thumb,.detail-thumb{position:relative;display:inline-block;}
.img-thumb img,.detail-thumb img{width:80px;height:80px;object-fit:cover;border-radius:6px;border:1px solid #eee;cursor:pointer;}
.img-thumb .del-btn,.detail-thumb .del-btn{position:absolute;top:-6px;right:-6px;width:20px;height:20px;background:#e64340;border-radius:50%;color:#fff;font-size:14px;line-height:20px;text-align:center;cursor:pointer;border:none;padding:0;}
.img-thumb .del-btn:hover,.detail-thumb .del-btn:hover{background:#c2352e;}
.add-btn{display:inline-flex;align-items:center;justify-content:center;width:80px;height:80px;border:2px dashed #ddd;border-radius:6px;cursor:pointer;color:#aaa;font-size:28px;background:#fafafa;transition:border-color .2s,color .2s;flex-shrink:0;}
.add-btn:hover{border-color:#1a7af8;color:#1a7af8;}
.add-btn input[type=file]{display:none;}
.type-tag{font-size:10px;background:#1a7af8;color:#fff;padding:1px 4px;border-radius:3px;position:absolute;bottom:4px;left:50%;transform:translateX(-50%);}
.type-tag.detail{background:#f5a623;}
.main-first-star{position:absolute;top:4px;left:4px;background:#e64340;color:#fff;font-size:10px;padding:1px 3px;border-radius:3px;}
.upload-loading{font-size:12px;color:#1a7af8;margin-top:6px;}
.section-label{font-weight:600;font-size:13px;color:#333;margin-bottom:4px;}
</style>

<div class="header">
    <h1>📦 商品管理</h1>
    <span class="welcome"><?= ($catFilter || $kw) ? '筛选结果 ' . $total . ' 个' : '共 ' . $total . ' 个商品' ?></span>
</div>

<div class="card">
    <?php if ($msg): ?>
    <div class="msg-box <?= strpos($msg, '不能为空') !== false || strpos($msg, '数据库错误') !== false ? 'msg-error' : 'msg-success' ?>">
        <?= strpos($msg, '✓') === false ? '✗ ' : '✓ ' ?><?= htmlspecialchars($msg) ?>
    </div>
    <?php endif; ?>

    <div class="toolbar">
        <div class="toolbar-left">
            <button class="btn btn-primary" onclick="openAdd()">+ 添加商品</button>
        </div>
        <form method="GET" class="toolbar-right" id="filterForm">
            <select name="cat_id" class="form-select" id="filterCat" onchange="document.getElementById('filterForm').submit()">
                <option value="">全部分类</option>
                <?php foreach ($cats as $cat): ?>
                    <option value="<?= $cat['id'] ?>" <?= $catFilter == $cat['id'] ? 'selected' : '' ?>><?= htmlspecialchars($cat['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <input type="text" name="kw" class="form-input" placeholder="商品名 / 货号 / 条形码" value="<?= htmlspecialchars($kw) ?>">
            <button type="submit" class="btn btn-secondary">搜索</button>
            <?php if ($catFilter || $kw): ?>
                <a href="products.php" class="btn btn-outline" style="padding:6px 12px;font-size:13px;">清除</a>
            <?php endif; ?>
        </form>
    </div>
    <?php if ($kw || $catFilter): ?>
        <div style="margin-bottom:12px;font-size:13px;color:#666;">
            找到 <strong><?= $total ?></strong> 个商品
            <?php if ($kw): ?>，关键词「<?= htmlspecialchars($kw) ?>」<?php endif; ?>
            <?php if ($catFilter): ?><?php $cf = null; foreach($cats as $c) { if($c['id']==$catFilter) { $cf=$c['name']; break; } } ?><?= $cf ? '，分类「' . htmlspecialchars($cf) . '」' : '' ?><?php endif; ?>
        </div>
    <?php endif; ?>

    <div style="overflow-x:auto;">
    <table>
        <thead>
            <tr><th>图片</th><th>条形码</th><th>商品名称</th><th>规格</th><th>原价</th><th>活动价</th><th>库存</th><th>分类</th><th>状态</th><th>操作</th></tr>
        </thead>
        <tbody>
        <?php foreach ($products as $p):
            $imgUrl = $p['image'] ?? '';
        ?>
        <tr>
            <td>
                <?php if (!empty($imgUrl)): ?>
                    <img src="<?= htmlspecialchars($imgUrl) ?>" style="width:44px;height:44px;object-fit:cover;border-radius:6px;background:#f5f5f5;" onerror="this.style.display='none'">
                <?php else: ?>
                    <div style="width:44px;height:44px;background:#f5f5f5;border-radius:6px;display:flex;align-items:center;justify-content:center;font-size:10px;color:#999;">无图</div>
                <?php endif; ?>
            </td>
            <td style="font-size:12px;color:#666;font-family:monospace;"><?= htmlspecialchars($p['barcode'] ?? '') ?></td>
            <td><div style="max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-weight:500;"><?= htmlspecialchars($p['name']) ?></div></td>
            <td style="color:#888;font-size:12px;"><?= htmlspecialchars($p['spec'] ?? '') ?></td>
            <td><?= number_format($p['price'], 2) ?>元</td>
            <td style="color:#e64340;font-weight:600;"><?= number_format($p['sale_price'], 2) ?>元</td>
            <td><?= $p['stock'] <= 0 ? '<span class="tag tag-off">缺货</span>' : $p['stock'] ?></td>
            <td><?= htmlspecialchars($p['category_l1'] ?? '') ?> <?= $p['category_l2'] ? '>' . htmlspecialchars($p['category_l2']) : '' ?></td>
            <td><span class="tag <?= $p['status'] ? 'tag-on' : 'tag-off' ?>"><?= $p['status'] ? '上架' : '下架' ?></span></td>
            <td>
                <button class="btn btn-secondary btn-sm" onclick="openEdit(<?= $p['id'] ?>)">编辑</button>
                <button class="btn btn-danger btn-sm" onclick="doDelete(<?= $p['id'] ?>, '<?= htmlspecialchars(addslashes($p['name'])) ?>')">删除</button>
            </td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($products)): ?>
            <tr><td colspan="10" style="text-align:center;padding:40px;color:#999;">暂无商品</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>

    <?php if ($totalPages > 1): ?>
    <?php $q = http_build_query(array_filter(['cat_id'=>$catFilter?:null,'kw'=>$kw?:null])); ?>
    <div class="pagination">
        <?php if ($page > 1): ?><a href="?page=<?= $page-1 ?><?= $q ? '&'.$q : '' ?>">‹ 上一页</a><?php else: ?><span class="disabled">‹ 上一页</span><?php endif; ?>
        <?php for ($i = max(1,$page-2); $i <= min($totalPages,$page+2); $i++): ?>
            <?= $i==$page ? "<span class='current'>$i</span>" : "<a href='?page=$i&$q'>$i</a>" ?>
        <?php endfor; ?>
        <?php if ($page < $totalPages): ?><a href="?page=<?= $page+1 ?><?= $q ? '&'.$q : '' ?>">下一页 ›</a><?php else: ?><span class="disabled">下一页 ›</span><?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<!-- 模态框 -->
<div class="modal-overlay" id="modal">
    <div class="modal">
        <div class="modal-header">
            <div class="modal-title" id="modalTitle">添加商品</div>
            <button class="modal-close" onclick="closeModal()">×</button>
        </div>
        <form method="POST" id="productForm">
            <input type="hidden" name="action" id="formAction" value="add">
            <input type="hidden" name="id" id="formId">

            <!-- 条形码放最前面，必填 -->
            <div class="form-row">
                <label class="form-label">条形码 <span style="color:red">*</span></label>
                <input type="text" name="barcode" class="form-input" id="f_barcode" required placeholder="输入条形码，图片将以此为文件夹保存">
            </div>

            <div class="form-row">
                <label class="form-label">商品名称 <span style="color:red">*</span></label>
                <input type="text" name="name" class="form-input" id="f_name" required>
            </div>

            <div class="form-row form-row-inline">
                <div><label class="form-label">货号</label><input type="text" name="sku" class="form-input" id="f_sku"></div>
                <div><label class="form-label">规格</label><input type="text" name="spec" class="form-input" id="f_spec"></div>
            </div>

            <div class="form-row form-row-inline">
                <div><label class="form-label">原价 <span style="color:red">*</span></label><input type="number" name="price" class="form-input" id="f_price" step="0.01" min="0" required></div>
                <div><label class="form-label">活动价</label><input type="number" name="sale_price" class="form-input" id="f_sale_price" step="0.01" min="0"></div>
            </div>

            <div class="form-row form-row-inline">
                <div><label class="form-label">库存 <span style="color:red">*</span></label><input type="number" name="stock" class="form-input" id="f_stock" min="0" required value="0"></div>
                <div><label class="form-label">状态</label><select name="status" class="form-select" id="f_status"><option value="1">上架</option><option value="0">下架</option></select></div>
            </div>

            <div class="form-row">
                <label class="form-label">一级分类 <span style="color:red">*</span></label>
                <select name="category_l1" class="form-select" id="f_cat_l1" required onchange="loadL2(this.value)">
                    <option value="">-- 选择 --</option>
                    <?php foreach ($cats as $cat): ?>
                        <option value="<?= htmlspecialchars($cat['name']) ?>" data-id="<?= $cat['id'] ?>"><?= htmlspecialchars($cat['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <input type="hidden" name="category_l1_id" id="f_cat_l1_id">
            </div>

            <div class="form-row">
                <label class="form-label">二级分类</label>
                <select name="category_l2" class="form-select" id="f_cat_l2"><option value="">-- 选择（可选）--</option></select>
                <input type="hidden" name="category_l2_id" id="f_cat_l2_id">
            </div>

            <!-- 主图上传 -->
            <div class="form-row">
                <label class="form-label">商品主图</label>
                <input type="hidden" name="image" id="f_image" value="">
                <div class="section-label" style="color:#999;font-weight:400;font-size:12px;">（第一张自动设为主图）</div>
                <div class="img-gallery" id="main_gallery">
                    <label class="add-btn" title="添加主图">+
                        <input type="file" accept="image/*" multiple id="f_main_files" style="display:none" onchange="doImgUpload(this.files, '主图')">
                    </label>
                </div>
                <div class="upload-loading" id="main_loading" style="display:none;">上传中…</div>
            </div>

            <!-- 详情图上传 -->
            <div class="form-row">
                <label class="form-label">商品详情图</label>
                <input type="hidden" name="description" id="f_description" value="">
                <div class="detail-gallery" id="detail_gallery">
                    <label class="add-btn" title="添加详情图">+
                        <input type="file" accept="image/*" multiple id="f_detail_files" style="display:none" onchange="doImgUpload(this.files, '详情图')">
                    </label>
                </div>
                <div class="upload-loading" id="detail_loading" style="display:none;">上传中…</div>
            </div>

            <div class="form-actions">
                <button type="button" class="btn btn-secondary" onclick="closeModal()">取消</button>
                <button type="submit" class="btn btn-primary" id="submitBtn">保存</button>
            </div>
        </form>
    </div>
</div>

<script>
const allProducts = <?= json_encode($products) ?>;
const l1Cats      = <?= json_encode($cats) ?>;
const allCats     = <?= json_encode($allCats) ?>;
const API_HOST = '<?= (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http' ?>://<?= $_SERVER['HTTP_HOST'] ?? 'taozhi.433345.xyz' ?>';

function fullUrl(path) {
    if (!path) return '';
    // data URI 直接返回，不加域名前缀
    if (/^data:/i.test(path)) return path;
    if (/^https?:\/\//i.test(path)) return path;
    if (path.startsWith('/')) return API_HOST + path;
    return API_HOST + '/' + path;
}

// ---------- 主图 & 详情图两个独立数组 ----------
let mainImgs   = [];   // [{url, _blob}]
let detailImgs = [];   // [{url, _blob}]

function rebuildMainGallery() {
    const gal = document.getElementById('main_gallery');
    gal.innerHTML = '';
    mainImgs.forEach((img, i) => {
        if (!img.url) return;
        const wrap = document.createElement('span');
        wrap.className = 'img-thumb';
        const isFirst = (i === mainImgs.findIndex(x => x.url && !x._blob));
        wrap.innerHTML = `
            <img src="${fullUrl(img.url)}" onclick="window.open('${fullUrl(img.url)}','_blank')">
            ${isFirst ? '<span class="main-first-star">主</span>' : ''}
            <button type="button" class="del-btn" onclick="delMainImg(${i})">×</button>
        `;
        gal.appendChild(wrap);
    });
    const addBtn = document.createElement('label');
    addBtn.className = 'add-btn';
    addBtn.title = '添加主图';
    addBtn.innerHTML = '+';
    addBtn.style.cssText = 'display:inline-flex;align-items:center;justify-content:center;width:80px;height:80px;border:2px dashed #ddd;border-radius:6px;cursor:pointer;color:#aaa;font-size:28px;background:#fafafa;flex-shrink:0;';
    const inp = document.createElement('input'); inp.type = 'file'; inp.accept = 'image/*'; inp.multiple = true;
    inp.style.display = 'none'; inp.onchange = function() { doImgUpload(inp.files, '主图'); };
    addBtn.appendChild(inp);
    gal.appendChild(addBtn);
    syncFields();
}

function rebuildDetailGallery() {
    const gal = document.getElementById('detail_gallery');
    gal.innerHTML = '';
    detailImgs.forEach((img, i) => {
        if (!img.url) return;
        const wrap = document.createElement('span');
        wrap.className = 'detail-thumb';
        wrap.innerHTML = `
            <img src="${fullUrl(img.url)}" onclick="window.open('${fullUrl(img.url)}','_blank')">
            <button type="button" class="del-btn" onclick="delDetailImg(${i})">×</button>
        `;
        gal.appendChild(wrap);
    });
    const addBtn = document.createElement('label');
    addBtn.className = 'add-btn';
    addBtn.title = '添加详情图';
    addBtn.innerHTML = '+';
    addBtn.style.cssText = 'display:inline-flex;align-items:center;justify-content:center;width:80px;height:80px;border:2px dashed #ddd;border-radius:6px;cursor:pointer;color:#aaa;font-size:28px;background:#fafafa;flex-shrink:0;';
    const inp = document.createElement('input'); inp.type = 'file'; inp.accept = 'image/*'; inp.multiple = true;
    inp.style.display = 'none'; inp.onchange = function() { doImgUpload(inp.files, '详情图'); };
    addBtn.appendChild(inp);
    gal.appendChild(addBtn);
    syncFields();
}

function syncFields() {
    const main = mainImgs.filter(x => x.url && !x._blob).map(x => x.url);
    const detail = detailImgs.filter(x => x.url && !x._blob).map(x => x.url);
    document.getElementById('f_image').value = main[0] || '';   // 第一张主图为商品主图
    document.getElementById('f_description').value = JSON.stringify(detail);
}

function doImgUpload(files, imgType) {
    if (!files || files.length === 0) return;
    const bc = document.getElementById('f_barcode').value.trim();
    if (!bc) {
        alert('请先填写条形码，再上传图片');
        document.getElementById('f_barcode').focus();
        return;
    }
    if (!/^[A-Za-z0-9_\-]+$/.test(bc)) {
        alert('条形码只能包含字母、数字、_- 字符');
        return;
    }
    const loadingId = imgType === '主图' ? 'main_loading' : 'detail_loading';
    document.getElementById(loadingId).style.display = 'block';
    document.getElementById('submitBtn').disabled = true;
    let pending = files.length;
    Array.from(files).forEach(file => {
        const arr = imgType === '主图' ? mainImgs : detailImgs;
        const idx = arr.length;
        // 占位：同一位置复用，避免竞态产生重复项
        arr.push({ url: null, _pending: true });

        // 本地预览（替换占位）
        const reader = new FileReader();
        reader.onload = function(e) {
            if (arr[idx] && arr[idx]._pending) {
                arr[idx] = { url: e.target.result, _blob: true };
                if (imgType === '主图') rebuildMainGallery(); else rebuildDetailGallery();
            }
        };
        reader.readAsDataURL(file);

        // 上传（替换占位）
        const formData = new FormData();
        formData.append('file', file);
        formData.append('barcode', bc);
        formData.append('img_type', imgType);
        fetch(API_HOST + '/api/upload.php', { method: 'POST', body: formData })
            .then(r => r.json())
            .then(res => {
                if (res.code === 0) {
                    arr[idx] = { url: res.url };
                } else {
                    alert(imgType + '上传失败：' + res.msg);
                    arr[idx] = { url: '' };
                }
                if (imgType === '主图') rebuildMainGallery(); else rebuildDetailGallery();
                pending--;
                if (pending === 0) {
                    document.getElementById(loadingId).style.display = 'none';
                    document.getElementById('submitBtn').disabled = false;
                }
            })
            .catch(() => {
                arr[idx] = { url: '' };
                if (imgType === '主图') rebuildMainGallery(); else rebuildDetailGallery();
                pending--;
                if (pending === 0) {
                    document.getElementById(loadingId).style.display = 'none';
                    document.getElementById('submitBtn').disabled = false;
                }
            });
    });
}

function delMainImg(idx)  { mainImgs.splice(idx, 1);   rebuildMainGallery(); }
function delDetailImg(idx){ detailImgs.splice(idx, 1); rebuildDetailGallery(); }

// ---------- 二级分类联动 ----------
function loadL2(l1Name) {
    const sel = document.getElementById('f_cat_l1');
    document.getElementById('f_cat_l1_id').value = sel.selectedOptions[0]?.dataset?.id || '';
    const l2 = document.getElementById('f_cat_l2');
    l2.innerHTML = '<option value="">-- 选择（可选）--</option>';
    if (!l1Name) return;
    const l1 = l1Cats.find(c => c.name === l1Name);
    if (!l1) return;
    allCats.filter(c => c.parent_id == l1.id).forEach(x => {
        const opt = document.createElement('option');
        opt.value = x.name; opt.dataset.id = x.id; opt.textContent = x.name;
        l2.appendChild(opt);
    });
}

// ---------- 模态框 ----------

function openAdd() {
    mainImgs = [];
    detailImgs = [];
    document.getElementById('modalTitle').textContent = '添加商品';
    document.getElementById('formAction').value = 'add';
    document.getElementById('formId').value = '';
    document.getElementById('productForm').reset();
    document.getElementById('f_cat_l2').innerHTML = '<option value="">-- 选择（可选）--</option>';
    document.getElementById('f_stock').value = '0';
    document.getElementById('f_status').value = '1';
    document.getElementById('f_barcode').readOnly = false;
    syncFields();
    rebuildMainGallery();
    rebuildDetailGallery();
    document.getElementById('modal').classList.add('active');
}

function openEdit(id) {
    const p = allProducts.find(x => x.id == id);
    if (!p) return;
    const bc = String(p.barcode ?? '').replace(/\.0$/, '');
    // 数据库里 description 存详情图数组
    try {
        const rawDetail = JSON.parse(p.description || '[]');
        detailImgs = rawDetail.map(u => ({ url: u }));
    } catch(e) { detailImgs = []; }
    // 主图从 image 字段取（第一张），其余主图从 extra_main_imgs 字段（若有）
    // 这里统一：image=第一张主图，extra_main_imgs 暂不支持（可后续扩展）
    // 已有主图只有 image 字段那一张，若有多张主图需求可扩展字段
    const mainFirst = p.image ? [{ url: p.image }] : [];
    mainImgs = mainFirst;
    document.getElementById('modalTitle').textContent = '编辑商品';
    document.getElementById('formAction').value = 'update';
    document.getElementById('formId').value = id;
    document.getElementById('f_barcode').value = bc;
    document.getElementById('f_barcode').readOnly = true;
    document.getElementById('f_name').value = p.name || '';
    document.getElementById('f_sku').value = p.sku || '';
    document.getElementById('f_spec').value = p.spec || '';
    document.getElementById('f_price').value = p.price || 0;
    document.getElementById('f_sale_price').value = p.sale_price || '';
    document.getElementById('f_stock').value = p.stock || 0;
    document.getElementById('f_status').value = p.status || 1;
    document.getElementById('f_cat_l1').value = p.category_l1 || '';
    loadL2(p.category_l1 || '');
    setTimeout(() => { document.getElementById('f_cat_l2').value = p.category_l2 || ''; }, 0);
    syncFields();
    rebuildMainGallery();
    rebuildDetailGallery();
    document.getElementById('modal').classList.add('active');
}

function closeModal() {
    document.getElementById('modal').classList.remove('active');
    document.getElementById('f_barcode').readOnly = false;
}

function doDelete(id, name) {
    if (!confirm('确认删除「' + name + '」？')) return;
    const f = document.createElement('form'); f.method = 'POST';
    f.innerHTML = '<input name="action" value="delete"><input name="id" value="' + id + '">';
    document.body.appendChild(f); f.submit();
}

document.getElementById('modal').addEventListener('click', e => { if (e.target === e.currentTarget) closeModal(); });
</script>

<?php
$pageContent = ob_get_clean();
$pageTitle = '商品管理 - 桃之后台';
include 'layout.php';
