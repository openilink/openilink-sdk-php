<?php

declare(strict_types=1);

namespace OpenILink;

use JsonException;
use OpenILink\Exception\APIError;
use OpenILink\Exception\HTTPError;
use OpenILink\Exception\NoContextTokenException;
use OpenILink\Exception\RequestException;
use RuntimeException;

final class Client
{
    private const DEFAULT_LONG_POLL_TIMEOUT_MS = 35000;
    private const DEFAULT_API_TIMEOUT_MS = 15000;
    private const DEFAULT_CONFIG_TIMEOUT_MS = 10000;
    private const DEFAULT_CDN_TIMEOUT_MS = 60000;
    private const QR_LONG_POLL_TIMEOUT_MS = 35000;
    private const DEFAULT_LOGIN_TIMEOUT_SECONDS = 480;
    private const MAX_QR_REFRESH_COUNT = 3;
    private const MAX_CONSECUTIVE_FAILURES = 3;
    private const BACKOFF_DELAY_MS = 30000;
    private const RETRY_DELAY_MS = 2000;
    private const SESSION_EXPIRED_PAUSE_MS = 3600000;

    private string $baseUrl;
    private string $cdnBaseUrl;
    private string $token;
    private string $botType;
    private string $version;
    private string $routeTag;
    /**
     * @var null|callable(string, int): string
     */
    private $silkDecoder = null;

    /**
     * @var array<string, string>
     */
    private array $contextTokens = [];

    public function __construct(string $token = '', array $config = [])
    {
        $this->baseUrl = (string) ($config['base_url'] ?? Constants::DEFAULT_BASE_URL);
        $this->cdnBaseUrl = (string) ($config['cdn_base_url'] ?? Constants::DEFAULT_CDN_BASE_URL);
        $this->token = $token;
        $this->botType = (string) ($config['bot_type'] ?? Constants::DEFAULT_BOT_TYPE);
        $this->version = (string) ($config['version'] ?? '1.0.2');
        $this->routeTag = (string) ($config['route_tag'] ?? '');
        if (isset($config['silk_decoder']) && is_callable($config['silk_decoder'])) {
            $this->silkDecoder = $config['silk_decoder'];
        }
    }

    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    public function setBaseUrl(string $baseUrl): void
    {
        $this->baseUrl = $baseUrl;
    }

    public function getCdnBaseUrl(): string
    {
        return $this->cdnBaseUrl;
    }

    public function setCdnBaseUrl(string $cdnBaseUrl): void
    {
        $this->cdnBaseUrl = $cdnBaseUrl;
    }

    public function getToken(): string
    {
        return $this->token;
    }

    public function setToken(string $token): void
    {
        $this->token = $token;
    }

    public function getBotType(): string
    {
        return $this->botType;
    }

    public function setBotType(string $botType): void
    {
        $this->botType = $botType;
    }

    public function getVersion(): string
    {
        return $this->version;
    }

    public function setVersion(string $version): void
    {
        $this->version = $version;
    }

    public function getRouteTag(): string
    {
        return $this->routeTag;
    }

    public function setRouteTag(string $routeTag): void
    {
        $this->routeTag = $routeTag;
    }

    /**
     * @param callable(string, int): string $silkDecoder
     */
    public function setSilkDecoder(callable $silkDecoder): void
    {
        $this->silkDecoder = $silkDecoder;
    }

    public function getUpdates(string $getUpdatesBuf = '', ?int $timeoutMs = null): array
    {
        $request = [
            'get_updates_buf' => $getUpdatesBuf,
            'base_info' => $this->buildBaseInfo(),
        ];

        try {
            $body = $this->doPost('ilink/bot/getupdates', $request, $timeoutMs ?? self::DEFAULT_LONG_POLL_TIMEOUT_MS);
        } catch (RequestException $exception) {
            if ($exception->isTimeout()) {
                return [
                    'ret' => 0,
                    'msgs' => [],
                    'get_updates_buf' => $getUpdatesBuf,
                ];
            }

            throw $exception;
        }

        return $this->decodeJson($body, 'getUpdates');
    }

