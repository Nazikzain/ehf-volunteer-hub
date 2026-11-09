<?php
declare(strict_types=1);

namespace App\Core;

final class Response
{
    public function __construct(
        public string $body = '',
        public int $status = 200,
        public array $headers = ['Content-Type' => 'text/html; charset=UTF-8'],
    ) {
    }

    public static function html(string $body, int $status = 200): self
    {
        return new self($body, $status);
    }

    public static function json(mixed $data, int $status = 200): self
    {
        return new self(json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $status,
            ['Content-Type' => 'application/json; charset=UTF-8']);
    }

    public static function redirect(string $to, int $status = 303): self
    {
        return new self('', $status, ['Location' => $to]);
    }

    public static function csv(string $filename, array $header, iterable $rows): self
    {
        $fh = fopen('php://temp', 'w+');
        fwrite($fh, "\xEF\xBB\xBF");
        fputcsv($fh, $header, ',', '"', '\\');
        foreach ($rows as $r) {
            // Neutralise spreadsheet formula injection (=, +, -, @ at start of a cell).
            $r = array_map(static fn($v) => is_string($v) && $v !== '' && strpbrk($v[0], "=+-@\t\r") !== false ? "'" . $v : $v, array_values($r));
            fputcsv($fh, $r, ',', '"', '\\');
        }
        rewind($fh);
        $body = stream_get_contents($fh);
        fclose($fh);
        $safe = preg_replace('/[^A-Za-z0-9._-]/', '_', $filename);
        return new self($body, 200, ['Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $safe . '"']);
    }

    public function send(): void
    {
        http_response_code($this->status);
        foreach (SecurityHeaders::all() + $this->headers as $k => $v) {
            header("{$k}: {$v}");
        }
        echo $this->body;
    }
}
