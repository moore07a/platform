<?php

if (!function_exists('send_json')) {
    function send_json(array $payload, int $statusCode = 200): void
    {
        http_response_code($statusCode);
        header('Content-Type: application/json');
        echo json_encode($payload);
        exit();
    }
}

if (!function_exists('require_http_method')) {
    function require_http_method(string $method): void
    {
        if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== strtoupper($method)) {
            send_json([
                'success' => false,
                'error' => 'Method not allowed'
            ], 405);
        }
    }
}

if (!function_exists('json_input')) {
    function json_input(): array
    {
        $raw = file_get_contents('php://input');
        if ($raw === false || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }
}

if (!function_exists('require_csrf_token')) {
    function require_csrf_token(): void
    {
        $headerToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        $bodyToken = $_POST['csrf_token'] ?? '';
        $token = $headerToken !== '' ? $headerToken : $bodyToken;

        if (!function_exists('verify_csrf_token') || !verify_csrf_token($token)) {
            send_json([
                'success' => false,
                'error' => 'Invalid CSRF token'
            ], 419);
        }
    }
}

if (!function_exists('log_app_error')) {
    function log_app_error(string $message, array $context = []): void
    {
        $root = defined('PROJECT_ROOT') ? PROJECT_ROOT : dirname(__DIR__);
        $logDir = $root . '/logs';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0775, true);
        }

        $record = [
            'time' => date('c'),
            'message' => $message,
            'context' => $context
        ];

        @file_put_contents($logDir . '/app.log', json_encode($record) . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}

if (!function_exists('require_rate_limit')) {
    function require_rate_limit(string $key, int $maxRequests = 60, int $windowSeconds = 60): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }

        $bucketKey = 'rate_limit_' . $key;
        $now = time();
        $bucket = $_SESSION[$bucketKey] ?? ['count' => 0, 'start' => $now];

        if (($now - (int) $bucket['start']) >= $windowSeconds) {
            $bucket = ['count' => 0, 'start' => $now];
        }

        $bucket['count']++;
        $_SESSION[$bucketKey] = $bucket;

        if ($bucket['count'] > $maxRequests) {
            send_json([
                'success' => false,
                'error' => 'Too many requests. Please try again shortly.'
            ], 429);
        }
    }
}
