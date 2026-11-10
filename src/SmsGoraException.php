<?php

declare(strict_types=1);

namespace Tekservix\SmsGora;

/** Thrown when the SMS Gora API answers with an error. */
class SmsGoraException extends \RuntimeException
{
    /** @var array<string, string[]>|null */
    public ?array $errors;
    /** @var mixed */
    public $body;
    /** Seconds to wait, sent with HTTP 429. */
    public ?float $retryAfter;
    public int $status;

    public function __construct(string $message, int $status = 0, ?array $errors = null, $body = null, ?float $retryAfter = null)
    {
        parent::__construct($message, $status);
        $this->status = $status;
        $this->errors = $errors;
        $this->body = $body;
        $this->retryAfter = $retryAfter;
    }
}