    public function sendMessage(array $message): void
    {
        $this->doPost(
            'ilink/bot/sendmessage',
            [
                'msg' => $message,
                'base_info' => $this->buildBaseInfo(),
            ],
            self::DEFAULT_API_TIMEOUT_MS,
        );
    }

    public function sendText(string $to, string $text, string $contextToken): string
    {
        $clientId = $this->generateClientId();

        $this->sendMessage([
            'to_user_id' => $to,
            'client_id' => $clientId,
            'message_type' => Constants::MESSAGE_TYPE_BOT,
            'message_state' => Constants::MESSAGE_STATE_FINISH,
            'context_token' => $contextToken,
            'item_list' => [
                [
                    'type' => Constants::ITEM_TYPE_TEXT,
                    'text_item' => [
                        'text' => $text,
                    ],
                ],
            ],
        ]);

        return $clientId;
    }

    public function getConfig(string $userId, string $contextToken): array
    {
        $body = $this->doPost(
            'ilink/bot/getconfig',
            [
                'ilink_user_id' => $userId,
                'context_token' => $contextToken,
                'base_info' => $this->buildBaseInfo(),
            ],
            self::DEFAULT_CONFIG_TIMEOUT_MS,
        );

        return $this->decodeJson($body, 'getConfig');
    }

    public function sendTyping(string $userId, string $typingTicket, int $status): void
    {
        $this->doPost(
            'ilink/bot/sendtyping',
            [
                'ilink_user_id' => $userId,
                'typing_ticket' => $typingTicket,
                'status' => $status,
                'base_info' => $this->buildBaseInfo(),
            ],
            self::DEFAULT_CONFIG_TIMEOUT_MS,
        );
    }

    public function getUploadUrl(array $request): array
    {
        $request['base_info'] = $this->buildBaseInfo();

        $body = $this->doPost('ilink/bot/getuploadurl', $request, self::DEFAULT_API_TIMEOUT_MS);

        return $this->decodeJson($body, 'getUploadUrl');
    }

    public function fetchQRCode(): array
    {
        $query = http_build_query([
            'bot_type' => $this->botType !== '' ? $this->botType : Constants::DEFAULT_BOT_TYPE,
        ]);

        $body = $this->doGet(
            $this->buildUrl('ilink/bot/get_bot_qrcode') . '?' . $query,
            $this->routeTagHeaders(),
            self::DEFAULT_API_TIMEOUT_MS,
        );

        return $this->decodeJson($body, 'fetchQRCode');
    }

    public function pollQRStatus(string $qrcode): array
    {
        $query = http_build_query(['qrcode' => $qrcode]);

        try {
            $body = $this->doGet(
                $this->buildUrl('ilink/bot/get_qrcode_status') . '?' . $query,
                $this->routeTagHeaders() + ['iLink-App-ClientVersion' => '1'],
                self::QR_LONG_POLL_TIMEOUT_MS,
            );
        } catch (RequestException $exception) {
            if ($exception->isTimeout()) {
                return ['status' => 'wait'];
            }

            throw $exception;
        }

        return $this->decodeJson($body, 'pollQRStatus');
    }

