<?php
/**
 * topologia_helpers.php — monta a árvore de ativos (nós + arestas) a partir
 * de hosts_rede, para a página topologia.php e a api_topologia.php.
 *
 * Sem LLDP/SNMP de topologia (fase 2/3 do roadmap), não dá pra saber em qual
 * porta de qual switch cada host está — então, em vez de pendurar centenas de
 * hosts num único "hub" escolhido arbitrariamente (ilegível com muitos hosts
 * na mesma sub-rede), a árvore agrupa por camadas que a própria base já sabe:
 *
 *   Rede
 *    ├── Infraestrutura de Rede   (todo switch/roteador/AP da sub-rede)
 *    ├── Setor: Recepção          (hosts com setor preenchido)
 *    ├── Setor: Faturamento
 *    └── Sem setor definido
 *          ├── Desktop (91)       (agrupado por tipo, quando não há setor)
 *          └── Impressora (12)
 *
 * Cada grupo que ainda tiver muitos filhos é clusterizado no front-end
 * (ver topologia.php). A confiança gravada é baixa — é um ponto de partida
 * visual, corrigível manualmente mais adiante.
 */

// Ícone (Bootstrap Icons) + cor por tipo — mesmo mapeamento visual de hosts_rede.php,
// para a topologia parecer a mesma linguagem visual do resto do sistema.
function topologiaTipoVisual(string $tipo): array {
    $map = [
        'Computador'           => ['bi-pc-display',        '#6366f1'],
        'Desktop'              => ['bi-pc-display',        '#6366f1'],
        'Notebook'             => ['bi-laptop',             '#8b5cf6'],
        'Tablet'               => ['bi-tablet-fill',        '#a855f7'],
        'Terminal'             => ['bi-terminal-fill',      '#64748b'],
        'Impressora'           => ['bi-printer-fill',       '#0ea5e9'],
        'Impressora Colorida'  => ['bi-printer-fill',       '#7c3aed'],
        'Impressora Etiqueta'  => ['bi-printer-fill',       '#06b6d4'],
        'Switch'               => ['bi-hdd-network',        '#22c55e'],
        'Switch/AP Intelbras'  => ['bi-hdd-network',        '#16a34a'],
        'Access Point'         => ['bi-wifi',               '#f59e0b'],
        'Roteador'             => ['bi-router-fill',        '#10b981'],
        'Roteador MikroTik'    => ['bi-router-fill',        '#059669'],
        'Servidor'             => ['bi-server',             '#3b82f6'],
        'Servidor NAS'         => ['bi-hdd-rack-fill',      '#2563eb'],
        'Monitor'              => ['bi-display',            '#14b8a6'],
        'Celular'              => ['bi-phone-fill',         '#f59e0b'],
        'Telefone IP'          => ['bi-telephone-fill',     '#ec4899'],
        'Nobreak/UPS'          => ['bi-battery-charging',   '#f97316'],
        'Controle de Acesso'   => ['bi-door-open',          '#3b82f6'],
        'Equipamento Médico'   => ['bi-heart-pulse',        '#ec4899'],
        'Equipamento Especial' => ['bi-tools',              '#94a3b8'],
        'IHM/Painel'           => ['bi-display-fill',       '#64748b'],
    ];
    return $map[$tipo] ?? ['bi-question-circle', '#94a3b8'];
}

// Tipos que atuam como "hub" (agregam outros hosts da sub-rede) na ausência de LLDP/SNMP
function topologiaEhHub(?string $tipo): bool {
    if (!$tipo) return false;
    foreach (['Switch', 'Roteador', 'Access Point'] as $prefixo) {
        if (str_starts_with($tipo, $prefixo)) return true;
    }
    return false;
}

