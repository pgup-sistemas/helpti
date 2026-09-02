<?php
/**
 * HelpTI — Reconciliação de Hosts de Rede
 * Lê scan_ultimo.json gerado pelo scanner_rede.py e atualiza hosts_rede + inventario.
 *
 * Pode ser chamado:
 *   - Pelo cron (executa o scanner + reconcilia)
 *   - Diretamente após o scanner já ter rodado (só reconcilia)
 *
 * Cron sugerido (diário às 6h):
 *   0 6 * * * cd /path && python3 scanner_rede.py >> /tmp/scanner.log 2>&1 && php cron_scanner.php >> /tmp/scanner.log 2>&1
 */

define('CLI_RUN', true);
require __DIR__ . '/db.php';
$pdo = db();

$json_file = __DIR__ . '/scan_ultimo.json';

// Se executado via cron sem JSON ainda, roda o scanner primeiro
$modo_scanner = false;
if (!file_exists($json_file) || (isset($argv[1]) && $argv[1] === '--scan')) {
    echo "[" . date('Y-m-d H:i:s') . "] Executando scanner_rede.py...\n";
    passthru('cd ' . escapeshellarg(__DIR__) . ' && python3 scanner_rede.py 2>&1');
    $modo_scanner = true;
}

if (!file_exists($json_file)) {
    echo "[" . date('Y-m-d H:i:s') . "] ERRO: scan_ultimo.json não encontrado.\n";
    exit(1);
}

$json = json_decode(file_get_contents($json_file), true);
if (!$json || empty($json['hosts'])) {
    echo "[" . date('Y-m-d H:i:s') . "] ERRO: JSON inválido ou vazio.\n";
    exit(1);
}

$escaneado_em = $json['escaneado_em'];
$hosts        = $json['hosts'];
echo "[" . date('Y-m-d H:i:s') . "] Reconciliando " . count($hosts) . " host(s) escaneados em {$escaneado_em}\n";

