<?php

declare(strict_types=1);

// Settings come from environment variables, never from committed files.
// Copy .env.example to .env (it's git-ignored) or set them in your web server.

if (is_file(__DIR__ . '/.env')) {
    foreach (file(__DIR__ . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        putenv(trim($key) . '=' . trim($value));
    }
}

function env(string $key, ?string $default = null): string
{
    $value = getenv($key);
    if ($value === false || $value === '') {
        if ($default === null) {
            throw new RuntimeException("Missing required setting $key");
        }
        return $default;
    }
    return $value;
}

function db(): PDO
{
    return new PDO(env('DB_DSN'), env('DB_USER'), env('DB_PASSWORD'), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
}
