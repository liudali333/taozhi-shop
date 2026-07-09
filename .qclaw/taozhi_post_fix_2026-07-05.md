# 桃之 - 修复微信小程序 POST 数据丢失问题 (2026-07-05 12:00)

## 问题
前端显示「登录失败 - 后端返回: 登录参数错误」，数据库无用户记录。

## 根因
微信小程序 `wx.request` POST 默认 Content-Type 是 `application/json`，
但 PHP `$_POST` 只能解析 `application/x-www-form-urlencoded`，
所以 `$_POST['code']` 是空字符串，触发 `if (!$code) { response(1, '登录参数错误') }`。

## 修复
在 `user.php` 和 `address.php` 顶部加 `parsePost()` 函数，
兼容 JSON 和表单两种格式，merge 到 `$_POST`。

```php
function parsePost() {
    if (!empty($_POST)) return $_POST;
    $input = file_get_contents('php://input');
    if (!$input) return [];
    $json = json_decode($input, true);
    if (is_array($json)) return $json;
    parse_str($input, $data);
    return is_array($data) ? $data : [];
}
$_POST = array_merge($_POST, parsePost());
```

## 待上传
- `php/api/user.php`
- `php/api/address.php`
