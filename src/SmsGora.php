<?php

declare(strict_types=1);

namespace Tekservix\SmsGora;

/**
 * Client for the SMS Gora SMS Gateway API.
 *
 * Docs: https://tekservix.com/smsgora/docs/
 */
class SmsGora
{
    public const VERSION = '1.0.0';
    public const DEFAULT_BASE_URL = 'https://tekservix.com/smsgora/api/v1';

    private string $apiKey;
    private string $baseUrl;
    private int $timeout;
    /** @var callable|null */
    private $handler;

    /**
     * @param string $apiKey Your SMS Gora API key (starts with sgk_). Keep it on your server.
     * @param array{baseUrl?: string, timeout?: int, handler?: callable} $options
     *        handler: optional fn(string $method, string $url, array $headers, ?string $body): array{0:int,1:array,2:string}
     */
    public function __construct(string $apiKey, array $options = [])
    {
        if ($apiKey === '') {
            throw new \InvalidArgumentException('SmsGora: apiKey is required (create one in SMS Gora -> API keys)');
        }
        $this->apiKey = $apiKey;
        $this->baseUrl = rtrim($options['baseUrl'] ?? self::DEFAULT_BASE_URL, '/');
        $this->timeout = (int) ($options['timeout'] ?? 30);
        $this->handler = $options['handler'] ?? null;
    }

    /**
     * Call any documented endpoint, e.g. request('GET', '/devices'). Returns the `data` field.
     *
     * @param array<string, mixed>|null $body
     * @return mixed
     * @throws SmsGoraException
     */
    public function request(string $method, string $path, ?array $body = null)
    {
        $headers = [
            'x-api-key' => $this->apiKey,
            'Accept' => 'application/json',
            'User-Agent' => 'smsgora-php/' . self::VERSION,
        ];
        $payload = null;
        if ($body !== null) {
            $payload = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $headers['Content-Type'] = 'application/json';
        }

        [$status, $resHeaders, $text] = $this->handler
            ? ($this->handler)($method, $this->baseUrl . $path, $headers, $payload)
            : $this->send($method, $this->baseUrl . $path, $headers, $payload);

        $json = $text !== '' ? json_decode($text, true) : null;
        if ($status >= 400 || (is_array($json) && ($json['success'] ?? null) === false)) {
            $retry = $resHeaders['retry-after'] ?? null;
            throw new SmsGoraException(
                (is_array($json) && isset($json['message'])) ? (string) $json['message'] : 'HTTP ' . $status,
                $status,
                is_array($json) ? ($json['errors'] ?? null) : null,
                $json ?? $text,
                $retry !== null ? (float) $retry : null
            );
        }
        return (is_array($json) && array_key_exists('data', $json)) ? $json['data'] : $json;
    }

    /**
     * Send an SMS to one number or up to 1,000. Picks your best online phone unless deviceId is given.
     *
     * @param string|string[] $to
     * @param array{deviceId?: int|string, simSlot?: int, scheduleAt?: string|\DateTimeInterface} $options
     *        simSlot: 0 = SIM 1, 1 = SIM 2
     * @return mixed
     */
    public function sendSms($to, string $message, array $options = [])
    {
        $body = ['recipients' => is_array($to) ? array_values($to) : [$to], 'message' => $message];
        if (isset($options['simSlot'])) {
            $body['sim_slot'] = $options['simSlot'];
        }
        if (!empty($options['scheduleAt'])) {
            $at = $options['scheduleAt'];
            $body['schedule_at'] = $at instanceof \DateTimeInterface ? $at->format(DATE_ATOM) : $at;
        }
        $path = !empty($options['deviceId'])
            ? '/devices/' . rawurlencode((string) $options['deviceId']) . '/send-sms'
            : '/send-sms';
        return $this->request('POST', $path, $body);
    }

    /**
     * Different texts for different people in one call (up to 5,000 recipients).
     *
     * @param array<int, array{recipients: string[], message?: string}> $messages
     * @return mixed
     */
    public function sendBulkSms($deviceId, array $messages, ?string $messageTemplate = null)
    {
        $body = ['messages' => $messages];
        if ($messageTemplate !== null) {
            $body['messageTemplate'] = $messageTemplate;
        }
        return $this->request('POST', '/devices/' . rawurlencode((string) $deviceId) . '/send-bulk-sms', $body);
    }

    /** Status is queued, dispatched, sent, delivered, failed or cancelled. */
    public function getMessage($id)
    {
        return $this->request('GET', '/messages/' . rawurlencode((string) $id));
    }

    /** Cancel a queued or scheduled message. */
    public function cancelMessage($id)
    {
        return $this->request('POST', '/messages/' . rawurlencode((string) $id) . '/cancel');
    }

    /** Filters: direction (in/out), status, device_id, phone, since, after_id, page, limit. */
    public function listMessages(array $filters = [])
    {
        return $this->request('GET', '/messages' . self::query($filters));
    }

    /** Incoming SMS for all phones, or one phone with the device_id filter. */
    public function listReceivedSms(array $filters = [])
    {
        $deviceId = $filters['device_id'] ?? null;
        unset($filters['device_id']);
        $path = $deviceId ? '/devices/' . rawurlencode((string) $deviceId) . '/get-received-sms' : '/received-sms';
        return $this->request('GET', $path . self::query($filters));
    }

    public function getBatch($id)
    {
        return $this->request('GET', '/batches/' . rawurlencode((string) $id));
    }

    /** Your linked Android phones. */
    public function listDevices()
    {
        return $this->request('GET', '/devices');
    }

    public function getDevice($id)
    {
        return $this->request('GET', '/devices/' . rawurlencode((string) $id));
    }

    /** Account, plan and usage. */
    public function getAccount()
    {
        return $this->request('GET', '/account');
    }

    public function listWebhooks()
    {
        return $this->request('GET', '/webhooks');
    }

    /** Register a webhook. The answer includes the secret (only returned once). */
    public function createWebhook(string $url, array $events = ['sms.received'], $deviceId = null)
    {
        return $this->request('POST', '/webhooks', ['url' => $url, 'events' => $events, 'device_id' => $deviceId]);
    }

    public function deleteWebhook($id)
    {
        return $this->request('DELETE', '/webhooks/' . rawurlencode((string) $id));
    }

    /**
     * Check the X-SmsGora-Signature header (sha256=<hex HMAC-SHA256 of the raw body>).
     *
     * @param string $rawBody   Raw request body, e.g. file_get_contents('php://input').
     */
    public static function verifyWebhook(string $rawBody, ?string $signature, string $secret): bool
    {
        if ($rawBody === '' || !$signature || $secret === '') {
            return false;
        }
        return hash_equals('sha256=' . hash_hmac('sha256', $rawBody, $secret), $signature);
    }

    private static function query(array $params): string
    {
        $params = array_filter($params, static fn ($v) => $v !== null && $v !== '');
        return $params ? '?' . http_build_query($params) : '';
    }

    /** @return array{0:int,1:array<string,string>,2:string} */
    private function send(string $method, string $url, array $headers, ?string $body): array
    {
        $lines = [];
        foreach ($headers as $k => $v) {
            $lines[] = $k . ': ' . $v;
        }
        $resHeaders = [];
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $lines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_HEADERFUNCTION => static function ($ch, $line) use (&$resHeaders) {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $resHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return strlen($line);
            },
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $text = curl_exec($ch);
        if ($text === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new SmsGoraException('SmsGora: request failed: ' . $error);
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return [$status, $resHeaders, (string) $text];
    }
}
