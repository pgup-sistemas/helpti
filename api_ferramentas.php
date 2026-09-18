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
$SCAN_DIR  = __DIR__ . '/storage/scans';
if (!is_dir($SCAN_DIR)) @mkdir($SCAN_DIR, 0770, true);
$SCAN_LOG  = sys_get_temp_dir() . '/helpti_scan.log';
$SCAN_PID  = sys_get_temp_dir() . '/helpti_scan.pid';
$SCAN_DONE = sys_get_temp_dir() . '/helpti_scan_done';

// ── helpers Windows ──────────────────────────────────────────────────────────
function isProcessRunningWin(int $pid): bool
{
    $out = shell_exec("tasklist /FI \"PID eq $pid\" /NH 2>nul");
    return $out && str_contains($out, (string)$pid);
}

function findPhpExe(): string
{
    // PHP_BINARY inside Apache (mod_php) returns httpd.exe, not php.exe.
    // Look for php.exe in the same directory as PHP_BINARY, or common XAMPP paths.
    $candidates = [
        dirname(PHP_BINARY) . DIRECTORY_SEPARATOR . 'php.exe',
        'C:\\xampp\\php\\php.exe',
        'C:\\php\\php.exe',
    ];
    foreach ($candidates as $c) {
        if (is_file($c)) return $c;
    }
    return PHP_BINARY; // fallback
}

function findPython(): string
{
    foreach (['python', 'python3', 'py'] as $cmd) {
        $out = shell_exec("$cmd --version 2>nul");
        if ($out && stripos($out, 'python') !== false) return $cmd;
    }
    return '';
}

function procRodando(int $pid): bool
{
    if (PHP_OS_FAMILY === 'Windows') return isProcessRunningWin($pid);
    return $pid && file_exists("/proc/$pid");
}

// ── 1. Iniciar scan de rede ───────────────────────────────────────────────────
if ($action === 'scan_iniciar') {
    csrfVerify();

    if (file_exists($SCAN_PID)) {
        $pid = (int) file_get_contents($SCAN_PID);
        if ($pid && procRodando($pid)) {
            echo json_encode(['ok' => false, 'erro' => 'Scan já em execução.']);
            exit;
        }
    }

    // Remove arquivos antigos
    @unlink($SCAN_LOG);
    @unlink($SCAN_DONE);

    $redes = trim($_POST['redes'] ?? '');
    $argsLista = [];
    if ($redes) {
        foreach (array_filter(array_map('trim', explode("\n", $redes))) as $r) {
            if (preg_match('/^\d{1,3}(\.\d{1,3}){3}\/\d{1,2}$/', $r)) {
                $argsLista[] = $r;
            }
        }
    }

    $script    = __DIR__ . '/scanner_rede.py';
    $reconcile = __DIR__ . '/cron_scanner.php';
    $phpExe    = PHP_OS_FAMILY === 'Windows' ? findPhpExe() : PHP_BINARY;

    if (PHP_OS_FAMILY === 'Windows') {
        $pythonExe = findPython();
        if (!$pythonExe) {
            echo json_encode(['ok' => false, 'erro' => 'Python3 não encontrado no PATH.']);
            exit;
        }
        // Bat temporário para rodar em background sem janela
        $bat = sys_get_temp_dir() . '\\helpti_scan.bat';
        $argsStr = implode(' ', array_map(fn($a) => '"'.$a.'"', $argsLista));
        // Grava o PID do python.exe num arquivo para rastrear o processo
        $pidFile = sys_get_temp_dir() . '\\helpti_scan_real.pid';
        $batContent  = "@echo off\r\n";
        $batContent .= "set PYTHONIOENCODING=utf-8\r\n";
        $batContent .= "set PYTHONUTF8=1\r\n";
        $batContent .= "\"$pythonExe\" -u \"$script\" $argsStr >> \"$SCAN_LOG\" 2>&1\r\n";
        $batContent .= "\"$phpExe\" \"$reconcile\" >> \"$SCAN_LOG\" 2>&1\r\n";
        file_put_contents($bat, $batContent);

        // Libera o session lock antes das operações lentas
        session_write_close();

        // Usa PowerShell para iniciar em background e capturar PID imediatamente
        $psCmd = 'powershell -NoProfile -Command "Start-Process cmd -ArgumentList \'/c """' . addslashes($bat) . '"""\' -WindowStyle Hidden -PassThru | Select-Object -ExpandProperty Id"';
        $pidStr = trim((string) shell_exec($psCmd . ' 2>nul'));
        $pid = (int) $pidStr ?: rand(20000, 59999);
    } else {
        $pythonExe = findPython() ?: 'python3';
        $argsStr = implode(' ', array_map('escapeshellarg', $argsLista));
        $cmd = "nohup bash -c " . escapeshellarg(
            "'$pythonExe' " . escapeshellarg($script) . " $argsStr > " . escapeshellarg($SCAN_LOG) . " 2>&1 && '$phpExe' " . escapeshellarg($reconcile) . " >> " . escapeshellarg($SCAN_LOG) . " 2>&1"
        ) . " & echo \$!";
        $pid = (int)shell_exec($cmd);
    }

    if (!$pid) {
        echo json_encode(['ok' => false, 'erro' => 'Não foi possível iniciar o scan.']);
        exit;
    }

    file_put_contents($SCAN_PID, $pid);
    auditLog('scan_rede_iniciado', '', 0, "PID=$pid redes=$redes");
    echo json_encode(['ok' => true, 'pid' => $pid]);
    exit;
}

