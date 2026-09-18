<?php
// Endpoint JSON do módulo ITAM — sem HTML, sem layout
require 'db.php';
requireLogin();

header('Content-Type: application/json');
header('Cache-Control: no-store');

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$pdo    = db();
$u      = usuario();

// ── Helpers ──────────────────────────────────────────────────────────────────

function recalcAcuracia(PDO $pdo, int $ciclo_id): void {
    $pdo->prepare("
        UPDATE ciclos_auditoria SET
            total_verificado = (
                SELECT COUNT(*) FROM auditoria_itens
                WHERE ciclo_id = ? AND verificado = 1
            ),
            taxa_acuracia = ROUND(
                (SELECT COUNT(*) FROM auditoria_itens WHERE ciclo_id = ? AND verificado = 1)
                / GREATEST(total_esperado, 1) * 100, 2
            )
        WHERE id = ?
    ")->execute([$ciclo_id, $ciclo_id, $ciclo_id]);
}

function jsonOk(mixed $data): never {
    echo json_encode(['ok' => true, 'data' => $data]);
    exit;
}

function jsonErro(string $msg, int $code = 400): never {
    http_response_code($code);
    echo json_encode(['ok' => false, 'erro' => $msg]);
    exit;
}

// ── Roteamento ────────────────────────────────────────────────────────────────

switch ($action) {

    // ── Iniciar ciclo ─────────────────────────────────────────────────────────
    case 'iniciar':
        if (($u['perfil'] ?? '') === 'tecnico') jsonErro('Sem permissão.', 403);
        csrfVerify();

        $nome   = trim($_POST['nome'] ?? '');
        $descr  = trim($_POST['descricao'] ?? '');
        $setor  = trim($_POST['setor_filtro'] ?? '');

        if ($nome === '') jsonErro('Informe o nome do ciclo.');

        // Monta filtro de setor
        $where  = "status != 'Descartado' AND deleted_at IS NULL";
        $params = [];
        if ($setor !== '') { $where .= " AND setor = ?"; $params[] = $setor; }

        $total = (int)$pdo->prepare("SELECT COUNT(*) FROM inventario WHERE $where")
                          ->execute($params) ? 0 : 0;
        $st = $pdo->prepare("SELECT COUNT(*) FROM inventario WHERE $where");
        $st->execute($params);
        $total = (int)$st->fetchColumn();

        if ($total === 0) jsonErro('Nenhum ativo encontrado para este filtro.');

        // Cria o ciclo
        $pdo->prepare("INSERT INTO ciclos_auditoria (nome, descricao, responsavel_id, status, setor_filtro, total_esperado)
                        VALUES (?,?,?,'Em Andamento',?,?)")
            ->execute([$nome, $descr ?: null, $u['id'], $setor ?: null, $total]);
        $ciclo_id = (int)$pdo->lastInsertId();

        // Snapshot dos ativos no checklist
        $ativos = $pdo->prepare("SELECT id, status, setor, responsavel_nome FROM inventario WHERE $where");
        $ativos->execute($params);
        $ins = $pdo->prepare("INSERT IGNORE INTO auditoria_itens
            (ciclo_id, inventario_id, status_esperado, setor_esperado, responsavel_esperado)
            VALUES (?,?,?,?,?)");
        foreach ($ativos->fetchAll() as $a) {
            $ins->execute([$ciclo_id, $a['id'], $a['status'], $a['setor'], $a['responsavel_nome']]);
        }

        auditLog('ciclo_auditoria_iniciado', 'ciclos_auditoria', $ciclo_id, $nome);
        jsonOk(['ciclo_id' => $ciclo_id, 'total_esperado' => $total]);

    // ── Checklist de um ciclo ─────────────────────────────────────────────────
    case 'checklist':
        $ciclo_id   = (int)($_GET['ciclo_id'] ?? 0);
        $so_pend    = (int)($_GET['verificado'] ?? -1); // 0=só pendentes, -1=todos

        if (!$ciclo_id) jsonErro('ciclo_id obrigatório.');

        $where  = 'ai.ciclo_id = ?';
        $params = [$ciclo_id];
        if ($so_pend === 0) { $where .= ' AND ai.verificado = 0'; }

        $rows = $pdo->prepare("
            SELECT ai.*, i.tipo, i.marca, i.modelo, i.numero_serie, i.patrimonio
            FROM auditoria_itens ai
            JOIN inventario i ON i.id = ai.inventario_id
            WHERE $where
            ORDER BY ai.verificado ASC, i.setor, i.tipo, i.marca
        ");
        $rows->execute($params);
        jsonOk($rows->fetchAll());

    // ── Buscar ativo por patrimônio / S/N dentro do ciclo ─────────────────────
    case 'buscar':
        $ciclo_id = (int)($_GET['ciclo_id'] ?? 0);
        $q        = trim($_GET['q'] ?? '');
        if (!$ciclo_id || $q === '') jsonErro('Parâmetros obrigatórios: ciclo_id e q.');

        $row = $pdo->prepare("
            SELECT ai.*, i.tipo, i.marca, i.modelo, i.numero_serie, i.patrimonio, i.imei
            FROM auditoria_itens ai
            JOIN inventario i ON i.id = ai.inventario_id
            WHERE ai.ciclo_id = ?
              AND (i.patrimonio = ? OR i.numero_serie = ? OR i.imei = ?)
            LIMIT 1
        ");
        $row->execute([$ciclo_id, $q, $q, $q]);
        $item = $row->fetch();

        if (!$item) jsonErro('Ativo não localizado neste ciclo.');
        jsonOk($item);

    // ── Verificar / confirmar um ativo ────────────────────────────────────────
    case 'verificar':
        csrfVerify();
        $ciclo_id    = (int)($_POST['ciclo_id'] ?? 0);
        $inv_id      = (int)($_POST['inventario_id'] ?? 0);
        $status_enc  = trim($_POST['status_encontrado'] ?? '');
        $setor_enc   = trim($_POST['setor_encontrado'] ?? '');
        $resp_enc    = trim($_POST['responsavel_encontrado'] ?? '');
        $obs         = trim($_POST['observacao'] ?? '');

        if (!$ciclo_id || !$inv_id) jsonErro('ciclo_id e inventario_id obrigatórios.');

        $pdo->beginTransaction();
        try {
            // SELECT FOR UPDATE evita dupla verificação concorrente
            $item = $pdo->prepare("SELECT * FROM auditoria_itens WHERE ciclo_id=? AND inventario_id=? FOR UPDATE");
            $item->execute([$ciclo_id, $inv_id]);
            $item = $item->fetch();
            if (!$item) { $pdo->rollBack(); jsonErro('Item não pertence a este ciclo.'); }
            if ($item['verificado']) { $pdo->rollBack(); jsonErro('Este ativo já foi verificado neste ciclo.'); }

            $divergencia = (
                ($status_enc && $status_enc !== $item['status_esperado']) ||
                ($setor_enc  && $setor_enc  !== $item['setor_esperado'])  ||
                ($resp_enc   && $resp_enc   !== $item['responsavel_esperado'])
            ) ? 1 : 0;

            $pdo->prepare("UPDATE auditoria_itens SET
                verificado=1, verificado_em=NOW(), tecnico_id=?,
                status_encontrado=?, setor_encontrado=?, responsavel_encontrado=?,
                divergencia=?, observacao=?
                WHERE ciclo_id=? AND inventario_id=?")
                ->execute([$u['id'], $status_enc ?: $item['status_esperado'],
                           $setor_enc ?: $item['setor_esperado'],
                           $resp_enc  ?: $item['responsavel_esperado'],
                           $divergencia, $obs ?: null, $ciclo_id, $inv_id]);

            // Atualiza ultima_verificacao do ativo
            $pdo->prepare("UPDATE inventario SET ultima_verificacao=NOW() WHERE id=?")
                ->execute([$inv_id]);

            recalcAcuracia($pdo, $ciclo_id);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            jsonErro('Erro interno ao verificar item.', 500);
        }

        // Busca acurácia atualizada
        $ciclo = $pdo->prepare("SELECT total_verificado, total_esperado, taxa_acuracia FROM ciclos_auditoria WHERE id=?");
        $ciclo->execute([$ciclo_id]);
        $ciclo = $ciclo->fetch();

        jsonOk(['divergencia' => $divergencia, 'ciclo' => $ciclo]);

    // ── Encerrar ciclo ────────────────────────────────────────────────────────
    case 'encerrar':
        if (($u['perfil'] ?? '') === 'tecnico') jsonErro('Sem permissão.', 403);
        csrfVerify();
        $ciclo_id = (int)($_POST['ciclo_id'] ?? 0);
        if (!$ciclo_id) jsonErro('ciclo_id obrigatório.');

        $ciclo = $pdo->prepare("SELECT * FROM ciclos_auditoria WHERE id=?");
        $ciclo->execute([$ciclo_id]);
        $ciclo = $ciclo->fetch();
        if (!$ciclo) jsonErro('Ciclo não encontrado.');
        if ($ciclo['status'] === 'Concluído') jsonErro('Ciclo já encerrado.');

        recalcAcuracia($pdo, $ciclo_id);
        $pdo->prepare("UPDATE ciclos_auditoria SET status='Concluído', encerrado_em=NOW() WHERE id=?")
            ->execute([$ciclo_id]);

        // Registra movimentação nos itens com divergência
        $divs = $pdo->prepare("
            SELECT ai.*, i.setor AS setor_atual, i.responsavel_nome AS resp_atual, i.status AS status_atual
            FROM auditoria_itens ai JOIN inventario i ON i.id = ai.inventario_id
            WHERE ai.ciclo_id = ? AND ai.divergencia = 1
        ");
        $divs->execute([$ciclo_id]);
        $ins_hm = $pdo->prepare("INSERT INTO historico_movimentacao
            (inventario_id, usuario_id, tipo, setor_anterior, setor_novo, resp_anterior, resp_novo, status_anterior, status_novo, observacao)
            VALUES (?,?,?,?,?,?,?,?,?,?)");
        foreach ($divs->fetchAll() as $d) {
            $ins_hm->execute([
                $d['inventario_id'], $u['id'], 'Auditoria',
                $d['setor_esperado'], $d['setor_encontrado'],
                $d['responsavel_esperado'], $d['responsavel_encontrado'],
                $d['status_esperado'], $d['status_encontrado'],
                "Divergência detectada no ciclo #{$ciclo_id}: {$ciclo['nome']}",
            ]);
        }

        auditLog('ciclo_auditoria_encerrado', 'ciclos_auditoria', $ciclo_id, $ciclo['nome']);
        $final = $pdo->prepare("SELECT total_verificado, total_esperado, taxa_acuracia FROM ciclos_auditoria WHERE id=?");
        $final->execute([$ciclo_id]);
        jsonOk($final->fetch());

    // ── Relatório de um ciclo ─────────────────────────────────────────────────
    case 'relatorio':
        $ciclo_id = (int)($_GET['ciclo_id'] ?? 0);
        if (!$ciclo_id) jsonErro('ciclo_id obrigatório.');

        $ciclo = $pdo->prepare("SELECT ca.*, u.nome AS responsavel_nome FROM ciclos_auditoria ca
            LEFT JOIN usuarios u ON u.id = ca.responsavel_id WHERE ca.id=?");
        $ciclo->execute([$ciclo_id]);
        $ciclo = $ciclo->fetch();
        if (!$ciclo) jsonErro('Ciclo não encontrado.');

        $divergencias = $pdo->prepare("
            SELECT
                SUM(divergencia = 1 AND setor_encontrado != setor_esperado)    AS setor_errado,
                SUM(divergencia = 1 AND responsavel_encontrado != responsavel_esperado) AS resp_trocado,
                SUM(divergencia = 1 AND status_encontrado != status_esperado)  AS status_diferente,
                SUM(verificado = 0)                                             AS nao_localizados,
                SUM(divergencia = 1)                                            AS total_divergencias
            FROM auditoria_itens WHERE ciclo_id = ?
        ");
        $divergencias->execute([$ciclo_id]);

        $itens = $pdo->prepare("
            SELECT ai.*, i.tipo, i.marca, i.modelo, i.numero_serie, i.patrimonio,
                   u.nome AS tecnico_nome
            FROM auditoria_itens ai
            JOIN inventario i ON i.id = ai.inventario_id
            LEFT JOIN usuarios u ON u.id = ai.tecnico_id
            WHERE ai.ciclo_id = ?
            ORDER BY ai.divergencia DESC, ai.verificado ASC, i.setor
        ");
        $itens->execute([$ciclo_id]);

        jsonOk([
            'ciclo'        => $ciclo,
            'divergencias' => $divergencias->fetch(),
            'itens'        => $itens->fetchAll(),
        ]);

    default:
        jsonErro('Action inválida.', 404);
}
