<?php
declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';

use Tekservix\SmsGora\SmsGora;
use Tekservix\SmsGora\SmsGoraException;

$fails = 0;
function check(string $name, bool $ok): void { global $fails; echo ($ok ? 'ok   ' : 'FAIL ') . $name . PHP_EOL; if (!$ok) $fails++; }

$last = null;
$reply = [200, [], '{"success":true,"data":{"ok":true}}'];
$handler = function ($m, $u, $h, $b) use (&$last, &$reply) { $last = [$m, $u, $h, $b === null ? null : json_decode($b, true)]; return $reply; };
$c = new SmsGora('sgk_test', ['baseUrl' => 'https://api.test/', 'handler' => $handler]);

check('send returns data', $c->sendSms('+1555', 'hi') === ['ok' => true]);
check('send path', $last[0] === 'POST' && $last[1] === 'https://api.test/send-sms');
check('api key header', $last[2]['x-api-key'] === 'sgk_test');
check('send body', $last[3] === ['recipients' => ['+1555'], 'message' => 'hi']);

$c->sendSms(['+1', '+2'], 'x', ['deviceId' => 42, 'simSlot' => 1, 'scheduleAt' => new DateTimeImmutable('2026-12-01T08:00:00Z')]);
check('device path', $last[1] === 'https://api.test/devices/42/send-sms');
check('sim + schedule', $last[3]['sim_slot'] === 1 && $last[3]['schedule_at'] === '2026-12-01T08:00:00+00:00');

$c->sendBulkSms(42, [['recipients' => ['+1']]], 't');
check('bulk body', $last[3] === ['messages' => [['recipients' => ['+1']]], 'messageTemplate' => 't']);
$c->listReceivedSms(['device_id' => 3, 'limit' => 5, 'since' => null]);
check('received path', $last[1] === 'https://api.test/devices/3/get-received-sms?limit=5');
$c->listMessages(['status' => 'sent']);
check('list query', $last[1] === 'https://api.test/messages?status=sent');
$c->deleteWebhook(9);
check('delete webhook', $last[0] === 'DELETE' && $last[1] === 'https://api.test/webhooks/9');

$reply = [429, ['retry-after' => '7'], '{"success":false,"message":"Too many requests"}'];
try { $c->getAccount(); check('429 throws', false); }
catch (SmsGoraException $e) { check('429 details', $e->status === 429 && $e->retryAfter === 7.0 && $e->getMessage() === 'Too many requests'); }

$raw = '{"event":"sms.received"}';
$sig = 'sha256=' . hash_hmac('sha256', $raw, 's3cret');
check('webhook valid', SmsGora::verifyWebhook($raw, $sig, 's3cret'));
check('webhook wrong secret', !SmsGora::verifyWebhook($raw, $sig, 'nope'));
check('webhook missing sig', !SmsGora::verifyWebhook($raw, null, 's3cret'));

try { new SmsGora(''); check('empty key throws', false); } catch (InvalidArgumentException $e) { check('empty key throws', true); }

echo $fails ? "$fails failed\n" : "all passed\n";
exit($fails ? 1 : 0);
