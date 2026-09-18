<?php
require 'db.php';
requireGestora();   // ações destrutivas: gestora/admin apenas (auditoria 4.2)

csrfVerify();

$pdo = db();
$u   = usuario();
$id  = (int)($_POST['id'] ?? 0);

if ($id) {
    $c = $pdo->prepare("SELECT numero FROM chamados WHERE id = ? AND deleted_at IS NULL");
    $c->execute([$id]);
    $ch = $c->fetch();

    if ($ch) {
        // Soft delete — preserva histórico e imagens, cumpre LGPD
        $pdo->prepare("UPDATE chamados SET deleted_at = NOW() WHERE id = ?")
            ->execute([$id]);

        auditLog('chamado_excluido', 'chamados', $id, "Número: {$ch['numero']}");
        flash("Chamado {$ch['numero']} excluído.");
    }
}

header('Location: chamados.php');
exit;
