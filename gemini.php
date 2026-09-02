<?php
// ============================================================
// gemini.php — Wrapper server-side para Google Gemini
// Nunca expor GEMINI_API_KEY ao cliente — todas as chamadas
// passam por este arquivo ou por api_ia.php
// ============================================================

function geminiAsk(string $prompt, int $maxTokens = 512): string {
    $key = defined('GEMINI_API_KEY') ? GEMINI_API_KEY : '';
    if (!$key) return '';

    $url  = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.5-flash-lite:generateContent?key=' . $key;
    $minTokens = $maxTokens;
    $body = json_encode([
        'contents' => [['parts' => [['text' => $prompt]]]],
        'generationConfig' => [
            'maxOutputTokens' => $minTokens,
            'temperature'     => 1,
        ],
    ]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4, // IPv6 pode estar bloqueado em hosting compartilhado
    ]);
    $raw  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code !== 200 || !$raw) return '';

    $json = json_decode($raw, true);
    return $json['candidates'][0]['content']['parts'][0]['text'] ?? '';
}
