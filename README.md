# SMS Gora PHP client

PHP client for the [SMS Gora SMS Gateway](https://tekservix.com/smsgora/) API.

SMS Gora turns Android phones with ordinary SIM cards into an SMS gateway. Your server calls the API, a linked phone sends the text, and replies and delivery reports come back to you. There's no sender ID registration and no per-message fee from us: your mobile operator bills the texts like any SMS.

- PHP 7.4 to 8.4, no dependencies beyond the curl and json extensions
- Works in Laravel, Symfony, WordPress plugins and plain PHP
- Webhook signature check built in

## Install

```bash
composer require tekservix/smsgora
```

## Quick start

Create an API key in the SMS Gora dashboard (SMS Gora -> API keys) and keep it on your server.

```php
use Tekservix\SmsGora\SmsGora;

$sms = new SmsGora(getenv('SMSGORA_KEY'));

$result = $sms->sendSms('+15551234567', 'Your verification code is 482913');
print_r($result['message_ids']); // [1873]
```

## Sending

```php
// Several recipients (up to 1,000 per call)
$sms->sendSms(['+15551234567', '+447700900123'], 'Your order has shipped');

// From a chosen phone and SIM, scheduled for later
$sms->sendSms('+15551234567', 'Reminder: your appointment is tomorrow at 10:00', [
    'deviceId'   => 42,
    'simSlot'    => 1, // 0 = SIM 1, 1 = SIM 2
    'scheduleAt' => new DateTimeImmutable('2026-12-01 08:00:00 UTC'),
]);

// Different texts for different people in one call (up to 5,000 recipients)
$sms->sendBulkSms(42, [
    ['recipients' => ['+15551234567'], 'message' => 'Hi Aline, parcel #1042 is ready.'],
    ['recipients' => ['+15557654321']],
], 'Hello, your parcel is ready for pickup.');
```

## Delivery status and incoming SMS

```php
$msg = $sms->getMessage(1873);
echo $msg['status']; // queued -> dispatched -> sent -> delivered (or failed)

$sms->cancelMessage(1874); // works while queued or scheduled

$inbox   = $sms->listReceivedSms(['since' => '2026-11-01', 'limit' => 50]);
$phones  = $sms->listDevices();
$account = $sms->getAccount();
```

Anything else in the [API reference](https://tekservix.com/smsgora/docs/) is one call away:

```php
$batch = $sms->request('GET', '/batches/311');
```

## Webhooks

Add your HTTPS URL in SMS Gora -> Webhooks (or with `createWebhook()`) and keep the secret. Each request carries `X-SmsGora-Signature: sha256=...`, an HMAC-SHA256 of the raw body:

```php
use Tekservix\SmsGora\SmsGora;

$raw = file_get_contents('php://input');
if (!SmsGora::verifyWebhook($raw, $_SERVER['HTTP_X_SMSGORA_SIGNATURE'] ?? null, getenv('SMSGORA_WEBHOOK_SECRET'))) {
    http_response_code(401);
    exit;
}
http_response_code(200); // answer within 10 seconds; queue slow work

$event = json_decode($raw, true);
if ($event['event'] === 'sms.received') {
    error_log($event['data']['from'] . ': ' . $event['data']['message']);
}
```

In Laravel, use `$request->getContent()` and `$request->header('X-SmsGora-Signature')`, and exclude the route from CSRF checks.

## Errors

Failed calls throw `Tekservix\SmsGora\SmsGoraException` with `status`, `getMessage()`, field `errors` and, on 429, `retryAfter` in seconds.

```php
use Tekservix\SmsGora\SmsGoraException;

try {
    $sms->sendSms('+15551234567', 'Hi');
} catch (SmsGoraException $e) {
    if ($e->status === 429) {
        // rate limit (300 requests/minute per key) or plan limit reached
    }
}
```

Never put the key in JavaScript or a mobile app: anyone could read it. Call the API from your server.

## WordPress and WooCommerce

No code needed: the [SMS Gora WordPress plugin](https://tekservix.com/smsgora/docs/wordpress-plugin) sends WooCommerce order texts out of the box.

## Migrating from TextBee

SMS Gora accepts TextBee-style requests, so most integrations switch by changing the base URL and key. See the [compatibility notes](https://tekservix.com/smsgora/docs/).

## More from Tekservix

- [WAservix WhatsApp API Gateway](https://tekservix.com/waservix/): send WhatsApp messages from your own number (`composer require tekservix/waservix`)
- [AI Sales Agent](https://tekservix.com/ai-sales-agent): answers customers on WhatsApp and SMS using your website's content
- [Website to App](https://tekservix.com/mobile-apps): turn your website into Android and iOS apps
- Also for Node.js (`npm install smsgora`) and Python (`pip install smsgora`)

## License

MIT
