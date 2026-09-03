<?php
// ============================================================
// api_ferramentas.php — Backend das Ferramentas de TI
// Ações: scan_iniciar | scan_status | verificar_host |
//        exportar_inventario | chamados_por_tecnico
// Acesso restrito: admin
// ============================================================

require_once __DIR__ . '/db.php';
requireAdmin();

header('Content-Type: application/json; charset=UTF-8');

$action = $_GET['action'] ?? $_POST['action'] ?? '';

// ── Diretório de trabalho dos scans ──────────────────────────────────────────
$SCAN_DIR  = __DIR__;
$SCAN_LOG  = sys_get_temp_dir() . '/helpti_scan.log';
$SCAN_PID  = sys_get_temp_dir() . '/helpti_scan.pid';
$SCAN_DONE = sys_get_temp_dir() . '/helpti_scan_done';

// ── 1. Iniciar scan de rede ───────────────────────────────────────────────────
if ($action === 'scan_iniciar') {
    csrfVerify();

    if (file_exists($SCAN_PID)) {
        $pid = (int) file_get_contents($SCAN_PID);
        if ($pid && file_exists("/proc/$pid")) {
            echo json_encode(['ok' => false, 'erro' => 'Scan já em execução.']);
            exit;
        }
    }

    // Remove arquivos antigos
    @unlink($SCAN_LOG);
    @unlink($SCAN_DONE);

    $redes = trim($_POST['redes'] ?? '');
    $args  = '';
    if ($redes) {
        $lista = array_filter(array_map('trim', explode("\n", $redes)));
        foreach ($lista as $r) {
            if (preg_match('/^\d{1,3}(\.\d{1,3}){3}\/\d{1,2}$/', $r)) {
                $args .= ' ' . escapeshellarg($r);
            }
        }
    }

    $script    = escapeshellarg(__DIR__ . '/scanner_rede.py');
    $reconcile = escapeshellarg(__DIR__ . '/cron_scanner.php');
    // Após o scanner terminar, roda reconciliação automática (>> mesmo log)
    $cmd = "cd " . escapeshellarg(__DIR__) . " && nohup bash -c 'python3 {$script}{$args} && php {$reconcile}' > " . escapeshellarg($SCAN_LOG) . " 2>&1 & echo \$!";
    $pid = (int) shell_exec($cmd);

    if (!$pid) {
        echo json_encode(['ok' => false, 'erro' => 'Não foi possível iniciar o scan. Verifique se Python3 está instalado.']);
        exit;
    }

    file_put_contents($SCAN_PID, $pid);
    auditLog('scan_rede_iniciado', '', 0, "PID=$pid redes=$redes");
    echo json_encode(['ok' => true, 'pid' => $pid]);
    exit;
}

// ── 2. Status do scan em execução ────────────────────────────────────────────
if ($action === 'scan_status') {
    $pid      = file_exists($SCAN_PID) ? (int) file_get_contents($SCAN_PID) : 0;
    $rodando  = $pid && file_exists("/proc/$pid");
    $log      = file_exists($SCAN_LOG) ? file_get_contents($SCAN_LOG) : '';

    // Detecta CSV mais recente gerado pelo scanner
    $csvs = glob(__DIR__ . '/scan_rede_*.csv');
    usort($csvs, fn($a, $b) => filemtime($b) - filemtime($a));
    $csv_recente = $csvs[0] ?? null;
    $csv_nome    = $csv_recente ? basename($csv_recente) : null;
    $csv_novo    = $csv_recente && filemtime($csv_recente) > (time() - 300);

    if (!$rodando && $pid) {
        @unlink($SCAN_PID);
    }

    echo json_encode([
        'ok'       => true,
        'rodando'  => $rodando,
        'pid'      => $pid,
        'log'      => mb_substr($log, -3000),
        'csv'      => $csv_novo ? $csv_nome : null,
    ]);
    exit;
}

// ── 3. Download do CSV gerado ─────────────────────────────────────────────────
if ($action === 'baixar_csv') {
    $arquivo = basename($_GET['arquivo'] ?? '');
    if (!preg_match('/^scan_rede_[\d_]+\.csv$/', $arquivo)) {
        http_response_code(400); echo json_encode(['ok'=>false]); exit;
    }
    $path = __DIR__ . '/' . $arquivo;
    if (!file_exists($path)) {
        http_response_code(404); echo json_encode(['ok'=>false]); exit;
    }
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $arquivo . '"');
    readfile($path);
    exit;
}

