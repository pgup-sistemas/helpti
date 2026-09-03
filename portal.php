<?php
// ============================================================
//  portal.php — PORTAL PÚBLICO UNIFICADO (sem login)
//  Reúne: Abrir Chamado + Acompanhar Chamado
//         Solicitar Suprimentos + Acompanhar Suprimentos
// ============================================================
require 'db.php';

$pdo = db();

// Determinar aba ativa
$aba     = $_GET['aba']    ?? 'ti';      // ti | sup
$subaba  = $_GET['subaba'] ?? 'abrir';   // abrir | acompanhar (para ti) | pedir | acompanhar (para sup)

// ───────────────────────────────────────────────
//  LÓGICA: ABRIR CHAMADO (POST)
// ───────────────────────────────────────────────
$chamado_sucesso       = $_GET['chamado_sucesso'] ?? null;
$chamado_sucesso_token = trim($_GET['t'] ?? '');
$chamado_erros   = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_form'] ?? '') === 'abrir_chamado') {
    csrfVerify();                                             // P0-3: valida token também no portal
    if (!rateLimit('portal_chamado_' . clientIp(), 5, 600)) { // P1-8: 5 chamados / 10 min por IP
        $chamado_erros[] = 'Muitas solicitações em pouco tempo. Aguarde alguns minutos.';
    }
    $nome     = trim($_POST['nome'] ?? '');
    $telefone = trim($_POST['telefone'] ?? '');
    $setor    = trim($_POST['setor'] ?? '');
    $desc     = trim($_POST['descricao'] ?? '');

    if (!$nome)  $chamado_erros[] = 'Informe seu nome.';
    // P0-3: setor precisa ser um valor conhecido — nunca texto livre (vetor de XSS armazenado)
    if (!$setor || !in_array($setor, $SETORES, true)) $chamado_erros[] = 'Selecione um setor válido da lista.';
    if (!$desc || strlen($desc) < 5) $chamado_erros[] = 'Descreva o problema (mínimo 5 caracteres).';
    if (mb_strlen($nome) > 100 || mb_strlen($desc) > 5000) $chamado_erros[] = 'Campos muito longos.';

    $uploaded_paths = [];
    if (!$chamado_erros && !empty($_FILES['imagens']['name'][0])) {
        $files = $_FILES['imagens'];
        $count = count($files['name']);
        if ($count > 3) {
            $chamado_erros[] = 'Máximo 3 imagens.';
        } else {
            $allowed_mimes = ['image/jpeg'=>'jpg','image/png'=>'png','image/gif'=>'gif','image/webp'=>'webp'];
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            if (!is_dir('uploads')) mkdir('uploads', 0755, true);
            for ($i = 0; $i < $count; $i++) {
                if ($files['error'][$i] === UPLOAD_ERR_NO_FILE) continue;
                if ($files['error'][$i] !== UPLOAD_ERR_OK)      { $chamado_erros[] = "Erro ao enviar imagem " . ($i+1) . "."; continue; }
                if ($files['size'][$i] > 5*1024*1024)            { $chamado_erros[] = "Imagem ".h($files['name'][$i])." excede 5 MB."; continue; }
                $real_mime = $finfo->file($files['tmp_name'][$i]);
                if (!array_key_exists($real_mime, $allowed_mimes)) { $chamado_erros[] = "Tipo inválido para ".h($files['name'][$i])."."; continue; }
                $ext  = $allowed_mimes[$real_mime];
                $dest = 'uploads/img_' . bin2hex(random_bytes(16)) . '.' . $ext;
                if (move_uploaded_file($files['tmp_name'][$i], $dest)) $uploaded_paths[] = $dest;
                else $chamado_erros[] = "Não foi possível salvar ".h($files['name'][$i]).".";
            }
            if ($chamado_erros) { foreach ($uploaded_paths as $p) if (file_exists($p)) unlink($p); $uploaded_paths = []; }
        }
    }

    if (!$chamado_erros) {
        $numero       = gerarNumero();
        $semana       = getSemana(date('Y-m-d'));
        $imagens_json = !empty($uploaded_paths) ? json_encode($uploaded_paths) : null;
        $acompToken   = tokenOpaco();
        $avalToken    = tokenOpaco();
        $pdo->prepare("INSERT INTO chamados
                (numero,descricao,setor,solicitante,telefone_solicitante,semana,status,origem,imagens,acompanhamento_token,avaliacao_token)
             VALUES (?,?,?,?,?,?,'Aberto','Formulário Web',?,?,?)")
            ->execute([$numero,$desc,$setor,$nome,$telefone?:null,$semana,$imagens_json,$acompToken,$avalToken]);

        // Notifica todos os técnicos/admins sobre chamado novo sem responsável
        $emails_ti = $pdo->query("SELECT email FROM usuarios WHERE ativo=1 AND perfil IN ('tecnico','admin','gestora')")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($emails_ti as $eti) {
            notificarChamado('aberto', ['numero'=>$numero,'setor'=>$setor,'descricao'=>$desc,'solicitante'=>$nome], $eti);
        }
        header("Location: portal.php?aba=ti&subaba=abrir&chamado_sucesso=" . urlencode($numero) . "&t=" . urlencode($acompToken));
        exit;
    }
    $aba = 'ti'; $subaba = 'abrir';
}

// ───────────────────────────────────────────────
//  LÓGICA: ACOMPANHAR CHAMADO
// ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['avaliar_token'])) {
    csrfVerify();
    $atok = trim($_POST['avaliar_token'] ?? '');
    $nota = (int)($_POST['nota'] ?? 0);
    $comt = mb_substr(trim($_POST['comentario'] ?? ''), 0, 2000);
    if ($atok !== '' && $nota >= 1 && $nota <= 5 && rateLimit('aval_' . clientIp(), 10, 3600)) {
        // Chamado identificado pelo token opaco de avaliação, e precisa estar Concluído (P1-1)
        $chk = $pdo->prepare("SELECT id FROM chamados WHERE avaliacao_token=? AND status='Concluído' AND deleted_at IS NULL");
        $chk->execute([$atok]);
        $cid = $chk->fetchColumn();
        if ($cid) {
            // Regra única: 1 avaliação imutável por chamado (P1-7). INSERT IGNORE não sobrescreve.
            $pdo->prepare("INSERT IGNORE INTO avaliacoes (chamado_id,nota,comentario) VALUES (?,?,?)")
                ->execute([$cid,$nota,$comt?:null]);
        }
    }
}

// ── ACOMPANHAR CHAMADO ────────────────────────────────────────────────
// P0-4: portal público NÃO lista chamados nem permite busca por nome.
// Rastreamento é por número + token de acompanhamento (link enviado ao abrir).
// Sem token: card mínimo de status (sem PII). Com token válido: detalhe completo.
$chamado_detalhe    = null;
$historico          = [];
$chamado_erro_busca = null;
$acesso_completo    = false;
$numero_chamado  = trim($_GET['numero_chamado'] ?? '');
$token_chamado   = trim($_GET['t'] ?? $_GET['token'] ?? '');
// Fallback: usuário colou o link inteiro no campo de número (o JS normalmente já trata)
if (preg_match('/[?&](numero_chamado|numero_sup)=/', $numero_chamado)) {
    parse_str(ltrim(strstr($numero_chamado, '?') ?: $numero_chamado, '?'), $_qs);
    $numero_chamado = trim($_qs['numero_chamado'] ?? $_qs['numero_sup'] ?? '');
    if (!$token_chamado) $token_chamado = trim($_qs['t'] ?? '');
}
$numero_chamado  = strtoupper($numero_chamado);
$lista_chamados  = []; // mantido para compatibilidade de template — sempre vazio

