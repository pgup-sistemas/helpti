<?php
if (!defined('HELPTI_BOOT')) { http_response_code(403); exit('Acesso negado.'); }
/**
 * HelpTI — Sincronização do Inventário
 * Conecta inventario → impressoras e inventario → chamados
 */

// ── Sincroniza impressoras a partir do inventário ─────────
function sync_impressoras_from_inventario(): array {
    $pdo = db();
    $tipos_impressora = ['Impressora', 'Impressora Etiqueta'];
    $placeholders = implode(',', array_fill(0, count($tipos_impressora), '?'));

    $itens = $pdo->prepare("
        SELECT * FROM inventario
        WHERE tipo IN ($placeholders)
        ORDER BY id
    ");
    $itens->execute($tipos_impressora);

    $criadas = 0;
    $atualizadas = 0;

    foreach ($itens->fetchAll(PDO::FETCH_ASSOC) as $inv) {
        // Extrai IP das observacoes
        $ip = '';
        if (preg_match('/\bIP:\s*([\d\.]+)/', $inv['observacoes'] ?? '', $m)) {
            $ip = $m[1];
        }

        // Nome: preferencia ao modelo (hostname), depois marca
        $nome = trim(($inv['modelo'] ?: $inv['marca']) ?: 'Impressora');
        $marca_modelo = trim($inv['marca'] . ($inv['modelo'] ? ' ' . $inv['modelo'] : ''));

        // Status: mapeia do inventário para impressoras
        $status_map = [
            'Em Uso'        => 'Ativa',
            'Disponível'    => 'Ativa',
            'Em Manutenção' => 'Em Manutenção',
            'Descartado'    => 'Inativa',
        ];
        $status = $status_map[$inv['status']] ?? 'Ativa';

        // Verifica se já existe impressora ligada a este inventario_id
        $existe = $pdo->prepare("SELECT id, nome, marca_modelo FROM impressoras WHERE inventario_id = ?");
        $existe->execute([$inv['id']]);
        $imp_row = $existe->fetch(PDO::FETCH_ASSOC);
        $imp_id  = $imp_row ? $imp_row['id'] : false;

        if ($imp_id) {
            // Preserva nome/marca detectados por SNMP — só sobrescreve se o inventário
            // tem um valor melhor (não "Desconhecido") ou se a impressora ainda está sem nome real.
            $desconhecido = fn($v) => in_array(strtolower(trim($v ?? '')), ['desconhecido', '', 'impressora']);
            $nome_final   = (!$desconhecido($nome))           ? $nome        : ($desconhecido($imp_row['nome'])        ? $nome        : $imp_row['nome']);
            $mm_final     = (!$desconhecido($marca_modelo))   ? $marca_modelo: ($desconhecido($imp_row['marca_modelo'])? $marca_modelo : $imp_row['marca_modelo']);

            $pdo->prepare("
                UPDATE impressoras SET
                    nome = ?, marca_modelo = ?, numero_serie = ?,
                    ip = ?, setor = ?, status = ?, atualizado_em = NOW()
                WHERE id = ?
            ")->execute([$nome_final, $mm_final, $inv['numero_serie'], $ip, $inv['setor'], $status, $imp_id]);
            $atualizadas++;
        } else {
            // Cria nova impressora
            $pdo->prepare("
                INSERT INTO impressoras
                    (inventario_id, nome, marca_modelo, numero_serie, ip, setor, status, criado_em, atualizado_em)
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
            ")->execute([$inv['id'], $nome, $marca_modelo, $inv['numero_serie'], $ip, $inv['setor'], $status]);
            $criadas++;
        }

        // Mantém inventário sincronizado com status real da impressora
        $status_inv_correto = match($status) {
            'Ativa'          => 'Em Uso',
            'Em Manutenção'  => 'Em Manutenção',
            'Inativa'        => 'Descartado',
            default          => null,
        };
        if ($status_inv_correto && $inv['status'] !== $status_inv_correto) {
            $pdo->prepare("UPDATE inventario SET status = ? WHERE id = ?")
                ->execute([$status_inv_correto, $inv['id']]);
        }
    }

    return ['criadas' => $criadas, 'atualizadas' => $atualizadas];
}

// ── Atualiza status do inventário quando chamado muda ─────
function sync_inventario_status_chamado(int $chamado_id, string $status_chamado): void {
    $pdo = db();

    $chamado = $pdo->prepare("SELECT inventario_id FROM chamados WHERE id = ?");
    $chamado->execute([$chamado_id]);
    $inv_id = $chamado->fetchColumn();

    if (!$inv_id) return;

    // Chamado aberto/em andamento → equipamento Em Manutenção
    // Chamado concluído → volta a Em Uso
    $novo_status = match($status_chamado) {
        'Concluído' => 'Em Uso',
        default     => 'Em Manutenção',
    };

    $pdo->prepare("UPDATE inventario SET status = ?, atualizado_em = NOW() WHERE id = ?")
        ->execute([$novo_status, $inv_id]);

    // Se for impressora, sincroniza status na tabela impressoras também
    $imp = $pdo->prepare("SELECT id FROM impressoras WHERE inventario_id = ?");
    $imp->execute([$inv_id]);
    if ($imp_id = $imp->fetchColumn()) {
        $status_imp = $status_chamado === 'Concluído' ? 'Ativa' : 'Em Manutenção';
        $pdo->prepare("UPDATE impressoras SET status = ?, atualizado_em = NOW() WHERE id = ?")
            ->execute([$status_imp, $imp_id]);
    }
}

// ── Busca equipamentos do solicitante/setor para chamados ─
function listar_equipamentos_chamado(string $setor = '', string $responsavel = ''): array {
    $pdo = db();
    $where = ["status NOT IN ('Descartado')"];
    $params = [];
    if ($setor) { $where[] = 'setor = ?'; $params[] = $setor; }
    if ($responsavel) { $where[] = 'responsavel_nome LIKE ?'; $params[] = "%$responsavel%"; }
    $st = $pdo->prepare('SELECT id, tipo, marca, modelo, numero_serie, patrimonio, setor, responsavel_nome, status
        FROM inventario WHERE ' . implode(' AND ', $where) . ' ORDER BY tipo, marca, modelo');
    $st->execute($params);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}
