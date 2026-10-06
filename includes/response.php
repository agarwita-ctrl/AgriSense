<?php
/**
 * AgriSense - JSON response helpers shared by the REST API and the
 * dashboard's fetch() endpoints.
 */

class Response
{
    /** Emit a JSON body with the given HTTP status and stop. */
    public static function json(array $payload, int $status = 200): never
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');
        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    /** Successful response envelope. */
    public static function ok(array $data = [], string $message = ''): never
    {
        $payload = ['success' => true];
        if ($message !== '') {
            $payload['message'] = $message;
        }

        self::json($payload + $data);
    }

    /**
     * Error envelope.
     *
     * @param array<string,string> $errors Field name => problem, for forms.
     */
    public static function error(string $message, int $status = 400, array $errors = []): never
    {
        $payload = ['success' => false, 'error' => $message];
        if ($errors) {
            $payload['errors'] = $errors;
        }

        self::json($payload, $status);
    }

    /**
     * Decode a JSON request body.
     *
     * Falls back to form-encoded input so the endpoints stay usable from a
     * plain HTML form or an ESP32 posting url-encoded data.
     */
    public static function body(): array
    {
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';

        if (str_contains($contentType, 'application/json')) {
            $raw = file_get_contents('php://input') ?: '';
            if (trim($raw) === '') {
                return [];
            }
            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                self::error('The request body is not valid JSON.', 400);
            }

            return $decoded;
        }

        return $_POST ?: [];
    }

    /** Reject any method other than those listed. */
    public static function allowMethods(string ...$methods): void
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if ($method === 'OPTIONS') {
            header('Allow: ' . implode(', ', $methods));
            http_response_code(204);
            exit;
        }
        if (!in_array($method, $methods, true)) {
            header('Allow: ' . implode(', ', $methods));
            self::error('Method ' . $method . ' is not allowed on this endpoint.', 405);
        }
    }
}