if ($numero_chamado) {
    if (!rateLimit('portal_track_' . clientIp(), 30, 600)) {
        $chamado_erro_busca = "Muitas consultas. Aguarde alguns minutos.";
    } else {
        $st = $pdo->prepare("SELECT c.*, u.nome AS resp_nome
                             FROM chamados c LEFT JOIN usuarios u ON u.id=c.responsavel_id
                             WHERE c.numero=? AND c.deleted_at IS NULL");
        $st->execute([$numero_chamado]);
        $chamado_detalhe = $st->fetch();
        if ($chamado_detalhe) {
            $acesso_completo = $token_chamado !== ''
                && !empty($chamado_detalhe['acompanhamento_token'])
                && hash_equals($chamado_detalhe['acompanhamento_token'], $token_chamado);
            if ($acesso_completo) {
                $h_st = $pdo->prepare("SELECT h.*,u.nome AS usu FROM historico h
                                       LEFT JOIN usuarios u ON u.id=h.usuario_id
                                       WHERE h.chamado_id=? ORDER BY h.criado_em DESC");
                $h_st->execute([$chamado_detalhe['id']]);
                $historico = $h_st->fetchAll();
            }
        } else {
            $chamado_erro_busca = "Chamado '$numero_chamado' não encontrado.";
        }
    }
    $aba = 'ti'; $subaba = 'acompanhar';
}
$fc_paginas = 0;

// ───────────────────────────────────────────────
//  LÓGICA: PEDIDO DE SUPRIMENTOS (POST)
// ───────────────────────────────────────────────
$sup_sucesso       = $_GET['sup_sucesso'] ?? null;
$sup_sucesso_token = trim($_GET['t'] ?? '');
$sup_erros   = [];
$impressoras      = $pdo->query("SELECT id,nome,setor,modelo_toner FROM impressoras WHERE status='Ativa' ORDER BY nome")->fetchAll();
$tipos_suprimentos= $pdo->query("SELECT id,nome FROM tipos_suprimentos WHERE ativo=1 ORDER BY nome")->fetchAll();
$tipos_ids_post   = $_POST['tipo_suprimento_id'] ?? [''];
$quantidades_post = $_POST['quantidade'] ?? [1];
$descricoes_post  = $_POST['descricao_livre'] ?? [''];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_form'] ?? '') === 'pedir_suprimento') {
    csrfVerify();
    if (!rateLimit('portal_sup_' . clientIp(), 5, 600)) {
        $sup_erros[] = 'Muitas solicitações em pouco tempo. Aguarde alguns minutos.';
    }
    $solicitante   = trim($_POST['solicitante'] ?? '');
    $setor_sup     = trim($_POST['setor'] ?? '');
    $impressora_id = $_POST['impressora_id'] ? (int)$_POST['impressora_id'] : null;
    $observacoes   = mb_substr(trim($_POST['observacoes'] ?? ''), 0, 2000);

    if (!$solicitante || mb_strlen($solicitante) > 100) $sup_erros[] = 'Informe seu nome completo.';
    if (!$setor_sup || !in_array($setor_sup, $SETORES, true)) $sup_erros[] = 'Selecione um setor válido da lista.';
    if (empty($tipos_ids_post) || (count($tipos_ids_post)===1 && empty($tipos_ids_post[0])))
        $sup_erros[] = 'Adicione pelo menos um insumo.';

    $itens_validos = [];
    foreach ($tipos_ids_post as $idx => $tipo_id) {
        $qtd  = (int)($quantidades_post[$idx] ?? 1);
        $desc = trim($descricoes_post[$idx] ?? '');
        if ($qtd < 1)                         $sup_erros[] = "Quantidade do item ".($idx+1)." inválida.";
        if ($tipo_id==='outro' && !$desc)     $sup_erros[] = "Descreva o insumo (Outros) no item ".($idx+1).".";
        if (empty($tipo_id))                  $sup_erros[] = "Selecione o insumo no item ".($idx+1).".";
        $itens_validos[] = ['tipo_id'=>($tipo_id==='outro'?null:(int)$tipo_id),'descricao'=>($tipo_id==='outro'?$desc:null),'quantidade'=>$qtd];
    }

    if (!$sup_erros) {
        try {
            $pdo->beginTransaction();
            $numero_sup = gerarNumeroSuprimento();
            $supToken   = tokenOpaco();
            $pdo->prepare("INSERT INTO pedidos_suprimentos (numero,impressora_id,setor,solicitante,status,observacoes,acompanhamento_token) VALUES (?,?,?,?,'Pendente',?,?)")
                ->execute([$numero_sup,$impressora_id,$setor_sup,$solicitante,$observacoes?:null,$supToken]);
            $pedido_id = $pdo->lastInsertId();
            $st_item = $pdo->prepare("INSERT INTO pedidos_suprimentos_itens (pedido_id,tipo_suprimento_id,descricao_livre,quantidade) VALUES (?,?,?,?)");
            foreach ($itens_validos as $item) $st_item->execute([$pedido_id,$item['tipo_id'],$item['descricao'],min(50,max(1,(int)$item['quantidade']))]);
            $pdo->commit();
            header("Location: portal.php?aba=sup&subaba=pedir&sup_sucesso=".urlencode($numero_sup)."&t=".urlencode($supToken));
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            logApp('error', 'portal_pedido_falhou', ['msg' => $e->getMessage()]);
            $sup_erros[] = "Não foi possível registrar o pedido. Tente novamente.";
        }
    }
    $aba = 'sup'; $subaba = 'pedir';
}

// ───────────────────────────────────────────────
//  LÓGICA: ACOMPANHAR SUPRIMENTOS
// ───────────────────────────────────────────────
// ── ACOMPANHAR SUPRIMENTOS ──────────────────────────────────────────
// P0-4: sem listagem pública. Rastreio por número + token (link do pedido).
$pedido_detalhe  = null;
$itens_detalhe   = [];
$sup_erro_busca  = null;
$acesso_sup_completo = false;
$numero_sup_busca = trim($_GET['numero_sup'] ?? '');
$token_sup        = trim($_GET['t'] ?? $_GET['token'] ?? '');
if (preg_match('/[?&](numero_chamado|numero_sup)=/', $numero_sup_busca)) {
    parse_str(ltrim(strstr($numero_sup_busca, '?') ?: $numero_sup_busca, '?'), $_qs);
    $numero_sup_busca = trim($_qs['numero_sup'] ?? $_qs['numero_chamado'] ?? '');
    if (!$token_sup) $token_sup = trim($_qs['t'] ?? '');
}
$numero_sup_busca = strtoupper($numero_sup_busca);
$lista_pedidos    = [];
$fs_paginas       = 0;

if ($numero_sup_busca) {
    if (!rateLimit('portal_track_' . clientIp(), 30, 600)) {
        $sup_erro_busca = "Muitas consultas. Aguarde alguns minutos.";
    } else {
        $st = $pdo->prepare("SELECT s.*,i.nome AS impressora_nome FROM pedidos_suprimentos s LEFT JOIN impressoras i ON i.id=s.impressora_id WHERE s.numero=?");
        $st->execute([$numero_sup_busca]);
        $pedido_detalhe = $st->fetch();
        if ($pedido_detalhe) {
            $acesso_sup_completo = $token_sup !== ''
                && !empty($pedido_detalhe['acompanhamento_token'])
                && hash_equals($pedido_detalhe['acompanhamento_token'], $token_sup);
            if ($acesso_sup_completo) {
                $st2 = $pdo->prepare("SELECT pi.*,ts.nome AS tipo_nome FROM pedidos_suprimentos_itens pi LEFT JOIN tipos_suprimentos ts ON ts.id=pi.tipo_suprimento_id WHERE pi.pedido_id=?");
                $st2->execute([$pedido_detalhe['id']]);
                $itens_detalhe = $st2->fetchAll();
            }
        } else {
            $sup_erro_busca = "Pedido '$numero_sup_busca' não encontrado.";
        }
    }
    $aba = 'sup'; $subaba = 'acompanhar';
}

// Pre-seleção de impressora via GET
$impressora_get_id = (int)($_GET['impressora_id'] ?? 0);
$pre_setor_sup = '';
if ($impressora_get_id) {
    $chk = $pdo->prepare("SELECT setor FROM impressoras WHERE id=?");
    $chk->execute([$impressora_get_id]);
    $chk_r = $chk->fetch();
    if ($chk_r) $pre_setor_sup = $chk_r['setor'];
}

// helpers de badge inline
function sbStatus(string $s): string {
    $m=['Aberto'=>'badge-aberto','Em Andamento'=>'badge-andamento','Pendente'=>'badge-pendente','Concluído'=>'badge-concluido'];
    return '<span class="badge '.($m[$s]??'bg-secondary text-white').'">'.h($s).'</span>';
}
function sbNivel(string $n): string {
    if (str_contains($n,'Baixa')) return '<span class="badge badge-nivel-baixa">Baixa</span>';
    if (str_contains($n,'Média')) return '<span class="badge badge-nivel-media">Média</span>';
    if (str_contains($n,'Alta'))  return '<span class="badge badge-nivel-alta">Alta</span>';
    return '<span class="badge bg-light text-muted">A Definir</span>';
}
function sbSup(string $s): string {
    $m=['Pendente'=>'badge-pendente','Aprovado'=>'badge-andamento','Entregue'=>'badge-concluido','Cancelado'=>'bg-secondary text-white'];
    return '<span class="badge '.($m[$s]??'bg-secondary text-white').'">'.h($s).'</span>';
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
<title>Portal do Colaborador — <?= CLINICA_NOME ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
:root{
  --brand:#1D3557;
  --brand-dark:#457B9D;
  --brand-light:#A8DADC;
  --bg-color:#F1FAEE;
  --danger:#E63946;
}
*{box-sizing:border-box}
body{background:var(--bg-color);font-family:'Manrope',system-ui,sans-serif;min-height:100vh;padding:0;margin:0;color:#1f2937}
h1,h2,h3,.form-card-header h2,.panel-head h2{font-family:'Manrope',system-ui,sans-serif;letter-spacing:-.01em}

/* ── Marca (topo discreto, sem barra fixa) ── */
.portal-brand{display:flex;align-items:center;justify-content:center;gap:7px;font-size:13px;font-weight:700;color:var(--brand);opacity:.85;margin-bottom:1rem}

/* ── Rodapé (link de acesso restrito) ── */
.portal-footer{text-align:center;margin-top:1.5rem}
.portal-footer a{color:#9ca3af;font-size:12px;text-decoration:none;display:inline-flex;align-items:center;gap:5px;transition:.15s}
.portal-footer a:hover{color:var(--brand)}
.portal-footer-vendor{font-size:10px;color:#cbd5e1;margin-top:.5rem}

/* ── Container principal ── */
.portal-body{max-width:660px;margin:0 auto;padding:1.5rem 1rem 3rem}

/* ── Card único com tabs integradas ── */
.portal-card{background:#fff;border-radius:14px;box-shadow:0 2px 20px rgba(0,0,0,.08);overflow:hidden;margin-bottom:1.25rem}

/* Abas contexto dentro do card */
.card-ctx-tabs{display:flex;border-bottom:1px solid #e5e9f2}
.card-ctx-tab{flex:1;padding:.65rem .5rem;text-align:center;font-size:13px;font-weight:600;color:#9ca3af;text-decoration:none;display:flex;align-items:center;justify-content:center;gap:5px;transition:.15s;border-bottom:2.5px solid transparent}
.card-ctx-tab:hover{color:var(--brand);background:#f8fbff}
.card-ctx-tab.active{color:var(--brand);border-bottom-color:var(--brand);background:#f8fbff}
.card-ctx-tab i{font-size:14px}

/* Sub-tabs (pills compactos) */
.card-sub-tabs{display:flex;gap:6px;padding:.65rem 1.25rem;background:#f9fafb;border-bottom:1px solid #e5e9f2}
.card-sub-tab{font-size:12px;font-weight:600;padding:.28rem .8rem;border-radius:20px;border:1.5px solid #e5e9f2;color:#6c757d;text-decoration:none;transition:.15s;display:flex;align-items:center;gap:4px}
.card-sub-tab:hover{border-color:var(--brand);color:var(--brand)}
.card-sub-tab.active{background:var(--brand);border-color:var(--brand);color:#fff}

/* Cabeçalho do formulário dentro do card */
.form-card-header{background:linear-gradient(135deg,var(--brand),var(--brand-dark));color:#fff;padding:1.6rem 1.5rem 1.4rem;text-align:center}
.form-card-header h2{font-size:17px;font-weight:800;margin:.7rem 0 0}
.form-card-header p{font-size:12.5px;opacity:.75;margin:.3rem 0 0}
.form-card-header .ico{width:48px;height:48px;border-radius:50%;background:rgba(255,255,255,.16);display:flex;align-items:center;justify-content:center;font-size:22px;margin:0 auto}
.form-card-body{padding:1.5rem}

/* Cards internos (dentro do portal-card, sem shadow/radius próprios) */
.form-card{background:#fff;overflow:hidden;margin-bottom:0}
.panel-card{background:#fff;overflow:hidden;margin-bottom:0}
.panel-card + .panel-card{border-top:1px solid #e5e9f2}

/* ── Inputs ── */
.form-label{font-weight:700;font-size:13px;color:#374151;margin-bottom:.35rem}
.form-control,.form-select{border-radius:9px;border:1.5px solid #e5e9f2;font-size:14px;padding:.62rem .85rem;transition:.15s}
.form-control:focus,.form-select:focus{border-color:var(--brand-dark);box-shadow:0 0 0 3px rgba(69,123,157,.12)}
.form-control::placeholder{color:#aeb4bd}

/* ── Upload de imagens (dropzone) ── */
.upload-zone{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:.15rem;border:1.5px dashed #cbd5e1;border-radius:12px;padding:1.35rem 1rem;cursor:pointer;transition:.2s;text-align:center;background:#f9fafb}
.upload-zone:hover{border-color:var(--brand-dark);background:#f0f9ff}
.upload-zone-icon{font-size:24px;color:var(--brand-dark);margin-bottom:.15rem}
.upload-zone-text{font-size:13px;font-weight:700;color:#374151}
.upload-zone-hint{font-size:11px;color:#9ca3af}
.file-list{display:flex;flex-wrap:wrap;gap:6px;margin-top:.65rem}
.file-chip{display:flex;align-items:center;gap:6px;background:#eff6ff;border:1px solid #bfdbfe;color:#1e40af;border-radius:20px;padding:.28rem .8rem .28rem .6rem;font-size:11.5px;font-weight:600;max-width:100%}
.file-chip span{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:180px}
.file-chip i{font-size:14px;flex-shrink:0}

/* ── Botão Classificar com IA ── */
.btn-ia{background:linear-gradient(135deg,#eef2ff,#f0f9ff);border:1.5px solid #c7d2fe;color:#4338ca;border-radius:20px;font-size:12px;font-weight:700;padding:.4rem 1rem;display:inline-flex;align-items:center;gap:6px;transition:.15s;cursor:pointer}
.btn-ia:hover{background:linear-gradient(135deg,#e0e7ff,#e0f2fe);border-color:#a5b4fc;color:#3730a3}
.btn-ia:disabled{opacity:.6;cursor:default}

/* ── Botões — override Bootstrap ── */
.btn-primary{background-color:var(--brand);border-color:var(--brand);color:#fff}
.btn-primary:hover,.btn-primary:focus{background-color:var(--brand-dark);border-color:var(--brand-dark);color:#fff}
.btn-outline-primary{color:var(--brand);border-color:var(--brand)}
.btn-outline-primary:hover{background-color:var(--brand);border-color:var(--brand);color:#fff}
.text-primary{color:var(--brand)!important}
a{color:var(--brand)}

/* ── Botão principal ── */
.btn-send{width:100%;padding:.8rem;background:var(--brand);border:none;border-radius:10px;color:#fff;font-size:14.5px;font-weight:700;cursor:pointer;transition:.15s;margin-top:.5rem}
.btn-send:hover{background:var(--brand-dark)}
.btn-send:active{transform:scale(.98)}

/* ── Sucesso ── */
.success-box{text-align:center;padding:2rem 1.5rem}
.success-box .chk{font-size:52px;color:#16a34a}
.success-box h3{font-size:17px;font-weight:700;margin:.6rem 0 .3rem}
.success-box .num{display:inline-block;background:#f0fdf4;border:1.5px solid #bbf7d0;color:#166534;font-size:20px;font-weight:800;border-radius:10px;padding:.45rem 1.25rem;letter-spacing:.05em;margin:.6rem 0}
.success-box p{font-size:13px;color:#6b7280}
.success-box .ss-hint{font-size:11.5px;color:#9ca3af;margin:.15rem 0 0}
.success-box .ss-link-sec{font-size:12.5px;color:#6c757d;text-decoration:none;margin-top:.35rem}
.success-box .ss-link-sec:hover{color:var(--brand);text-decoration:underline}
/* clique-para-copiar */
.copiavel{cursor:pointer;transition:.15s}
.copiavel:hover{filter:brightness(.97)}
.copiavel.copiado{background:#16a34a!important;border-color:#16a34a!important;color:#fff!important}
button.num{font-family:inherit}
/* lista "abertos neste navegador" */
/* "Abertos neste navegador" — seção leve, sem card pesado */
.meus-itens{margin-top:1.1rem}
.meus-itens-label{font-size:11px;font-weight:700;color:#6c757d;text-transform:uppercase;letter-spacing:.05em;margin:0 0 .5rem 2px}
.meus-itens-lista{background:#fff;border:1px solid #e5e9f2;border-radius:12px;overflow:hidden}
.meu-item{display:flex;align-items:center;gap:6px;border-bottom:1px solid #f1f5f9;transition:.12s}
.meu-item:last-child{border-bottom:none}
.meu-item:hover{background:#f8fbff}
.meu-item .mi-info{flex:1;min-width:0;display:flex;align-items:center;gap:10px;padding:.6rem .3rem .6rem .9rem;text-decoration:none;color:inherit}
.meu-item .mi-num{font-weight:700;font-size:13px;color:var(--brand);font-family:ui-monospace,SFMono-Regular,monospace}
.meu-item .mi-data{font-size:11px;color:#9ca3af;white-space:nowrap}
.meu-item .mi-go{margin-left:auto;color:#cbd5e1;font-size:12px}
.meu-item:hover .mi-go{color:var(--brand-dark)}
.meu-item .mi-esquecer{background:none;border:none;color:#cbd5e1;font-size:12px;cursor:pointer;padding:6px 10px 6px 4px;line-height:1;flex-shrink:0}
.meu-item .mi-esquecer:hover{color:#ef4444}
.btn-outline-brand{border:1.5px solid var(--brand);color:var(--brand);background:transparent;border-radius:8px;padding:.5rem 1.25rem;font-size:13px;font-weight:600;text-decoration:none;cursor:pointer;display:inline-block;transition:.15s}
.btn-outline-brand:hover{background:var(--brand);color:#fff}

/* ── Painel acompanhamento ── */
.panel-card{background:#fff;border-radius:14px;box-shadow:0 2px 16px rgba(0,0,0,.07);overflow:hidden;margin-bottom:1rem}
.panel-head{background:var(--brand);color:#fff;padding:1rem 1.25rem;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px}
.panel-head h2{font-size:16px;font-weight:700;margin:0;display:flex;align-items:center;gap:8px}
.panel-head p{font-size:12px;opacity:.8;margin:.2rem 0 0}
.panel-body{padding:1.25rem}

/* ── Table ── */
.table th{font-size:11.5px;font-weight:600;color:#6c757d;text-transform:uppercase;letter-spacing:.04em;background:#f8f9fa;border-bottom:2px solid #e5e9f2}
.table td{vertical-align:middle;font-size:13px}
.btn-xs{padding:.2rem .55rem;font-size:11.5px;border-radius:5px}

/* ── Badges ── */
.badge-aberto{background:var(--brand-light);color:var(--brand)}
.badge-andamento{background:#fef3c7;color:#92400e}
.badge-pendente{background:#fee2e2;color:#991b1b}
.badge-concluido{background:#dcfce7;color:#166534}
.badge-nivel-baixa{background:#f0fdf4;color:#166534}
.badge-nivel-media{background:#fef9c3;color:#713f12}
.badge-nivel-alta{background:#fef2f2;color:var(--danger)}

/* ── Timeline ── */
.timeline{position:relative;padding-left:28px}
.timeline::before{content:"";position:absolute;left:8px;top:5px;bottom:5px;width:2px;background:#e5e9f2}
.tl-item{position:relative;margin-bottom:1.25rem}
.tl-dot{position:absolute;left:-25px;top:4px;width:11px;height:11px;border-radius:50%;background:var(--brand-dark);border:2px solid #fff;box-shadow:0 0 0 2px var(--brand-dark)}
.tl-dot.sys{background:#9ca3af;box-shadow:0 0 0 2px #9ca3af}
.tl-time{font-size:11px;color:#9ca3af;margin-bottom:2px}
.tl-text{font-size:13px;color:#374151}

/* ── Progress Tracker ── */
.st-track{display:flex;justify-content:space-between;position:relative;margin-bottom:2rem;padding:0 .75rem}
.st-track::before{content:"";position:absolute;top:14px;left:10%;right:10%;height:4px;background:#e5e9f2;z-index:1}
.st-prog{position:absolute;top:14px;left:10%;height:4px;background:var(--brand-dark);z-index:2;transition:width .3s}
.st-step{position:relative;z-index:3;text-align:center;width:30%}
.st-ico{width:32px;height:32px;border-radius:50%;background:#fff;border:3px solid #e5e9f2;color:#9ca3af;display:flex;align-items:center;justify-content:center;margin:0 auto 6px;transition:.2s}
.st-step.active .st-ico{border-color:var(--brand-dark);background:var(--brand-dark);color:#fff}
.st-step.current .st-ico{border-color:var(--brand);background:var(--brand);color:#fff;box-shadow:0 0 0 4px rgba(29,53,87,.15)}
.st-lbl{font-size:11px;font-weight:600;color:#9ca3af}
.st-step.active .st-lbl,.st-step.current .st-lbl{color:#111827}

/* ── Suprimentos: cart ── */
.cart-box{background:#fafafa;border:1px solid #e5e9f2;border-radius:8px;padding:.5rem 1rem;margin-bottom:1rem}
.item-row{display:flex;flex-direction:column;padding:.65rem 0;border-bottom:1px dashed #e5e9f2}
.item-row:last-child{border-bottom:none}
.btn-remove-item{background:#fee2e2;color:#ef4444;border:none;border-radius:6px;width:36px;height:36px;display:flex;align-items:center;justify-content:center;transition:.15s;flex-shrink:0}
.btn-remove-item:hover{background:#fca5a5;color:#b91c1c}
.btn-add-item{background:transparent;border:1.5px dashed var(--brand);color:var(--brand);border-radius:8px;padding:.6rem;width:100%;font-weight:600;font-size:13px;transition:.2s}
.btn-add-item:hover{background:var(--brand-light)}

/* ── Responsivo ── */
@media(max-width:576px){
  .portal-body{padding:.75rem .75rem 3rem}
  .form-card-body,.panel-body{padding:1rem}
  .st-lbl{font-size:9.5px}
  .st-ico{width:28px;height:28px}
  .st-track::before,.st-prog{top:11px}
  .table-responsive{font-size:12px}
}
</style>
</head>
<body>

<div class="portal-body">

<div class="portal-brand"><i class="bi bi-pc-display-horizontal"></i><?= APP_NOME ?></div>

<!-- Card único com tabs integradas -->
<div class="portal-card">
  <!-- Linha 1: contexto (T.I. / Suprimentos) -->
  <div class="card-ctx-tabs">
    <a href="?aba=ti&subaba=<?= $aba==='ti'?$subaba:'abrir' ?>"
       class="card-ctx-tab <?= $aba==='ti'?'active':'' ?>">
      <i class="bi bi-headset"></i> Suporte T.I.
    </a>
    <a href="?aba=sup&subaba=<?= $aba==='sup'?$subaba:'pedir' ?>"
       class="card-ctx-tab <?= $aba==='sup'?'active':'' ?>">
      <i class="bi bi-box-seam"></i> Suprimentos
    </a>
  </div>
  <!-- Linha 2: sub-abas compactas -->
  <div class="card-sub-tabs">
    <?php if ($aba === 'ti'): ?>
      <a href="?aba=ti&subaba=abrir"      class="card-sub-tab <?= $subaba==='abrir'?'active':'' ?>"><i class="bi bi-plus-circle"></i>Abrir</a>
      <a href="?aba=ti&subaba=acompanhar" class="card-sub-tab <?= $subaba==='acompanhar'?'active':'' ?>"><i class="bi bi-clock-history"></i>Acompanhar</a>
    <?php else: ?>
      <a href="?aba=sup&subaba=pedir"       class="card-sub-tab <?= $subaba==='pedir'?'active':'' ?>"><i class="bi bi-cart-plus"></i>Solicitar</a>
      <a href="?aba=sup&subaba=acompanhar"  class="card-sub-tab <?= $subaba==='acompanhar'?'active':'' ?>"><i class="bi bi-clock-history"></i>Acompanhar</a>
    <?php endif; ?>
  </div>
  <!-- Conteúdo das abas -->
  <div>

<?php
// ═══════════════════════════════════════
//  ABA T.I. → ABRIR CHAMADO
// ═══════════════════════════════════════
if ($aba === 'ti' && $subaba === 'abrir'):
?>

<div class="form-card">
  <?php if ($chamado_sucesso): ?>
    <div class="success-box"
         data-registrar="ti"
         data-numero="<?= h($chamado_sucesso) ?>"
         data-token="<?= h($chamado_sucesso_token) ?>">
      <div class="chk"><i class="bi bi-check-circle-fill"></i></div>
      <h3>Chamado aberto com sucesso!</h3>
      <p>Nossa equipe de TI já foi avisada.</p>

      <button type="button" class="num copiavel" data-copy="<?= h($chamado_sucesso) ?>" data-copy-label="Número copiado!" title="Copiar número">
        <?= h($chamado_sucesso) ?> <i class="bi bi-clipboard ms-1" style="font-size:13px;opacity:.55"></i>
      </button>
      <p class="ss-hint">Guarde este número — é ele que você informa se precisar falar com a TI.</p>

      <div class="d-flex flex-column gap-2 mt-3 align-items-center">
        <a href="?aba=ti&subaba=abrir" class="btn-send" style="max-width:260px;text-decoration:none;text-align:center"><i class="bi bi-plus-circle me-1"></i>Abrir outro chamado</a>
        <a href="?aba=ti&subaba=acompanhar&numero_chamado=<?= urlencode($chamado_sucesso) ?>&t=<?= urlencode($chamado_sucesso_token) ?>" class="ss-link-sec">Acompanhar o andamento deste chamado →</a>
      </div>
    </div>
  <?php else: ?>
    <div class="form-card-header">
      <div class="ico"><i class="bi bi-headset"></i></div>
      <h2>Suporte de T.I.</h2>
      <p>Preencha o formulário e registre seu chamado</p>
    </div>
    <div class="form-card-body">
      <?php if ($chamado_erros): ?>
        <div class="alert alert-danger py-2 px-3 mb-3" style="font-size:13px"><?= implode('<br>',array_map('h',$chamado_erros)) ?></div>
      <?php endif; ?>
      <form method="post" enctype="multipart/form-data" novalidate>
        <input type="hidden" name="_form" value="abrir_chamado">
        <?= csrfField() ?>
        <div class="mb-3">
          <label class="form-label">Seu nome completo</label>
          <input type="text" name="nome" class="form-control" placeholder="Ex: Ana Paula Souza" value="<?= h($_POST['nome']??'') ?>" required autocomplete="name">
        </div>
        <div class="mb-3">
          <label class="form-label">Ramal / Telefone <span class="text-muted">(opcional)</span></label>
          <input type="text" name="telefone" class="form-control" placeholder="Ex: 3214 ou (92) 99999-9999" value="<?= h($_POST['telefone']??'') ?>" autocomplete="tel">
        </div>
        <div class="mb-3">
          <label class="form-label">Setor</label>
          <select name="setor" class="form-select" required>
            <option value="">— Selecione seu setor —</option>
            <?php foreach ($SETORES as $s): ?>
              <option value="<?= h($s) ?>" <?= (($_POST['setor']??'')===$s)?'selected':'' ?>><?= h($s) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="mb-3">
          <label class="form-label">Descreva o problema</label>
          <textarea name="descricao" id="ia-descricao" class="form-control" rows="4" placeholder="Ex: A impressora não está imprimindo, o computador travou..." required><?= h($_POST['descricao'] ?? $_GET['desc'] ?? '') ?></textarea>
          <div class="mt-2">
            <button type="button" id="btn-ia-classificar" class="btn-ia">
              <i class="bi bi-stars"></i>Classificar com IA
            </button>
            <span id="ia-classificar-status" class="text-muted ms-2" style="font-size:12px;display:none">Analisando...</span>
          </div>
          <div id="ia-sugestao" class="mt-2 p-2 rounded" style="background:#f0f9ff;border:1px solid #bae6fd;font-size:12.5px;display:none">
            <i class="bi bi-robot me-1 text-primary"></i>
            <strong>Sugestão IA:</strong>
            <span id="ia-sugestao-texto"></span>
            <div class="mt-1">
              <button type="button" id="btn-ia-aceitar" class="btn btn-primary btn-sm" style="font-size:11px;padding:2px 10px">Aplicar sugestão</button>
              <button type="button" onclick="document.getElementById('ia-sugestao').style.display='none'" class="btn btn-outline-secondary btn-sm ms-1" style="font-size:11px;padding:2px 8px">Ignorar</button>
            </div>
          </div>
        </div>
        <div class="mb-3">
          <label class="form-label">Anexar imagens <span class="text-muted">(máximo 3)</span></label>
          <label for="imagens" class="upload-zone" id="uploadZone">
            <i class="bi bi-cloud-arrow-up upload-zone-icon"></i>
            <span class="upload-zone-text">Toque para escolher imagens</span>
            <span class="upload-zone-hint">JPG, PNG ou WEBP — até 5MB cada</span>
          </label>
          <input type="file" name="imagens[]" id="imagens" accept="image/*" multiple hidden>
          <div id="file-list" class="file-list"></div>
          <div id="file-error" class="text-danger mt-1" style="font-size:12px;display:none"><i class="bi bi-exclamation-circle-fill me-1"></i>Máximo 3 imagens.</div>
        </div>
        <button type="submit" class="btn-send"><i class="bi bi-send-fill me-2"></i>Enviar chamado</button>
      </form>
    </div>
  <?php endif; ?>
</div>

<?php
// ═══════════════════════════════════════
//  ABA T.I. → ACOMPANHAR
// ═══════════════════════════════════════
elseif ($aba === 'ti' && $subaba === 'acompanhar'):
?>

<?php if ($chamado_erro_busca): ?>
  <div class="alert alert-warning alert-dismissible fade show mb-3"><i class="bi bi-exclamation-triangle-fill me-2"></i><?= h($chamado_erro_busca) ?><button class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>

<?php if ($chamado_detalhe): ?>
  <!-- DETALHE CHAMADO -->
  <div class="panel-card">
    <div class="panel-head">
      <div>
        <a href="?aba=ti&subaba=acompanhar" class="text-white text-decoration-none" style="font-size:12px"><i class="bi bi-arrow-left me-1"></i>Voltar</a>
        <h2 class="mt-1"><i class="bi bi-ticket-detailed"></i> <?= h($chamado_detalhe['numero']) ?></h2>
      </div>
      <?= sbStatus($chamado_detalhe['status']) ?>
    </div>
    <div class="panel-body">
      <?php
        $tw = '0%'; $s1='active'; $s2=''; $s3='';
        if (in_array($chamado_detalhe['status'],['Em Andamento','Pendente'])) { $tw='50%'; $s2='current'; }
        elseif ($chamado_detalhe['status']==='Concluído') { $tw='100%'; $s2='active'; $s3='active'; }
        else { $s1='current'; }
      ?>
      <div class="st-track">
        <div class="st-prog" style="width:<?= $tw ?>"></div>
        <div class="st-step <?= $s1 ?>"><div class="st-ico"><i class="bi bi-file-earmark-plus"></i></div><div class="st-lbl">Registrado</div></div>
        <div class="st-step <?= $s2 ?>"><div class="st-ico"><i class="bi bi-person-gear"></i></div><div class="st-lbl">Em Atendimento</div></div>
        <div class="st-step <?= $s3 ?>"><div class="st-ico"><i class="bi bi-check-lg"></i></div><div class="st-lbl">Concluído</div></div>
      </div>

      <?php if (!$acesso_completo): ?>
        <!-- Sem token de acompanhamento: apenas status, sem dados pessoais (P0-4) -->
        <div class="row g-3 mb-2">
          <div class="col-6"><div class="text-muted small">Setor</div><div class="fw-semibold"><?= h($chamado_detalhe['setor']) ?></div></div>
          <div class="col-6"><div class="text-muted small">Aberto em</div><div><?= date('d/m/Y H:i',strtotime($chamado_detalhe['criado_em'])) ?></div></div>
          <div class="col-6"><div class="text-muted small">Situação</div><div class="fw-semibold"><?= h($chamado_detalhe['status']) ?></div></div>
          <div class="col-6"><div class="text-muted small">Responsável</div><div><?= $chamado_detalhe['responsavel_id'] ? 'Em atendimento' : 'Aguardando atribuição' ?></div></div>
        </div>
        <div class="alert alert-light border mt-3" style="font-size:12.5px">
          <i class="bi bi-info-circle me-1"></i>Acima está a <strong>situação atual</strong> do seu chamado.
          Para ver a descrição completa, o histórico e avaliar o atendimento, abra o
          <strong>link de acompanhamento</strong> que você recebeu ao registrar o chamado —
          ou escolha o chamado na lista <strong>“Abertos neste navegador”</strong> logo abaixo (se você o abriu neste computador).
        </div>
      <?php else: ?>
      <div class="row g-4">
        <div class="col-md-7">
          <h6 class="fw-bold border-bottom pb-2 mb-3">Informações</h6>
          <div class="row g-3 mb-3">
            <div class="col-6"><div class="text-muted small">Solicitante</div><div class="fw-semibold"><?= h($chamado_detalhe['solicitante']) ?></div></div>
            <div class="col-6"><div class="text-muted small">Setor</div><div class="fw-semibold"><?= h($chamado_detalhe['setor']) ?></div></div>
            <div class="col-6"><div class="text-muted small">Aberto em</div><div><?= date('d/m/Y H:i',strtotime($chamado_detalhe['criado_em'])) ?></div></div>
            <div class="col-6"><div class="text-muted small">Nível</div><?= sbNivel($chamado_detalhe['nivel']) ?></div>
            <div class="col-12"><div class="text-muted small">Responsável</div><div class="fw-semibold"><?= h($chamado_detalhe['resp_nome']??'Aguardando atribuição...') ?></div></div>
          </div>
          <h6 class="fw-bold border-bottom pb-2 mb-2">Descrição</h6>
          <p class="p-3 bg-light rounded" style="font-size:13.5px;white-space:pre-wrap"><?= h($chamado_detalhe['descricao']) ?></p>

          <?php if (!empty($chamado_detalhe['imagens'])): $imgs=json_decode($chamado_detalhe['imagens'],true); if($imgs): ?>
            <h6 class="fw-bold border-bottom pb-2 mb-2 mt-3">Imagens</h6>
            <div class="d-flex gap-2 flex-wrap">
              <?php foreach($imgs as $img): if (!is_string($img) || !str_starts_with($img, 'uploads/')) continue; ?>
                <a href="<?= h($img) ?>" target="_blank"><img src="<?= h($img) ?>" class="img-thumbnail" style="max-height:100px;max-width:100px;object-fit:cover"></a>
              <?php endforeach; ?>
            </div>
          <?php endif; endif; ?>

          <?php if ($chamado_detalhe['status']==='Concluído' && $chamado_detalhe['resolucao']): ?>
            <h6 class="fw-bold border-bottom pb-2 mb-2 mt-3 text-success"><i class="bi bi-check-circle-fill me-1"></i>Resolução</h6>
            <p class="p-3 rounded border border-success" style="background:#f0fdf4;font-size:13.5px;white-space:pre-wrap"><?= h($chamado_detalhe['resolucao']) ?></p>
          <?php endif; ?>

          <?php if ($chamado_detalhe['status']==='Concluído'):
            $av=$pdo->prepare("SELECT * FROM avaliacoes WHERE chamado_id=?");
            $av->execute([$chamado_detalhe['id']]); $avaliacao=$av->fetch(); ?>
            <div class="mt-3 p-3 rounded border" style="background:#fffbeb">
              <?php if ($avaliacao): ?>
                <div class="fw-semibold mb-1" style="font-size:13px"><i class="bi bi-star-fill text-warning me-1"></i>Sua avaliação</div>
                <div style="font-size:20px;letter-spacing:2px"><?php for($i=1;$i<=5;$i++) echo $i<=$avaliacao['nota']?'⭐':'☆'; ?></div>
                <?php if($avaliacao['comentario']): ?><p class="text-muted mt-1 mb-0" style="font-size:13px">"<?= h($avaliacao['comentario']) ?>"</p><?php endif; ?>
              <?php else: ?>
                <div class="fw-semibold mb-2" style="font-size:13px"><i class="bi bi-star me-1 text-warning"></i>Avalie o atendimento</div>
                <form method="post">
                  <?= csrfField() ?>
                  <input type="hidden" name="avaliar_token" value="<?= h($chamado_detalhe['avaliacao_token'] ?? '') ?>">
                  <input type="hidden" name="aba" value="ti">
                  <input type="hidden" name="subaba" value="acompanhar">
                  <input type="hidden" name="numero_chamado" value="<?= h($chamado_detalhe['numero']) ?>">
                  <input type="hidden" name="t" value="<?= h($token_chamado) ?>">
                  <div class="d-flex gap-2 mb-2" id="estrelas">
                    <?php for($i=1;$i<=5;$i++): ?><label title="<?= $i ?> estrela(s)" style="cursor:pointer;font-size:24px" onclick="setNota(<?= $i ?>)">☆</label><?php endfor; ?>
                  </div>
                  <input type="hidden" name="nota" id="nota" value="">
                  <textarea name="comentario" maxlength="2000" class="form-control form-control-sm mb-2" rows="2" placeholder="Comentário opcional..."></textarea>
                  <button type="submit" class="btn btn-warning btn-sm fw-semibold" id="btnAvaliar" disabled>Enviar avaliação</button>
                </form>
              <?php endif; ?>
            </div>
          <?php endif; ?>
        </div>
        <div class="col-md-5">
          <h6 class="fw-bold border-bottom pb-2 mb-3">Atualizações</h6>
          <?php if ($historico): ?>
            <div class="timeline">
              <?php foreach($historico as $hl): $sys=!$hl['usuario_id']; ?>
                <div class="tl-item">
                  <div class="tl-dot <?= $sys?'sys':'' ?>"></div>
                  <div class="tl-time"><?= date('d/m/Y H:i',strtotime($hl['criado_em'])) ?></div>
                  <div class="tl-text"><strong><?= h($hl['usu']??'Sistema') ?>:</strong> <span class="text-muted"><?= h($hl['acao']) ?></span></div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php else: ?>
            <p class="text-muted text-center py-3" style="font-size:13px">Nenhuma atualização ainda.</p>
          <?php endif; ?>
        </div>
      </div>
      <?php endif; ?>
    </div>
  </div>

<?php else: ?>
  <!-- RASTREAR CHAMADO (sem listagem pública — P0-4) -->
  <div class="panel-card">
    <div class="panel-head">
      <div><h2><i class="bi bi-pc-display-horizontal"></i> Rastrear Chamado</h2><p>Informe o número ou cole o link de acompanhamento</p></div>
    </div>
    <div class="panel-body">
      <form method="get" class="row g-2 align-items-end" data-rastrear="ti">
        <input type="hidden" name="aba" value="ti">
        <input type="hidden" name="subaba" value="acompanhar">
        <div class="col-12 col-md-9">
          <label class="form-label fw-bold text-primary" style="font-size:12px">Número do chamado ou link de acompanhamento</label>
          <input type="text" name="numero_chamado" class="form-control" placeholder="CHM-2026-00001" autocomplete="off" required>
        </div>
        <div class="col-12 col-md-3">
          <button type="submit" class="btn btn-primary w-100 py-2 fw-semibold">Buscar</button>
        </div>
      </form>
      <p class="text-muted mt-3 mb-0" style="font-size:12px">
        <i class="bi bi-info-circle me-1"></i>Só com o <strong>número</strong> você vê a situação atual.
        Colando o <strong>link de acompanhamento</strong> (o que aparece ao abrir o chamado) você vê tudo — descrição, histórico e avaliação.
      </p>
    </div>
  </div>
<?php endif; ?>

<!-- Chamados abertos neste navegador (localStorage) — aparece em ambas as visões -->
<div class="meus-itens" id="meusItensTi" style="display:none">
  <div class="meus-itens-label"><i class="bi bi-clock-history me-1"></i>Abertos neste navegador</div>
  <div class="meus-itens-lista" id="meusItensTiLista"></div>
</div>

<?php
// ═══════════════════════════════════════
//  ABA SUPRIMENTOS → SOLICITAR
// ═══════════════════════════════════════
elseif ($aba === 'sup' && $subaba === 'pedir'):
?>

<div class="form-card">
  <?php if ($sup_sucesso): ?>
    <div class="success-box"
         data-registrar="sup"
         data-numero="<?= h($sup_sucesso) ?>"
         data-token="<?= h($sup_sucesso_token) ?>">
      <div class="chk"><i class="bi bi-check-circle-fill"></i></div>
      <h3>Pedido enviado com sucesso!</h3>
      <p>A equipe de TI vai separar os itens e entregar no seu setor.</p>

      <button type="button" class="num copiavel" data-copy="<?= h($sup_sucesso) ?>" data-copy-label="Número copiado!" title="Copiar número">
        <?= h($sup_sucesso) ?> <i class="bi bi-clipboard ms-1" style="font-size:13px;opacity:.55"></i>
      </button>
      <p class="ss-hint">Guarde este número para consultar o pedido depois.</p>

      <div class="d-flex flex-column gap-2 mt-3 align-items-center">
        <a href="?aba=sup&subaba=pedir" class="btn-send" style="max-width:260px;text-decoration:none;text-align:center"><i class="bi bi-plus-circle me-1"></i>Fazer outro pedido</a>
        <a href="?aba=sup&subaba=acompanhar&numero_sup=<?= urlencode($sup_sucesso) ?>&t=<?= urlencode($sup_sucesso_token) ?>" class="ss-link-sec">Acompanhar este pedido →</a>
      </div>
    </div>
  <?php else: ?>
    <div class="form-card-header">
      <div class="ico"><i class="bi bi-cart-plus"></i></div>
      <h2>Pedido de Suprimentos</h2>
      <p>Solicite toners, bobinas ou papel para o seu setor</p>
    </div>
    <div class="form-card-body">
      <?php if ($sup_erros): ?>
        <div class="alert alert-danger py-2 px-3 mb-3" style="font-size:13px"><?= implode('<br>',array_map('h',$sup_erros)) ?></div>
      <?php endif; ?>
      <form method="post" id="supForm" novalidate>
        <input type="hidden" name="_form" value="pedir_suprimento">
        <?= csrfField() ?>
        <div class="mb-3">
          <label class="form-label">Seu nome completo</label>
          <input type="text" name="solicitante" class="form-control" placeholder="Ex: Maria Oliveira" value="<?= h($_POST['solicitante']??'') ?>" required autocomplete="name">
        </div>
        <div class="mb-3">
          <label class="form-label">Setor Solicitante</label>
          <select name="setor" id="supSetor" class="form-select" required>
            <option value="">— Selecione seu setor —</option>
            <?php foreach($SETORES as $s): $sel=(($pre_setor_sup===$s)||($_POST['setor']??'')===$s)?'selected':''; ?>
              <option value="<?= h($s) ?>" <?= $sel ?>><?= h($s) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="mb-4">
          <label class="form-label">Impressora Vinculada <span class="text-muted">(opcional)</span></label>
          <select name="impressora_id" id="supImpressora" class="form-select">
            <option value="" data-setor="">— Geral do Setor / Nenhuma específica —</option>
            <?php foreach($impressoras as $imp):
              $sel=($impressora_get_id===(int)$imp['id']||($_POST['impressora_id']??'')==$imp['id'])?'selected':''; ?>
              <option value="<?= $imp['id'] ?>" data-setor="<?= h($imp['setor']) ?>" <?= $sel ?>><?= h($imp['nome']) ?></option>
            <?php endforeach; ?>
          </select>
          <div id="printerHelper" class="text-muted mt-1" style="font-size:11.5px;display:none"><i class="bi bi-info-circle me-1"></i>Mostrando impressoras do setor selecionado.</div>
        </div>
        <hr style="opacity:.1">
        <label class="form-label fw-bold mb-2" style="font-size:14px;color:var(--brand)">Lista de Insumos</label>
        <div class="cart-box">
          <div id="itensContainer">
            <?php foreach($tipos_ids_post as $idx=>$tipo_post):
              $qtd_post=$quantidades_post[$idx]??1;
              $desc_post=$descricoes_post[$idx]??''; ?>
              <div class="item-row">
                <div class="d-flex align-items-center gap-2">
                  <div class="flex-grow-1">
                    <select name="tipo_suprimento_id[]" class="form-select select-insumo" required>
                      <option value="">— Selecione o Insumo —</option>
                      <?php foreach($tipos_suprimentos as $t): ?><option value="<?= $t['id'] ?>" <?= ((string)$tipo_post===(string)$t['id'])?'selected':'' ?>><?= h($t['nome']) ?></option><?php endforeach; ?>
                      <option value="outro" <?= $tipo_post==='outro'?'selected':'' ?>>+ Outros (Especificar)</option>
                    </select>
                  </div>
                  <div style="width:72px"><input type="number" name="quantidade[]" class="form-control text-center" min="1" max="50" value="<?= h($qtd_post) ?>" required></div>
                  <button type="button" class="btn-remove-item" title="Remover"><i class="bi bi-x-lg"></i></button>
                </div>
                <div class="mt-2 container-desc-livre" style="<?= $tipo_post==='outro'?'display:block':'display:none' ?>;padding-right:44px">
                  <input type="text" name="descricao_livre[]" class="form-control input-desc-livre" placeholder="Descreva o insumo..." value="<?= h($desc_post) ?>">
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
        <button type="button" class="btn-add-item mb-3" id="btnAddItem"><i class="bi bi-plus-lg me-1"></i>Adicionar outro insumo</button>
        <div class="mb-3">
          <label class="form-label">Observações <span class="text-muted">(opcional)</span></label>
          <textarea name="observacoes" class="form-control" rows="2" placeholder="Ex: Entregar com urgência na sala 2..."><?= h($_POST['observacoes']??'') ?></textarea>
        </div>
        <button type="submit" class="btn-send"><i class="bi bi-send-fill me-2"></i>Enviar Pedido</button>
      </form>
    </div>
  <?php endif; ?>
</div>

<template id="itemTemplate">
  <div class="item-row">
    <div class="d-flex align-items-center gap-2">
      <div class="flex-grow-1">
        <select name="tipo_suprimento_id[]" class="form-select select-insumo" required>
          <option value="">— Selecione o Insumo —</option>
          <?php foreach($tipos_suprimentos as $t): ?><option value="<?= $t['id'] ?>"><?= h($t['nome']) ?></option><?php endforeach; ?>
          <option value="outro">+ Outros (Especificar)</option>
        </select>
      </div>
      <div style="width:72px"><input type="number" name="quantidade[]" class="form-control text-center" min="1" max="50" value="1" required></div>
      <button type="button" class="btn-remove-item" title="Remover"><i class="bi bi-x-lg"></i></button>
    </div>
    <div class="mt-2 container-desc-livre" style="display:none;padding-right:44px">
      <input type="text" name="descricao_livre[]" class="form-control input-desc-livre" placeholder="Descreva o insumo...">
    </div>
  </div>
</template>

<?php
// ═══════════════════════════════════════
//  ABA SUPRIMENTOS → ACOMPANHAR
// ═══════════════════════════════════════
elseif ($aba === 'sup' && $subaba === 'acompanhar'):
?>

<?php if ($sup_erro_busca): ?>
  <div class="alert alert-warning alert-dismissible fade show mb-3"><i class="bi bi-exclamation-triangle-fill me-2"></i><?= h($sup_erro_busca) ?><button class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>

<?php if ($pedido_detalhe): ?>
  <!-- DETALHE PEDIDO -->
  <div class="panel-card">
    <div class="panel-head">
      <div>
        <a href="?aba=sup&subaba=acompanhar" class="text-white text-decoration-none" style="font-size:12px"><i class="bi bi-arrow-left me-1"></i>Voltar</a>
        <h2 class="mt-1"><i class="bi bi-box-seam"></i> <?= h($pedido_detalhe['numero']) ?></h2>
      </div>
      <?= sbSup($pedido_detalhe['status']) ?>
    </div>
    <div class="panel-body">
      <?php
        $tw='0%'; $s1='active'; $s2=''; $s3='';
        if ($pedido_detalhe['status']==='Aprovado') { $tw='50%'; $s2='current'; }
        elseif ($pedido_detalhe['status']==='Entregue') { $tw='100%'; $s2='active'; $s3='active'; }
        elseif ($pedido_detalhe['status']==='Cancelado') { $s1='active'; }
        else { $s1='current'; }
      ?>
      <?php if ($pedido_detalhe['status']==='Cancelado'): ?>
        <div class="alert alert-danger d-flex align-items-center mb-4"><i class="bi bi-x-circle-fill me-2"></i><div>Este pedido foi <strong>Cancelado</strong> pela equipe de T.I.</div></div>
      <?php else: ?>
        <div class="st-track">
          <div class="st-prog" style="width:<?= $tw ?>"></div>
          <div class="st-step <?= $s1 ?>"><div class="st-ico"><i class="bi bi-file-earmark-plus"></i></div><div class="st-lbl">Registrado</div></div>
          <div class="st-step <?= $s2 ?>"><div class="st-ico"><i class="bi bi-hourglass-split"></i></div><div class="st-lbl">Em Separação</div></div>
          <div class="st-step <?= $s3 ?>"><div class="st-ico"><i class="bi bi-check-lg"></i></div><div class="st-lbl">Entregue</div></div>
        </div>
      <?php endif; ?>

      <?php if (!$acesso_sup_completo): ?>
        <div class="row g-3 mb-2">
          <div class="col-6"><div class="text-muted small">Setor</div><div class="fw-semibold"><?= h($pedido_detalhe['setor']) ?></div></div>
          <div class="col-6"><div class="text-muted small">Solicitado em</div><div><?= date('d/m/Y H:i',strtotime($pedido_detalhe['criado_em'])) ?></div></div>
          <div class="col-6"><div class="text-muted small">Situação</div><div class="fw-semibold"><?= h($pedido_detalhe['status']) ?></div></div>
        </div>
        <div class="alert alert-light border mt-3" style="font-size:12.5px">
          <i class="bi bi-info-circle me-1"></i>Acima está a <strong>situação atual</strong> do pedido.
          Para ver os itens e os detalhes, abra o <strong>link de acompanhamento</strong> gerado ao enviar o pedido —
          ou escolha-o na lista <strong>“Abertos neste navegador”</strong> logo abaixo.
        </div>
      <?php else: ?>
      <div class="row g-4">
        <div class="col-md-7">
          <h6 class="fw-bold border-bottom pb-2 mb-3">Informações do Pedido</h6>
          <div class="row g-3 mb-3">
            <div class="col-6"><div class="text-muted small">Solicitante</div><div class="fw-semibold"><?= h($pedido_detalhe['solicitante']) ?></div></div>
            <div class="col-6"><div class="text-muted small">Setor</div><div class="fw-semibold"><?= h($pedido_detalhe['setor']) ?></div></div>
            <div class="col-6"><div class="text-muted small">Solicitado em</div><div><?= date('d/m/Y H:i',strtotime($pedido_detalhe['criado_em'])) ?></div></div>
            <div class="col-6"><div class="text-muted small">Impressora</div><div><?= h($pedido_detalhe['impressora_nome']??'Uso Geral') ?></div></div>
          </div>
          <h6 class="fw-bold border-bottom pb-2 mb-2">Itens Solicitados</h6>
          <div class="table-responsive">
            <table class="table table-sm table-bordered">
              <thead class="table-light"><tr><th>Insumo</th><th class="text-center" style="width:70px">Qtd</th></tr></thead>
              <tbody>
                <?php foreach($itens_detalhe as $item): ?>
                  <tr>
                    <td><strong><?= h($item['tipo_nome']??'Outros') ?></strong><?php if($item['descricao_livre']): ?><br><span class="text-muted small"><?= h($item['descricao_livre']) ?></span><?php endif; ?></td>
                    <td class="text-center"><span class="badge bg-primary"><?= (int)$item['quantidade'] ?></span></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <?php if($pedido_detalhe['observacoes']): ?><p class="text-muted small p-2 border rounded" style="white-space:pre-wrap"><?= h($pedido_detalhe['observacoes']) ?></p><?php endif; ?>
        </div>
        <div class="col-md-5">
          <h6 class="fw-bold border-bottom pb-2 mb-3">Status e Entrega</h6>
          <div class="p-3 rounded border <?= $pedido_detalhe['status']==='Entregue'?'border-success bg-success-subtle':'border-warning bg-warning-subtle' ?>" style="font-size:13px">
            <div class="d-flex align-items-center gap-2 mb-2">
              <i class="bi <?= $pedido_detalhe['status']==='Entregue'?'bi-check-circle-fill text-success':'bi-info-circle-fill text-warning' ?>" style="font-size:18px"></i>
              <strong>Estágio: <?= h($pedido_detalhe['status']) ?></strong>
            </div>
            <?php if($pedido_detalhe['status']==='Entregue'): ?>
              <p class="mb-0">Seus insumos foram entregues!</p>
            <?php elseif($pedido_detalhe['status']==='Aprovado'): ?>
              <p class="mb-0">Aprovado! Itens em fase de separação/transporte.</p>
            <?php elseif($pedido_detalhe['status']==='Pendente'): ?>
              <p class="mb-0">Aguardando análise da equipe de T.I.</p>
            <?php else: ?>
              <p class="mb-0">Este pedido foi cancelado.</p>
            <?php endif; ?>
          </div>
        </div>
      </div>
      <?php endif; /* acesso_sup_completo */ ?>
    </div>
  </div>

<?php else: ?>
  <!-- RASTREAR PEDIDO (sem listagem pública — P0-4) -->
  <div class="panel-card">
    <div class="panel-head">
      <div><h2><i class="bi bi-box-seam"></i> Rastrear Pedido</h2><p>Informe o número ou cole o link de acompanhamento</p></div>
    </div>
    <div class="panel-body">
      <form method="get" class="row g-2 align-items-end" data-rastrear="sup">
        <input type="hidden" name="aba" value="sup">
        <input type="hidden" name="subaba" value="acompanhar">
        <div class="col-12 col-md-9">
          <label class="form-label fw-bold text-primary" style="font-size:12px">Número do pedido ou link de acompanhamento</label>
          <input type="text" name="numero_sup" class="form-control" placeholder="SUP-2026-00001" autocomplete="off" required>
        </div>
        <div class="col-12 col-md-3">
          <button type="submit" class="btn btn-primary w-100 py-2 fw-semibold">Buscar</button>
        </div>
      </form>
      <p class="text-muted mt-3 mb-0" style="font-size:12px">
        <i class="bi bi-info-circle me-1"></i>Só com o <strong>número</strong> você vê a situação atual.
        Colando o <strong>link de acompanhamento</strong> você vê os itens e os detalhes do pedido.
      </p>
    </div>
  </div>
<?php endif; ?>

<!-- Pedidos abertos neste navegador (localStorage) — aparece em ambas as visões -->
<div class="meus-itens" id="meusItensSup" style="display:none">
  <div class="meus-itens-label"><i class="bi bi-clock-history me-1"></i>Abertos neste navegador</div>
  <div class="meus-itens-lista" id="meusItensSupLista"></div>
</div>

<?php endif; ?>

  </div><!-- /conteúdo tabs -->
</div><!-- /portal-card -->

<div class="portal-footer">
  <a href="login.php"><i class="bi bi-shield-lock"></i>Área Restrita</a>
  <div class="portal-footer-vendor">by <?= APP_VENDOR ?></div>
</div>

</div><!-- /portal-body -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

<script>
// ── Clique para copiar (número, código, link) ──────────────
(function () {
  function copiar(texto) {
    if (navigator.clipboard && window.isSecureContext) return navigator.clipboard.writeText(texto);
    return new Promise(function (res, rej) {
      var ta = document.createElement('textarea');
      ta.value = texto; ta.style.position = 'fixed'; ta.style.opacity = '0';
      document.body.appendChild(ta); ta.select();
      try { document.execCommand('copy'); res(); } catch (e) { rej(e); }
      document.body.removeChild(ta);
    });
  }
  document.addEventListener('click', function (e) {
    var el = e.target.closest('.copiavel');
    if (!el) return;
    var txt = el.getAttribute('data-copy');
    var label = el.getAttribute('data-copy-label') || 'Copiado!';
    var original = el.innerHTML;
    function feedback(ok) {
      el.classList.add('copiado');
      el.innerHTML = '<i class="bi bi-' + (ok ? 'check-lg' : 'exclamation-triangle') + ' me-1"></i>' + (ok ? label : 'Selecione e copie');
      setTimeout(function () { el.classList.remove('copiado'); el.innerHTML = original; }, ok ? 1600 : 2600);
    }
    copiar(txt).then(function () { feedback(true); }).catch(function () {
      try { window.prompt('Copie manualmente (Ctrl+C):', txt); } catch (e) { feedback(false); }
    });
  });
})();

// ── Rastrear: aceita número OU um link de acompanhamento colado ────
(function () {
  document.querySelectorAll('form[data-rastrear]').forEach(function (form) {
    form.addEventListener('submit', function (e) {
      var tipo = form.dataset.rastrear;
      var campo = form.querySelector('input[name="numero_' + (tipo === 'ti' ? 'chamado' : 'sup') + '"]');
      var v = (campo.value || '').trim();
      if (!/[?&](numero_chamado|numero_sup)=/.test(v)) return; // é só o número → segue o fluxo normal
      // é um link colado — extrai os parâmetros e navega direto
      try {
        var qs = v.slice(v.indexOf('?') + 1);
        var p = new URLSearchParams(qs);
        var num = p.get('numero_chamado') || p.get('numero_sup');
        var tok = p.get('t') || '';
        if (num) {
          e.preventDefault();
          var pnum = tipo === 'ti' ? 'numero_chamado' : 'numero_sup';
          location.href = location.pathname + '?aba=' + (tipo === 'ti' ? 'ti' : 'sup') +
            '&subaba=acompanhar&' + pnum + '=' + encodeURIComponent(num) +
            (tok ? '&t=' + encodeURIComponent(tok) : '');
        }
      } catch (err) { /* link malformado → deixa submeter normal */ }
    });
  });
})();

// ── "Meus itens neste navegador" (localStorage) ───────────────
(function () {
  var KEY = 'helpti_portal_itens';
  function ler() { try { return JSON.parse(localStorage.getItem(KEY) || '[]'); } catch (e) { return []; } }
  function salvar(l) { try { localStorage.setItem(KEY, JSON.stringify(l.slice(0, 30))); } catch (e) {} }

  function linkAcompanhamento(tipo, numero, token) {
    var pnum = tipo === 'ti' ? 'numero_chamado' : 'numero_sup';
    return location.pathname + '?aba=' + (tipo === 'ti' ? 'ti' : 'sup') +
           '&subaba=acompanhar&' + pnum + '=' + encodeURIComponent(numero) +
           (token ? '&t=' + encodeURIComponent(token) : '');
  }

  // registra o item recém-aberto (data-registrar no .success-box)
  var box = document.querySelector('.success-box[data-registrar]');
  if (box && box.dataset.numero) {
    var lista = ler().filter(function (i) { return i.numero !== box.dataset.numero; });
    lista.unshift({
      tipo: box.dataset.registrar, numero: box.dataset.numero,
      token: box.dataset.token || '', ts: Date.now()
    });
    salvar(lista);
  }

  function fmtData(ts) {
    var d = new Date(ts);
    return d.toLocaleDateString('pt-BR') + ' ' + d.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' });
  }
  function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : s; return d.innerHTML; }

  function render(tipo, wrapId, listId) {
    var wrap = document.getElementById(wrapId), cont = document.getElementById(listId);
    if (!wrap || !cont) return;
    var itens = ler().filter(function (i) { return i.tipo === tipo; });
    if (!itens.length) { wrap.style.display = 'none'; return; }
    cont.innerHTML = itens.map(function (i) {
      var url = linkAcompanhamento(tipo, i.numero, i.token);
      return '<div class="meu-item">' +
        '<a class="mi-info" href="' + url + '">' +
          '<span class="mi-num">' + esc(i.numero) + '</span>' +
          '<span class="mi-data">' + fmtData(i.ts) + '</span>' +
          '<i class="bi bi-chevron-right mi-go"></i>' +
        '</a>' +
        '<button class="mi-esquecer" title="Remover da lista" data-esquecer="' + esc(i.numero) + '"><i class="bi bi-x-lg"></i></button>' +
      '</div>';
    }).join('');
    wrap.style.display = '';
  }

  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-esquecer]');
    if (!b) return;
    e.preventDefault();
    salvar(ler().filter(function (i) { return i.numero !== b.getAttribute('data-esquecer'); }));
    render('ti', 'meusItensTi', 'meusItensTiLista');
    render('sup', 'meusItensSup', 'meusItensSupLista');
  });

  render('ti', 'meusItensTi', 'meusItensTiLista');
  render('sup', 'meusItensSup', 'meusItensSupLista');
})();

// ── Validação de imagens + preview em chips ──
const imgInput = document.getElementById('imagens');
if (imgInput) {
  const fileList  = document.getElementById('file-list');
  const uploadZone = document.getElementById('uploadZone');
  const zoneText   = uploadZone?.querySelector('.upload-zone-text');
  imgInput.addEventListener('change', function() {
    const excedeu = this.files.length > 3;
    document.getElementById('file-error').style.display = excedeu ? 'block' : 'none';
    if (excedeu) { this.value = ''; fileList.innerHTML = ''; if (zoneText) zoneText.textContent = 'Toque para escolher imagens'; return; }

    fileList.innerHTML = '';
    Array.from(this.files).forEach(f => {
      const chip = document.createElement('span');
      chip.className = 'file-chip';
      chip.innerHTML = '<i class="bi bi-image"></i><span>' + f.name.replace(/[<>&"]/g, '') + '</span>';
      fileList.appendChild(chip);
    });
    if (zoneText) zoneText.textContent = this.files.length
      ? this.files.length + ' imagem(ns) selecionada(s)'
      : 'Toque para escolher imagens';
  });
}

// ── Estrelas avaliação ──
function setNota(n) {
  document.getElementById('nota').value = n;
  document.getElementById('btnAvaliar').disabled = false;
  document.querySelectorAll('#estrelas label').forEach((s,i) => s.textContent = i < n ? '⭐' : '☆');
}

// ── Auto-polling chamado ──
<?php if (!empty($chamado_detalhe) && $chamado_detalhe['status'] !== 'Concluído'): ?>
(function() {
  const numero = <?= json_encode($chamado_detalhe['numero']) ?>;
  const atual  = <?= json_encode($chamado_detalhe['status']) ?>;
  setInterval(function() {
    fetch('status_chamado.php?numero=' + encodeURIComponent(numero))
      .then(r => r.ok ? r.json() : null)
      .then(d => { if (d && d.status !== atual) location.reload(); })
      .catch(() => {});
  }, 30000);
})();
<?php endif; ?>

// ── Suprimentos: filtro impressoras por setor ──
const supSetor = document.getElementById('supSetor');
const supImp   = document.getElementById('supImpressora');
const prHelper = document.getElementById('printerHelper');
if (supSetor && supImp) {
  const origOpts = Array.from(supImp.options);
  function filterPrinters() {
    const sel = supSetor.value;
    supImp.innerHTML = '';
    supImp.appendChild(origOpts[0]);
    let cnt = 0;
    origOpts.forEach((o, i) => {
      if (i === 0) return;
      if (!sel || o.getAttribute('data-setor') === sel) { supImp.appendChild(o); cnt++; }
    });
    if (prHelper) prHelper.style.display = (sel && cnt > 0) ? 'block' : 'none';
  }
  supSetor.addEventListener('change', filterPrinters);
  if (supSetor.value) filterPrinters();
}

// ── Suprimentos: carrinho de itens ──
const itensContainer = document.getElementById('itensContainer');
const itemTemplate   = document.getElementById('itemTemplate');
const btnAddItem     = document.getElementById('btnAddItem');

function bindItemEvents(row) {
  const sel  = row.querySelector('.select-insumo');
  const wrap = row.querySelector('.container-desc-livre');
  const inp  = row.querySelector('.input-desc-livre');
  if (sel) sel.addEventListener('change', function() {
    const isOutro = this.value === 'outro';
    wrap.style.display = isOutro ? 'block' : 'none';
    if (isOutro) { inp.setAttribute('required','required'); inp.focus(); }
    else { inp.removeAttribute('required'); inp.value = ''; }
  });
  const btnRm = row.querySelector('.btn-remove-item');
  if (btnRm) btnRm.addEventListener('click', function() {
    if (itensContainer && itensContainer.querySelectorAll('.item-row').length > 1) row.remove();
    else alert('Adicione pelo menos um insumo.');
  });
}

if (itensContainer) itensContainer.querySelectorAll('.item-row').forEach(bindItemEvents);
if (btnAddItem && itemTemplate) {
  btnAddItem.addEventListener('click', function() {
    const clone = itemTemplate.content.cloneNode(true);
    const row = clone.querySelector('.item-row');
    bindItemEvents(row);
    itensContainer.appendChild(row);
  });
}

// ── IA: classificar chamado ─────────────────────────────────
(function() {
  const btn = document.getElementById('btn-ia-classificar');
  if (!btn) return;

  let sugestaoIA = null;

  btn.addEventListener('click', function() {
    const descricao = (document.getElementById('ia-descricao')?.value || '').trim();
    if (!descricao) { alert('Descreva o problema antes de classificar.'); return; }

    const status = document.getElementById('ia-classificar-status');
    const caixa  = document.getElementById('ia-sugestao');
    const texto  = document.getElementById('ia-sugestao-texto');

    btn.disabled = true;
    status.style.display = 'inline';

    const csrf = document.querySelector('input[name=csrf_token]')?.value || '';
    fetch('api_ia.php', {
      method: 'POST',
      headers: {'Content-Type': 'application/x-www-form-urlencoded'},
      body: new URLSearchParams({action:'classificar', descricao, csrf_token: csrf})
    })
    .then(r => r.json())
    .then(d => {
      btn.disabled = false;
      status.style.display = 'none';
      if (!d.ok) { alert('IA: ' + (d.erro || 'Erro desconhecido')); return; }
      sugestaoIA = d;
      texto.textContent = d.nivel + (d.categoria ? ' · ' + d.categoria : '') + (d.justificativa ? ' — ' + d.justificativa : '');
      caixa.style.display = 'block';
    })
    .catch(() => { btn.disabled = false; status.style.display = 'none'; alert('Erro ao contactar IA.'); });
  });

  document.getElementById('btn-ia-aceitar')?.addEventListener('click', function() {
    if (!sugestaoIA) return;
    // setar nivel no select dentro do painel interno (portal não tem nivel no form público)
    document.getElementById('ia-sugestao').style.display = 'none';
  });
})();
</script>
</body>
</html>