    /**
     * @param array{
     *     on_qrcode?: callable(string): void,
     *     on_scanned?: callable(): void,
     *     on_expired?: callable(int, int): void
     * } $callbacks
     */
    public function loginWithQr(array $callbacks = [], ?int $timeoutSeconds = null): array
    {
        $deadline = microtime(true) + ($timeoutSeconds ?? self::DEFAULT_LOGIN_TIMEOUT_SECONDS);
        $qr = $this->fetchQRCode();
        $currentQr = (string) ($qr['qrcode'] ?? '');

        $this->invokeCallback($callbacks['on_qrcode'] ?? null, (string) ($qr['qrcode_img_content'] ?? ''));

        $scannedNotified = false;
        $refreshCount = 1;

        while (microtime(true) <= $deadline) {
            $status = $this->pollQRStatus($currentQr);

            switch ((string) ($status['status'] ?? 'wait')) {
                case 'scaned':
                    if (!$scannedNotified) {
                        $scannedNotified = true;
                        $this->invokeCallback($callbacks['on_scanned'] ?? null);
                    }
                    break;

                case 'expired':
                    $refreshCount++;
                    if ($refreshCount > self::MAX_QR_REFRESH_COUNT) {
                        return [
                            'connected' => false,
                            'message' => 'QR code expired too many times',
                        ];
                    }

                    $this->invokeCallback($callbacks['on_expired'] ?? null, $refreshCount, self::MAX_QR_REFRESH_COUNT);

                    $qr = $this->fetchQRCode();
                    $currentQr = (string) ($qr['qrcode'] ?? '');
                    $scannedNotified = false;
                    $this->invokeCallback($callbacks['on_qrcode'] ?? null, (string) ($qr['qrcode_img_content'] ?? ''));
                    break;

                case 'confirmed':
                    $botId = (string) ($status['ilink_bot_id'] ?? '');
                    if ($botId === '') {
                        return [
                            'connected' => false,
                            'message' => 'server did not return bot ID',
                        ];
                    }

                    $this->token = (string) ($status['bot_token'] ?? '');
                    if (!empty($status['baseurl'])) {
                        $this->baseUrl = (string) $status['baseurl'];
                    }

                    return [
                        'connected' => true,
                        'bot_token' => (string) ($status['bot_token'] ?? ''),
                        'bot_id' => $botId,
                        'base_url' => (string) ($status['baseurl'] ?? ''),
                        'user_id' => (string) ($status['ilink_user_id'] ?? ''),
                        'message' => 'connected',
                    ];
            }

            $this->sleepMilliseconds(1000);
        }

        return [
            'connected' => false,
            'message' => 'login timeout',
        ];
    }

    /**
     * @param callable(array): void $handler
     * @param array{
     *     initial_buf?: string,
     *     on_buf_update?: callable(string): void,
     *     on_error?: callable(\Throwable): void,
     *     on_session_expired?: callable(): void,
     *     should_continue?: callable(): bool
     * } $options
     */
    public function monitor(callable $handler, array $options = []): void
    {
        $buf = (string) ($options['initial_buf'] ?? '');
        $failures = 0;
        $nextTimeoutMs = null;

        $onError = $options['on_error'] ?? static function (\Throwable $exception): void {
        };

        while ($this->shouldContinue($options['should_continue'] ?? null)) {
            try {
                $response = $this->getUpdates($buf, $nextTimeoutMs);
            } catch (\Throwable $exception) {
                $failures++;
                $onError(
                    new RuntimeException(
                        sprintf('getUpdates (%d/%d): %s', $failures, self::MAX_CONSECUTIVE_FAILURES, $exception->getMessage()),
                        0,
                        $exception,
                    ),
                );

                if ($failures >= self::MAX_CONSECUTIVE_FAILURES) {
                    $failures = 0;
                    $this->sleepMilliseconds(self::BACKOFF_DELAY_MS, $options['should_continue'] ?? null);
                } else {
                    $this->sleepMilliseconds(self::RETRY_DELAY_MS, $options['should_continue'] ?? null);
                }

                continue;
            }

            $longPollingTimeoutMs = (int) ($response['longpolling_timeout_ms'] ?? 0);
            if ($longPollingTimeoutMs > 0) {
                $nextTimeoutMs = $longPollingTimeoutMs;
            }

            $ret = (int) ($response['ret'] ?? 0);
            $errCode = (int) ($response['errcode'] ?? 0);

            if ($ret !== 0 || $errCode !== 0) {
                $apiError = new APIError($ret, $errCode, (string) ($response['errmsg'] ?? ''));

                if ($apiError->isSessionExpired()) {
                    $this->invokeCallback($options['on_session_expired'] ?? null);
                    $onError($apiError);
                    $failures = 0;
                    $this->sleepMilliseconds(self::SESSION_EXPIRED_PAUSE_MS, $options['should_continue'] ?? null);
                    continue;
                }

                $failures++;
                $onError(
                    new RuntimeException(
                        sprintf('getUpdates (%d/%d): %s', $failures, self::MAX_CONSECUTIVE_FAILURES, $apiError->getMessage()),
                        0,
                        $apiError,
                    ),
                );

                if ($failures >= self::MAX_CONSECUTIVE_FAILURES) {
                    $failures = 0;
                    $this->sleepMilliseconds(self::BACKOFF_DELAY_MS, $options['should_continue'] ?? null);
                } else {
                    $this->sleepMilliseconds(self::RETRY_DELAY_MS, $options['should_continue'] ?? null);
                }

                continue;
            }

            $failures = 0;

            if (!empty($response['get_updates_buf'])) {
                $buf = (string) $response['get_updates_buf'];
                $this->invokeCallback($options['on_buf_update'] ?? null, $buf);
            }

            foreach (($response['msgs'] ?? []) as $message) {
                if (!empty($message['context_token']) && !empty($message['from_user_id'])) {
                    $this->setContextToken((string) $message['from_user_id'], (string) $message['context_token']);
                }

                $handler($message);
            }
        }
    }

