<?php
require 'db.php';
requireGestora();   // ações destrutivas: gestora/admin apenas (auditoria 4.2)

$pdo = db();

csrfVerify();
$id = (int)($_POST['id'] ?? 0);
if ($id) {
    // Não exclui se houver manutenções vinculadas
    $manut = $pdo->prepare("SELECT COUNT(*) FROM manutencoes_impressoras WHERE impressora_id=?");
    $manut->execute([$id]);
    if ($manut->fetchColumn() > 0) {
        flash('Impressora possui manutenções registradas e não pode ser excluída. Marque como Inativa.', 'danger');
        header('Location: impressoras.php'); exit;
    }

    $st = $pdo->prepare("SELECT nome FROM impressoras WHERE id=?");
    $st->execute([$id]);
    $imp = $st->fetch();
    if ($imp) {
        $pdo->prepare("DELETE FROM impressoras WHERE id=?")->execute([$id]);
        flash("Impressora \"{$imp['nome']}\" excluída.");
    }
}

header('Location: impressoras.php');
exit;
