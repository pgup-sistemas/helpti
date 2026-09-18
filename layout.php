<?php
function layoutHeader(string $titulo, string $paginaAtiva = ''): void {
    $u = usuario();
    $flash = getFlash();
    $perfil = $u['perfil'] ?? '';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($titulo) ?> — <?= CLINICA_NOME ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="design-tokens.css" rel="stylesheet">
<style>
/* ── Chrome do layout (sidebar, topbar, busca, notif) ── */
/* Tokens e componentes globais → design-tokens.css        */

:root{ --sidebar-w:230px; --danger:#E63946; }

body{background:var(--bg-page);font-family:'Segoe UI',system-ui,sans-serif;font-size:14px;color:var(--tx-primary)}
.bg-primary{background-color:var(--brand)!important}
.input-group-text{background:var(--bg-surface-alt);color:var(--tx-secondary);border-color:var(--border)}
.row-border-bottom{border-bottom:1px solid var(--border)}
.ev-texto{font-size:12.5px;color:var(--tx-primary);word-break:break-word}
.preview-bar{background:var(--bg-surface-alt);padding:.45rem .85rem;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center}
:root{--venc-danger-bg:var(--sem-danger-bg);--venc-danger-tx:var(--sem-danger-tx);--venc-warn-bg:var(--sem-warn-bg);--venc-warn-tx:var(--sem-warn-tx)}
[data-theme="dark"] mark{background:#422006;color:#fde68a}
[data-theme="dark"] .badge.bg-light{background:#263347!important;color:#cbd5e1!important}
[data-theme="dark"] .badge.text-dark{color:#cbd5e1!important}
[data-theme="dark"] .badge.bg-secondary{background:#334155!important}
[data-theme="dark"] .dropdown-menu{background:var(--bg-surface);border-color:var(--border)}
[data-theme="dark"] .dropdown-item{color:var(--tx-primary)}
[data-theme="dark"] .dropdown-item:hover{background:var(--bg-hover)}
[data-theme="dark"] .dropdown-divider{border-color:var(--border)}
[data-theme="dark"] select option{background:var(--bg-surface);color:var(--tx-primary)}
[data-theme="dark"] .text-dark{color:var(--tx-primary)!important}
[data-theme="dark"] .bg-light{background:var(--bg-surface-alt)!important}
[data-theme="dark"] .border-info{border-color:#0369a1!important}
[data-theme="dark"] .btn-outline-info{color:#7dd3fc;border-color:#0369a1}
[data-theme="dark"] .btn-outline-info:hover{background:#0369a1;color:#fff}
[data-theme="dark"] .alert-warning{background:var(--sem-warn-bg);color:var(--sem-warn-tx);border-color:#78350f}
[data-theme="dark"] .alert-danger{background:var(--sem-danger-bg);color:var(--sem-danger-tx);border-color:#7f1d1d}
[data-theme="dark"] .alert-info{background:var(--sem-info-bg);color:var(--sem-info-tx);border-color:#1e3a5f}
[data-theme="dark"] .alert-success{background:var(--sem-success-bg);color:var(--sem-success-tx);border-color:#14532d}
[data-theme="dark"] .alert-warning a,[data-theme="dark"] .alert-warning .fw-semibold{color:#fbbf24}
[data-theme="dark"] .alert-danger  a,[data-theme="dark"] .alert-danger  .fw-semibold{color:#f87171}
[data-theme="dark"] .alert-warning .btn-close,[data-theme="dark"] .alert-danger .btn-close{filter:invert(1)}
[data-theme="dark"] .nav-link:hover,[data-theme="dark"] .nav-link.active{background:rgba(69,123,157,.22);color:#93c5fd}
[data-theme="dark"] .nav-link{color:var(--tx-secondary)}
[data-theme="dark"] .sidebar-brand{color:var(--tx-primary)}
[data-theme="dark"] .brand-content i{color:var(--brand)}
[data-theme="dark"] .topbar-user-name{color:var(--tx-primary)!important}
[data-theme="dark"] .topbar-user-sep{border-color:var(--border)!important}
[data-theme="dark"] .badge-pending{background:var(--sem-warn-bg);color:var(--sem-warn-tx)}
[data-theme="dark"] .badge-approved{background:var(--sem-info-bg);color:var(--sem-info-tx)}

/* ── Sidebar ── */
.sidebar{position:fixed;top:0;left:0;width:var(--sidebar-w);height:100vh;background:var(--bg-surface);border-right:1px solid var(--border);display:flex;flex-direction:column;z-index:100;transition:width .3s ease,transform .3s ease;overflow-x:hidden}
.sidebar-brand{padding:1.1rem 1.25rem;font-weight:700;font-size:15px;color:var(--tx-primary);border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:center;height:60px;transition:padding .2s ease}
.brand-content{display:flex;align-items:center;gap:8px;white-space:nowrap;overflow:hidden}
.brand-content i{color:var(--brand);font-size:20px}
.brand-content span{display:inline-block;overflow:hidden;white-space:nowrap;opacity:1;max-width:200px;transition:opacity .15s ease,max-width .2s ease}
.sidebar-nav{flex:1;padding:.5rem 0;overflow-y:auto;overflow-x:hidden;scrollbar-width:thin;scrollbar-color:var(--border) transparent}
.sidebar-nav::-webkit-scrollbar{width:3px}
.sidebar-nav::-webkit-scrollbar-track{background:transparent}
.sidebar-nav::-webkit-scrollbar-thumb{background:var(--border);border-radius:3px}
.nav-link{display:flex;align-items:center;gap:10px;padding:.55rem 1.25rem;color:var(--tx-secondary);font-size:13.5px;border-radius:0;transition:.15s;white-space:nowrap}
.nav-link:hover,.nav-link.active{background:var(--brand-light);color:var(--brand);font-weight:600}
.nav-link i{font-size:16px;width:18px;text-align:center;transition:font-size .2s ease}
.nav-link span{display:inline-block;overflow:hidden;white-space:nowrap;opacity:1;max-width:200px;transition:opacity .15s ease,max-width .2s ease}
.nav-section{background:none;border:none;width:100%;padding:.5rem 1.25rem .2rem;font-size:11px;font-weight:600;color:var(--tx-nav);text-transform:uppercase;letter-spacing:.06em;white-space:nowrap;overflow:hidden;display:flex;align-items:center;justify-content:space-between;cursor:pointer;user-select:none;transition:.15s;text-align:left}
.nav-section:hover{color:var(--tx-secondary)}
.nav-section .nav-sec-arrow{font-size:10px;transition:transform .2s;flex-shrink:0;opacity:.6}
.nav-section[aria-expanded="false"] .nav-sec-arrow{transform:rotate(-90deg)}
body.sidebar-collapsed .nav-section .nav-sec-arrow{display:none}
.sidebar-footer{padding:.35rem .75rem .4rem;border-top:1px solid var(--border);font-size:11px;color:var(--tx-muted);white-space:nowrap;overflow:hidden;display:flex;flex-direction:column;gap:2px}
.footer-vendor{font-size:10px;color:var(--tx-faint);opacity:.8}
.sidebar-toggle-btn{background:var(--bg-surface-alt);border:1px solid var(--border);color:var(--tx-secondary);border-radius:var(--radius-sm);padding:.3rem .6rem;font-size:12.5px;cursor:pointer;transition:.15s;gap:5px}
.sidebar-toggle-btn:hover{background:var(--bg-hover);color:var(--tx-primary)}

/* ── Collapsed sidebar ── */
body.sidebar-collapsed .sidebar{width:70px}
body.sidebar-collapsed .main-wrap{margin-left:70px}
body.sidebar-collapsed .sidebar-brand{padding:1.1rem 0}
body.sidebar-collapsed .brand-content span{opacity:0;max-width:0}
body.sidebar-collapsed .nav-section{display:none}
body.sidebar-collapsed .nav-link span{opacity:0;max-width:0}
body.sidebar-collapsed .nav-link{justify-content:center;padding:.8rem 0}
body.sidebar-collapsed .nav-link i{font-size:20px;width:auto;margin:0}
body.sidebar-collapsed .footer-vendor{display:none}
body.sidebar-collapsed #sidebarToggleBtn span{opacity:0;max-width:0}
#toggleIcon{transition:transform .3s ease}
body.sidebar-collapsed #toggleIcon{transform:rotate(180deg)}

/* ── Topbar ── */
.topbar{position:fixed;top:0;left:var(--sidebar-w);right:0;height:60px;background:var(--bg-surface);border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;padding:0 1.5rem;z-index:99;transition:left .3s ease;gap:8px}
.topbar-right{display:flex;align-items:center;gap:8px}
body.sidebar-collapsed .topbar{left:70px}
.main-wrap{margin-left:var(--sidebar-w);min-height:100vh;padding:1.5rem;padding-top:calc(1.5rem + 60px);transition:margin-left .3s ease}
.theme-btn{background:none;border:none;cursor:pointer;padding:6px;border-radius:var(--radius-md);color:var(--tx-secondary);font-size:18px;transition:.15s;line-height:1}
.theme-btn:hover{background:var(--bg-hover);color:var(--brand)}

/* ── Busca global ── */
.busca-wrap{position:relative;flex:1;max-width:380px;min-width:0}
.busca-input{width:100%;height:var(--input-h);border:1px solid var(--border);border-radius:var(--radius-md);padding:0 12px 0 34px;font-size:13px;outline:none;background:var(--busca-bg);color:var(--tx-primary);transition:.15s}
.busca-input::placeholder{color:var(--tx-faint)}
.busca-input:focus{border-color:var(--brand);background:var(--bg-surface);box-shadow:0 0 0 3px rgba(29,53,87,.08)}
.busca-icon{position:absolute;left:11px;top:50%;transform:translateY(-50%);color:var(--tx-faint);font-size:14px;pointer-events:none}
.busca-dropdown{position:absolute;top:calc(100% + 4px);left:0;right:0;background:var(--bg-surface);border:1px solid var(--border);border-radius:var(--radius-lg);box-shadow:var(--shadow-lg);z-index:300;display:none;max-height:420px;overflow-y:auto}
.busca-dropdown.aberto{display:block}
.busca-grupo{padding:.4rem .75rem .2rem;font-size:10px;font-weight:700;color:var(--tx-faint);text-transform:uppercase;letter-spacing:.06em}
.busca-item{display:flex;align-items:center;gap:10px;padding:.55rem .75rem;cursor:pointer;text-decoration:none;color:inherit;transition:.1s}
.busca-item:hover,.busca-item.ativo{background:var(--bg-hover)}
.busca-ico{width:28px;height:28px;border-radius:7px;display:flex;align-items:center;justify-content:center;font-size:13px;flex-shrink:0;color:#fff}
.busca-titulo{font-size:13px;font-weight:600;color:var(--tx-primary);line-height:1.2}
.busca-sub{font-size:11px;color:var(--tx-muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.busca-vazio{padding:.9rem .75rem;font-size:13px;color:var(--tx-faint);text-align:center}

/* ── Notificações ── */
.notif-btn{position:relative;background:none;border:none;cursor:pointer;padding:6px;border-radius:var(--radius-md);color:var(--tx-secondary);transition:.15s}
.notif-btn:hover{background:var(--bg-hover);color:var(--brand)}
.notif-btn .badge-dot{position:absolute;top:4px;right:4px;min-width:17px;height:17px;border-radius:10px;background:#ef4444;color:#fff;font-size:10px;font-weight:700;display:flex;align-items:center;justify-content:center;padding:0 3px;border:2px solid var(--bg-surface);opacity:0;transition:.2s}
.notif-btn .badge-dot.show{opacity:1}
.notif-panel{position:absolute;top:calc(100% + 8px);right:0;width:380px;background:var(--bg-surface);border-radius:var(--radius-lg);box-shadow:var(--shadow-lg);border:1px solid var(--border);z-index:200;display:none;max-height:480px;overflow:hidden;flex-direction:column}
.notif-panel.aberto{display:flex}
.notif-panel-header{padding:.75rem 1rem;border-bottom:1px solid var(--border-light);font-weight:700;font-size:13px;color:var(--tx-primary);display:flex;justify-content:space-between;align-items:center;flex-shrink:0}
.notif-list{overflow-y:auto;flex:1}
.notif-item{display:flex;align-items:flex-start;gap:10px;padding:.7rem 1rem;border-bottom:1px solid var(--border-light);text-decoration:none;color:inherit;transition:.12s}
.notif-item:hover{background:var(--bg-hover)}
.notif-item:last-child{border-bottom:none}
.notif-ico{width:34px;height:34px;border-radius:var(--radius-md);display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:16px}
.notif-texto{font-size:12.5px;color:var(--tx-primary);line-height:1.4;word-break:break-word}
.notif-acao{font-size:11px;font-weight:600;white-space:nowrap;align-self:center;flex-shrink:0;margin-left:4px}
.notif-empty{padding:2rem 1rem;text-align:center;color:var(--tx-faint);font-size:13px}

/* ── Tabela Bootstrap (aliases para .ht-table não precisar reescrever cada template) ── */
.table th{font-size:var(--text-xs);font-weight:700;color:var(--table-th-tx);text-transform:uppercase;letter-spacing:.05em;background:var(--table-th-bg);border-bottom:2px solid var(--border)}
.table td{vertical-align:middle;font-size:var(--text-base);color:var(--tx-primary)}
.table-hover tbody tr:hover td{background:var(--bg-hover)}
.table{--bs-table-bg:var(--bg-surface);--bs-table-striped-bg:var(--bg-surface-alt);color:var(--tx-primary)}
.table-responsive:has(> .table-sortable){overflow:visible}
.table-sortable thead th{position:sticky;top:60px;z-index:5;background:var(--table-th-bg)}
@media(max-width:992px){
  .table-responsive:has(> .table-sortable){overflow-x:auto}
  .table-sortable thead th{position:static}
}
[data-href].row-link{cursor:pointer}

/* ── Responsivo (mobile) ── */
@media(max-width:768px){
  .sidebar{transform:translateX(-100%)}
  .main-wrap{margin-left:0}
  .topbar{left:0}
  body.sidebar-collapsed .topbar{left:0}
  body.sidebar-collapsed .sidebar{transform:translateX(0);width:var(--sidebar-w)}
  body.sidebar-collapsed .brand-content span{opacity:1;max-width:200px}
  body.sidebar-collapsed .nav-section,
  body.sidebar-collapsed .footer-vendor{display:block}
  body.sidebar-collapsed .nav-link span{opacity:1;max-width:200px}
  body.sidebar-collapsed #sidebarToggleBtn span{opacity:1;max-width:120px}
  body.sidebar-collapsed .nav-link{justify-content:flex-start;padding:.55rem 1.25rem}
  body.sidebar-collapsed .nav-link i{font-size:16px;width:18px}
  body.sidebar-collapsed #toggleIcon{transform:rotate(0deg)}
}
</style>
<script>
if(localStorage.getItem('theme') === 'dark')
    document.documentElement.setAttribute('data-theme','dark');
</script>
</head>
<body class="">
<script>
// Aplica o estado do menu recolhido antes da primeira pintura da página,
// evitando o "flash" de menu expandido (o body ainda não existia no <head>).
if(localStorage.getItem('sidebar-collapsed') === 'true')
    document.body.classList.add('sidebar-collapsed');
</script>

<aside class="sidebar">
  <div class="sidebar-brand">
    <div class="brand-content"><i class="bi bi-pc-display-horizontal"></i> <span><?= APP_NOME ?></span></div>
  </div>
  <nav class="sidebar-nav">

    <?php
    // Determina seções ativas para manter expandidas
    $secAtiva = match(true) {
      in_array($paginaAtiva, ['dashboard'])                                   => 'visao',
      in_array($paginaAtiva, ['chamados','novo'])                             => 'atend',
      in_array($paginaAtiva, ['inventario','termos','impressoras','manutencoes','contratos','auditoria','ciclos_auditoria']) => 'parque',
      in_array($paginaAtiva, ['suprimentos'])                                 => 'suprim',
      in_array($paginaAtiva, ['relatorios'])                                  => 'relat',
      in_array($paginaAtiva, ['tipos_inventario','tipos_suprimentos','categorias']) => 'config',
      in_array($paginaAtiva, ['usuarios','setores','unidades'])               => 'admin',
      default => ''
    };
    // Helpers para o componente collapse padrão do Bootstrap
    $navOpen = fn(string $sec): bool => $secAtiva === $sec;
    $navShow = fn(string $sec): string => $navOpen($sec) ? 'show' : '';
    $navExp  = fn(string $sec): string => $navOpen($sec) ? 'true' : 'false';
    ?>

    <button type="button" class="nav-section" data-bs-toggle="collapse" data-bs-target="#sec-visao" aria-expanded="<?= $navExp('visao') ?>" aria-controls="sec-visao">
      <span>Visão Geral</span><i class="bi bi-chevron-down nav-sec-arrow"></i>
    </button>
    <div class="collapse <?= $navShow('visao') ?> nav-group" id="sec-visao">
      <a href="dashboard.php" class="nav-link <?= $paginaAtiva==='dashboard'?'active':'' ?>" title="Dashboard">
        <i class="bi bi-grid-1x2-fill"></i> <span>Dashboard</span>
      </a>
    </div>

    <button type="button" class="nav-section" data-bs-toggle="collapse" data-bs-target="#sec-atend" aria-expanded="<?= $navExp('atend') ?>" aria-controls="sec-atend">
      <span>Atendimento</span><i class="bi bi-chevron-down nav-sec-arrow"></i>
    </button>
    <div class="collapse <?= $navShow('atend') ?> nav-group" id="sec-atend">
      <a href="chamados.php" class="nav-link <?= $paginaAtiva==='chamados'?'active':'' ?>" title="Chamados">
        <i class="bi bi-ticket-detailed-fill"></i> <span>Chamados</span>
      </a>
      <a href="novo_chamado.php" class="nav-link <?= $paginaAtiva==='novo'?'active':'' ?>" title="Novo Chamado">
        <i class="bi bi-plus-circle-fill"></i> <span>Novo Chamado</span>
      </a>
    </div>

    <button type="button" class="nav-section" data-bs-toggle="collapse" data-bs-target="#sec-parque" aria-expanded="<?= $navExp('parque') ?>" aria-controls="sec-parque">
      <span>Parque de TI</span><i class="bi bi-chevron-down nav-sec-arrow"></i>
    </button>
    <div class="collapse <?= $navShow('parque') ?> nav-group" id="sec-parque">
      <a href="inventario.php" class="nav-link <?= $paginaAtiva==='inventario'?'active':'' ?>" title="Inventário">
        <i class="bi bi-pc-display"></i> <span>Inventário</span>
      </a>
      <a href="hosts_rede.php" class="nav-link <?= $paginaAtiva==='hosts_rede'?'active':'' ?>" title="Hosts de Rede">
        <i class="bi bi-diagram-3-fill"></i> <span>Hosts de Rede</span>
      </a>
      <a href="topologia.php" class="nav-link <?= $paginaAtiva==='topologia'?'active':'' ?>" title="Topologia">
        <i class="bi bi-share-fill"></i> <span>Topologia</span>
      </a>
      <a href="termos.php" class="nav-link <?= $paginaAtiva==='termos'?'active':'' ?>" title="Termos de Guarda / Uso">
        <i class="bi bi-file-earmark-person-fill"></i> <span>Termos de Uso</span>
      </a>
      <a href="impressoras.php" class="nav-link <?= $paginaAtiva==='impressoras'?'active':'' ?>" title="Impressoras">
        <i class="bi bi-printer-fill"></i> <span>Impressoras</span>
      </a>
      <a href="relatorio_impressoras.php" class="nav-link <?= $paginaAtiva==='relatorio_impressoras'?'active':'' ?>" title="Relatório de Páginas">
        <i class="bi bi-graph-up"></i> <span>Rel. de Páginas</span>
      </a>
      <a href="manutencoes.php" class="nav-link <?= $paginaAtiva==='manutencoes'?'active':'' ?>" title="Manutenções">
        <i class="bi bi-wrench-adjustable"></i> <span>Manutenções</span>
      </a>
      <a href="contratos.php" class="nav-link <?= $paginaAtiva==='contratos'?'active':'' ?>" title="Contratos & Licenças">
        <i class="bi bi-file-earmark-check-fill"></i> <span>Contratos & Licenças</span>
      </a>
      <a href="auditoria.php" class="nav-link <?= $paginaAtiva==='auditoria'?'active':'' ?>" title="Auditoria de Campo">
        <i class="bi bi-clipboard2-check-fill"></i> <span>Auditoria de Campo</span>
      </a>
      <?php if ($perfil !== 'tecnico'): ?>
      <a href="ciclos_auditoria.php" class="nav-link <?= $paginaAtiva==='ciclos_auditoria'?'active':'' ?>" title="Ciclos de Auditoria">
        <i class="bi bi-list-check"></i> <span>Ciclos de Auditoria</span>
      </a>
      <?php endif; ?>
    </div>

    <button type="button" class="nav-section" data-bs-toggle="collapse" data-bs-target="#sec-suprim" aria-expanded="<?= $navExp('suprim') ?>" aria-controls="sec-suprim">
      <span>Suprimentos</span><i class="bi bi-chevron-down nav-sec-arrow"></i>
    </button>
    <div class="collapse <?= $navShow('suprim') ?> nav-group" id="sec-suprim">
      <a href="pedidos_suprimentos.php" class="nav-link <?= $paginaAtiva==='suprimentos'?'active':'' ?>" title="Pedidos">
        <i class="bi bi-box-seam-fill"></i> <span>Pedidos</span>
      </a>
    </div>

    <?php if ($perfil !== 'tecnico'): ?>
    <button type="button" class="nav-section" data-bs-toggle="collapse" data-bs-target="#sec-relat" aria-expanded="<?= $navExp('relat') ?>" aria-controls="sec-relat">
      <span>Relatórios</span><i class="bi bi-chevron-down nav-sec-arrow"></i>
    </button>
    <div class="collapse <?= $navShow('relat') ?> nav-group" id="sec-relat">
      <a href="relatorios.php" class="nav-link <?= $paginaAtiva==='relatorios'?'active':'' ?>" title="Relatórios">
        <i class="bi bi-bar-chart-fill"></i> <span>Relatórios</span>
      </a>
    </div>
    <?php endif; ?>

    <?php if ($perfil !== 'tecnico'): ?>
    <button type="button" class="nav-section" data-bs-toggle="collapse" data-bs-target="#sec-config" aria-expanded="<?= $navExp('config') ?>" aria-controls="sec-config">
      <span>Configurações</span><i class="bi bi-chevron-down nav-sec-arrow"></i>
    </button>
    <div class="collapse <?= $navShow('config') ?> nav-group" id="sec-config">
      <a href="tipos_inventario.php" class="nav-link <?= $paginaAtiva==='tipos_inventario'?'active':'' ?>" title="Tipos de Equipamento">
        <i class="bi bi-cpu-fill"></i> <span>Tipos de Equipamento</span>
      </a>
      <a href="tipos_suprimentos.php" class="nav-link <?= $paginaAtiva==='tipos_suprimentos'?'active':'' ?>" title="Tipos de Suprimentos">
        <i class="bi bi-box-fill"></i> <span>Tipos de Suprimentos</span>
      </a>
      <a href="categorias.php" class="nav-link <?= $paginaAtiva==='categorias'?'active':'' ?>" title="Categorias de Chamado">
        <i class="bi bi-tags-fill"></i> <span>Categorias</span>
      </a>
    </div>
    <?php endif; ?>

    <?php if ($perfil === 'admin'): ?>
    <button type="button" class="nav-section" data-bs-toggle="collapse" data-bs-target="#sec-admin" aria-expanded="<?= $navExp('admin') ?>" aria-controls="sec-admin">
      <span>Administração</span><i class="bi bi-chevron-down nav-sec-arrow"></i>
    </button>
    <div class="collapse <?= $navShow('admin') ?> nav-group" id="sec-admin">
      <a href="usuarios.php" class="nav-link <?= $paginaAtiva==='usuarios'?'active':'' ?>" title="Usuários">
        <i class="bi bi-people-fill"></i> <span>Usuários</span>
      </a>
      <a href="setores.php" class="nav-link <?= $paginaAtiva==='setores'?'active':'' ?>" title="Setores">
        <i class="bi bi-building-fill"></i> <span>Setores</span>
      </a>
      <a href="unidades.php" class="nav-link <?= $paginaAtiva==='unidades'?'active':'' ?>" title="Unidades / Filiais">
        <i class="bi bi-geo-alt-fill"></i> <span>Unidades / Filiais</span>
      </a>
      <a href="ferramentas.php" class="nav-link <?= $paginaAtiva==='ferramentas'?'active':'' ?>" title="Ferramentas TI">
        <i class="bi bi-tools"></i> <span>Ferramentas TI</span>
      </a>
    </div>
    <?php endif; ?>

    <div style="margin-top:auto">
      <div class="nav-section" style="cursor:default;pointer-events:none">
        <span>Acesso</span>
      </div>
      <a href="portal.php" class="nav-link" target="_blank" title="Portal do Colaborador">
        <i class="bi bi-box-arrow-up-right"></i> <span>Portal do Colaborador</span>
      </a>
      <a href="ajuda_sistema.php" class="nav-link <?= $paginaAtiva==='ajuda'?'active':'' ?>" title="Ajuda">
        <i class="bi bi-question-circle"></i> <span>Ajuda</span>
      </a>
      <a href="logout.php" class="nav-link" style="color:#f87171" title="Sair">
        <i class="bi bi-box-arrow-right"></i> <span>Sair</span>
      </a>
    </div>

  </nav>
  <div class="sidebar-footer">
    <button type="button" class="sidebar-toggle-btn w-100 d-flex justify-content-center align-items-center" id="sidebarToggleBtn" title="Recolher / Expandir Menu" style="padding:.3rem 0">
      <i class="bi bi-chevron-bar-left" id="toggleIcon"></i> <span>Recolher</span>
    </button>
    <div class="footer-vendor px-1 text-center">by <?= APP_VENDOR ?></div>
  </div>
</aside>

<!-- Topbar -->
<div class="topbar">
  <!-- Busca global -->
  <div class="busca-wrap" id="buscaWrap">
    <i class="bi bi-search busca-icon"></i>
    <input type="text" class="busca-input" id="buscaInput" placeholder="Buscar chamados, equipamentos, contratos…" autocomplete="off">
    <div class="busca-dropdown" id="buscaDropdown"></div>
  </div>
  <div class="topbar-right">
  <button class="theme-btn" id="themeBtn" title="Alternar modo escuro/claro" onclick="toggleTheme()">
    <i class="bi bi-moon-fill" id="themeIcon"></i>
  </button>
  <div style="position:relative">
    <button class="notif-btn" id="notifBtn" title="Notificações" onclick="toggleNotif(event)">
      <i class="bi bi-bell-fill" style="font-size:20px"></i>
      <span class="badge-dot" id="notifBadge"></span>
    </button>
    <div class="notif-panel" id="notifPanel">
      <div class="notif-panel-header">
        <span><i class="bi bi-bell me-2 text-primary"></i>Notificações</span>
        <span id="notifTotal" class="badge bg-danger" style="font-size:11px"></span>
      </div>
      <div class="notif-list" id="notifList">
        <div class="notif-empty"><i class="bi bi-check-circle" style="font-size:28px;display:block;margin-bottom:8px;opacity:.3"></i>Tudo em ordem!</div>
      </div>
    </div>
  </div>
  <div class="topbar-user-sep" style="font-size:12px;color:var(--tx-faint);padding-left:8px;border-left:1px solid var(--border);margin-left:4px">
    <span class="fw-semibold topbar-user-name" style="color:var(--tx-primary)"><?= h($u['nome']) ?></span>
    <span class="ms-1 text-capitalize">(<?= h($u['perfil']) ?>)</span>
  </div>
  </div><!-- /topbar-right -->
</div>

<main class="main-wrap">
<?php if ($flash): ?>
<div class="toast-container-fixed" id="toastContainer">
<?php
  $tipo = $flash['tipo'];
  $isSuccess = ($tipo === 'success');
  $bg  = $isSuccess ? 'bg-success' : 'bg-danger';
  $ico = $isSuccess ? 'bi-check-circle-fill' : 'bi-exclamation-triangle-fill';
?>
  <div class="toast align-items-center text-white <?= $bg ?> border-0" role="alert" id="flashToast">
    <div class="toast-body">
      <i class="bi <?= $ico ?> toast-ico"></i>
      <span style="flex:1"><?= h($flash['msg']) ?></span>
      <button type="button" class="btn-close btn-close-white ms-2" data-bs-dismiss="toast"></button>
    </div>
  </div>
</div>
<?php endif; ?>
<?php
}

/**
 * Renders a Bootstrap breadcrumb.
 * $items = [['label'=>'Chamados','href'=>'chamados.php'], ['label'=>'Editar']]
 * The last item is always rendered as the active (current) page.
 */
function breadcrumb(array $items): void {
    echo '<nav aria-label="breadcrumb" class="mb-3"><ol class="breadcrumb">';
    $last = array_key_last($items);
    foreach ($items as $i => $item) {
        if ($i === $last) {
            echo '<li class="breadcrumb-item active" aria-current="page">' . h($item['label']) . '</li>';
        } else {
            echo '<li class="breadcrumb-item"><a href="' . h($item['href']) . '">' . h($item['label']) . '</a></li>';
        }
    }
    echo '</ol></nav>';
}

/**
 * Botão pequeno para copiar um valor (IP, número de série etc.) para a área de transferência.
 * Uso: <?= copyBtn($imp['ip']) ?>
 */
function copyBtn(?string $texto): string {
    if (!$texto) return '';
    return ' <button type="button" class="btn-copy" title="Copiar" onclick="copiarTexto(this, ' . htmlspecialchars(json_encode($texto), ENT_QUOTES) . ')"><i class="bi bi-clipboard"></i></button>';
}

function layoutFooter(): void {
?>
</main>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>if(document.querySelector('[data-cep],[data-cidade-uf]')){var _s=document.createElement('script');_s.src='cep.js';document.head.appendChild(_s);}</script>
<script>
  // ── Flash Toast ───────────────────────────────────────────
  (function(){
    const el = document.getElementById('flashToast');
    if(!el) return;
    const t = new bootstrap.Toast(el, {delay: 4500, autohide: true});
    t.show();
    el.addEventListener('hidden.bs.toast', () => {
      const c = el.closest('.toast-container-fixed');
      if(c) c.remove();
    });
  })();
</script>
<script>
  // ── Recolher/expandir menu lateral (ícone gira via CSS) ───
  document.getElementById('sidebarToggleBtn')?.addEventListener('click', function() {
    document.body.classList.toggle('sidebar-collapsed');
    localStorage.setItem('sidebar-collapsed', document.body.classList.contains('sidebar-collapsed'));
  });

  // ── Seções recolhíveis — componente collapse padrão do Bootstrap ──
  // O HTML já vem com o estado inicial correto (server-side, via $secAtiva).
  // Aqui só restauramos preferências salvas do usuário para seções não-ativas
  // e escutamos os eventos nativos do Bootstrap para persistir mudanças futuras.
  (function() {
    const state = JSON.parse(localStorage.getItem('nav-sec') || '{}');
    document.querySelectorAll('.nav-group').forEach(group => {
      const sec     = group.id.replace('sec-', '');
      const trigger = document.querySelector('[data-bs-target="#' + group.id + '"]');
      if (!trigger) return;
      const isActive = group.querySelector('.nav-link.active') !== null;

      if (state[sec] === true && !isActive) {
        group.classList.remove('show');
        trigger.setAttribute('aria-expanded', 'false');
      }

      group.addEventListener('hidden.bs.collapse', () => {
        const st = JSON.parse(localStorage.getItem('nav-sec') || '{}');
        st[sec] = true;
        localStorage.setItem('nav-sec', JSON.stringify(st));
      });
      group.addEventListener('shown.bs.collapse', () => {
        const st = JSON.parse(localStorage.getItem('nav-sec') || '{}');
        st[sec] = false;
        localStorage.setItem('nav-sec', JSON.stringify(st));
      });
    });
  })();

  // ── Modo escuro ───────────────────────────────────────────
  function toggleTheme() {
    const dark = document.documentElement.getAttribute('data-theme') === 'dark';
    const next = dark ? 'light' : 'dark';
    document.documentElement.setAttribute('data-theme', next);
    localStorage.setItem('theme', next);
    atualizarIconeTema();
  }
  function atualizarIconeTema() {
    const dark = document.documentElement.getAttribute('data-theme') === 'dark';
    const ic = document.getElementById('themeIcon');
    if (!ic) return;
    ic.className = dark ? 'bi bi-sun-fill' : 'bi bi-moon-fill';
    document.getElementById('themeBtn').title = dark ? 'Modo claro' : 'Modo escuro';
  }
  atualizarIconeTema();

  // ── Notificações ──────────────────────────────────────────

  // Escape para interpolação segura em innerHTML (P0-3)
  function esc(s) {
    return String(s ?? '').replace(/[&<>"']/g, c => ({
      '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
    }[c]));
  }
  // Cor só pode ser um hex — senão devolve cinza neutro
  function corSegura(c) {
    return /^#[0-9a-fA-F]{3,8}$/.test(String(c || '')) ? c : '#6b7280';
  }
  // Link interno: só caminhos relativos do próprio app
  function linkSeguro(l) {
    l = String(l || '');
    return /^[a-zA-Z0-9_\-]+\.php(\?[^"'<>\s]*)?$/.test(l) ? l : '#';
  }

  function carregarNotificacoes() {
    fetch('notificacoes.php')
      .then(r => r.json())
      .then(d => {
        const badge = document.getElementById('notifBadge');
        const total = document.getElementById('notifTotal');
        const list  = document.getElementById('notifList');
        if (!badge) return;

        badge.textContent = d.total > 9 ? '9+' : d.total;
        badge.classList.toggle('show', d.total > 0);
        total.textContent = d.total > 0 ? d.total : '';
        total.style.display = d.total > 0 ? '' : 'none';

        if (!d.itens.length) {
          list.innerHTML = '<div class="notif-empty"><i class="bi bi-check-circle" style="font-size:28px;display:block;margin-bottom:8px;opacity:.3"></i>Tudo em ordem!</div>';
          return;
        }
        list.innerHTML = d.itens.map(n => {
          const cor = corSegura(n.cor);
          const ico = /^bi-[a-z0-9-]+$/.test(String(n.icon || '')) ? n.icon : 'bi-bell';
          return `
          <a href="${esc(linkSeguro(n.link))}" class="notif-item">
            <div class="notif-ico" style="background:${cor}20;color:${cor}">
              <i class="bi ${esc(ico)}"></i>
            </div>
            <div style="flex:1;min-width:0">
              <div class="notif-texto">${esc(n.texto)}</div>
            </div>
            <span class="notif-acao" style="color:${cor}">${esc(n.label)} →</span>
          </a>`;
        }).join('');
      })
      .catch(() => {});
  }

  function toggleNotif(e) {
    e.stopPropagation();
    const panel = document.getElementById('notifPanel');
    panel.classList.toggle('aberto');
    if (panel.classList.contains('aberto')) carregarNotificacoes();
  }

  document.addEventListener('click', () => {
    document.getElementById('notifPanel')?.classList.remove('aberto');
  });

  carregarNotificacoes();
  setInterval(carregarNotificacoes, 60000);

  // ── Busca global ──────────────────────────────────────────
  (function() {
    const input    = document.getElementById('buscaInput');
    const dropdown = document.getElementById('buscaDropdown');
    let timer, grupoAtual = null, ativo = -1, todosItems = [];

    function fechar() { dropdown.classList.remove('aberto'); ativo = -1; }

    function render(itens) {
      if (!itens.length) {
        dropdown.innerHTML = '<div class="busca-vazio"><i class="bi bi-search me-1"></i>Nenhum resultado encontrado.</div>';
        dropdown.classList.add('aberto'); return;
      }
      let html = ''; grupoAtual = null;
      itens.forEach((it, i) => {
        if (it.grupo !== grupoAtual) {
          html += `<div class="busca-grupo">${esc(it.grupo)}</div>`;
          grupoAtual = it.grupo;
        }
        const ico = /^bi-[a-z0-9-]+$/.test(String(it.icone || '')) ? it.icone : 'bi-dot';
        html += `<a class="busca-item" href="${esc(linkSeguro(it.link))}" data-idx="${i}">
          <div class="busca-ico" style="background:${corSegura(it.cor)}"><i class="bi ${esc(ico)}"></i></div>
          <div style="min-width:0">
            <div class="busca-titulo">${esc(it.titulo)}</div>
            <div class="busca-sub">${esc(it.sub)}</div>
          </div></a>`;
      });
      dropdown.innerHTML = html;
      dropdown.classList.add('aberto');
      todosItems = dropdown.querySelectorAll('.busca-item');
    }

    function marcarAtivo(n) {
      todosItems.forEach(el => el.classList.remove('ativo'));
      if (n >= 0 && n < todosItems.length) { todosItems[n].classList.add('ativo'); ativo = n; }
    }

    input.addEventListener('input', function() {
      clearTimeout(timer);
      const q = this.value.trim();
      if (q.length < 2) { fechar(); return; }
      timer = setTimeout(() => {
        fetch('busca_global.php?q=' + encodeURIComponent(q))
          .then(r => r.json()).then(render).catch(() => fechar());
      }, 220);
    });

    input.addEventListener('keydown', function(e) {
      if (!dropdown.classList.contains('aberto')) return;
      if (e.key === 'ArrowDown')  { e.preventDefault(); marcarAtivo(Math.min(ativo+1, todosItems.length-1)); }
      if (e.key === 'ArrowUp')    { e.preventDefault(); marcarAtivo(Math.max(ativo-1, 0)); }
      if (e.key === 'Enter' && ativo >= 0) { e.preventDefault(); todosItems[ativo].click(); }
      if (e.key === 'Escape')     fechar();
    });

    document.addEventListener('click', function(e) {
      if (!document.getElementById('buscaWrap').contains(e.target)) fechar();
    });

    input.addEventListener('focus', function() {
      if (this.value.trim().length >= 2 && dropdown.innerHTML) dropdown.classList.add('aberto');
    });
  })();

  // ── Linhas de tabela clicáveis (<tr data-href="...">) ──────
  // Clique na linha navega; cliques em links/botões/forms/dropdowns são ignorados.
  document.addEventListener('click', function (e) {
    const tr = e.target.closest('tr[data-href], [data-href].row-link');
    if (!tr) return;
    if (e.target.closest('a, button, input, select, label, form, .dropdown')) return;
    if (window.getSelection && String(window.getSelection())) return; // não navega ao selecionar texto
    const url = tr.getAttribute('data-href');
    if (e.ctrlKey || e.metaKey || e.button === 1) window.open(url, '_blank');
    else window.location.href = url;
  });

  // ── Copiar texto (IP, número de série etc.) ─────────────────
  function copiarTexto(btn, texto) {
    navigator.clipboard.writeText(texto).then(function () {
      const icon = btn.querySelector('i');
      const original = icon.className;
      icon.className = 'bi bi-check-lg';
      btn.classList.add('copiado');
      btn.title = 'Copiado!';
      setTimeout(function () {
        icon.className = original;
        btn.classList.remove('copiado');
        btn.title = 'Copiar';
      }, 1500);
    }).catch(function () {});
  }

  // ── Tabelas ordenáveis (clique no cabeçalho) ────────────────
  // Uso: <table class="table-sortable"> ... <th data-sort>Nome</th> ...
  // data-sort-type="number" para ordenar numericamente (padrão: texto)
  // Em uma célula, use data-sort-value="123" quando o texto exibido não for o valor real (ex: badges)
  // A última ordenação escolhida fica salva por tabela (localStorage) e é restaurada ao voltar à página.
  document.querySelectorAll('table.table-sortable').forEach(function (table, tableIdx) {
    const headerRow = table.querySelector('thead tr');
    if (!headerRow) return;
    const headers = Array.from(headerRow.children).filter(th => th.hasAttribute('data-sort'));
    const storageKey = 'table-sort:' + location.pathname + ':' + (table.id || tableIdx);

    function aplicarOrdenacao(th, dir) {
      const colIndex = Array.from(headerRow.children).indexOf(th);
      const tbody = table.querySelector('tbody');
      if (!tbody) return;
      const rows = Array.from(tbody.querySelectorAll(':scope > tr')).filter(r => r.children.length === headerRow.children.length);
      if (!rows.length) return;
      const type = th.dataset.sortType || 'text';

      headers.forEach(function (h) {
        h.dataset.sortDir = '';
        const ic = h.querySelector('i');
        if (ic) { ic.className = 'bi bi-arrow-down-up ms-1'; ic.style.opacity = '.35'; }
      });
      th.dataset.sortDir = dir;
      const arrow = th.querySelector('i');
      arrow.className = dir === 'asc' ? 'bi bi-sort-alpha-down ms-1' : 'bi bi-sort-alpha-up ms-1';
      arrow.style.opacity = '1';

      rows.sort(function (a, b) {
        const ca = a.children[colIndex], cb = b.children[colIndex];
        let va = (ca?.dataset.sortValue ?? ca?.textContent ?? '').trim();
        let vb = (cb?.dataset.sortValue ?? cb?.textContent ?? '').trim();
        if (type === 'number') {
          va = parseFloat(va.replace(/[^\d.,-]/g, '').replace(',', '.')) || 0;
          vb = parseFloat(vb.replace(/[^\d.,-]/g, '').replace(',', '.')) || 0;
          return dir === 'asc' ? va - vb : vb - va;
        }
        return dir === 'asc' ? va.localeCompare(vb, 'pt-BR') : vb.localeCompare(va, 'pt-BR');
      });

      rows.forEach(r => tbody.appendChild(r));
    }

    headers.forEach(function (th) {
      const colIndex = Array.from(headerRow.children).indexOf(th);
      th.style.cursor = 'pointer';
      th.style.userSelect = 'none';
      th.style.whiteSpace = 'nowrap';

      const arrow = document.createElement('i');
      arrow.className = 'bi bi-arrow-down-up ms-1';
      arrow.style.cssText = 'opacity:.35;font-size:11px';
      th.appendChild(arrow);

      th.addEventListener('click', function () {
        const dir = th.dataset.sortDir === 'asc' ? 'desc' : 'asc';
        aplicarOrdenacao(th, dir);
        try { localStorage.setItem(storageKey, JSON.stringify({col: colIndex, dir: dir})); } catch (e) {}
      });
    });

    // Restaura a última ordenação salva, se houver
    try {
      const saved = JSON.parse(localStorage.getItem(storageKey) || 'null');
      if (saved) {
        const th = Array.from(headerRow.children)[saved.col];
        if (th && th.hasAttribute('data-sort')) aplicarOrdenacao(th, saved.dir);
      }
    } catch (e) {}
  });
</script>
</body>
</html>
<?php
}