    /**
     * @return array{
     *     file_key: string,
     *     download_encrypted_query_param: string,
     *     aes_key: string,
     *     file_size: int,
     *     ciphertext_size: int
     * }
     */
    public function uploadFile(string $plaintext, string $toUserId, int $mediaType): array
    {
        $rawSize = strlen($plaintext);
        $rawMd5 = md5($plaintext);
        $fileSize = Cdn::aesEcbPaddedSize($rawSize);
        $fileKey = $this->randomHex(16);
        $aesKey = random_bytes(16);

        $uploadResponse = $this->getUploadUrl([
            'filekey' => $fileKey,
            'media_type' => $mediaType,
            'to_user_id' => $toUserId,
            'rawsize' => $rawSize,
            'rawfilemd5' => $rawMd5,
            'filesize' => $fileSize,
            'no_need_thumb' => true,
            'aeskey' => bin2hex($aesKey),
        ]);

        if ((int) ($uploadResponse['ret'] ?? 0) !== 0) {
            throw new APIError((int) ($uploadResponse['ret'] ?? 0), 0, (string) ($uploadResponse['errmsg'] ?? ''));
        }

        $uploadParam = (string) ($uploadResponse['upload_param'] ?? '');
        if ($uploadParam === '') {
            throw new RuntimeException('ilink: getUploadUrl returned no upload_param');
        }

        $ciphertext = Cdn::encryptAesEcb($plaintext, $aesKey);
        $downloadParam = $this->uploadToCDN(Cdn::buildUploadUrl($this->cdnBaseUrl, $uploadParam, $fileKey), $ciphertext);

        return [
            'file_key' => $fileKey,
            'download_encrypted_query_param' => $downloadParam,
            'aes_key' => bin2hex($aesKey),
            'file_size' => $rawSize,
            'ciphertext_size' => strlen($ciphertext),
        ];
    }

    /**
     * @param array{
     *     download_encrypted_query_param: string,
     *     aes_key: string,
     *     file_size: int,
     *     ciphertext_size: int
     * } $uploaded
     */
    public function sendImage(string $to, string $contextToken, array $uploaded): string
    {
        $clientId = $this->generateClientId();

        $this->sendMessage([
            'to_user_id' => $to,
            'client_id' => $clientId,
            'message_type' => Constants::MESSAGE_TYPE_BOT,
            'message_state' => Constants::MESSAGE_STATE_FINISH,
            'context_token' => $contextToken,
            'item_list' => [
                [
                    'type' => Constants::ITEM_TYPE_IMAGE,
                    'image_item' => [
                        'media' => [
                            'encrypt_query_param' => (string) $uploaded['download_encrypted_query_param'],
                            'aes_key' => Cdn::mediaAesKeyHex((string) $uploaded['aes_key']),
                            'encrypt_type' => 1,
                        ],
                        'mid_size' => (int) $uploaded['ciphertext_size'],
                    ],
                ],
            ],
        ]);

        return $clientId;
    }

