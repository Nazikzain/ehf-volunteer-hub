<?php
declare(strict_types=1);

namespace App\Core;

final class Router
{
    private array $routes = [];

    /**
     * @param array $opts ['auth' => bool, 'perm' => ?string, 'name' => ?string]
     */
    public function add(string $method, string $pattern, array $handler, array $opts = []): void
    {
        $regex = '#^' . preg_replace('#\{([a-z_]+)\}#', '(?P<$1>\d+)', $pattern) . '$#';
        $this->routes[] = ['method' => $method, 'pattern' => $pattern, 'regex' => $regex, 'handler' => $handler,
            'opts' => $opts + ['auth' => true, 'perm' => null]];
    }

    public function get(string $p, array $h, array $o = []): void { $this->add('GET', $p, $h, $o); }
    public function post(string $p, array $h, array $o = []): void { $this->add('POST', $p, $h, $o); }

    /** @return array{0: array, 1: array}|null route + params; throws 405 if path matches another method */
    public function match(string $method, string $path): ?array
    {
        $pathMatched = false;
        foreach ($this->routes as $r) {
            if (preg_match($r['regex'], $path, $m)) {
                $pathMatched = true;
                if ($r['method'] === $method) {
                    return [$r, array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY)];
                }
            }
        }
        if ($pathMatched) {
            throw new HttpException(405);
        }
        return null;
    }

    public function all(): array
    {
        return $this->routes;
    }
}
