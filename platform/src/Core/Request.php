<?php
declare(strict_types=1);

namespace App\Core;

final class Request
{
    public array $params = [];
    public readonly string $id;

    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query,
        public readonly array $post,
        public readonly array $server,
        public readonly array $headers = [],
    ) {
        $this->id = bin2hex(random_bytes(8));
    }

    public static function fromGlobals(): self
    {
        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $base = rtrim((string) env('APP_BASE_PATH', ''), '/');
        if ($base !== '' && str_starts_with($uri, $base)) {
            $uri = substr($uri, strlen($base)) ?: '/';
        }
        $headers = [];
        foreach ($_SERVER as $k => $v) {
            if (str_starts_with($k, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr($k, 5)))] = $v;
            }
        }
        return new self(strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'), rtrim($uri, '/') ?: '/', $_GET, $_POST, $_SERVER, $headers);
    }

    public function input(string $key, mixed $default = null): mixed
    {
        $v = $this->post[$key] ?? $this->query[$key] ?? $default;
        return is_string($v) ? trim($v) : $v;
    }

    public function int(string $key): ?int
    {
        $v = $this->input($key);
        return (is_string($v) && ctype_digit($v)) || is_int($v) ? (int) $v : null;
    }

    public function param(string $key): int
    {
        return (int) ($this->params[$key] ?? 0);
    }

    public function ip(): string
    {
        return (string) ($this->server['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    public function wantsJson(): bool
    {
        return str_contains($this->headers['accept'] ?? '', 'application/json')
            || ($this->headers['x-requested-with'] ?? '') === 'XMLHttpRequest';
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }
}
