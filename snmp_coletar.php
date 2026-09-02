<?php
/**
 * HelpTI — Coleta SNMP de Impressoras
 * Salva snapshot de páginas e toner; envia alertas por e-mail quando necessário.
 *
 * Cron sugerido (a cada 4h):
 *   0 [asterisco]/4 * * * php /path/to/snmp_coletar.php >> /tmp/snmp_coletar.log 2>&1
 */

define('CLI_RUN', true);
require __DIR__ . '/db.php';
$pdo = db();

// ── Cria tabela se ainda não existir ───────────────────────
$pdo->exec("CREATE TABLE IF NOT EXISTS `impressoras_snapshot` (
  `id` int NOT NULL AUTO_INCREMENT,
  `impressora_id` int NOT NULL,
  `coletado_em` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `paginas_total` int DEFAULT NULL,
  `toner_preto_pct` tinyint DEFAULT NULL,
  `toner_ciano_pct` tinyint DEFAULT NULL,
  `toner_magenta_pct` tinyint DEFAULT NULL,
  `toner_amarelo_pct` tinyint DEFAULT NULL,
  `raw_snmp` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_impressora_data` (`impressora_id`, `coletado_em`),
  CONSTRAINT `fk_snap_impressora` FOREIGN KEY (`impressora_id`)
    REFERENCES `impressoras` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// ── Destinatários: todos os admins do sistema ───────────────
$admins = $pdo->query(
    "SELECT nome, email FROM usuarios WHERE perfil = 'admin' AND email IS NOT NULL AND email != ''"
)->fetchAll();

// ── Busca impressoras ativas com IP ─────────────────────────
$impressoras = $pdo->query(
    "SELECT id, nome, marca_modelo, ip, setor, alerta_toner_em, alerta_offline_em, inventario_id
     FROM impressoras
     WHERE ip IS NOT NULL AND ip != '' AND status = 'Ativa'"
)->fetchAll();

$inicio = date('Y-m-d H:i:s');
echo "[{$inicio}] Iniciando coleta SNMP — " . count($impressoras) . " impressora(s) ativa(s) com IP\n";
echo "  Admins para alertas: " . implode(', ', array_column($admins, 'email')) . "\n\n";

$alertas_toner   = 0;
$alertas_offline = 0;

foreach ($impressoras as $imp) {
    $ip   = $imp['ip'];
    $nome = $imp['nome'];
    echo "  [{$ip}] {$nome} ... ";

    $dados = snmp_coleta($ip);

    // ── Sem resposta SNMP ───────────────────────────────────
    if ($dados === null) {
        echo "sem resposta SNMP";

        // Fallback: SNMP pode estar bloqueado no equipamento (comum em HP com
        // segurança reforçada) mas o EWS/HTTP continua ativo. Tenta recuperar
        // nome/modelo por lá quando ainda está "Desconhecido".
        $desconhecido_imp = in_array(strtolower(trim($imp['nome'])), ['desconhecido', '']) ||
                            in_array(strtolower(trim($imp['marca_modelo'] ?? '')), ['desconhecido', '']);
        if ($desconhecido_imp) {
            $http_info = hp_modelo_http($ip);
            if ($http_info) {
                $novo_nome = in_array(strtolower(trim($imp['nome'])), ['desconhecido', '']) ? $http_info['modelo'] : $imp['nome'];
                $pdo->prepare("UPDATE impressoras SET nome = ?, marca_modelo = ?, numero_serie = COALESCE(NULLIF(numero_serie,''), ?) WHERE id = ?")
                    ->execute([$novo_nome, $http_info['modelo'], $http_info['serie'], $imp['id']]);
                if (!empty($imp['inventario_id'])) {
                    $pdo->prepare("
                        UPDATE inventario SET marca = ?, modelo = ?, atualizado_em = NOW()
                        WHERE id = ? AND (marca IN ('Desconhecido','') OR marca IS NULL)
                    ")->execute([$http_info['modelo'], $http_info['modelo'], $imp['inventario_id']]);
                }
                echo " [nome atualizado via HTTP: {$http_info['modelo']}]";
            }
        }

        // Alerta offline: só para impressoras que já responderam SNMP antes
        $st = $pdo->prepare("SELECT COUNT(*) FROM impressoras_snapshot WHERE impressora_id = ?");
        $st->execute([$imp['id']]);
        $ja_respondeu = (int)$st->fetchColumn() > 0;

        $ultima_offline = $imp['alerta_offline_em'];
        $pode_alertar   = $ja_respondeu && (!$ultima_offline
            || (time() - strtotime($ultima_offline)) > 86400);

        if ($pode_alertar && $admins) {
            enfileira_alerta_offline($pdo, $imp, $admins);
            $pdo->prepare("UPDATE impressoras SET alerta_offline_em = NOW() WHERE id = ?")
                ->execute([$imp['id']]);
            echo " [alerta offline enviado]";
            $alertas_offline++;
        }

        echo "\n";
        continue;
    }

    // ── Atualiza nome/modelo se ainda "Desconhecido" ───────
    $desconhecido_imp = in_array(strtolower(trim($imp['nome'])), ['desconhecido', '']) ||
                        in_array(strtolower(trim($imp['marca_modelo'] ?? '')), ['desconhecido', '']);
    if ($desconhecido_imp) {
        $modelo_snmp = snmp_get_str($ip, '1.3.6.1.2.1.25.3.2.1.3.1');
        if ($modelo_snmp) {
            $novo_nome = in_array(strtolower(trim($imp['nome'])), ['desconhecido', '']) ? $modelo_snmp : $imp['nome'];
            $pdo->prepare("UPDATE impressoras SET nome = ?, marca_modelo = ? WHERE id = ?")
                ->execute([$novo_nome, $modelo_snmp, $imp['id']]);
            // Propaga para inventário vinculado
            if (!empty($imp['inventario_id'])) {
                $pdo->prepare("
                    UPDATE inventario SET marca = ?, modelo = ?, atualizado_em = NOW()
                    WHERE id = ? AND (marca IN ('Desconhecido','') OR marca IS NULL)
                ")->execute([$modelo_snmp, $modelo_snmp, $imp['inventario_id']]);
            }
            echo " [nome atualizado: {$modelo_snmp}]";
        }
    }

    // ── Salva snapshot ──────────────────────────────────────
    $pdo->prepare("
        INSERT INTO impressoras_snapshot
            (impressora_id, paginas_total,
             toner_preto_pct, toner_ciano_pct, toner_magenta_pct, toner_amarelo_pct,
             raw_snmp)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ")->execute([
        $imp['id'],
        $dados['paginas_total'],
        $dados['toner_preto_pct'],
        $dados['toner_ciano_pct'],
        $dados['toner_magenta_pct'],
        $dados['toner_amarelo_pct'],
        json_encode($dados['raw']),
    ]);

    // Impressora respondeu — limpa flag de offline
    if ($imp['alerta_offline_em']) {
        $pdo->prepare("UPDATE impressoras SET alerta_offline_em = NULL WHERE id = ?")
            ->execute([$imp['id']]);
    }

    $pag = $dados['paginas_total'] !== null ? number_format($dados['paginas_total'], 0, ',', '.') . ' pág' : '—';

    // Monta o resumo de toner mostrando todos os canais coletados (só aparece
    // ciano/magenta/amarelo quando a impressora realmente reportou esses canais)
    $canais_log = [
        'preto'   => $dados['toner_preto_pct'],
        'ciano'   => $dados['toner_ciano_pct'],
        'magenta' => $dados['toner_magenta_pct'],
        'amarelo' => $dados['toner_amarelo_pct'],
    ];
    $partes_toner = [];
    foreach ($canais_log as $cor => $pct) {
        if ($pct !== null) $partes_toner[] = "{$pct}% {$cor}";
    }
    $toner = $partes_toner ? implode(', ', $partes_toner) : '—';
    echo "OK — {$pag} | toner {$toner}";

    // ── Alerta de toner crítico (qualquer canal ≤ 15%) ─────
    $canais = [
        'toner_preto_pct'   => $dados['toner_preto_pct'],
        'toner_ciano_pct'   => $dados['toner_ciano_pct'],
        'toner_magenta_pct' => $dados['toner_magenta_pct'],
        'toner_amarelo_pct' => $dados['toner_amarelo_pct'],
    ];
    $min_toner = 100;
    foreach ($canais as $pct) {
        if ($pct !== null) $min_toner = min($min_toner, $pct);
    }
    $toner_critico = $min_toner <= 15;

    if ($toner_critico && $admins) {
        $ultima_toner = $imp['alerta_toner_em'];
        $pode_alertar = !$ultima_toner
            || (time() - strtotime($ultima_toner)) > 86400;

        if ($pode_alertar) {
            enfileira_alerta_toner($pdo, $imp, $dados, $admins);
            $pdo->prepare("UPDATE impressoras SET alerta_toner_em = NOW() WHERE id = ?")
                ->execute([$imp['id']]);
            $nivel = $min_toner <= 5 ? '🔴 URGENTE' : '🟡 ATENÇÃO';
            echo " [{$nivel} alerta toner]";
            $alertas_toner++;
        }
    } elseif (!$toner_critico && $imp['alerta_toner_em']) {
        // Todos os toneres OK — limpa flag
        $pdo->prepare("UPDATE impressoras SET alerta_toner_em = NULL WHERE id = ?")
            ->execute([$imp['id']]);
    }

    echo "\n";
}

echo "\n[" . date('Y-m-d H:i:s') . "] Coleta finalizada";
echo " | Alertas toner: {$alertas_toner} | Alertas offline: {$alertas_offline}\n";

// ── E-mails ─────────────────────────────────────────────────

function enfileira_alerta_toner(PDO $pdo, array $imp, array $dados, array $admins): void
{
    $tp    = (int)$dados['toner_preto_pct'];
    $urgente = $tp <= 5;
    $emoji = $urgente ? '🔴' : '🟡';
    $nivel = $urgente ? 'URGENTE — Toner quase vazio' : 'Toner baixo';
    $url   = defined('APP_URL') ? APP_URL . '/impressora.php?id=' . $imp['id'] : '#';

    // Monta linha de cores (se for colorida)
    $cores_html = '';
    foreach (['ciano' => $dados['toner_ciano_pct'], 'magenta' => $dados['toner_magenta_pct'], 'amarelo' => $dados['toner_amarelo_pct']] as $cor => $pct) {
        if ($pct !== null) {
            $badge = $pct <= 15 ? '#dc3545' : ($pct <= 30 ? '#fd7e14' : '#198754');
            $cores_html .= "<span style='display:inline-block;margin:2px 4px;padding:2px 8px;background:{$badge};color:#fff;border-radius:4px;font-size:12px'>{$cor}: {$pct}%</span>";
        }
    }

    $corpo = "
    <div style='font-family:Segoe UI,Arial,sans-serif;max-width:560px;margin:0 auto'>
      <div style='background:#1D3557;color:#fff;padding:16px 24px;border-radius:8px 8px 0 0'>
        <h2 style='margin:0;font-size:18px'>{$emoji} {$nivel}</h2>
        <p style='margin:4px 0 0;font-size:13px;opacity:.85'>HelpTI — Monitoramento de Impressoras</p>
      </div>
      <div style='background:#fff;padding:24px;border:1px solid #e5e9f2;border-top:none;border-radius:0 0 8px 8px'>
        <table style='width:100%;border-collapse:collapse;font-size:14px'>
          <tr><td style='padding:6px 0;color:#6c757d;width:130px'>Impressora</td>
              <td style='padding:6px 0;font-weight:600'>" . htmlspecialchars($imp['nome']) . "</td></tr>
          <tr><td style='padding:6px 0;color:#6c757d'>Setor</td>
              <td style='padding:6px 0'>" . htmlspecialchars($imp['setor'] ?: '—') . "</td></tr>
          <tr><td style='padding:6px 0;color:#6c757d'>IP</td>
              <td style='padding:6px 0;font-family:monospace'>" . htmlspecialchars($imp['ip']) . "</td></tr>
          <tr><td style='padding:6px 0;color:#6c757d'>Toner Preto</td>
              <td style='padding:6px 0'>
                <span style='display:inline-block;padding:3px 10px;background:" . ($urgente ? '#dc3545' : '#fd7e14') . ";color:#fff;border-radius:4px;font-weight:700'>{$tp}%</span>
              </td></tr>
          " . ($cores_html ? "<tr><td style='padding:6px 0;color:#6c757d;vertical-align:top'>Cores</td><td style='padding:6px 0'>{$cores_html}</td></tr>" : "") . "
        </table>
        <div style='margin-top:20px'>
          <a href='{$url}' style='display:inline-block;padding:10px 20px;background:#1D3557;color:#fff;text-decoration:none;border-radius:6px;font-size:13px'>
            Ver impressora no sistema
          </a>
        </div>
        <p style='margin-top:16px;font-size:12px;color:#94a3b8'>
          Este alerta é enviado uma vez por dia enquanto o toner estiver abaixo de 15%.
        </p>
      </div>
    </div>";

    $assunto = "{$emoji} [{$nivel}] " . $imp['nome'] . " — Toner: {$tp}%";
    foreach ($admins as $admin) {
        $pdo->prepare("INSERT INTO email_queue (destinatario, assunto, corpo) VALUES (?, ?, ?)")
            ->execute([$admin['email'], $assunto, $corpo]);
    }
}

function enfileira_alerta_offline(PDO $pdo, array $imp, array $admins): void
{
    $url   = defined('APP_URL') ? APP_URL . '/impressora.php?id=' . $imp['id'] : '#';
    $corpo = "
    <div style='font-family:Segoe UI,Arial,sans-serif;max-width:560px;margin:0 auto'>
      <div style='background:#E63946;color:#fff;padding:16px 24px;border-radius:8px 8px 0 0'>
        <h2 style='margin:0;font-size:18px'>⚠️ Impressora sem resposta SNMP</h2>
        <p style='margin:4px 0 0;font-size:13px;opacity:.85'>HelpTI — Monitoramento de Impressoras</p>
      </div>
      <div style='background:#fff;padding:24px;border:1px solid #e5e9f2;border-top:none;border-radius:0 0 8px 8px'>
        <p style='font-size:14px;color:#333'>A impressora abaixo não respondeu à consulta SNMP. Pode estar desligada, offline ou com problema de rede.</p>
        <table style='width:100%;border-collapse:collapse;font-size:14px'>
          <tr><td style='padding:6px 0;color:#6c757d;width:130px'>Impressora</td>
              <td style='padding:6px 0;font-weight:600'>" . htmlspecialchars($imp['nome']) . "</td></tr>
          <tr><td style='padding:6px 0;color:#6c757d'>Setor</td>
              <td style='padding:6px 0'>" . htmlspecialchars($imp['setor'] ?: '—') . "</td></tr>
          <tr><td style='padding:6px 0;color:#6c757d'>IP</td>
              <td style='padding:6px 0;font-family:monospace'>" . htmlspecialchars($imp['ip']) . "</td></tr>
          <tr><td style='padding:6px 0;color:#6c757d'>Verificado em</td>
              <td style='padding:6px 0'>" . date('d/m/Y H:i') . "</td></tr>
        </table>
        <div style='margin-top:20px'>
          <a href='{$url}' style='display:inline-block;padding:10px 20px;background:#E63946;color:#fff;text-decoration:none;border-radius:6px;font-size:13px'>
            Ver impressora no sistema
          </a>
        </div>
        <p style='margin-top:16px;font-size:12px;color:#94a3b8'>
          Este alerta é enviado uma vez por dia enquanto a impressora permanecer sem resposta.
        </p>
      </div>
    </div>";

    $assunto = "⚠️ [Offline] Impressora sem resposta: " . $imp['nome'];
    foreach ($admins as $admin) {
        $pdo->prepare("INSERT INTO email_queue (destinatario, assunto, corpo) VALUES (?, ?, ?)")
            ->execute([$admin['email'], $assunto, $corpo]);
    }
}

// ── Funções SNMP ────────────────────────────────────────────

function snmp_get_str(string $ip, string $oid): ?string
{
    $out = shell_exec(
        'snmpget -v2c -c public -t 2 -r 1 ' . escapeshellarg($ip) . ' ' . escapeshellarg($oid) . ' 2>/dev/null'
    );
    if (!$out) return null;
    if (preg_match('/STRING:\s*"(.+)"/', $out, $m)) return trim($m[1]);
    if (preg_match('/STRING:\s*(.+)/', $out, $m))   return trim($m[1]);
    return null;
}

function snmp_get(string $ip, string $oid): ?string
{
    $out = shell_exec(
        'snmpget -v2c -c public -t 2 -r 1 ' . escapeshellarg($ip) . ' ' . escapeshellarg($oid) . ' 2>/dev/null'
    );
    if (!$out) return null;
    return preg_match('/:\s*(-?\d+)/', $out, $m) ? $m[1] : null;
}

function toner_pct(?string $nivel, ?string $cap): ?int
{
    if ($nivel === null || $cap === null) return null;
    $c = (int)$cap;
    $n = (int)$nivel;
    // -2 = unknown (Printer MIB); -3 = unlimited (thermal)
    if ($n === -2 || $n < 0 || $c <= 0 || $c === -3) return null;
    return min(100, (int)round(($n / $c) * 100));
}

// Tenta buscar nível de toner via XML HTTP da HP quando SNMP retorna -2/unknown
function hp_toner_xml(string $ip): ?int
{
    $xml = @file_get_contents(
        "http://{$ip}/DevMgmt/ConsumableConfigDyn.xml",
        false,
        stream_context_create(['http' => ['timeout' => 3]])
    );
    if (!$xml) return null;
    if (preg_match('/<dd:ConsumablePercentageLevelRemaining>(\d+)<\/dd:ConsumablePercentageLevelRemaining>/', $xml, $m)) {
        return min(100, (int)$m[1]);
    }
    return null;
}

// Tenta buscar modelo/número de série via XML HTTP da HP quando SNMP não responde
// (algumas impressoras HP bloqueiam SNMP por segurança mas mantêm o EWS/HTTP ativo)
function hp_modelo_http(string $ip): ?array
{
    $xml = @file_get_contents(
        "http://{$ip}/DevMgmt/ProductConfigDyn.xml",
        false,
        stream_context_create(['http' => ['timeout' => 3]])
    );
    if (!$xml) return null;
    $modelo = null;
    $serie  = null;
    if (preg_match('/<dd:MakeAndModel>(.+?)<\/dd:MakeAndModel>/', $xml, $m)) $modelo = trim($m[1]);
    if (preg_match('/<dd:SerialNumber>(.+?)<\/dd:SerialNumber>/', $xml, $m)) $serie = trim($m[1]);
    return $modelo ? ['modelo' => $modelo, 'serie' => $serie] : null;
}

function snmp_coleta(string $ip): ?array
{
    $paginas = snmp_get($ip, '1.3.6.1.2.1.43.10.2.1.4.1.1');
    if ($paginas === null) return null;

    $raw  = ['paginas' => $paginas];
    $pcts = [];
    $tem_snmp_unknown = false;
    foreach ([1 => 'preto', 2 => 'ciano', 3 => 'magenta', 4 => 'amarelo'] as $idx => $cor) {
        $niv = snmp_get($ip, "1.3.6.1.2.1.43.11.1.1.9.1.{$idx}");
        $cap = snmp_get($ip, "1.3.6.1.2.1.43.11.1.1.8.1.{$idx}");
        if ($niv === '-2') $tem_snmp_unknown = true;
        $raw["toner_{$cor}_nivel"] = $niv;
        $raw["toner_{$cor}_cap"]   = $cap;
        $pcts[$cor] = toner_pct($niv, $cap);
    }

    // Fallback HTTP XML para HP com toner -2 (cartucho original mas SNMP não expõe %)
    if ($pcts['preto'] === null && $tem_snmp_unknown) {
        $xml_pct = hp_toner_xml($ip);
        if ($xml_pct !== null) {
            $pcts['preto'] = $xml_pct;
            $raw['toner_preto_xml_fallback'] = $xml_pct;
        }
    }

    return [
        'paginas_total'     => (int)$paginas,
        'toner_preto_pct'   => $pcts['preto'],
        'toner_ciano_pct'   => $pcts['ciano'],
        'toner_magenta_pct' => $pcts['magenta'],
        'toner_amarelo_pct' => $pcts['amarelo'],
        'raw'               => $raw,
    ];
}