// ── 4. Verificar host (ping + portas + DNS) ───────────────────────────────────
if ($action === 'verificar_host') {
    csrfVerify();
    $ip = trim($_POST['ip'] ?? '');
    if (!filter_var($ip, FILTER_VALIDATE_IP)) {
        echo json_encode(['ok' => false, 'erro' => 'IP inválido.']); exit;
    }

    // Ping (1 pacote, timeout 1s)
    $ping_out = shell_exec("ping -c 1 -W 1 " . escapeshellarg($ip) . " 2>&1");
    $online   = str_contains($ping_out ?? '', '1 received');

    // Latência
    $latencia = null;
    if (preg_match('/time=([\d\.]+)\s*ms/', $ping_out ?? '', $m)) {
        $latencia = $m[1];
    }

    // Hostname DNS
    $hostname = '';
    try { $hostname = gethostbyaddr($ip); if ($hostname === $ip) $hostname = ''; } catch (Throwable) {}

    // Scan das portas principais
    $PORTAS = [
        22 => 'SSH', 23 => 'Telnet', 25 => 'SMTP', 53 => 'DNS',
        80 => 'HTTP', 135 => 'RPC', 139 => 'NetBIOS', 443 => 'HTTPS',
        445 => 'SMB', 515 => 'LPD', 631 => 'IPP', 3306 => 'MySQL',
        3389 => 'RDP', 5900 => 'VNC', 8080 => 'HTTP-Alt', 9100 => 'RAW Print',
    ];
    $abertas = [];
    foreach ($PORTAS as $porta => $nome) {
        $sock = @fsockopen($ip, $porta, $e, $em, 0.4);
        if ($sock) { fclose($sock); $abertas[$porta] = $nome; }
    }

    // MAC via arp (local)
    $mac = '';
    $arp = shell_exec("arp -n " . escapeshellarg($ip) . " 2>/dev/null");
    if (preg_match('/([0-9a-f]{2}(?::[0-9a-f]{2}){5})/i', $arp ?? '', $m)) {
        $mac = strtoupper($m[1]);
    }

    // SNMP — páginas e toner (apenas se for impressora: porta 9100/515/631)
    $snmp = null;
    $portas_imp = [515, 631, 9100];
    if (array_intersect($portas_imp, array_keys($abertas))) {
        $snmp = snmp_coleta_host($ip);
    }

    echo json_encode([
        'ok'       => true,
        'ip'       => $ip,
        'online'   => $online,
        'latencia' => $latencia,
        'hostname' => $hostname,
        'mac'      => $mac,
        'portas'   => $abertas,
        'snmp'     => $snmp,
    ]);
    exit;
}

function snmp_get_val(string $ip, string $oid): ?string
{
    $out = shell_exec('snmpget -v2c -c public -t 2 -r 1 ' . escapeshellarg($ip) . ' ' . escapeshellarg($oid) . ' 2>/dev/null');
    if (!$out) return null;
    return preg_match('/:\s*(-?\d+)/', $out, $m) ? $m[1] : null;
}

function snmp_coleta_host(string $ip): ?array
{
    $paginas = snmp_get_val($ip, '1.3.6.1.2.1.43.10.2.1.4.1.1');
    if ($paginas === null) return null;

    $toners = [];
    foreach ([1 => 'preto', 2 => 'ciano', 3 => 'magenta', 4 => 'amarelo'] as $idx => $cor) {
        $niv = snmp_get_val($ip, "1.3.6.1.2.1.43.11.1.1.9.1.{$idx}");
        $cap = snmp_get_val($ip, "1.3.6.1.2.1.43.11.1.1.8.1.{$idx}");
        if ($niv !== null && $cap !== null && (int)$cap > 0 && (int)$cap !== -3) {
            $toners[$cor] = min(100, (int)round(((int)$niv / (int)$cap) * 100));
        }
    }

    return ['paginas' => (int)$paginas, 'toners' => $toners];
}

