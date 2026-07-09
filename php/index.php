<?php
/**
 * 桃之小程序 - 自动跳转到后台管理
 */
$proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'taozhi.433345.xyz';
header('Location: ' . $proto . '://' . $host . '/admin/login.php');
exit;
