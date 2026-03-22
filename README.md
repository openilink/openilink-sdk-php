# openilink-sdk-php

`openilink-sdk-php` 是一个面向 OpenILink Bot API 的 PHP SDK，提供常用能力的轻量封装，便于在 PHP 项目中快速完成登录、收发消息和会话相关操作。

当前支持的核心能力：

- 二维码登录
- 长轮询接收消息
- 发送文本消息
- 发送图片、视频、文件消息
- 获取会话配置
- 发送打字状态
- CDN 上传下载与 AES-128-ECB 加解密
- 语音消息解码（可插拔 SILK 解码器 + WAV 封装）
- 获取上传 URL
- 缓存 `context_token` 并主动推送文本
- 结构化错误类型（`APIError`、`HTTPError`）

## 安装

```bash
composer require openilink/openilink-sdk-php
```

要求：

- PHP 8.1+
- `ext-curl`
- `ext-json`
- `ext-openssl`

## 快速开始

```php
<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use OpenILink\Client;
use OpenILink\MessageHelper;

$client = new Client('');

$result = $client->loginWithQr([
    'on_qrcode' => static function (string $url): void {
        echo "请扫码:\n{$url}\n";
    },
    'on_scanned' => static function (): void {
        echo "已扫码，请在微信中确认\n";
    },
]);

if (!($result['connected'] ?? false)) {
    throw new RuntimeException((string) ($result['message'] ?? '登录失败'));
}

$client->monitor(
    static function (array $message) use ($client): void {
        $text = MessageHelper::extractText($message);
        if ($text === '') {
            return;
        }

        $client->sendText(
            (string) $message['from_user_id'],
            '收到: ' . $text,
            (string) $message['context_token'],
        );
    }
);
```

完整示例见 [example/echo.php](./example/echo.php)。

## API

### 创建客户端

```php
$client = new Client($token, [
    'base_url' => 'https://ilinkai.weixin.qq.com',
    'cdn_base_url' => 'https://novac2c.cdn.weixin.qq.com/c2c',
    'bot_type' => '3',
    'version' => '1.0.2',
    'route_tag' => 'gray-a',
    'silk_decoder' => static function (string $silkData, int $sampleRate): string {
        return decodeSilkSomehow($silkData, $sampleRate);
    },
]);
```

### 登录

```php
$result = $client->loginWithQr([
    'on_qrcode' => static function (string $url): void {},
    'on_scanned' => static function (): void {},
    'on_expired' => static function (int $attempt, int $max): void {},
]);
```

返回值示例：

```php
[
    'connected' => true,
    'bot_token' => 'xxx',
    'bot_id' => 'xxx',
    'base_url' => 'https://...',
    'user_id' => 'xxx',
    'message' => 'connected',
]
```

### 收消息

```php
$response = $client->getUpdates($buf);
```

或直接进入监听循环：

```php
$client->monitor(
    static function (array $message): void {
        // 处理单条消息
    },
    [
        'initial_buf' => '',
        'on_buf_update' => static function (string $buf): void {},
        'on_error' => static function (Throwable $e): void {},
        'on_session_expired' => static function (): void {},
        'should_continue' => static function (): bool {
            return true;
        },
    ],
);
```

### 发消息

```php
$clientId = $client->sendText($toUserId, 'hello', $contextToken);
```

如果目标用户已经有缓存的 `context_token`：

```php
$clientId = $client->push($toUserId, 'hello');
```

发送媒体：

```php
$uploaded = $client->uploadFile($fileBytes, $toUserId, \OpenILink\Constants::MEDIA_IMAGE);
$client->sendImage($toUserId, $contextToken, $uploaded);

$client->sendMediaFile($toUserId, $contextToken, $fileBytes, 'report.pdf', '请查收');
```

### 工具方法

提取文本：

```php
$text = MessageHelper::extractText($message);
```

发送打字状态：

```php
$client->sendTyping($userId, $typingTicket, \OpenILink\Constants::TYPING);
```

获取上传 URL：

```php
$upload = $client->getUploadUrl([
    'filekey' => 'demo.jpg',
    'media_type' => \OpenILink\Constants::MEDIA_IMAGE,
    'to_user_id' => $toUserId,
    'rawsize' => 12345,
    'rawfilemd5' => '...',
    'filesize' => 12345,
    'no_need_thumb' => true,
    'aeskey' => '...',
]);
```

CDN 下载：

```php
$raw = $client->downloadRaw($encryptedQueryParam);
$plain = $client->downloadFile($encryptedQueryParam, $aesKeyBase64);
```

语音消息解码：

```php
use OpenILink\Client;
use OpenILink\Voice;

$client = new Client($token, [
    'silk_decoder' => static function (string $silkData, int $sampleRate): string {
        return decodeSilkSomehow($silkData, $sampleRate);
    },
]);

$wav = $client->downloadVoice($message['item_list'][0]['voice_item']['media'] ?? null);
$wrapped = Voice::buildWav($pcmBytes, 24000, 1, 16);
```

错误处理：

```php
use OpenILink\Exception\APIError;
use OpenILink\Exception\HTTPError;
use OpenILink\Exception\NoContextTokenException;

if ($error instanceof APIError && $error->isSessionExpired()) {
    // 重新登录
}

if ($error instanceof HTTPError) {
    echo $error->getStatusCode();
}

if ($error instanceof NoContextTokenException) {
    // 用户还没有 context_token
}
```