    /**
     * @param array{
     *     download_encrypted_query_param: string,
     *     aes_key: string,
     *     file_size: int,
     *     ciphertext_size: int
     * } $uploaded
     */
    public function sendVideo(string $to, string $contextToken, array $uploaded): string
    {
        $clientId = $this->generateClientId();

        $this->sendMessage([
            'to_user_id' => $to,
            'client_id' => $clientId,
            'message_type' => Constants::MESSAGE_TYPE_BOT,
            'message_state' => Constants::MESSAGE_STATE_FINISH,
            'context_token' => $contextToken,
            'item_list' => [
                [
                    'type' => Constants::ITEM_TYPE_VIDEO,
                    'video_item' => [
                        'media' => [
                            'encrypt_query_param' => (string) $uploaded['download_encrypted_query_param'],
                            'aes_key' => Cdn::mediaAesKeyHex((string) $uploaded['aes_key']),
                            'encrypt_type' => 1,
                        ],
                        'video_size' => (int) $uploaded['ciphertext_size'],
                    ],
                ],
            ],
        ]);

        return $clientId;
    }

    /**
     * @param array{
     *     download_encrypted_query_param: string,
     *     aes_key: string,
     *     file_size: int,
     *     ciphertext_size: int
     * } $uploaded
     */
    public function sendFileAttachment(string $to, string $contextToken, string $fileName, array $uploaded): string
    {
        $clientId = $this->generateClientId();

        $this->sendMessage([
            'to_user_id' => $to,
            'client_id' => $clientId,
            'message_type' => Constants::MESSAGE_TYPE_BOT,
            'message_state' => Constants::MESSAGE_STATE_FINISH,
            'context_token' => $contextToken,
            'item_list' => [
                [
                    'type' => Constants::ITEM_TYPE_FILE,
                    'file_item' => [
                        'media' => [
                            'encrypt_query_param' => (string) $uploaded['download_encrypted_query_param'],
                            'aes_key' => Cdn::mediaAesKeyHex((string) $uploaded['aes_key']),
                            'encrypt_type' => 1,
                        ],
                        'file_name' => $fileName,
                        'len' => (string) $uploaded['file_size'],
                    ],
                ],
            ],
        ]);

        return $clientId;
    }

    public function sendMediaFile(string $to, string $contextToken, string $data, string $fileName, string $caption = ''): void
    {
        $mime = Mime::mimeFromFilename($fileName);
        $mediaType = Constants::MEDIA_FILE;

        if (Mime::isVideoMime($mime)) {
            $mediaType = Constants::MEDIA_VIDEO;
        } elseif (Mime::isImageMime($mime)) {
            $mediaType = Constants::MEDIA_IMAGE;
        }

        $uploaded = $this->uploadFile($data, $to, $mediaType);

        if ($caption !== '') {
            $this->sendText($to, $caption, $contextToken);
        }

        if (Mime::isVideoMime($mime)) {
            $this->sendVideo($to, $contextToken, $uploaded);
            return;
        }

        if (Mime::isImageMime($mime)) {
            $this->sendImage($to, $contextToken, $uploaded);
            return;
        }

        $this->sendFileAttachment($to, $contextToken, basename($fileName), $uploaded);
    }

    public function downloadFile(string $encryptedQueryParam, string $aesKeyBase64): string
    {
        $key = Cdn::parseAESKey($aesKeyBase64);
        $ciphertext = $this->downloadRaw($encryptedQueryParam);

        return Cdn::decryptAesEcb($ciphertext, $key);
    }

    public function downloadRaw(string $encryptedQueryParam): string
    {
        $response = $this->request(
            'GET',
            Cdn::buildDownloadUrl($this->cdnBaseUrl, $encryptedQueryParam),
            [],
            null,
            self::DEFAULT_CDN_TIMEOUT_MS,
        );

        return $response['body'];
    }