// ── 5. Iniciar coleta SNMP em background ─────────────────────────────────────
$SNMP_LOG = sys_get_temp_dir() . '/helpti_snmp.log';
$SNMP_PID = sys_get_temp_dir() . '/helpti_snmp.pid';

if ($action === 'snmp_iniciar') {
    csrfVerify();
    if (file_exists($SNMP_PID)) {
        $pid = (int)file_get_contents($SNMP_PID);
        if ($pid && file_exists("/proc/$pid")) {
            echo json_encode(['ok' => false, 'erro' => 'Coleta SNMP já em execução.']); exit;
        }
    }
    @unlink($SNMP_LOG);
    $script = escapeshellarg(__DIR__ . '/snmp_coletar.php');
    $cmd = "nohup php {$script} > " . escapeshellarg($SNMP_LOG) . " 2>&1 & echo \$!";
    $pid = (int)shell_exec($cmd);
    if (!$pid) { echo json_encode(['ok' => false, 'erro' => 'Falha ao iniciar.']); exit; }
    file_put_contents($SNMP_PID, $pid);
    echo json_encode(['ok' => true, 'pid' => $pid]);
    exit;
}

if ($action === 'snmp_status') {
    $SNMP_LOG = sys_get_temp_dir() . '/helpti_snmp.log';
    $SNMP_PID = sys_get_temp_dir() . '/helpti_snmp.pid';
    $pid     = file_exists($SNMP_PID) ? (int)file_get_contents($SNMP_PID) : 0;
    $rodando = $pid && file_exists("/proc/$pid");
    $log     = file_exists($SNMP_LOG) ? file_get_contents($SNMP_LOG) : '';
    if (!$rodando && $pid) @unlink($SNMP_PID);
    echo json_encode(['ok' => true, 'rodando' => $rodando, 'log' => mb_substr($log, -4000)]);
    exit;
}

// ── 6. Exportar inventário CSV ────────────────────────────────────────────────
if ($action === 'exportar_inventario') {
    $setor = trim($_GET['setor'] ?? '');
    $tipo  = trim($_GET['tipo']  ?? '');
    $status = trim($_GET['status'] ?? '');

    $where = []; $params = [];
    if ($setor)  { $where[] = 'setor = ?';  $params[] = $setor; }
    if ($tipo)   { $where[] = 'tipo = ?';   $params[] = $tipo; }
    if ($status) { $where[] = 'status = ?'; $params[] = $status; }
    $sql = 'SELECT * FROM inventario' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY setor, tipo, marca';

    $st = db()->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll();

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="inventario_' . date('Ymd_Hi') . '.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));
    fputcsv($out, ['tipo','marca','modelo','numero_serie','patrimonio','setor','responsavel_nome','status','data_aquisicao','valor','garantia_ate','imei','observacoes'], ';');
    foreach ($rows as $r) {
        fputcsv($out, [
            $r['tipo'], $r['marca'], $r['modelo'], $r['numero_serie'],
            $r['patrimonio'], $r['setor'], $r['responsavel_nome'], $r['status'],
            $r['data_aquisicao'], $r['valor'], $r['garantia_ate'],
            $r['imei'] ?? '', $r['observacoes'] ?? '',
        ], ';');
    }
    fclose($out);
    exit;
}

