<?php
declare(strict_types=1);
// ============================================================
// gemini.php — Wrapper server-side para Google Gemini
// Nunca expor GEMINI_API_KEY ao cliente — todas as chamadas
// passam por este arquivo ou por api_ia.php
// ============================================================

if (!defined('HELPTI_BOOT')) { http_response_code(403); exit('Acesso negado.'); }

function geminiAsk(string $prompt, int $maxTokens = 512): string {
    $key = defined('GEMINI_API_KEY') ? GEMINI_API_KEY : '';
    if (!$key) return '';

    // Chave vai no HEADER, nunca na query string (P1-5)
    $url  = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.5-flash-lite:generateContent';
    $body = json_encode([
        'contents' => [['parts' => [['text' => $prompt]]]],
        'generationConfig' => [
            'maxOutputTokens' => $maxTokens,
            'temperature'     => 1,
        ],
    ]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'x-goog-api-key: ' . $key,
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4, // IPv6 pode estar bloqueado em hosting compartilhado
    ]);
    $raw  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($code !== 200 || !$raw) {
        if (function_exists('logApp')) {
            logApp('warn', 'gemini_falha', ['http' => $code, 'curl_err' => $err, 'resp' => mb_substr((string)$raw, 0, 200)]);
        }
        return '';
    }

    $json = json_decode($raw, true);
    return $json['candidates'][0]['content']['parts'][0]['text'] ?? '';
}