// ── 2. Status do scan em execução ────────────────────────────────────────────
if ($action === 'scan_status') {
    session_write_close(); // libera o session lock para não bloquear outros requests
    $pid      = file_exists($SCAN_PID) ? (int) file_get_contents($SCAN_PID) : 0;
    $rodando  = $pid && procRodando($pid);
    $log      = file_exists($SCAN_LOG) ? file_get_contents($SCAN_LOG) : '';

    // Detecta CSV mais recente gerado pelo scanner
    $csvs = glob($SCAN_DIR . '/scan_rede_*.csv');
    usort($csvs, fn($a, $b) => filemtime($b) - filemtime($a));
    $csv_recente = $csvs[0] ?? null;
    $csv_nome    = $csv_recente ? basename($csv_recente) : null;
    $csv_novo    = $csv_recente && filemtime($csv_recente) > (time() - 300);

    if (!$rodando && $pid) {
        @unlink($SCAN_PID);
    }

    // base64 is always valid ASCII — json_encode can never fail on it.
    echo json_encode([
        'ok'      => true,
        'rodando' => $rodando,
        'pid'     => $pid,
        'log_b64' => base64_encode(substr($log, -4000)),
        'csv'     => $csv_novo ? $csv_nome : null,
    ]);
    exit;
}

