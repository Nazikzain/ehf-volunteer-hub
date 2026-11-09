<?php
declare(strict_types=1);

namespace App\Core;

/** Plain PHP templates. Every dynamic value is escaped with e() in templates. */
final class View
{
    public static array $shared = [];

    public static function render(string $template, array $data = [], ?string $layout = 'app'): string
    {
        $content = self::capture($template, $data + self::$shared);
        if ($layout === null) {
            return $content;
        }
        return self::capture('layouts/' . $layout, ['content' => $content] + $data + self::$shared);
    }

    public static function partial(string $template, array $data = []): string
    {
        return self::capture($template, $data + self::$shared);
    }

    private static function capture(string $template, array $data): string
    {
        $file = APP_ROOT . '/views/' . $template . '.php';
        if (!is_file($file)) {
            throw new \RuntimeException("View not found: {$template}");
        }
        extract($data, EXTR_SKIP);
        ob_start();
        try {
            include $file;
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
        return (string) ob_get_clean();
    }
}
