#!/bin/bash
# session_fix.sh - 修复宝塔面板 PHP Session 丢失问题
# 用法: 上传到服务器后 SSH 执行: bash session_fix.sh

SITE_DIR="/www/wwwroot/taozhi.433345.xyz"
SESSION_DIR="/www/server/php/session/taozhi"

echo "=== 创建 Session 目录 ==="
mkdir -p "$SESSION_DIR"
chown -R www:www "$SESSION_DIR"
chmod 0777 "$SESSION_DIR"
echo "目录: $SESSION_DIR"

echo ""
echo "=== 测试写入 ==="
touch "$SESSION_DIR/test.txt"
if [ -f "$SESSION_DIR/test.txt" ]; then
    rm "$SESSION_DIR/test.txt"
    echo "✅ 目录写入正常"
else
    echo "❌ 目录写入失败，尝试 777..."
    chmod 777 "$SESSION_DIR"
fi

echo ""
echo "=== PHP 版本 ==="
php -v | head -1

echo ""
echo "=== 当前 session.save_path ==="
php -r "echo ini_get('session.save_path') ?: '/tmp (未设置)';" 

echo ""
echo "=== 创建 .htaccess ==="
cat > "$SITE_DIR/admin/.htaccess" << 'EOF'
<IfModule mod_php.c>
    php_value session.save_path "/www/server/php/session/taozhi"
    php_value session.cookie_path "/"
</IfModule>
EOF
echo "✅ .htaccess 已创建于 $SITE_DIR/admin/.htaccess"

echo ""
echo "=== 如果 .htaccess 不生效，在宝塔面板操作 ==="
echo "1. 打开『软件商店』→『PHP-7.4』→『配置』"
echo "2. 找到『session.save_path』，改为: /www/server/php/session/taozhi"
echo "3. 保存并重启 PHP"
echo ""
echo "完成后访问: https://taozhi.433345.xyz/admin/debug_session.php 验证"
