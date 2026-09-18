<?php
// ============================================================
// bin/cron_contratos.php — Renovação automática e marcação de vencidos
// cPanel (1×/dia, de madrugada):  php /caminho/bin/cron_contratos.php
// Antes rodava em todo GET de contratos.php (P1-3).
// ============================================================

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/lib_cron.php';

cron_guard('contratos');

$pdo = db();
$intervalo_map = ['Mensal'=>'+1 month','Trimestral'=>'+3 months','Semestral'=>'+6 months','Anual'=>'+1 year'];
$renovados = 0; $vencidos = 0;

try {
    // 1) Não-auto vencidos → status 'Vencido'
    $vencidos = $pdo->exec("UPDATE contratos SET status='Vencido'
                            WHERE data_vencimento < CURDATE() AND status='Ativo' AND renovacao_auto=0");

    // 2) Auto: avança a data para o próximo período no futuro
    $auto = $pdo->query("SELECT id, data_vencimento, periodicidade FROM contratos
                         WHERE data_vencimento < CURDATE() AND status='Ativo' AND renovacao_auto=1")->fetchAll();

    foreach ($auto as $c) {
        $intervalo = $intervalo_map[$c['periodicidade']] ?? null;
        if (!$intervalo) {
            // 'Único' não renova — vira Vencido de fato
            $pdo->prepare("UPDATE contratos SET status='Vencido' WHERE id=? AND status='Ativo'")->execute([$c['id']]);
            $vencidos++;
            continue;
        }
        $nova = new DateTime($c['data_vencimento']);
        $hoje = new DateTime('today');
        $guard = 0;
        while ($nova < $hoje && $guard++ < 600) $nova->modify($intervalo);
        $novaStr = $nova->format('Y-m-d');

        try {
            $pdo->beginTransaction();
            // Optimistic lock: só renova se a data ainda é a que lemos (evita renovação dupla)
            $upd = $pdo->prepare("UPDATE contratos SET data_vencimento=?
                                  WHERE id=? AND data_vencimento=? AND status='Ativo' AND renovacao_auto=1");
            $upd->execute([$novaStr, $c['id'], $c['data_vencimento']]);
            if ($upd->rowCount() === 1) {
                $pdo->prepare("INSERT INTO contratos_renovacoes (contrato_id, data_anterior, data_nova, tipo)
                               VALUES (?,?,?,'auto')")
                    ->execute([$c['id'], $c['data_vencimento'], $novaStr]);
                $renovados++;
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            logApp('error', 'cron_contratos_item', ['contrato' => $c['id'], 'msg' => $e->getMessage()]);
        }
    }

    cron_finish('contratos', true, "renovados={$renovados} vencidos={$vencidos}");
    echo "[" . date('c') . "] contratos: renovados={$renovados} vencidos={$vencidos}\n";
} catch (Throwable $e) {
    cron_finish('contratos', false, $e->getMessage());
    throw $e;
}