function topologiaMontarDados(PDO $pdo): array {
    $hosts = $pdo->query("
        SELECT h.*,
               inv.tipo AS inv_tipo, inv.marca AS inv_marca, inv.modelo AS inv_modelo,
               inv.setor AS inv_setor, inv.patrimonio,
               imp.id AS imp_id,
               sn.toner_preto_pct AS toner_preto, sn.toner_ciano_pct AS toner_ciano,
               sn.toner_magenta_pct AS toner_magenta, sn.toner_amarelo_pct AS toner_amarelo
        FROM hosts_rede h
        LEFT JOIN inventario inv ON inv.id = h.inventario_id
        LEFT JOIN impressoras imp ON imp.ip = h.ip
        LEFT JOIN impressoras_ultimo_snapshot sn ON sn.impressora_id = imp.id
        ORDER BY h.rede, INET_ATON(h.ip)
    ")->fetchAll(PDO::FETCH_ASSOC);

    // A tabela `impressoras` (cadastro manual + monitoramento SNMP) é a fonte
    // de verdade sobre o que é impressora — mais confiável que o palpite do
    // scanner ARP por fabricante/porta. Sincroniza aqui pra árvore, filtro e
    // ícone concordarem com o que impressoras.php já mostra.
    foreach ($hosts as &$h) {
        if ($h['imp_id'] !== null && !str_starts_with($h['tipo'] ?? '', 'Impressora')) {
            $h['tipo'] = 'Impressora';
        }
    }
    unset($h);

    $nodes = [];
    $edges = [];
    $porRede = [];
    foreach ($hosts as $h) {
        $rede = $h['rede'] ?: 'sem-rede';
        $porRede[$rede][] = $h;
    }

    foreach ($porRede as $rede => $hostsDaRede) {
        $rootId = 'rede_' . md5($rede);
        $nodes[] = [
            'id'     => $rootId,
            'kind'   => 'root',
            'label'  => $rede === 'sem-rede' ? 'Rede não identificada' : $rede,
            'icon'   => 'bi-diagram-3-fill',
            'color'  => '#1D3557',
        ];

        // Separa infraestrutura de rede (switch/roteador/AP — todos, não só um) do
        // restante, que é agrupado por setor e, na ausência dele, por tipo.
        $infra = [];
        $demais = [];
        foreach ($hostsDaRede as $h) {
            if (topologiaEhHub($h['tipo'])) { $infra[] = $h; } else { $demais[] = $h; }
        }

        $porSetor = [];
        $semSetor = [];
        foreach ($demais as $h) {
            $setor = trim((string)($h['setor'] ?? ''));
            if ($setor !== '') { $porSetor[$setor][] = $h; } else { $semSetor[] = $h; }
        }

        if ($infra) {
            $infraId = 'infra_' . md5($rede);
            $nodes[] = ['id' => $infraId, 'kind' => 'group', 'label' => 'Infraestrutura de Rede', 'rede_id' => $rootId,
                        'icon' => 'bi-hdd-network-fill', 'color' => '#16a34a', 'count' => count($infra)];
            $edges[] = ['source' => $rootId, 'target' => $infraId, 'confidence' => 70, 'origem' => 'inferred'];
            foreach ($infra as $h) {
                $edges[] = ['source' => $infraId, 'target' => 'host_' . $h['id'], 'confidence' => 70, 'origem' => 'inferred'];
            }
        }

        foreach ($porSetor as $setor => $hostsDoSetor) {
            $setorId = 'setor_' . md5($rede . '|' . $setor);
            $nodes[] = ['id' => $setorId, 'kind' => 'group', 'label' => $setor, 'rede_id' => $rootId,
                        'icon' => 'bi-building', 'color' => '#0891b2', 'count' => count($hostsDoSetor)];
            $edges[] = ['source' => $rootId, 'target' => $setorId, 'confidence' => 45, 'origem' => 'inferred'];
            foreach ($hostsDoSetor as $h) {
                $edges[] = ['source' => $setorId, 'target' => 'host_' . $h['id'], 'confidence' => 45, 'origem' => 'inferred'];
            }
        }

        if ($semSetor) {
            $semSetorId = 'semsetor_' . md5($rede);
            $nodes[] = ['id' => $semSetorId, 'kind' => 'group', 'label' => 'Sem setor definido', 'rede_id' => $rootId,
                        'icon' => 'bi-question-diamond', 'color' => '#94a3b8', 'count' => count($semSetor)];
            $edges[] = ['source' => $rootId, 'target' => $semSetorId, 'confidence' => 20, 'origem' => 'inferred'];

            $porTipo = [];
            foreach ($semSetor as $h) {
                $porTipo[$h['tipo'] ?: 'Desconhecido'][] = $h;
            }
            foreach ($porTipo as $tipo => $hostsDoTipo) {
                [$tipoIcon, $tipoCor] = topologiaTipoVisual($tipo);
                $tipoId = 'semsetortipo_' . md5($rede . '|' . $tipo);
                $nodes[] = ['id' => $tipoId, 'kind' => 'group', 'label' => $tipo, 'rede_id' => $rootId,
                            'icon' => $tipoIcon, 'color' => $tipoCor, 'count' => count($hostsDoTipo)];
                $edges[] = ['source' => $semSetorId, 'target' => $tipoId, 'confidence' => 20, 'origem' => 'inferred'];
                foreach ($hostsDoTipo as $h) {
                    $edges[] = ['source' => $tipoId, 'target' => 'host_' . $h['id'], 'confidence' => 20, 'origem' => 'inferred'];
                }
            }
        }

        foreach ($hostsDaRede as $h) {
            [$icon, $cor] = topologiaTipoVisual($h['tipo'] ?? '');
            $status = $h['online'] ? 'ok' : 'off';
            $badges = [];
            if (!$h['inventario_id']) $badges[] = ['label' => 'NÃO CADASTRADO', 'sev' => 'new'];
            if (!$h['tipo']) { $badges[] = ['label' => 'DESCONHECIDO', 'sev' => 'warn']; $status = 'unknown'; }
            if ($h['imp_id'] !== null) {
                foreach (['toner_preto' => 'Preto', 'toner_ciano' => 'Ciano', 'toner_magenta' => 'Magenta', 'toner_amarelo' => 'Amarelo'] as $campo => $nomeCor) {
                    if ($h[$campo] !== null && (int)$h[$campo] <= 15) {
                        $badges[] = ['label' => 'TONER ' . mb_strtoupper($nomeCor) . ' BAIXO', 'sev' => 'crit'];
                        $status = $status === 'off' ? 'off' : 'warn';
                    }
                }
            }

            $attributes = null;
            if (!empty($h['attributes'])) {
                $attributes = json_decode($h['attributes'], true);
            }

            $nodes[] = [
                'id'          => 'host_' . $h['id'],
                'kind'        => 'host',
                'rede_id'     => $rootId,
                'label'       => $h['hostname'] ?: ($h['marca'] ?: $h['ip']),
                'ip'          => $h['ip'],
                'mac'         => $h['mac_address'],
                'tipo'        => $h['tipo'] ?: 'Desconhecido',
                'fabricante'  => $h['fabricante'],
                'marca'       => $h['marca'],
                'setor'       => $h['setor'],
                'portas'      => $h['portas'],
                'icon'        => $icon,
                'color'       => $cor,
                'status'      => $status,
                'badges'      => $badges,
                'inventario_id' => $h['inventario_id'],
                'inv_marca'   => $h['inv_marca'],
                'inv_modelo'  => $h['inv_modelo'],
                'patrimonio'  => $h['patrimonio'],
                'primeiro_visto' => $h['primeiro_visto'],
                'ultimo_visto'   => $h['ultimo_visto'],
                'attributes'  => $attributes,
                'is_printer'  => $h['imp_id'] !== null,
                'toner'       => $h['imp_id'] !== null ? [
                    'preto'    => $h['toner_preto'],
                    'ciano'    => $h['toner_ciano'],
                    'magenta'  => $h['toner_magenta'],
                    'amarelo'  => $h['toner_amarelo'],
                ] : null,
            ];
        }
    }

    return ['nodes' => $nodes, 'edges' => $edges];
}
