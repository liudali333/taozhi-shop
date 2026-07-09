<?php
/**
 * 微信配置（小程序登录 + 微信支付）
 *
 * 合并原 wechat_config.php（登录） + wechat_pay_config.php（支付）
 * 部署后请删除服务器上的 wechat_pay_config.php
 *
 * 使用方式：
 *   $config = require __DIR__ . '/../wechat_config.php';
 *   $appid  = $config['appid'];
 *   $mchId  = $config['mch_id'];
 */

return [

    // ==================== 小程序登录配置 ====================
    // 注意：键名必须为 'appid' 和 'secret'，user.php 中已硬编码
    'appid'  => 'wxe0c704fd57a2f502',     // ← 替换成真实小程序 AppID
    'secret' => 'ea7bb4ad2df1ab819836c3096e8f5bd2', // ← 替换成真实小程序 AppSecret

    // ==================== 微信支付配置 ====================
    'mch_id'      => '1675212013',       // ← 替换成真实商户号
    'private_key' => __DIR__ . '/cert/apiclient_key.pem',   // pay.php 签名用（商户私钥）
    'serial_no'   => '2458EF043970D85AA1AB0D348D48EF09E5B6890A', // 证书序列号
    'api_key'     => 'Kx9mR3pL7nQwE2tY8aZ5sD4fH6jUvB1c',   // APIv2 密钥（商户平台「API安全」设置）
    'apiv3_key'   => 'Xy8Kp2mQw3Rt6LjN9zB5vC7hF4dG1sA0',   // APIv3 密钥

    // 证书路径（上传到服务器 php/cert/ 目录）
    'cert_path'          => __DIR__ . '/cert/apiclient_cert.pem',     // 商户证书
    'key_path'           => __DIR__ . '/cert/apiclient_key.pem',      // 商户私钥
    'platform_cert_path' => __DIR__ . '/cert/platform_cert.pem',      // 平台证书（微信支付下载）
    'public_key_path'    => __DIR__ . '/cert/platform_cert.pem',      // pay_notify 验签用（同上）

    // 支付通知回调地址
    'notify_url' => 'https://taozhi.433345.xyz/api/pay_notify.php',

    // ==================== 订阅消息模板 ====================
    // 微信公众平台 → 订阅消息 → 公共模板库 → 搜索「领取成功」或「优惠券」类
    // 申请后填入模板 ID（类似于 tmp_AbCdEf...）
    // 领取优惠券通知场景：用户扫码领取后、过期前提醒
    'coupon_subscribe_tpl' => 'mov_hroYwggIBcnKTa43bRLMGNA70gbn2-k-VlLfq9A',   // 优惠券到账提醒模板

    // ==================== 高德地图配置 ====================
    // 文档：https://lbs.amap.com/api/webservice/summary
    'amap_web_key' => 'fb277f76881983b28400d76343d67374',  // Web服务API Key（后端地址解析/距离计算）
    'amap_mini_key' => 'c6bc075b823845754545a2572c27457b',  // 微信小程序SDK Key

    // ==================== 达达配送配置 ====================
    // 达达开放平台：https://newopen.imdada.cn/
    'dada_app_key'    => 'dada8428afc730b101c',
    'dada_app_secret' => 'c55e666068b93a3b2847403284fe2482',
    'dada_source_id'  => '112420108',        // 联调商户编号
    'dada_store_no'   => 'af59533eb8954aa2', // 门店编号
    'dada_city_code'  => '010',              // 城市电话区号（⚠️ 请修改为你的城市）
    'dada_callback'   => 'https://taozhi.433345.xyz/api/dada_callback.php', // 达达回调地址

    // 环境开关
    'sandbox'     => true,   // ⚠️ 联调阶段用沙箱，正式上线改为 false
    'log_enabled' => true,
    'log_path'    => __DIR__ . '/logs/wechat.log',
];
