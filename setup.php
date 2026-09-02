<?php
/**
 * HelpTI — Script de Instalação
 * PageUp Sistemas
 *
 * ATENÇÃO: Delete este arquivo após a instalação!
 * Acesse: https://seudominio.com.br/setup.php
 */

// ── Proteção básica ────────────────────────────────────────
define('SETUP_TOKEN', 'helpti2026'); // mude se quiser
if (($_GET['token'] ?? '') !== SETUP_TOKEN && ($_POST['token'] ?? '') !== SETUP_TOKEN) {
    http_response_code(403);
    die('Acesso negado. Passe ?token=helpti2026 na URL.');
}

session_start();

// ── Lê config.php para pegar as constantes de DB ───────────
if (!file_exists(__DIR__ . '/config.php')) {
    die('<h2>config.php não encontrado.</h2> Envie todos os arquivos via FTP primeiro.');
}
require __DIR__ . '/config.php';

// ── Ação de instalação ─────────────────────────────────────
$log    = [];
$errors = [];

function ok(string $msg): void  { global $log;    $log[]    = $msg; }
function err(string $msg): void { global $errors; $errors[] = $msg; }

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['instalar'])) {

    // Conexão direta (sem require db.php para evitar crash se DB não existe)
    try {
        $dsn = 'mysql:host=' . DB_HOST . ';charset=utf8mb4';
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

        // Cria/seleciona banco
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `" . DB_NAME . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo->exec("USE `" . DB_NAME . "`");
        ok("Banco de dados <strong>" . DB_NAME . "</strong> OK.");

    } catch (Exception $e) {
        err("Não foi possível conectar ao MySQL: " . $e->getMessage());
        goto fim;
    }

    // ── Criação das tabelas ──────────────────────────────────
    $sqls = [];

    $sqls['categorias'] = "CREATE TABLE IF NOT EXISTS `categorias` (
      `id` int NOT NULL AUTO_INCREMENT,
      `nome` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
      `icone` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'bi-tag',
      `ativo` tinyint NOT NULL DEFAULT '1',
      PRIMARY KEY (`id`),
      KEY `idx_ativo` (`ativo`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $sqls['usuarios'] = "CREATE TABLE IF NOT EXISTS `usuarios` (
      `id` int NOT NULL AUTO_INCREMENT,
      `nome` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
      `email` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
      `senha` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
      `perfil` enum('tecnico','gestora','admin') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'tecnico',
      `ativo` tinyint NOT NULL DEFAULT '1',
      `criado_em` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`),
      UNIQUE KEY `email` (`email`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $sqls['setores'] = "CREATE TABLE IF NOT EXISTS `setores` (
      `id` int NOT NULL AUTO_INCREMENT,
      `nome` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
      `ativo` tinyint NOT NULL DEFAULT '1',
      `criado_em` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`),
      UNIQUE KEY `nome` (`nome`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $sqls['chamados'] = "CREATE TABLE IF NOT EXISTS `chamados` (
      `id` int NOT NULL AUTO_INCREMENT,
      `numero` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
      `descricao` text COLLATE utf8mb4_unicode_ci NOT NULL,
      `setor` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
      `solicitante` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
      `telefone_solicitante` varchar(25) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      `responsavel_id` int DEFAULT NULL,
      `nivel` enum('A Definir','Baixa Complexidade','Média Complexidade','Alta Complexidade') COLLATE utf8mb4_unicode_ci DEFAULT 'A Definir',
      `categoria_id` int DEFAULT NULL,
      `status` enum('Aberto','Em Andamento','Pendente','Concluído') COLLATE utf8mb4_unicode_ci DEFAULT 'Aberto',
      `semana` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      `resolucao` text COLLATE utf8mb4_unicode_ci,
      `origem` enum('Formulário Web','WhatsApp','Telefone','Presencial') COLLATE utf8mb4_unicode_ci DEFAULT 'Formulário Web',
      `imagens` text COLLATE utf8mb4_unicode_ci,
      `criado_em` datetime DEFAULT CURRENT_TIMESTAMP,
      `atualizado_em` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      `fechado_em` datetime DEFAULT NULL,
      PRIMARY KEY (`id`),
      UNIQUE KEY `numero` (`numero`),
      KEY `responsavel_id` (`responsavel_id`),
      KEY `idx_status` (`status`),
      KEY `idx_criado_em` (`criado_em`),
      KEY `fk_chamado_cat` (`categoria_id`),
      CONSTRAINT `chamados_ibfk_1` FOREIGN KEY (`responsavel_id`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL,
      CONSTRAINT `fk_chamado_cat` FOREIGN KEY (`categoria_id`) REFERENCES `categorias` (`id`) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $sqls['impressoras'] = "CREATE TABLE IF NOT EXISTS `impressoras` (
      `id` int NOT NULL AUTO_INCREMENT,
      `nome` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
      `marca_modelo` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
      `numero_serie` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      `ip` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      `setor` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
      `modelo_toner` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      `status` enum('Ativa','Em Manutenção','Inativa') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Ativa',
      `criado_em` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
      `atualizado_em` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`),
      KEY `idx_status` (`status`),
      KEY `idx_setor` (`setor`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $sqls['manutencoes_impressoras'] = "CREATE TABLE IF NOT EXISTS `manutencoes_impressoras` (
      `id` int NOT NULL AUTO_INCREMENT,
      `impressora_id` int NOT NULL,
      `tecnico_id` int DEFAULT NULL,
      `tipo` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
      `descricao_problema` text COLLATE utf8mb4_unicode_ci NOT NULL,
      `solucao` text COLLATE utf8mb4_unicode_ci,
      `pecas_trocadas` text COLLATE utf8mb4_unicode_ci,
      `custo` decimal(10,2) DEFAULT NULL,
      `data_manutencao` date NOT NULL,
      `status` enum('Pendente','Em Realização','Concluída') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Pendente',
      `criado_em` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
      `atualizado_em` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`),
      KEY `fk_manut_tecnico` (`tecnico_id`),
      KEY `idx_impressora_id` (`impressora_id`),
      KEY `idx_status` (`status`),
      CONSTRAINT `fk_manut_impressora` FOREIGN KEY (`impressora_id`) REFERENCES `impressoras` (`id`) ON DELETE CASCADE,
      CONSTRAINT `fk_manut_tecnico` FOREIGN KEY (`tecnico_id`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $sqls['tipos_suprimentos'] = "CREATE TABLE IF NOT EXISTS `tipos_suprimentos` (
      `id` int NOT NULL AUTO_INCREMENT,
      `nome` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
      `ativo` tinyint NOT NULL DEFAULT '1',
      PRIMARY KEY (`id`),
      KEY `idx_ativo` (`ativo`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $sqls['pedidos_suprimentos'] = "CREATE TABLE IF NOT EXISTS `pedidos_suprimentos` (
      `id` int NOT NULL AUTO_INCREMENT,
      `numero` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
      `impressora_id` int DEFAULT NULL,
      `setor` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
      `solicitante` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
      `status` enum('Pendente','Aprovado','Entregue','Cancelado') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Pendente',
      `observacoes` text COLLATE utf8mb4_unicode_ci,
      `observacoes_entrega` text COLLATE utf8mb4_unicode_ci,
      `criado_em` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
      `atualizado_em` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`),
      UNIQUE KEY `numero` (`numero`),
      KEY `fk_ped_impressora` (`impressora_id`),
      KEY `idx_status` (`status`),
      KEY `idx_criado_em` (`criado_em`),
      CONSTRAINT `fk_ped_impressora` FOREIGN KEY (`impressora_id`) REFERENCES `impressoras` (`id`) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $sqls['pedidos_suprimentos_itens'] = "CREATE TABLE IF NOT EXISTS `pedidos_suprimentos_itens` (
      `id` int NOT NULL AUTO_INCREMENT,
      `pedido_id` int NOT NULL,
      `tipo_suprimento_id` int DEFAULT NULL,
      `descricao_livre` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      `quantidade` int NOT NULL DEFAULT '1',
      PRIMARY KEY (`id`),
      KEY `fk_item_tipo` (`tipo_suprimento_id`),
      KEY `idx_pedido_id` (`pedido_id`),
      CONSTRAINT `fk_item_pedido` FOREIGN KEY (`pedido_id`) REFERENCES `pedidos_suprimentos` (`id`) ON DELETE CASCADE,
      CONSTRAINT `fk_item_tipo` FOREIGN KEY (`tipo_suprimento_id`) REFERENCES `tipos_suprimentos` (`id`) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $sqls['impressoras_snapshot'] = "CREATE TABLE IF NOT EXISTS `impressoras_snapshot` (
      `id` int NOT NULL AUTO_INCREMENT,
      `impressora_id` int NOT NULL,
      `coletado_em` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
      `paginas_total` int DEFAULT NULL,
      `toner_preto_pct` tinyint DEFAULT NULL,
      `toner_ciano_pct` tinyint DEFAULT NULL,
      `toner_magenta_pct` tinyint DEFAULT NULL,
      `toner_amarelo_pct` tinyint DEFAULT NULL,
      `raw_snmp` text COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      PRIMARY KEY (`id`),
      KEY `idx_impressora_data` (`impressora_id`, `coletado_em`),
      CONSTRAINT `fk_snap_impressora` FOREIGN KEY (`impressora_id`) REFERENCES `impressoras` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $sqls['tipos_inventario'] = "CREATE TABLE IF NOT EXISTS `tipos_inventario` (
      `id` int NOT NULL AUTO_INCREMENT,
      `nome` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
      `icone` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'bi-cpu',
      `ativo` tinyint NOT NULL DEFAULT '1',
      PRIMARY KEY (`id`),
      UNIQUE KEY `nome` (`nome`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $sqls['inventario'] = "CREATE TABLE IF NOT EXISTS `inventario` (
      `id` int NOT NULL AUTO_INCREMENT,
      `tipo` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
      `marca` varchar(60) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      `modelo` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      `numero_serie` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      `patrimonio` varchar(60) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      `setor` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      `responsavel_nome` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      `status` enum('Em Uso','Disponível','Em Manutenção','Descartado') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Em Uso',
      `data_aquisicao` date DEFAULT NULL,
      `valor` decimal(10,2) DEFAULT NULL,
      `garantia_ate` date DEFAULT NULL,
      `imei` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      `observacoes` text COLLATE utf8mb4_unicode_ci,
      `criado_em` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
      `atualizado_em` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`),
      KEY `idx_tipo` (`tipo`),
      KEY `idx_status` (`status`),
      KEY `idx_setor` (`setor`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $sqls['contratos'] = "CREATE TABLE IF NOT EXISTS `contratos` (
      `id` int NOT NULL AUTO_INCREMENT,
      `tipo` enum('Contrato','Licença','Garantia','Assinatura','Suporte','Outro') COLLATE utf8mb4_unicode_ci NOT NULL,
      `nome` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
      `fornecedor` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      `numero_contrato` varchar(60) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      `valor` decimal(10,2) DEFAULT NULL,
      `periodicidade` enum('Mensal','Trimestral','Semestral','Anual','Único') COLLATE utf8mb4_unicode_ci DEFAULT 'Anual',
      `data_inicio` date DEFAULT NULL,
      `data_vencimento` date NOT NULL,
      `renovacao_auto` tinyint NOT NULL DEFAULT '0',
      `alerta_dias` int NOT NULL DEFAULT '30',
      `status` enum('Ativo','Vencido','Cancelado','Em Renovação') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Ativo',
      `observacoes` text COLLATE utf8mb4_unicode_ci,
      `arquivo_url` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      `criado_em` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
      `atualizado_em` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`),
      KEY `idx_vencimento` (`data_vencimento`),
      KEY `idx_status` (`status`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $sqls['termos_uso'] = "CREATE TABLE IF NOT EXISTS `termos_uso` (
      `id` int NOT NULL AUTO_INCREMENT,
      `inventario_id` int NOT NULL,
      `responsavel_nome` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
      `responsavel_cpf` varchar(14) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      `responsavel_matricula` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      `setor` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      `data_entrega` date NOT NULL,
      `data_prevista_devolucao` date DEFAULT NULL,
      `data_devolucao` date DEFAULT NULL,
      `condicao_entrega` text COLLATE utf8mb4_unicode_ci,
      `condicao_devolucao` text COLLATE utf8mb4_unicode_ci,
      `status` enum('Ativo','Devolvido') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Ativo',
      `observacoes` text COLLATE utf8mb4_unicode_ci,
      `assinado_em` datetime DEFAULT NULL,
      `criado_em` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`),
      KEY `idx_status` (`status`),
      KEY `idx_inventario` (`inventario_id`),
      CONSTRAINT `termos_uso_ibfk_1` FOREIGN KEY (`inventario_id`) REFERENCES `inventario` (`id`) ON DELETE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    foreach ($sqls as $tabela => $sql) {
        try {
            $pdo->exec($sql);
            ok("Tabela <strong>$tabela</strong> criada / verificada.");
        } catch (Exception $e) {
            err("Erro ao criar <strong>$tabela</strong>: " . $e->getMessage());
        }
    }

    // ── Usuário admin ──────────────────────────────────────
    $nomeAdmin  = trim($_POST['admin_nome']  ?? 'Administrador');
    $emailAdmin = trim($_POST['admin_email'] ?? 'admin@pageup.net.br');
    $senhaAdmin = trim($_POST['admin_senha'] ?? '');

    if (!$senhaAdmin) {
        err("Informe uma senha para o administrador.");
    } else {
        $hash = password_hash($senhaAdmin, PASSWORD_DEFAULT);
        $st = $pdo->prepare("INSERT INTO `usuarios` (nome, email, senha, perfil, ativo) VALUES (?, ?, ?, 'admin', 1)
            ON DUPLICATE KEY UPDATE nome=VALUES(nome), senha=VALUES(senha), perfil='admin', ativo=1");
        try {
            $st->execute([$nomeAdmin, $emailAdmin, $hash]);
            ok("Usuário admin <strong>$emailAdmin</strong> criado/atualizado.");
        } catch (Exception $e) {
            err("Erro ao criar admin: " . $e->getMessage());
        }
    }

    // ── Seed: categorias ──────────────────────────────────
    $pdo->exec("INSERT IGNORE INTO `categorias` (`id`,`nome`,`icone`,`ativo`) VALUES
        (1,'Hardware','bi-cpu',1),(2,'Software','bi-window',1),
        (3,'Rede / Internet','bi-wifi',1),(4,'Acesso / Senha','bi-key',1),
        (5,'Impressora','bi-printer',1),(6,'E-mail','bi-envelope',1),
        (7,'Telefonia','bi-telephone',1),(8,'Outro','bi-three-dots',1)");
    ok("Seed <strong>categorias</strong>: 8 registros.");

    // ── Seed: tipos_suprimentos ───────────────────────────
    $pdo->exec("INSERT IGNORE INTO `tipos_suprimentos` (`id`,`nome`,`ativo`) VALUES
        (1,'Toner Preto',1),(2,'Toner Colorido',1),(3,'Papel A4 (Resma)',1),
        (4,'Bobina Térmica 80mm',1),(5,'Bobina Térmica 57mm',1),
        (6,'Cartucho Inkjet Preto',1),(7,'Cartucho Inkjet Colorido',1),
        (8,'Ribbon (Fita de Impressão)',1),(9,'Etiqueta Adesiva A4',1),
        (10,'Papel Fotográfico',1)");
    ok("Seed <strong>tipos_suprimentos</strong>: 10 registros.");

    // ── Seed: tipos_inventario ────────────────────────────
    $pdo->exec("INSERT IGNORE INTO `tipos_inventario` (`id`,`nome`,`icone`,`ativo`) VALUES
        (1,'Notebook','bi-laptop',1),(2,'Desktop','bi-pc-display',1),
        (3,'Monitor','bi-display',1),(4,'Celular','bi-phone',1),
        (5,'Tablet','bi-tablet',1),(6,'Impressora','bi-printer',1),
        (7,'Switch','bi-diagram-3',1),(8,'Roteador','bi-wifi',1),
        (9,'Nobreak','bi-battery-charging',1),(10,'Servidor','bi-server',1),
        (11,'Telefone IP','bi-telephone',1),(12,'Projetor','bi-projector',1),
        (13,'Outro','bi-box',1)");
    ok("Seed <strong>tipos_inventario</strong>: 13 registros.");

    // ── Seed: setores ─────────────────────────────────────
    $setores_seed = [
        'Administrativo','Comercial','Compras','Contabilidade','Controladoria',
        'Diretoria','Engenharia','Estoque','Faturamento','Financeiro',
        'Jurídico','Logística','Manutenção','Marketing','Operacional',
        'Produção','Qualidade','Recepção','Recursos Humanos','Segurança',
        'Suporte TI','T.I.','Tecnologia','Tesouraria','Treinamento',
    ];
    $st = $pdo->prepare("INSERT IGNORE INTO `setores` (nome) VALUES (?)");
    $seeded = 0;
    foreach ($setores_seed as $s) { $st->execute([$s]); $seeded += $st->rowCount(); }
    ok("Seed <strong>setores</strong>: $seeded novo(s) inserido(s).");

    // ── Seed: contratos (exemplos) ────────────────────────
    $ano = date('Y');
    $pdo->exec("INSERT IGNORE INTO `contratos` (tipo,nome,fornecedor,numero_contrato,valor,periodicidade,data_inicio,data_vencimento,renovacao_auto,alerta_dias,status,observacoes) VALUES
        ('Licença','Microsoft 365 Business Standard','Microsoft','MS-365-001',89.90,'Mensal','$ano-01-01','$ano-12-31',1,30,'Ativo','5 licenças — equipe TI e gestão'),
        ('Licença','Adobe Acrobat Pro','Adobe','ADOBE-001',79.00,'Mensal','$ano-01-01','".($ano+1)."-06-30',0,30,'Ativo','Licença administrativa'),
        ('Suporte','Garantia Estendida Servidores','Dell Technologies','DELL-SUP-001',450.00,'Anual','$ano-01-01','".($ano+2)."-01-01',1,60,'Ativo','Cobre servidores do CPD'),
        ('Assinatura','Antivírus Endpoint Protection','Kaspersky','KAS-001',320.00,'Anual','$ano-01-01','$ano-12-31',0,45,'Ativo','Renovar até dezembro'),
        ('Contrato','Link Dedicado de Internet','Operadora','LINK-001',1290.00,'Mensal','$ano-01-01','".($ano+1)."-12-31',0,45,'Ativo','SLA 99,7%')");
    ok("Seed <strong>contratos</strong>: 5 exemplos inseridos.");

    fim:
    // ── Verifica se instalação foi completa ───────────────
    $_SESSION['setup_done'] = empty($errors);
}

$token = htmlspecialchars($_GET['token'] ?? $_POST['token'] ?? SETUP_TOKEN);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>HelpTI — Instalação</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<style>
body{background:#F1FAEE;font-family:'Segoe UI',system-ui,sans-serif}
.brand{background:#1D3557;color:#fff;padding:1.5rem 2rem;border-radius:12px 12px 0 0}
.brand h1{font-size:22px;font-weight:700;margin:0}
.brand small{font-size:12px;opacity:.7}
.setup-card{background:#fff;border-radius:12px;box-shadow:0 4px 20px rgba(0,0,0,.08);max-width:640px;margin:3rem auto;overflow:hidden}
.setup-body{padding:2rem}
.log-item{font-size:13px;padding:.3rem 0;border-bottom:1px solid #f1f5f9;display:flex;align-items:center;gap:.5rem}
.log-item:last-child{border-bottom:none}
</style>
</head>
<body>
<div class="setup-card">
  <div class="brand">
    <h1><i class="bi bi-pc-display-horizontal me-2"></i>HelpTI</h1>
    <small>PageUp Sistemas — Script de Instalação</small>
  </div>
  <div class="setup-body">

  <?php if (!empty($log) || !empty($errors)): ?>
    <!-- ── Resultado ── -->
    <?php if (empty($errors)): ?>
    <div class="alert alert-success d-flex align-items-center gap-2">
      <i class="bi bi-check-circle-fill fs-5"></i>
      <div><strong>Instalação concluída!</strong> O sistema está pronto para uso.</div>
    </div>
    <?php else: ?>
    <div class="alert alert-danger d-flex align-items-center gap-2">
      <i class="bi bi-exclamation-triangle-fill fs-5"></i>
      <div><strong><?= count($errors) ?> erro(s)</strong> durante a instalação. Verifique abaixo.</div>
    </div>
    <?php endif; ?>

    <div class="mb-3">
      <?php foreach ($log as $l): ?>
        <div class="log-item"><i class="bi bi-check-circle-fill text-success"></i><?= $l ?></div>
      <?php endforeach; ?>
      <?php foreach ($errors as $e): ?>
        <div class="log-item"><i class="bi bi-x-circle-fill text-danger"></i><?= $e ?></div>
      <?php endforeach; ?>
    </div>

    <?php if (empty($errors)): ?>
    <div class="alert alert-warning d-flex align-items-center gap-2 mb-3">
      <i class="bi bi-trash-fill fs-5"></i>
      <div><strong>Apague o arquivo setup.php agora!</strong> Ele não deve ficar acessível em produção.</div>
    </div>
    <a href="login.php" class="btn btn-success w-100 py-2 fw-semibold">
      <i class="bi bi-box-arrow-in-right me-2"></i>Ir para o Login
    </a>
    <?php else: ?>
    <a href="?token=<?= $token ?>" class="btn btn-outline-secondary btn-sm">
      <i class="bi bi-arrow-left me-1"></i>Tentar novamente
    </a>
    <?php endif; ?>

  <?php else: ?>
    <!-- ── Formulário ── -->
    <h5 class="fw-bold mb-1">Configurações detectadas</h5>
    <p class="text-muted mb-3" style="font-size:13px">Lidas automaticamente de <code>config.php</code>:</p>

    <div class="bg-light rounded p-3 mb-4 font-monospace" style="font-size:12px">
      <div><strong>Banco:</strong> <?= DB_NAME ?> @ <?= DB_HOST ?></div>
      <div><strong>Usuário MySQL:</strong> <?= DB_USER ?></div>
      <div><strong>APP_URL:</strong> <?= APP_URL ?></div>
      <div><strong>E-mail:</strong> <?= APP_EMAIL_FROM ?></div>
    </div>

    <hr class="my-3">
    <h5 class="fw-bold mb-3">Criar usuário administrador</h5>

    <form method="post">
      <input type="hidden" name="token" value="<?= $token ?>">
      <input type="hidden" name="instalar" value="1">
      <div class="mb-3">
        <label class="form-label fw-semibold" style="font-size:13px">Nome completo</label>
        <input type="text" name="admin_nome" class="form-control form-control-sm" value="Administrador" required>
      </div>
      <div class="mb-3">
        <label class="form-label fw-semibold" style="font-size:13px">E-mail de acesso</label>
        <input type="email" name="admin_email" class="form-control form-control-sm" value="admin@pageup.net.br" required>
      </div>
      <div class="mb-4">
        <label class="form-label fw-semibold" style="font-size:13px">Senha (mínimo 8 caracteres)</label>
        <input type="password" name="admin_senha" class="form-control form-control-sm" minlength="8" required placeholder="Escolha uma senha segura">
      </div>

      <div class="alert alert-info d-flex gap-2" style="font-size:13px">
        <i class="bi bi-info-circle-fill flex-shrink-0 mt-1"></i>
        <div>Este script irá: criar todas as tabelas, popular categorias, tipos de suprimentos, tipos de equipamento, setores padrão e exemplos de contratos.</div>
      </div>

      <button type="submit" class="btn btn-primary w-100 py-2 fw-semibold">
        <i class="bi bi-play-circle me-2"></i>Instalar HelpTI
      </button>
    </form>
  <?php endif; ?>

  </div>
</div>
</body>
</html>
