<?php
// ============================================================
// api_ia.php — Endpoint server-side para recursos de IA
// Ações: classificar | sugerir_resposta | resumir
// A chave da API NUNCA é exposta ao cliente.
// ============================================================

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/gemini.php';

header('Content-Type: application/json; charset=UTF-8');

$action = $_POST['action'] ?? '';

// Verificação CSRF em todas as ações
csrfVerify();

// classificar: acessível pelo portal público (sem login) — mas com rate limit por IP (P1-5)
// sugerir_resposta e resumir: só para técnicos autenticados
if ($action !== 'classificar') {
    requireLogin();
} else {
    if (!rateLimit('ia_classificar_' . clientIp(), 15, 3600)) {
        http_response_code(429);
        echo json_encode(['ok' => false, 'erro' => 'Muitas solicitações à IA. Tente novamente mais tarde.']);
        exit;
    }
}

if (!GEMINI_API_KEY) {
    echo json_encode(['ok' => false, 'erro' => 'IA não configurada.']);
    exit;
}

// ── 1. Classificar chamado (sugerir nível + categoria) ─────────────────────
if ($action === 'classificar') {
    $titulo    = trim($_POST['titulo'] ?? '');
    $descricao = trim($_POST['descricao'] ?? '');

    if (!$titulo && !$descricao) {
        echo json_encode(['ok' => false, 'erro' => 'Informe título ou descrição.']);
        exit;
    }

    $pdo = db();
    $cats = $pdo->query("SELECT id, nome FROM categorias WHERE ativo=1 ORDER BY nome")->fetchAll();
    $listaCats = implode(', ', array_column($cats, 'nome'));

    $prompt = <<<PROMPT
Você é um analista de suporte de TI para uma clínica médica (Alphaclin).
Dado o seguinte chamado, classifique:

Título: {$titulo}
Descrição: {$descricao}

Responda SOMENTE um JSON válido neste formato exato (sem texto extra):
{
  "nivel": "Baixa Complexidade|Média Complexidade|Alta Complexidade",
  "categoria": "nome da categoria mais adequada entre: {$listaCats}",
  "justificativa": "uma frase curta explicando"
}

Regras de nível:
- Alta Complexidade: sistema fora do ar, servidor, rede geral, perda de dados
- Média Complexidade: impressora, lentidão, software com erro
- Baixa Complexidade: dúvida, troca de senha, configuração simples
PROMPT;

    $resp = geminiAsk($prompt, 256);
    $json = json_decode(preg_replace('/```json|```/', '', $resp), true);

    if (!$json) {
        $msg = $resp ? 'IA retornou formato inesperado. Tente novamente.' : 'IA temporariamente indisponível. Tente em instantes.';
        echo json_encode(['ok' => false, 'erro' => $msg]);
        exit;
    }

    // Descobrir categoria_id
    $catId = null;
    foreach ($cats as $cat) {
        if (mb_strtolower($cat['nome']) === mb_strtolower($json['categoria'] ?? '')) {
            $catId = $cat['id'];
            break;
        }
    }

    echo json_encode([
        'ok'            => true,
        'nivel'         => $json['nivel'] ?? '',
        'categoria'     => $json['categoria'] ?? '',
        'categoria_id'  => $catId,
        'justificativa' => $json['justificativa'] ?? '',
    ]);
    exit;
}

// ── 2. Sugerir resposta para o técnico ────────────────────────────────────
if ($action === 'sugerir_resposta') {
    $chamadoId = (int)($_POST['chamado_id'] ?? 0);
    if (!$chamadoId) { echo json_encode(['ok'=>false,'erro'=>'ID inválido.']); exit; }

    $pdo = db();
    $ch  = $pdo->prepare("SELECT * FROM chamados WHERE id=? AND deleted_at IS NULL");
    $ch->execute([$chamadoId]);
    $chamado = $ch->fetch();
    if (!$chamado) { echo json_encode(['ok'=>false,'erro'=>'Chamado não encontrado.']); exit; }

    // Histórico
    $hist = $pdo->prepare("SELECT acao FROM historico WHERE chamado_id=? ORDER BY criado_em LIMIT 10");
    $hist->execute([$chamadoId]);
    $linhasHist = implode("\n- ", array_column($hist->fetchAll(), 'acao'));

    // Artigos da KB relacionados
    $kbRows = [];
    $palavras = implode(' ', array_slice(explode(' ', $chamado['descricao']), 0, 8));
    if ($palavras) {
        $kb = $pdo->prepare("SELECT titulo, conteudo FROM knowledge_base
            WHERE MATCH(titulo,conteudo) AGAINST(? IN NATURAL LANGUAGE MODE)
            LIMIT 3");
        $kb->execute([$palavras]);
        $kbRows = $kb->fetchAll();
    }
    $kbTexto = '';
    foreach ($kbRows as $art) {
        $kbTexto .= "\n### " . $art['titulo'] . "\n" . mb_substr($art['conteudo'], 0, 400) . "\n";
    }

    $prompt = <<<PROMPT
Você é um técnico de TI sênior de suporte a uma clínica médica (Alphaclin).
Escreva uma resposta profissional e objetiva para o usuário do chamado abaixo.

Chamado: {$chamado['numero']}
Setor: {$chamado['setor']}
Solicitante: {$chamado['solicitante']}
Descrição: {$chamado['descricao']}
Status atual: {$chamado['status']}
Histórico de ações:
- {$linhasHist}

{$kbTexto}

Escreva a resposta em português, de forma clara e direta, no máximo 5 linhas.
Não inclua saudação formal longa. Não use markdown. Apenas o texto da resposta.
PROMPT;

    $texto = geminiAsk($prompt, 400);
    echo json_encode(['ok' => true, 'texto' => trim($texto)]);
    exit;
}

// ── 3. Auto-resumo ao concluir ────────────────────────────────────────────
if ($action === 'resumir') {
    $chamadoId = (int)($_POST['chamado_id'] ?? 0);
    if (!$chamadoId) { echo json_encode(['ok'=>false,'erro'=>'ID inválido.']); exit; }

    $pdo = db();
    $ch  = $pdo->prepare("SELECT * FROM chamados WHERE id=? AND deleted_at IS NULL");
    $ch->execute([$chamadoId]);
    $chamado = $ch->fetch();
    if (!$chamado) { echo json_encode(['ok'=>false,'erro'=>'Chamado não encontrado.']); exit; }

    $hist = $pdo->prepare("SELECT acao, criado_em FROM historico WHERE chamado_id=? ORDER BY criado_em");
    $hist->execute([$chamadoId]);
    $linhasHist = implode("\n- ", array_column($hist->fetchAll(), 'acao'));

    $prompt = <<<PROMPT
Você é um técnico de TI de uma clínica médica (Alphaclin).
Escreva um resumo técnico da resolução do chamado abaixo, para registro interno.

Chamado: {$chamado['numero']} — {$chamado['setor']}
Problema relatado: {$chamado['descricao']}
Histórico de ações:
- {$linhasHist}

Escreva em português, no máximo 4 linhas, descrevendo: o problema, a causa identificada e a solução aplicada.
Sem markdown. Sem saudação. Apenas o texto técnico.
PROMPT;

    $texto = geminiAsk($prompt, 350);
    echo json_encode(['ok' => true, 'texto' => trim($texto)]);
    exit;
}

echo json_encode(['ok' => false, 'erro' => 'Ação inválida.']);