// ── 6. Chamados por técnico (JSON) ────────────────────────────────────────────
if ($action === 'chamados_tecnicos') {
    $rows = db()->query("
        SELECT u.nome,
               SUM(c.status IN ('Aberto','Em Andamento','Pendente')) AS abertos,
               SUM(c.status = 'Concluído'
                   AND MONTH(c.fechado_em) = MONTH(NOW())
                   AND YEAR(c.fechado_em)  = YEAR(NOW()))            AS concluidos_mes,
               SUM(c.sla_alerta_enviado = 1
                   AND c.status IN ('Aberto','Em Andamento','Pendente')) AS sla_vencidos
        FROM chamados c
        JOIN usuarios u ON u.id = c.responsavel_id
        WHERE c.deleted_at IS NULL
        GROUP BY c.responsavel_id
        ORDER BY abertos DESC
    ")->fetchAll();
    echo json_encode(['ok' => true, 'dados' => $rows]);
    exit;
}

// ── 7. Ligar / desligar / reiniciar estações da rede ─────────────────────────
if ($action === 'power_hosts') {
    // Candidatos para o seletor: hosts com MAC conhecido, agrupáveis por setor.
    $rows = db()->query("
        SELECT h.ip, h.mac_address, h.hostname, h.online,
               COALESCE(NULLIF(h.setor,''), i.setor) AS setor_ef,
               COALESCE(i.tipo, h.tipo) AS tipo
        FROM hosts_rede h
        LEFT JOIN inventario i ON i.id = h.inventario_id
        WHERE h.mac_address <> ''
        ORDER BY setor_ef IS NULL, setor_ef, INET_ATON(h.ip)
    ")->fetchAll();
    $lista = [];
    foreach ($rows as $r) {
        if (!RedePower::ipPrivado((string) $r['ip'])) continue;
        $lista[] = [
            'ip'       => $r['ip'],
            'mac'      => $r['mac_address'],
            'hostname' => $r['hostname'] ?: '',
            'setor'    => $r['setor_ef'] ?: 'Sem setor',
            'tipo'     => $r['tipo'] ?: '',
            'online'   => (int) $r['online'] === 1,
        ];
    }
    echo json_encode(['ok' => true, 'configurado' => RedePower::configurado(), 'hosts' => $lista]);
    exit;
}

if ($action === 'power_acao') {
    csrfVerify();
    $tipo     = $_POST['tipo'] ?? '';
    $segundos = (int) ($_POST['segundos'] ?? 30);
    $mensagem = trim($_POST['mensagem'] ?? '');
    $bruto    = (string) ($_POST['alvos'] ?? '');

    if (!in_array($tipo, RedePower::ACOES, true)) {
        echo json_encode(['ok' => false, 'erro' => 'Ação inválida.']); exit;
    }
    if (in_array($tipo, RedePower::ACOES_RPC, true) && !RedePower::configurado()) {
        echo json_encode(['ok' => false, 'erro' => 'Credenciais de desligamento não configuradas em config.local.php.']); exit;
    }

    // Uma entrada por linha: "IP", "MAC" ou "IP\tMAC".
    $linhas = array_filter(array_map('trim', preg_split('/[\r\n]+/', $bruto)));
    $linhas = array_slice(array_values(array_unique($linhas)), 0, 80);
    if (!$linhas) {
        echo json_encode(['ok' => false, 'erro' => 'Nenhum alvo informado.']); exit;
    }

    // Mapa IP→MAC do banco, para Wake-on-LAN a partir do IP.
    $macPorIp = [];
    foreach (db()->query("SELECT ip, mac_address FROM hosts_rede WHERE mac_address <> ''") as $r) {
        $macPorIp[$r['ip']] = $r['mac_address'];
    }

    $resultados = [];
    foreach ($linhas as $linha) {
        $campos = preg_split('/[\s,;]+/', $linha);
        $ip = $mac = '';
        foreach ($campos as $c) {
            if (filter_var($c, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) $ip = $c;
            elseif (RedePower::macValido($c)) $mac = $c;
        }

        if ($tipo === 'ligar') {
            if ($mac === '' && $ip !== '') $mac = $macPorIp[$ip] ?? '';
            $alvo = $mac !== '' ? $mac : $linha;
            $res  = $mac !== ''
                ? RedePower::ligar($mac)
                : ['ok' => false, 'saida' => 'MAC não encontrado para este alvo.'];
        } else {
            $alvo = $ip !== '' ? $ip : $linha;
            $res  = $ip !== ''
                ? RedePower::executar($tipo, $ip, $segundos, $mensagem)
                : ['ok' => false, 'saida' => 'IP inválido.'];
        }

        auditLog('rede_power', 'host', 0, "{$tipo} {$alvo} => " . ($res['ok'] ? 'ok' : 'falha'));
        $resultados[] = ['alvo' => $alvo, 'ok' => $res['ok'], 'saida' => mb_substr($res['saida'], 0, 400)];
    }

    $sucesso = count(array_filter($resultados, fn($r) => $r['ok']));
    echo json_encode(['ok' => true, 'total' => count($resultados), 'sucesso' => $sucesso, 'resultados' => $resultados]);
    exit;
}

echo json_encode(['ok' => false, 'erro' => 'Ação inválida.']);