    public function downloadVoice(?array $media): string
    {
        if ($this->silkDecoder === null) {
            throw new RuntimeException('ilink: no SILK decoder configured; use config["silk_decoder"] or setSilkDecoder()');
        }

        if ($media === null) {
            throw new RuntimeException('ilink: voice media is nil');
        }

        $encryptedQueryParam = (string) ($media['encrypt_query_param'] ?? '');
        $aesKey = (string) ($media['aes_key'] ?? '');
        $silkData = $this->downloadFile($encryptedQueryParam, $aesKey);
        $pcm = ($this->silkDecoder)($silkData, Voice::DEFAULT_SAMPLE_RATE);

        return Voice::buildWav($pcm, Voice::DEFAULT_SAMPLE_RATE, 1, 16);
    }

    public function setContextToken(string $userId, string $token): void
    {
        $this->contextTokens[$userId] = $token;
    }

    public function getContextToken(string $userId): ?string
    {
        return $this->contextTokens[$userId] ?? null;
    }

    public function push(string $to, string $text): string
    {
        $token = $this->getContextToken($to);
        if ($token === null || $token === '') {
            throw new NoContextTokenException();
        }

        return $this->sendText($to, $text, $token);
    }

    private function buildBaseInfo(): array
    {
        return [
            'channel_version' => $this->version,
        ];
    }

    private function buildUrl(string $endpoint): string
    {
        return rtrim($this->baseUrl, '/') . '/' . ltrim($endpoint, '/');
    }

    /**
     * @param array<string, string> $extraHeaders
     * @return array<string, string>
     */
    private function buildHeaders(?string $body = null, array $extraHeaders = []): array
    {
        $headers = [
            'AuthorizationType' => 'ilink_bot_token',
            'X-WECHAT-UIN' => $this->randomWechatUin(),
        ] + $extraHeaders;

        if ($body !== null) {
            $headers['Content-Length'] = (string) strlen($body);
        }

        if ($this->token !== '') {
            $headers['Authorization'] = 'Bearer ' . $this->token;
        }

        if ($this->routeTag !== '') {
            $headers['SKRouteTag'] = $this->routeTag;
        }

        return $headers;
    }

    /**
     * @return array<string, string>
     */
    private function routeTagHeaders(): array
    {
        if ($this->routeTag === '') {
            return [];
        }

        return ['SKRouteTag' => $this->routeTag];
    }

    private function doPost(string $endpoint, array $payload, int $timeoutMs): string
    {
        try {
            $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Failed to encode request body: ' . $exception->getMessage(), 0, $exception);
        }

        $response = $this->request(
            'POST',
            $this->buildUrl($endpoint),
            $this->buildHeaders($json, ['Content-Type' => 'application/json']),
            $json,
            $timeoutMs,
        );

        return $response['body'];
    }

    private function doGet(string $url, array $headers, int $timeoutMs): string
    {
        $response = $this->request('GET', $url, $headers, null, $timeoutMs);

        return $response['body'];
    }