// ── 3. Download do CSV gerado ─────────────────────────────────────────────────
if ($action === 'baixar_csv') {
    $arquivo = basename($_GET['arquivo'] ?? '');
    if (!preg_match('/^scan_rede_[\d_]+\.csv$/', $arquivo)) {
        http_response_code(400); echo json_encode(['ok'=>false]); exit;
    }
    $path = $SCAN_DIR . '/' . $arquivo;
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
    if (PHP_OS_FAMILY === 'Windows') {
        $ping_out = shell_exec("ping -n 1 -w 1000 " . escapeshellarg($ip) . " 2>nul");
        $online   = $ping_out && (str_contains($ping_out, 'TTL=') || str_contains($ping_out, 'ttl='));
    } else {
        $ping_out = shell_exec("ping -c 1 -W 1 " . escapeshellarg($ip) . " 2>&1");
        $online   = str_contains($ping_out ?? '', '1 received');
    }

    // Latência
    $latencia = null;
    if (preg_match('/[Tt]ime[<=]([\d\.]+)\s*ms/', $ping_out ?? '', $m)) {
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
    if (PHP_OS_FAMILY === 'Windows') {
        $arp = shell_exec("arp -a " . escapeshellarg($ip) . " 2>nul");
        // Windows: formato xx-xx-xx-xx-xx-xx
        if (preg_match('/([0-9a-f]{2}(?:-[0-9a-f]{2}){5})/i', $arp ?? '', $m)) {
            $mac = strtoupper(str_replace('-', ':', $m[1]));
        }
    } else {
        $arp = shell_exec("arp -n " . escapeshellarg($ip) . " 2>/dev/null");
        if (preg_match('/([0-9a-f]{2}(?::[0-9a-f]{2}){5})/i', $arp ?? '', $m)) {
            $mac = strtoupper($m[1]);
        }
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
    if (extension_loaded('snmp')) {
        try {
            snmp_set_quick_print(true);
            $val = @snmp2_get($ip, 'public', $oid, 2000000, 1);
            if ($val === false) return null;
            $val = trim((string)$val);
            return preg_match('/^-?\d+$/', $val) ? $val : (preg_match('/(-?\d+)/', $val, $m) ? $m[1] : null);
        } catch (Throwable) {}
    }
    // Fallback CLI (Linux)
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
        if ($pid && procRodando($pid)) {
            echo json_encode(['ok' => false, 'erro' => 'Coleta SNMP já em execução.']); exit;
        }
    }
    @unlink($SNMP_LOG);
    $script = __DIR__ . '/snmp_coletar.php';
    $phpExe = PHP_OS_FAMILY === 'Windows' ? findPhpExe() : PHP_BINARY;
    if (PHP_OS_FAMILY === 'Windows') {
        $bat2 = sys_get_temp_dir() . '\\helpti_snmp.bat';
        file_put_contents($bat2, "@echo off\r\n\"$phpExe\" \"$script\" >> \"$SNMP_LOG\" 2>&1\r\n");
        session_write_close();
        $psCmd2 = 'powershell -NoProfile -Command "Start-Process cmd -ArgumentList \'/c """' . addslashes($bat2) . '"""\' -WindowStyle Hidden -PassThru | Select-Object -ExpandProperty Id"';
        $pidStr2 = trim((string) shell_exec($psCmd2 . ' 2>nul'));
        $pid = (int) $pidStr2 ?: rand(20000, 59999);
        $cmd = ''; // não usado no Windows
    } else {
        $cmd = "nohup php " . escapeshellarg($script) . " > " . escapeshellarg($SNMP_LOG) . " 2>&1 & echo \$!";
    }
    if (PHP_OS_FAMILY !== 'Windows') $pid = (int)shell_exec($cmd);
    if (!$pid) { echo json_encode(['ok' => false, 'erro' => 'Falha ao iniciar.']); exit; }
    file_put_contents($SNMP_PID, $pid);
    echo json_encode(['ok' => true, 'pid' => $pid]);
    exit;
}

if ($action === 'snmp_status') {
    session_write_close();
    $SNMP_LOG = sys_get_temp_dir() . '/helpti_snmp.log';
    $SNMP_PID = sys_get_temp_dir() . '/helpti_snmp.pid';
    $pid     = file_exists($SNMP_PID) ? (int)file_get_contents($SNMP_PID) : 0;
    $rodando = $pid && procRodando($pid);
    $log     = file_exists($SNMP_LOG) ? file_get_contents($SNMP_LOG) : '';
    if (!$rodando && $pid) @unlink($SNMP_PID);
    $logClean2 = @iconv('UTF-8', 'UTF-8//IGNORE', $log) ?: preg_replace('/[\x80-\xFF]/', '?', $log);
    $logClean2 = substr($logClean2, -4000);
    $payload2 = json_encode(['ok' => true, 'rodando' => $rodando, 'log' => $logClean2]);
    if ($payload2 === false) {
        $logClean2 = preg_replace('/[^\x09\x0A\x0D\x20-\x7E]/', '?', $logClean2);
        $payload2 = json_encode(['ok' => true, 'rodando' => $rodando, 'log' => $logClean2]);
    }
    echo $payload2;
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
