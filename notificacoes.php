<?php
require 'db.php';
requireLogin();
header('Content-Type: application/json');

$pdo = db();
$u = usuario();
$itens = [];

// Endpoint polado a cada 60s — nunca deve derrubar o painel se uma query falhar.
try {

// Chamados atribuídos a mim, ainda não iniciados (assinalado e esquecido)
$meus_novos = $pdo->prepare("
    SELECT id, numero, setor, descricao
    FROM chamados
    WHERE responsavel_id = ? AND status = 'Aberto' AND deleted_at IS NULL
    ORDER BY criado_em ASC LIMIT 10
");
$meus_novos->execute([$u['id']]);
$meus_novos = $meus_novos->fetchAll();
foreach ($meus_novos as $r) {
    $itens[] = [
        'tipo'  => 'atribuido',
        'icon'  => 'bi-person-check-fill',
        'cor'   => '#0d6efd',
        'texto' => "Chamado {$r['numero']} ({$r['setor']}) foi atribuído a você",
        'link'  => "chamado.php?id={$r['id']}",
        'label' => 'Atender',
    ];
}

// Chamados Alta Complexidade há mais de 2h sem solução
$urgentes = $pdo->query("
    SELECT id, numero, setor, descricao,
           TIMESTAMPDIFF(MINUTE, criado_em, NOW()) AS minutos
    FROM chamados
    WHERE status IN ('Aberto','Em Andamento','Pendente')
      AND nivel = 'Alta Complexidade'
      AND criado_em <= NOW() - INTERVAL 2 HOUR
    ORDER BY criado_em ASC LIMIT 10
")->fetchAll();
foreach ($urgentes as $r) {
    $h = floor($r['minutos'] / 60); $m = $r['minutos'] % 60;
    $itens[] = [
        'tipo'  => 'urgente',
        'icon'  => 'bi-exclamation-octagon-fill',
        'cor'   => '#ef4444',
        'texto' => "Chamado {$r['numero']} ({$r['setor']}) sem solução há {$h}h{$m}m",
        'link'  => "chamado.php?id={$r['id']}",
        'label' => 'Atender',
    ];
}

// Chamados abertos sem responsável
$sem_resp = (int)$pdo->query("SELECT COUNT(*) FROM chamados WHERE responsavel_id IS NULL AND status='Aberto'")->fetchColumn();
if ($sem_resp > 0) {
    $itens[] = [
        'tipo'  => 'sem_resp',
        'icon'  => 'bi-person-exclamation',
        'cor'   => '#f59e0b',
        'texto' => "{$sem_resp} chamado(s) aberto(s) sem responsável",
        'link'  => 'chamados.php?status=Aberto&resp=0',
        'label' => 'Ver',
    ];
}

// Contratos renovados automaticamente nas últimas 24h (avisa que aconteceu)
$tabela_renov = (bool)$pdo->query(
    "SELECT COUNT(*) FROM information_schema.tables
     WHERE table_schema = DATABASE() AND table_name = 'contratos_renovacoes'"
)->fetchColumn();
if ($tabela_renov) {
    $renovados = $pdo->query("
        SELECT r.contrato_id, r.data_nova, c.nome
        FROM contratos_renovacoes r JOIN contratos c ON c.id = r.contrato_id
        WHERE r.tipo='auto' AND r.criado_em >= NOW() - INTERVAL 24 HOUR
        ORDER BY r.criado_em DESC LIMIT 5
    ")->fetchAll();
    foreach ($renovados as $r) {
        $itens[] = [
            'tipo'  => 'contrato_renovado',
            'icon'  => 'bi-arrow-repeat',
            'cor'   => '#22c55e',
            'texto' => "Contrato \"{$r['nome']}\" foi renovado automaticamente até " . date('d/m/Y', strtotime($r['data_nova'])),
            'link'  => "contratos.php",
            'label' => 'Ver',
        ];
    }
}

// Contratos vencendo em 30 dias
$contratos = $pdo->query("
    SELECT id, nome, DATEDIFF(data_vencimento, CURDATE()) AS dias
    FROM contratos
    WHERE status='Ativo' AND data_vencimento BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
    ORDER BY data_vencimento ASC LIMIT 5
")->fetchAll();
foreach ($contratos as $r) {
    $itens[] = [
        'tipo'  => 'contrato',
        'icon'  => 'bi-file-earmark-check',
        'cor'   => '#0ea5e9',
        'texto' => "Contrato \"{$r['nome']}\" vence em {$r['dias']} dia(s)",
        'link'  => "contratos.php?action=editar&id={$r['id']}",
        'label' => 'Renovar',
    ];
}

// Suprimentos pendentes de aprovação
$sup = (int)$pdo->query("SELECT COUNT(*) FROM pedidos_suprimentos WHERE status='Pendente'")->fetchColumn();
if ($sup > 0) {
    $itens[] = [
        'tipo'  => 'suprimento',
        'icon'  => 'bi-box-seam',
        'cor'   => '#8b5cf6',
        'texto' => "{$sup} pedido(s) de suprimento aguardando aprovação",
        'link'  => 'pedidos_suprimentos.php',
        'label' => 'Aprovar',
    ];
}

// Suprimentos com estoque abaixo do mínimo
$est_baixo = $pdo->query("
    SELECT id, nome, estoque_atual, estoque_minimo
    FROM tipos_suprimentos
    WHERE ativo=1 AND estoque_minimo > 0 AND estoque_atual <= estoque_minimo
    ORDER BY (estoque_minimo - estoque_atual) DESC LIMIT 5
")->fetchAll();
foreach ($est_baixo as $r) {
    $falta = $r['estoque_minimo'] - $r['estoque_atual'];
    $itens[] = [
        'tipo'  => 'estoque',
        'icon'  => 'bi-box-seam-fill',
        'cor'   => '#ef4444',
        'texto' => "Estoque baixo: {$r['nome']} — {$r['estoque_atual']} un. (mín. {$r['estoque_minimo']})",
        'link'  => "editar_tipo_suprimento.php?id={$r['id']}",
        'label' => 'Ajustar',
    ];
}

// Termos vencidos ou vencendo em 7 dias
$termos = $pdo->query("
    SELECT t.id, t.responsavel_nome, i.marca, i.modelo,
           DATEDIFF(t.data_prevista_devolucao, CURDATE()) AS dias
    FROM termos_uso t JOIN inventario i ON i.id = t.inventario_id
    WHERE t.status='Ativo' AND t.data_prevista_devolucao IS NOT NULL
      AND t.data_prevista_devolucao <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)
    ORDER BY t.data_prevista_devolucao ASC LIMIT 5
")->fetchAll();
foreach ($termos as $r) {
    $dias = (int)$r['dias'];
    $txt  = $dias < 0
        ? "Empréstimo de {$r['marca']} {$r['modelo']} ({$r['responsavel_nome']}) vencido há ".abs($dias)." dia(s)"
        : "Empréstimo de {$r['marca']} {$r['modelo']} ({$r['responsavel_nome']}) vence em {$dias} dia(s)";
    $itens[] = [
        'tipo'  => 'termo',
        'icon'  => 'bi-person-clock',
        'cor'   => $dias < 0 ? '#ef4444' : '#f59e0b',
        'texto' => $txt,
        'link'  => 'termos.php',
        'label' => 'Ver',
    ];
}

// Garantias vencendo em 60 dias
$garantias = $pdo->query("
    SELECT id, marca, modelo, DATEDIFF(garantia_ate, CURDATE()) AS dias
    FROM inventario
    WHERE garantia_ate BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 60 DAY)
    ORDER BY garantia_ate ASC LIMIT 5
")->fetchAll();
foreach ($garantias as $r) {
    $itens[] = [
        'tipo'  => 'garantia',
        'icon'  => 'bi-shield-exclamation',
        'cor'   => '#f59e0b',
        'texto' => "Garantia de {$r['marca']} {$r['modelo']} vence em {$r['dias']} dia(s)",
        'link'  => "inventario.php?action=editar&id={$r['id']}",
        'label' => 'Ver',
    ];
}

// Impressoras com toner crítico (≤15% — último snapshot materializado, P3-2)
$tabela_snap = true;
if ($tabela_snap) {
    $toner_critico = $pdo->query("
        SELECT i.id, i.nome, i.setor,
               s.toner_preto_pct, s.toner_ciano_pct,
               s.toner_magenta_pct, s.toner_amarelo_pct
        FROM impressoras i
        JOIN impressoras_ultimo_snapshot s ON s.impressora_id = i.id
        WHERE i.status = 'Ativa'
          AND (
              (s.toner_preto_pct   IS NOT NULL AND s.toner_preto_pct   <= 15) OR
              (s.toner_ciano_pct   IS NOT NULL AND s.toner_ciano_pct   <= 15) OR
              (s.toner_magenta_pct IS NOT NULL AND s.toner_magenta_pct <= 15) OR
              (s.toner_amarelo_pct IS NOT NULL AND s.toner_amarelo_pct <= 15)
          )
        ORDER BY LEAST(
            COALESCE(s.toner_preto_pct,   100),
            COALESCE(s.toner_ciano_pct,   100),
            COALESCE(s.toner_magenta_pct, 100),
            COALESCE(s.toner_amarelo_pct, 100)
        ) ASC
        LIMIT 5
    ")->fetchAll();

    foreach ($toner_critico as $r) {
        // Monta lista dos canais críticos
        $cores_map = [
            'Preto'   => $r['toner_preto_pct'],
            'Ciano'   => $r['toner_ciano_pct'],
            'Magenta' => $r['toner_magenta_pct'],
            'Amarelo' => $r['toner_amarelo_pct'],
        ];
        $criticos = [];
        $min_pct  = 100;
        foreach ($cores_map as $cor => $pct) {
            if ($pct !== null && (int)$pct <= 15) {
                $criticos[] = "{$cor}: {$pct}%";
                $min_pct = min($min_pct, (int)$pct);
            }
        }
        $urgente = $min_pct <= 5;
        $itens[] = [
            'tipo'  => 'toner',
            'icon'  => $urgente ? 'bi-printer-fill' : 'bi-printer',
            'cor'   => $urgente ? '#ef4444' : '#f59e0b',
            'texto' => ($urgente ? 'Toner URGENTE' : 'Toner baixo') . ": {$r['nome']} ({$r['setor']}) — " . implode(', ', $criticos),
            'link'  => "impressora.php?id={$r['id']}",
            'label' => 'Ver',
        ];
    }

    // Impressoras offline — só as que já responderam SNMP ao menos uma vez
    $offline = $pdo->query("
        SELECT i.id, i.nome, i.setor, i.alerta_offline_em
        FROM impressoras i
        JOIN impressoras_ultimo_snapshot s ON s.impressora_id = i.id
        WHERE i.status = 'Ativa'
          AND i.alerta_offline_em IS NOT NULL
        ORDER BY i.alerta_offline_em DESC
        LIMIT 5
    ")->fetchAll();

    foreach ($offline as $r) {
        $itens[] = [
            'tipo'  => 'printer_offline',
            'icon'  => 'bi-printer-fill',
            'cor'   => '#6b7280',
            'texto' => "Impressora offline: {$r['nome']} ({$r['setor']}) — sem resposta SNMP",
            'link'  => "impressora.php?id={$r['id']}",
            'label' => 'Verificar',
        ];
    }
}

} catch (Throwable $e) {
    logApp('warn', 'notificacoes_falha', ['msg' => $e->getMessage()]);
    // devolve o que já montou até aqui
}

echo json_encode(['total' => count($itens), 'itens' => $itens]);