    /**
     * @return array{status_code: int, body: string, headers: array<string, string>}
     */
    private function request(string $method, string $url, array $headers, ?string $body, int $timeoutMs): array
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('ext-curl is required.');
        }

        $curl = curl_init($url);
        if ($curl === false) {
            throw new RuntimeException('Failed to initialize cURL.');
        }

        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }

        $responseHeaders = [];
        $options = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT_MS => $timeoutMs,
            CURLOPT_CONNECTTIMEOUT_MS => min($timeoutMs, 10000),
            CURLOPT_NOSIGNAL => true,
            CURLOPT_HEADERFUNCTION => static function ($curlHandle, string $headerLine) use (&$responseHeaders): int {
                $length = strlen($headerLine);
                $trimmed = trim($headerLine);

                if ($trimmed === '' || str_starts_with($trimmed, 'HTTP/')) {
                    return $length;
                }

                $parts = explode(':', $headerLine, 2);
                if (count($parts) !== 2) {
                    return $length;
                }

                $name = strtolower(trim($parts[0]));
                $value = trim($parts[1]);
                if ($name !== '') {
                    $responseHeaders[$name] = isset($responseHeaders[$name])
                        ? $responseHeaders[$name] . ', ' . $value
                        : $value;
                }

                return $length;
            },
        ];

        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = $body;
        }

        curl_setopt_array($curl, $options);

        $responseBody = curl_exec($curl);
        $curlErrno = curl_errno($curl);
        $curlError = curl_error($curl);
        $statusCode = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);

        curl_close($curl);

        if ($responseBody === false) {
            throw new RequestException('HTTP request failed: ' . $curlError, null, null, $curlErrno);
        }

        $responseBody = (string) $responseBody;

        if ($statusCode < 200 || $statusCode >= 300) {
            throw new HTTPError($statusCode, $responseBody, $responseHeaders);
        }

        return [
            'status_code' => $statusCode,
            'body' => $responseBody,
            'headers' => $responseHeaders,
        ];
    }

    private function uploadToCDN(string $cdnUrl, string $ciphertext): string
    {
        $lastException = null;

        for ($attempt = 1; $attempt <= Cdn::UPLOAD_MAX_RETRIES; $attempt++) {
            try {
                return $this->doUpload($cdnUrl, $ciphertext);
            } catch (\Throwable $exception) {
                $lastException = $exception;

                if ($exception instanceof HTTPError) {
                    $statusCode = $exception->getStatusCode();
                    if ($statusCode >= 400 && $statusCode < 500) {
                        throw $exception;
                    }
                }

                if ($attempt < Cdn::UPLOAD_MAX_RETRIES) {
                    $this->sleepMilliseconds(self::RETRY_DELAY_MS);
                }
            }
        }

        throw new RuntimeException(
            sprintf(
                'ilink: cdn upload failed after %d attempts: %s',
                Cdn::UPLOAD_MAX_RETRIES,
                $lastException instanceof \Throwable ? $lastException->getMessage() : 'unknown error',
            ),
            0,
            $lastException,
        );
    }

    private function doUpload(string $cdnUrl, string $ciphertext): string
    {
        $response = $this->request(
            'POST',
            $cdnUrl,
            $this->buildHeaders($ciphertext, ['Content-Type' => 'application/octet-stream']),
            $ciphertext,
            self::DEFAULT_CDN_TIMEOUT_MS,
        );

        $downloadParam = $response['headers']['x-encrypted-param'] ?? '';
        if ($downloadParam === '') {
            throw new RuntimeException('ilink: cdn response missing x-encrypted-param header');
        }

        return $downloadParam;
    }

    private function decodeJson(string $body, string $operation): array
    {
        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException(
                sprintf('Failed to decode %s response: %s', $operation, $exception->getMessage()),
                0,
                $exception,
            );
        }

        if (!is_array($decoded)) {
            throw new RuntimeException(sprintf('%s response is not a JSON object.', $operation));
        }

        return $decoded;
    }

    private function randomWechatUin(): string
    {
        $parts = unpack('Nvalue', random_bytes(4));
        $value = isset($parts['value']) ? sprintf('%u', $parts['value']) : '0';

        return base64_encode($value);
    }

    private function generateClientId(): string
    {
        return sprintf('sdk-%d-%s', $this->nowMillis(), bin2hex(random_bytes(4)));
    }

    private function nowMillis(): int
    {
        return (int) floor(microtime(true) * 1000);
    }

    private function randomHex(int $bytes): string
    {
        return bin2hex(random_bytes($bytes));
    }

    private function invokeCallback(?callable $callback, mixed ...$args): void
    {
        if ($callback !== null) {
            $callback(...$args);
        }
    }

    private function shouldContinue(?callable $callback): bool
    {
        if ($callback === null) {
            return true;
        }

        return (bool) $callback();
    }

    private function sleepMilliseconds(int $milliseconds, ?callable $shouldContinue = null): void
    {
        $target = microtime(true) + ($milliseconds / 1000);

        while (microtime(true) < $target) {
            if ($shouldContinue !== null && !$this->shouldContinue($shouldContinue)) {
                return;
            }

            usleep(250000);
        }
    }
}