// ── Garante que tabela existe ─────────────────────────────
$pdo->exec("CREATE TABLE IF NOT EXISTS `hosts_rede` (
  `id` int NOT NULL AUTO_INCREMENT,
  `ip` varchar(45) NOT NULL,
  `mac_address` varchar(17) NOT NULL,
  `hostname` varchar(255) DEFAULT NULL,
  `fabricante` varchar(100) DEFAULT NULL,
  `tipo` varchar(50) DEFAULT NULL,
  `marca` varchar(60) DEFAULT NULL,
  `portas` text DEFAULT NULL,
  `rede` varchar(20) DEFAULT NULL,
  `setor` varchar(60) DEFAULT NULL,
  `inventario_id` int DEFAULT NULL,
  `primeiro_visto` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `ultimo_visto` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `online` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_mac` (`mac_address`),
  KEY `idx_ip` (`ip`),
  KEY `idx_tipo` (`tipo`),
  KEY `fk_hosts_inventario` (`inventario_id`),
  CONSTRAINT `fk_hosts_inventario` FOREIGN KEY (`inventario_id`) REFERENCES `inventario` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// ── Extrai IP/MAC de observacoes para registros sem IP ────
$sem_ip = $pdo->query(
    "SELECT id, observacoes FROM inventario
     WHERE (ip IS NULL OR ip = '') AND observacoes LIKE '%IP: %'"
)->fetchAll();
foreach ($sem_ip as $r) {
    $ip = $mac = null;
    if (preg_match('/IP:\s*([\d\.]+)/', $r['observacoes'], $m))       $ip  = $m[1];
    if (preg_match('/MAC:\s*([0-9A-Fa-f:]{17})/', $r['observacoes'], $m)) $mac = strtolower($m[1]);
    if ($ip || $mac) {
        $pdo->prepare("UPDATE inventario SET ip = COALESCE(?, ip), mac_address = COALESCE(?, mac_address) WHERE id = ?")
            ->execute([$ip, $mac, $r['id']]);
    }
}
if ($sem_ip) echo "[" . date('Y-m-d H:i:s') . "] IP/MAC extraídos de observações: " . count($sem_ip) . " registro(s)\n";

// ── Marca todos como offline antes de processar ───────────
$pdo->exec("UPDATE hosts_rede SET online = 0");

$novos       = 0;
$atualizados = 0;
$vinculados  = 0;

foreach ($hosts as $h) {
    $mac      = strtolower(trim($h['mac']));
    $ip       = trim($h['ip']);
    $hostname = trim($h['hostname'] ?? '');
    $portas   = implode(',', $h['portas'] ?? []);

    if (!$mac || $mac === '00:00:00:00:00:00') continue;

    // Verifica se já existe no inventário por MAC ou IP
    $inv_id = null;
    $inv = $pdo->prepare("SELECT id FROM inventario WHERE mac_address = ? LIMIT 1");
    $inv->execute([$mac]);
    $row = $inv->fetch();
    if ($row) {
        $inv_id = $row['id'];
        // Atualiza IP no inventário se mudou
        $pdo->prepare("UPDATE inventario SET ip = ? WHERE id = ? AND (ip IS NULL OR ip != ?)")
            ->execute([$ip, $inv_id, $ip]);
    } else {
        // Tenta por IP como fallback
        $inv2 = $pdo->prepare("SELECT id FROM inventario WHERE ip = ? LIMIT 1");
        $inv2->execute([$ip]);
        $r2 = $inv2->fetch();
        if ($r2) {
            $inv_id = $r2['id'];
            // Salva MAC no inventário
            $pdo->prepare("UPDATE inventario SET mac_address = ? WHERE id = ? AND (mac_address IS NULL OR mac_address = '')")
                ->execute([$mac, $inv_id]);
        }
    }

    // Upsert em hosts_rede por MAC
    $existe = $pdo->prepare("SELECT id, inventario_id FROM hosts_rede WHERE mac_address = ?");
    $existe->execute([$mac]);
    $host_row = $existe->fetch();

    if ($host_row) {
        // Usa inventario_id existente se não achamos um novo
        if ($inv_id === null) $inv_id = $host_row['inventario_id'];

        $pdo->prepare("
            UPDATE hosts_rede SET
                ip = ?, hostname = ?, fabricante = ?, tipo = ?, marca = ?,
                portas = ?, rede = ?, setor = ?, inventario_id = ?,
                ultimo_visto = NOW(), online = 1
            WHERE mac_address = ?
        ")->execute([$ip, $hostname, $h['fabricante'], $h['tipo'], $h['marca'],
                     $portas, $h['rede'], $h['setor'], $inv_id, $mac]);
        $atualizados++;
        if ($inv_id && !$host_row['inventario_id']) $vinculados++;
    } else {
        $pdo->prepare("
            INSERT INTO hosts_rede
                (ip, mac_address, hostname, fabricante, tipo, marca, portas, rede, setor,
                 inventario_id, primeiro_visto, ultimo_visto, online)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), 1)
        ")->execute([$ip, $mac, $hostname, $h['fabricante'], $h['tipo'], $h['marca'],
                     $portas, $h['rede'], $h['setor'], $inv_id]);
        $novos++;
        if ($inv_id) $vinculados++;
    }
}

// Conta offline após processamento
$offline = (int)$pdo->query("SELECT COUNT(*) FROM hosts_rede WHERE online = 0")->fetchColumn();

// Atualiza status do inventário: Disponível → Em Uso se host está online
$atualizados_status = (int)$pdo->exec("
    UPDATE inventario i
    JOIN hosts_rede h ON h.inventario_id = i.id
    SET i.status = 'Em Uso', i.atualizado_em = NOW()
    WHERE h.online = 1
      AND i.status = 'Disponível'
");

echo "[" . date('Y-m-d H:i:s') . "] Concluído:\n";
echo "  Novos hosts    : {$novos}\n";
echo "  Atualizados    : {$atualizados}\n";
echo "  Vinculados inv.: {$vinculados}\n";
echo "  Offline agora  : {$offline}\n";
if ($atualizados_status > 0)
    echo "  Status inv.    : {$atualizados_status} atualizados Disponível → Em Uso\n";
