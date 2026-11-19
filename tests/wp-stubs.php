<?php

declare(strict_types=1);

// Minimal WordPress stand-ins for unit tests. State lives in WpStubs so tests can reset it.

final class WpStubs
{
    /** @var array<string, mixed> */
    public static array $options = [];

    /** @var list<array{url: string, args: array<string, mixed>}> */
    public static array $requests = [];

    /** @var array<string, mixed>|WP_Error */
    public static array|WP_Error $nextResponse = [];

    public static function reset(): void
    {
        self::$options = [];
        self::$requests = [];
        self::$nextResponse = ['response' => ['code' => 200], 'headers' => [], 'body' => '{}'];
    }
}

class WP_Error
{
    public function __construct(private string $code = '', private string $message = '') {}

    public function get_error_message(): string
    {
        return $this->message;
    }

    public function get_error_code(): string
    {
        return $this->code;
    }
}

function get_option(string $name, mixed $default = false): mixed
{
    return WpStubs::$options[$name] ?? $default;
}

/** @param array<string, mixed> $args */
function wp_remote_request(string $url, array $args = []): array|WP_Error
{
    WpStubs::$requests[] = ['url' => $url, 'args' => $args];

    return WpStubs::$nextResponse;
}

function __(string $text, string $domain = 'default'): string
{
    return $text;
}

function esc_html(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

function is_wp_error(mixed $thing): bool
{
    return $thing instanceof WP_Error;
}

/** @param array<string, mixed> $response */
function wp_remote_retrieve_headers(array $response): array
{
    return $response['headers'] ?? [];
}

/** @param array<string, mixed> $response */
function wp_remote_retrieve_response_code(array $response): int
{
    return (int) ($response['response']['code'] ?? 0);
}

/** @param array<string, mixed> $response */
function wp_remote_retrieve_body(array $response): string
{
    return (string) ($response['body'] ?? '');
}
