<?php

declare(strict_types=1);

/**
 * Melchio — Ponto de entrada da API
 *
 * Rotas:
 *   POST /api/evaluate   → Avalia dados do paciente contra as regras
 *   GET  /api/rules      → Lista regras carregadas (debug)
 *   GET  /api/health     → Health check
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Melchio\Engine\RuleEngine;

// ── Headers ───────────────────────────────────────────────────────────────────
header('Content-Type: application/json; charset=utf-8');
header('X-Service: melchio');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Preflight CORS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ── Helpers ───────────────────────────────────────────────────────────────────
function jsonResponse(array $data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function errorResponse(string $message, int $status = 400): void
{
    jsonResponse(['error' => true, 'message' => $message], $status);
}

// ── Roteamento ────────────────────────────────────────────────────────────────
$method  = $_SERVER['REQUEST_METHOD'];
$uri     = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$isDebug = filter_var($_ENV['APP_DEBUG'] ?? 'false', FILTER_VALIDATE_BOOLEAN);

try {
    $rulesPath = __DIR__ . '/../rules/clinical_rules.json';
    $engine    = new RuleEngine($rulesPath);

    // ── POST /api/evaluate ────────────────────────────────────────────────────
    if ($method === 'POST' && $uri === '/api/evaluate') {

        $raw   = file_get_contents('php://input');
        $input = json_decode($raw, true);

        if (!is_array($input)) {
            errorResponse('JSON inválido ou vazio no corpo da requisição.');
        }

        // Validação mínima
        if (!isset($input['patient']) || !isset($input['observations'])) {
            errorResponse('Campos obrigatórios ausentes. Envie "patient" e "observations".');
        }

        $result = $engine->evaluate($input);
        jsonResponse($result);
    }

    // ── GET /api/rules ────────────────────────────────────────────────────────
    if ($method === 'GET' && $uri === '/api/rules') {
        $rules = $engine->getRules();
        jsonResponse([
            'service' => 'melchio',
            'total'   => count($rules),
            'rules'   => $rules,
        ]);
    }

    // ── GET /api/health ───────────────────────────────────────────────────────
    if ($method === 'GET' && $uri === '/api/health') {
        jsonResponse([
            'status'        => 'ok',
            'service'       => 'melchio',
            'version'       => '1.0.0',
            'rules_loaded'  => count($engine->getRules()),
            'php_version'   => PHP_VERSION,
            'timestamp'     => gmdate('c'),
        ]);
    }

    // ── 404 ───────────────────────────────────────────────────────────────────
    errorResponse('Rota não encontrada.', 404);

} catch (\Throwable $e) {
    $response = ['error' => true, 'message' => 'Erro interno do servidor.'];
    if ($isDebug) {
        $response['debug'] = [
            'exception' => get_class($e),
            'message'   => $e->getMessage(),
            'file'      => $e->getFile(),
            'line'      => $e->getLine(),
        ];
    }
    jsonResponse($response, 500);
}