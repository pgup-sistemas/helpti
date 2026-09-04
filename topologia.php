<?php
require 'db.php';
requireLogin();
require 'layout.php';

$pdo = db();

$temTabela = (bool)$pdo->query(
    "SELECT COUNT(*) FROM information_schema.tables
     WHERE table_schema = DATABASE() AND table_name = 'hosts_rede'"
)->fetchColumn();

layoutHeader('Topologia', 'topologia');

if (!$temTabela) {
    echo '<div class="alert alert-warning">Tabela hosts_rede não encontrada. Execute o scanner primeiro em <a href="ferramentas.php">Ferramentas</a>.</div>';
    layoutFooter();
    exit;
}
?>

<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">

<style>
.page-header{margin-bottom:.5rem}
.topo-info-alert{font-size:11px;color:var(--tx-faint);margin-bottom:.5rem;cursor:help}
.topo-toolbar{display:flex;align-items:center;gap:.9rem;flex-wrap:wrap;margin-bottom:.6rem}
.topo-chip{border:none;background:none;color:var(--tx-secondary);font-size:11.5px;font-weight:600;padding:2px 1px;cursor:pointer;user-select:none;border-bottom:2px solid transparent}
.topo-chip:hover{color:var(--tx-primary)}
.topo-chip.active{color:var(--brand);border-bottom-color:var(--brand)}
.topo-sep{width:1px;height:14px;background:var(--border)}
.topo-legend{position:absolute;bottom:12px;right:12px;display:flex;align-items:center;gap:10px;font-size:10.5px;color:var(--tx-faint);background:var(--bg-surface);padding:4px 10px;border-radius:20px;border:1px solid var(--border);z-index:5;opacity:.75;transition:opacity .15s}
.topo-legend:hover{opacity:1}
.topo-legend span{display:flex;align-items:center;gap:4px}
.topo-legend i{width:7px;height:7px;border-radius:50%;display:inline-block}
#cy-wrap{position:relative}
#cy{width:100%;height:calc(100vh - 250px);min-height:420px;background:var(--bg-surface);border:1px solid var(--border);border-radius:10px}

/* Modo tela cheia: some com sidebar/topbar/cabeçalho, o canvas ocupa a
   viewport inteira. Sai com o botão ou tecla Esc. */
body.topo-fullscreen .sidebar,
body.topo-fullscreen .topbar,
body.topo-fullscreen .page-header,
body.topo-fullscreen .topo-info-alert{ display:none !important; }
body.topo-fullscreen .main-wrap{ margin-left:0 !important; padding:0 !important; }
body.topo-fullscreen .topo-toolbar{ border-radius:0; margin-bottom:0; padding:.6rem 1rem; }
body.topo-fullscreen #cy-wrap{ margin:0; }
body.topo-fullscreen #cy{ height:calc(100vh - 56px); border-radius:0; border-left:none; border-right:none; border-bottom:none; }
.topo-fs-btn{ margin-left:auto; background:none; border:1px solid var(--border); color:var(--tx-secondary); border-radius:8px; width:30px; height:30px; cursor:pointer; flex-shrink:0; }
.topo-fs-btn:hover{ background:var(--bg-hover); color:var(--brand) }
#cy-empty{position:absolute;inset:0;display:none;align-items:center;justify-content:center;flex-direction:column;gap:8px;color:var(--tx-faint);text-align:center;padding:2rem}
#cy-loading{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;color:var(--tx-muted);font-size:13px}
.cy-controls{position:absolute;top:12px;right:12px;display:flex;flex-direction:column;gap:5px;z-index:5}
.cy-controls button{width:32px;height:32px;border:1px solid var(--border);background:var(--bg-surface);color:var(--tx-secondary);border-radius:8px;cursor:pointer;display:flex;align-items:center;justify-content:center;box-shadow:0 1px 4px rgba(0,0,0,.08)}
.cy-controls button:hover{background:var(--bg-hover);color:var(--brand)}
.cy-hint{position:absolute;bottom:12px;left:12px;font-size:11px;color:var(--tx-faint);background:var(--bg-surface);padding:4px 10px;border-radius:20px;border:1px solid var(--border);z-index:5}

/* Card de ativo — HTML/CSS real sobreposto ao nó do Cytoscape, para ficar
   nítido em qualquer zoom (texto em canvas borra ao ampliar). */
.tnc{
  width:150px;background:var(--bg-surface);border:1.5px solid var(--border);border-radius:10px;
  padding:.5rem .6rem;box-shadow:0 1px 4px rgba(0,0,0,.08);cursor:pointer;
  font-family:'Segoe UI',system-ui,sans-serif;transition:box-shadow .15s,transform .15s,border-color .15s;
}
.tnc:hover{box-shadow:0 5px 16px rgba(0,0,0,.16);transform:translateY(-2px)}
.tnc.selected{border-color:var(--brand);box-shadow:0 0 0 3px rgba(29,53,87,.15)}
.tnc-top{display:flex;align-items:center;gap:8px;min-width:0}
.tnc-ico{width:32px;height:32px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:17px;flex-shrink:0}
.tnc-name{font-size:12.5px;font-weight:700;color:var(--tx-primary);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;min-width:0}
.tnc-ip{font-size:11px;color:var(--tx-faint);margin-top:4px;font-variant-numeric:tabular-nums;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.tnc-status{display:flex;align-items:center;gap:5px;margin-top:5px;font-size:10px;font-weight:700;letter-spacing:.02em}
.tnc-dot{width:6px;height:6px;border-radius:50%;display:inline-block;flex-shrink:0}
.tnc-status-ok{color:#16a34a}.tnc-status-ok .tnc-dot{background:#22c55e}
.tnc-status-warn{color:#b45309}.tnc-status-warn .tnc-dot{background:#f59e0b}
.tnc-status-off{color:#6b7280}.tnc-status-off .tnc-dot{background:#9ca3af}
.tnc-status-unknown{color:#64748b}.tnc-status-unknown .tnc-dot{background:#94a3b8}
.tnc-badges{display:flex;flex-wrap:wrap;gap:3px;margin-top:5px}
.tnc-badge{font-size:9px;font-weight:700;padding:1px 5px;border-radius:5px;letter-spacing:.02em;white-space:nowrap}
.tnc-badge.crit{background:#fef2f2;color:#991b1b}
.tnc-badge.warn{background:#fffbeb;color:#92400e}
.tnc-badge.new{background:#eff6ff;color:#1e40af}
[data-theme="dark"] .tnc-status-ok{color:#4ade80}
[data-theme="dark"] .tnc-status-warn{color:#fbbf24}
[data-theme="dark"] .tnc-badge.crit{background:#450a0a;color:#fca5a5}
[data-theme="dark"] .tnc-badge.warn{background:#422006;color:#fde68a}
[data-theme="dark"] .tnc-badge.new{background:#0c1e40;color:#93c5fd}

/* Side panel (offcanvas) — o Bootstrap usa fundo branco fixo por padrão,
   sem herdar o tema claro/escuro do HelpTI; força os tokens aqui. */
#painelAtivo{width:400px;background:var(--bg-surface);color:var(--tx-primary)}
#painelAtivo .offcanvas-header{background:var(--bg-surface);border-bottom:1px solid var(--border);align-items:flex-start;gap:12px}
#painelAtivo .offcanvas-title{color:var(--tx-primary)}
#painelAtivo .offcanvas-body{padding:0;background:var(--bg-surface)}
#painelAtivo .btn-close{filter:none}
[data-theme="dark"] #painelAtivo .btn-close{filter:invert(1) brightness(1.6)}
.pa-ico{font-size:26px;line-height:1}
.pa-status{display:inline-flex;align-items:center;gap:5px;font-size:11px;font-weight:700;margin-top:6px;padding:3px 9px;border-radius:20px}
.pa-tabs{padding:.6rem .9rem 0;border-bottom:1px solid var(--border);background:var(--bg-surface)}
.pa-tabs .nav-link{color:var(--tx-secondary);background:none;border:none;border-bottom:2px solid transparent;font-size:12.5px;font-weight:600}
.pa-tabs .nav-link:hover{color:var(--tx-primary);border-color:transparent}
.pa-tabs .nav-link.active{color:var(--brand);background:none;border-bottom-color:var(--brand)}
.pa-body{padding:1.1rem 1.25rem;max-height:calc(100vh - 190px);overflow-y:auto}
.pa-kv{display:flex;justify-content:space-between;gap:10px;padding:.4rem 0;border-bottom:1px solid var(--border-light);font-size:13px}
.pa-kv:last-child{border-bottom:none}
.pa-kv .k{color:var(--tx-muted)}
.pa-kv .v{font-weight:600;text-align:right;word-break:break-word}
.pa-section-title{font-size:11px;font-weight:700;color:var(--tx-nav);text-transform:uppercase;letter-spacing:.05em;margin:1rem 0 .5rem}
.pa-section-title:first-child{margin-top:0}
.pa-access{display:flex;flex-wrap:wrap;gap:8px}
</style>

<div class="page-header">
  <h1 class="page-title"><i class="bi bi-diagram-2-fill me-2 text-primary"></i>Topologia de Rede</h1>
  <div class="d-flex gap-2">
    <a href="hosts_rede.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-table me-1"></i>Ver em lista</a>
    <a href="ferramentas.php#scanner" class="btn btn-outline-primary btn-sm"><i class="bi bi-search me-1"></i>Escanear agora</a>
  </div>
</div>

<div class="topo-info-alert" title="Hierarquia inferida por sub-rede (sem LLDP/SNMP ainda). Switches/roteadores/APs viram concentradores; os demais dispositivos são associados a eles com confiança baixa — corrija manualmente vinculando ao inventário quando necessário.">
  <i class="bi bi-info-circle"></i> Hierarquia inferida por sub-rede — sem LLDP/SNMP ainda, confiança baixa nas relações automáticas.
</div>

<div class="topo-toolbar">
  <select id="redePicker" class="form-select form-select-sm" style="width:auto;font-size:12.5px;font-weight:600">
    <option value="all">🌐 Todas as redes</option>
  </select>
  <div class="topo-sep"></div>
  <button class="topo-chip active" data-filtro="all">Tudo</button>
  <button class="topo-chip" data-filtro="computer">Computadores</button>
  <button class="topo-chip" data-filtro="printer">Impressoras</button>
  <button class="topo-chip" data-filtro="net">Rede</button>
  <button class="topo-chip" data-filtro="mobile">Móveis</button>
  <button class="topo-chip" data-filtro="unknown">Desconhecidos</button>
  <div class="topo-sep"></div>
  <button class="topo-chip" data-filtro="alerts"><i class="bi bi-exclamation-triangle-fill me-1"></i>Com alerta</button>
  <div class="topo-sep"></div>
  <select id="layoutPicker" class="form-select form-select-sm" style="width:auto;font-size:12.5px;font-weight:600">
    <option value="dagre-lr">🌳 Árvore horizontal</option>
    <option value="dagre-tb">🌳 Árvore vertical</option>
    <option value="concentric">🎯 Radial</option>
    <option value="cose">🫧 Orgânico</option>
  </select>
  <button class="topo-fs-btn" id="btnFullscreen" title="Tela cheia"><i class="bi bi-arrows-fullscreen"></i></button>
</div>

<div id="cy-wrap">
  <div id="cy"></div>
  <div id="cy-loading"><i class="bi bi-arrow-repeat me-2"></i>Carregando topologia…</div>
  <div id="cy-empty">
    <i class="bi bi-diagram-2" style="font-size:32px"></i>
    <div>Nenhum host encontrado. Execute o scanner em <a href="ferramentas.php">Ferramentas</a>.</div>
  </div>
  <div class="topo-legend">
    <span><i style="background:#22c55e"></i> Online</span>
    <span><i style="background:#f59e0b"></i> Alerta</span>
    <span><i style="background:#9ca3af"></i> Offline</span>
    <span><i style="background:#94a3b8"></i> Desconhecido</span>
  </div>
  <div class="cy-controls">
    <button id="cyZoomIn" title="Aumentar zoom"><i class="bi bi-plus-lg"></i></button>
    <button id="cyZoomOut" title="Diminuir zoom"><i class="bi bi-dash-lg"></i></button>
    <button id="cyFit" title="Ajustar à tela"><i class="bi bi-aspect-ratio"></i></button>
  </div>
  <div class="cy-hint"><i class="bi bi-mouse me-1"></i>Scroll para zoom · arraste para navegar · clique num nó para detalhes</div>
</div>

<!-- Painel lateral do ativo selecionado -->
<div class="offcanvas offcanvas-end" tabindex="-1" id="painelAtivo">
  <div class="offcanvas-header">
    <div class="pa-ico" id="paIcone"><i class="bi bi-question-circle"></i></div>
    <div class="flex-grow-1 min-w-0">
      <h5 class="offcanvas-title" id="paNome" style="font-size:15.5px">—</h5>
      <div class="text-muted" style="font-size:12px" id="paSub">—</div>
      <div class="pa-status" id="paStatus">—</div>
    </div>
    <button type="button" class="btn-close" data-bs-dismiss="offcanvas"></button>
  </div>
  <div class="offcanvas-body">
    <ul class="nav nav-tabs pa-tabs" id="paTabs" role="tablist"></ul>
    <div class="pa-body" id="paBody"></div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/cytoscape@3.28.1/dist/cytoscape.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/cytoscape-dagre@4.0.1/dist/cytoscape-dagre.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/cytoscape-node-html-label@1.2.2/dist/cytoscape-node-html-label.min.js"></script>
<script>
(function(){
  function escapeHtml(s){
    return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  }

  const cyEl = document.getElementById('cy');
  const loadingEl = document.getElementById('cy-loading');
  const emptyEl = document.getElementById('cy-empty');

  // Com 250+ folhas sob poucos grupos, qualquer árvore de cima pra baixo tem
  // uma fileira enorme no nível das folhas — por isso o layout é escolhível:
  // árvore horizontal (folhas em coluna, rola na vertical), vertical (folhas
  // em linha, rola na horizontal), radial ou orgânico (força).
  const LAYOUTS = {
    'dagre-lr':   { name: 'dagre', rankDir: 'LR', nodeSep: 10, rankSep: 60, edgeSep: 6, padding: 20, fit: false, animate: false },
    'dagre-tb':   { name: 'dagre', rankDir: 'TB', nodeSep: 16, rankSep: 55, edgeSep: 8, padding: 20, fit: false, animate: false },
    'concentric': { name: 'concentric', minNodeSpacing: 25, padding: 20, fit: false, animate: false,
                    concentric: n => (n.data('kind') === 'root' ? 3 : n.data('kind') === 'group' ? 2 : 1),
                    levelWidth: () => 1 },
    'cose':       { name: 'cose', idealEdgeLength: 55, nodeRepulsion: 4000, padding: 20, fit: false, animate: false },
  };
  const FALLBACK_LAYOUT = { name: 'breadthfirst', directed: true, spacingFactor: 1.4, padding: 30, fit: false };
  let currentLayoutName = 'dagre-lr';
  const MIN_ZOOM_INICIAL = 0.7; // com muitas folhas, "caber tudo" sozinho encolhe demais

  // Ajusta a visão a um conjunto de elementos, mas nunca abaixo de um zoom
  // legível — nesse caso trava o zoom mínimo e centraliza (o resto se explora
  // rolando/dando zoom manualmente).
  function fitComPiso(cyInstance, eles, padding){
    cyInstance.fit(eles && eles.length ? eles : undefined, padding || 60);
    if (cyInstance.zoom() < MIN_ZOOM_INICIAL) {
      cyInstance.zoom(MIN_ZOOM_INICIAL);
      cyInstance.center(eles && eles.length ? eles : cyInstance.elements());
    }
  }

  // 'overview' (padrão): ajusta a visão só na raiz+grupos — com 250+ folhas,
  // encaixar a árvore inteira de uma vez espreme tudo numa fatia minúscula.
  // 'all': ajusta pra árvore inteira (botão de "ajustar à tela"). 'none':
  // preserva o zoom/pan atual (usado ao expandir um cluster no meio da tela).
  function runTreeLayout(cyInstance, layoutName, fitMode){
    if (layoutName) currentLayoutName = layoutName;
    fitMode = fitMode || 'overview';
    try {
      cyInstance.layout(LAYOUTS[currentLayoutName] || LAYOUTS['dagre-lr']).run();
    } catch (e) {
      console.warn('Layout "' + currentLayoutName + '" indisponível, usando breadthfirst.', e);
      cyInstance.layout(FALLBACK_LAYOUT).run();
    }
    if (fitMode === 'overview') {
      const overview = cyInstance.nodes('[kind="root"], [kind="group"]').filter(n => n.style('display') !== 'none');
      fitComPiso(cyInstance, overview, 60);
    } else if (fitMode === 'all') {
      cyInstance.fit(undefined, 30);
    }
  }

  function cssVar(name){ return getComputedStyle(document.documentElement).getPropertyValue(name).trim(); }

  const STATUS_COLOR = { ok:'#22c55e', warn:'#f59e0b', off:'#9ca3af', unknown:'#94a3b8' };
  const STATUS_LABEL = { ok:'ONLINE', warn:'ATENÇÃO', off:'OFFLINE', unknown:'DESCONHECIDO' };
  const STATUS_BG    = { ok:'#f0fdf4', warn:'#fffbeb', off:'#f3f4f6', unknown:'#f8fafc' };

  const FILTER_TIPOS = {
    computer: ['Computador','Desktop','Notebook','Tablet','Terminal'],
    printer:  ['Impressora','Impressora Colorida','Impressora Etiqueta'],
    net:      ['Switch','Switch/AP Intelbras','Access Point','Roteador','Roteador MikroTik'],
    mobile:   ['Celular','Notebook','Tablet','Telefone IP'],
    unknown:  ['Desconhecido'],
  };

  let cy = null;
  let currentFilter = 'all';
  let currentRede = 'all';
  const CLUSTER_THRESHOLD = 12; // acima disso, os filhos de um hub viram um nó "+N dispositivos"

  // Bootstrap Icons é uma webfont — não dá pra usar no canvas do Cytoscape sem
  // registrar codepoints. Para os nós de grupo (poucos, estruturais) usamos um
  // emoji equivalente, que o canvas desenha nativamente em qualquer SO.
  const ICON_EMOJI = {
    'bi-hdd-network-fill': '🔀', 'bi-hdd-network': '🔀', 'bi-building': '🏢',
    'bi-question-diamond': '❓', 'bi-pc-display': '🖥️', 'bi-laptop': '💻',
    'bi-tablet-fill': '📱', 'bi-terminal-fill': '⌨️', 'bi-printer-fill': '🖨️',
    'bi-wifi': '📶', 'bi-router-fill': '📡', 'bi-server': '🗄️',
    'bi-hdd-rack-fill': '🗄️', 'bi-display': '🖥️', 'bi-display-fill': '🖥️',
    'bi-phone-fill': '📱', 'bi-telephone-fill': '☎️', 'bi-battery-charging': '🔋',
    'bi-door-open': '🚪', 'bi-heart-pulse': '⚕️', 'bi-tools': '🔧',
  };

  function severityRank(n){
    if (!n) return 3;
    if (n.status === 'unknown') return 0;
    if (n.status === 'warn' || (n.badges && n.badges.length)) return 1;
    if (n.status === 'off') return 2;
    return 3;
  }

  // Agrupa filhos por pai e, quando há muitos, mantém os mais relevantes (com alerta)
  // visíveis e recolhe o resto num nó de cluster expansível — evita desenhar
  // centenas de nós de uma vez (zoom semântico simplificado, ver spec item 34).
  function buildElements(data){
    window.TOPO_NODES = {};
    data.nodes.forEach(n => { window.TOPO_NODES[n.id] = n; });

    const childrenBySource = {};
    data.edges.forEach(e => { (childrenBySource[e.source] = childrenBySource[e.source] || []).push(e.target); });

    // Só clusteriza alvos do tipo "host" (folhas) — nós de grupo (setor/tipo/
    // infra) são poucos e estruturais, sempre ficam todos visíveis.
    function ehHost(id){ return window.TOPO_NODES[id] && window.TOPO_NODES[id].kind === 'host'; }

    // Decide primeiro quais ids ficam escondidos atrás de um cluster,
    // para não criá-los como nós órfãos (sem aresta) no grafo inicial.
    window.TOPO_HIDDEN_CLUSTERS = {};
    const hiddenIds = new Set();
    Object.entries(childrenBySource).forEach(([source, targets]) => {
      const hostTargets = targets.filter(ehHost);
      if (hostTargets.length > CLUSTER_THRESHOLD) {
        const ordenado = hostTargets.slice().sort((a, b) => severityRank(window.TOPO_NODES[a]) - severityRank(window.TOPO_NODES[b]));
        ordenado.slice(CLUSTER_THRESHOLD).forEach(id => hiddenIds.add(id));
      }
    });

    const elements = [];
    data.nodes.forEach(n => {
      if (hiddenIds.has(n.id)) return;
      if (n.kind === 'group') {
        const emoji = ICON_EMOJI[n.icon] || '📦';
        elements.push({ data: {
          id: n.id, kind: n.kind,
          displayLabel: `${emoji}  ${n.label}\n${n.count} ativo${n.count === 1 ? '' : 's'}`,
          groupColor: n.color,
        }});
        return;
      }
      elements.push({ data: { id: n.id, kind: n.kind, label: n.label, statusColor: STATUS_COLOR[n.status] || '#c7d0dd' } });
    });

    Object.entries(childrenBySource).forEach(([source, targets]) => {
      const hostTargets = targets.filter(ehHost);
      const outrosTargets = targets.filter(t => !ehHost(t));
      outrosTargets.forEach(t => elements.push({ data: { id: 'e_' + source + '_' + t, source, target: t } }));

      if (hostTargets.length > CLUSTER_THRESHOLD) {
        const ordenado = hostTargets.slice().sort((a, b) => severityRank(window.TOPO_NODES[a]) - severityRank(window.TOPO_NODES[b]));
        const visiveis = ordenado.slice(0, CLUSTER_THRESHOLD);
        const escondidos = ordenado.slice(CLUSTER_THRESHOLD);
        visiveis.forEach(t => elements.push({ data: { id: 'e_' + source + '_' + t, source, target: t } }));
        const clusterId = 'cluster_' + source;
        elements.push({ data: { id: clusterId, kind: 'cluster', label: '+' + escondidos.length + ' dispositivos' } });
        elements.push({ data: { id: 'e_' + source + '_' + clusterId, source, target: clusterId } });
        window.TOPO_HIDDEN_CLUSTERS[clusterId] = { parent: source, childIds: escondidos };
      } else {
        hostTargets.forEach(t => elements.push({ data: { id: 'e_' + source + '_' + t, source, target: t } }));
      }
    });

    return elements;
  }

  // Só adiciona os nós/arestas escondidos ao grafo — sem mexer em layout/filtro.
  // Usado tanto pelo clique manual no cluster quanto pelo filtro (que precisa
  // expandir tudo antes de decidir o que mostrar).
  function expandirDados(clusterNode){
    const info = window.TOPO_HIDDEN_CLUSTERS[clusterNode.id()];
    if (!info) return false;
    const toAdd = [];
    info.childIds.forEach(id => {
      const n = window.TOPO_NODES[id];
      toAdd.push({ data: { id: n.id, kind: n.kind, label: n.label, statusColor: STATUS_COLOR[n.status] || '#c7d0dd' } });
    });
    info.childIds.forEach(id => {
      toAdd.push({ data: { id: 'e_' + info.parent + '_' + id, source: info.parent, target: id } });
    });
    cy.remove(clusterNode);
    delete window.TOPO_HIDDEN_CLUSTERS[clusterNode.id()];
    cy.add(toAdd);
    return true;
  }

  function expandirCluster(clusterNode){
    if (!expandirDados(clusterNode)) return;
    runTreeLayout(cy, null, 'none');
    applyFilter(currentFilter);
  }

  function buildStyle(){
    return [
      { selector: 'node', style: {
          'shape': 'round-rectangle',
          'background-opacity': 0, 'border-width': 0, 'label': '',
      }},
      // Host: a "caixa" do Cytoscape fica invisível — quem aparece é o card
      // HTML sobreposto (nodeHtmlLabel), nítido em qualquer nível de zoom.
      { selector: 'node[kind="host"]', style: { 'width': 150, 'height': 90 } },
      // Grupo (infra/setor/tipo): poucos nós, estruturais — renderizados
      // nativamente (emoji + contagem), sem depender do overlay HTML.
      { selector: 'node[kind="group"]', style: {
          'background-opacity': 1,
          'background-color': cssVar('--bg-surface-alt') || '#f8f9fa',
          'border-width': 2, 'border-color': 'data(groupColor)',
          'width': 190, 'height': 60,
          'label': 'data(displayLabel)', 'text-valign': 'center', 'text-halign': 'center',
          'text-wrap': 'wrap', 'text-max-width': '170px',
          'font-size': 11.5, 'font-weight': 700,
          'color': cssVar('--tx-primary') || '#111',
          'min-zoomed-font-size': 7,
      }},
      { selector: 'node[kind="root"]', style: {
          'background-opacity': 1,
          'background-color': cssVar('--brand') || '#1D3557',
          'border-width': 0, 'color': '#fff', 'shape': 'round-rectangle',
          'width': 170, 'height': 44, 'font-size': 12, 'font-weight': 700,
          'label': 'data(label)', 'text-valign': 'center', 'text-halign': 'center',
          'min-zoomed-font-size': 8,
      }},
      { selector: 'node[kind="cluster"]', style: {
          'background-opacity': 1,
          'background-color': cssVar('--bg-surface-alt') || '#f8f9fa',
          'border-width': 1.5, 'border-style': 'dashed',
          'border-color': cssVar('--tx-faint') || '#94a3b8',
          'color': cssVar('--tx-secondary') || '#5a6472',
          'font-size': 11, 'font-weight': 700,
          'width': 140, 'height': 44,
          'label': 'data(label)', 'text-valign': 'center', 'text-halign': 'center',
          'text-wrap': 'wrap', 'text-max-width': '120px',
          'min-zoomed-font-size': 8,
      }},
      { selector: 'edge', style: {
          'width': 1.6,
          'line-color': cssVar('--border') || '#c7d0dd',
          'target-arrow-shape': 'none',
          'curve-style': 'taxi', 'taxi-direction': 'downward', 'taxi-turn': 24,
      }},
    ];
  }

  fetch('api_topologia.php')
    .then(r => r.json())
    .then(data => {
      loadingEl.style.display = 'none';
      if (!data.nodes || !data.nodes.length) { emptyEl.style.display = 'flex'; return; }

      const elements = buildElements(data);

      const redePicker = document.getElementById('redePicker');
      data.nodes.filter(n => n.kind === 'root').forEach(n => {
        const opt = document.createElement('option');
        opt.value = n.id;
        opt.textContent = '🌐 ' + n.label;
        redePicker.appendChild(opt);
      });
      redePicker.addEventListener('change', () => {
        currentRede = redePicker.value;
        applyFilter(currentFilter);
      });

      cy = window.cy = cytoscape({
        container: cyEl,
        elements: elements,
        style: buildStyle(),
        layout: { name: 'preset' },
        wheelSensitivity: 0.25,
        minZoom: 0.05,
        maxZoom: 3,
      });
      runTreeLayout(cy);

      if (typeof cy.nodeHtmlLabel === 'function') {
        cy.nodeHtmlLabel([{
          query: 'node[kind="host"]',
          halign: 'center', valign: 'center', halignBox: 'center', valignBox: 'center',
          tpl: function(data){
            const n = window.TOPO_NODES[data.id];
            if (!n) return '';
            const badges = (n.badges || []).map(b =>
              `<span class="tnc-badge ${b.sev}">${escapeHtml(b.label)}</span>`).join('');
            return `
              <div class="tnc" data-node-id="${n.id}">
                <div class="tnc-top">
                  <span class="tnc-ico" style="background:${n.color}22;color:${n.color}"><i class="bi ${n.icon}"></i></span>
                  <span class="tnc-name">${escapeHtml(n.label)}</span>
                </div>
                <div class="tnc-ip">${escapeHtml(n.ip || '')}</div>
                <div class="tnc-status tnc-status-${n.status}"><i class="tnc-dot"></i>${STATUS_LABEL[n.status] || 'DESCONHECIDO'}</div>
                ${badges ? `<div class="tnc-badges">${badges}</div>` : ''}
              </div>`;
          }
        }]);
      }

      cy.on('tap', 'node[kind="host"]', function(evt){
        document.querySelectorAll('.tnc.selected').forEach(el => el.classList.remove('selected'));
        const el = cyEl.querySelector('.tnc[data-node-id="' + evt.target.id() + '"]');
        if (el) el.classList.add('selected');
        abrirPainel(window.TOPO_NODES[evt.target.id()]);
      });
      cy.on('tap', 'node[kind="cluster"]', function(evt){
        expandirCluster(evt.target);
      });
      cy.on('tap', 'node[kind="group"]', function(evt){
        const ramo = evt.target.successors().union(evt.target);
        cy.fit(ramo, 60);
      });

      applyFilter(currentFilter);
    })
    .catch((err) => {
      console.error('Falha ao carregar topologia:', err);
      loadingEl.innerHTML = '<span class="text-danger"><i class="bi bi-exclamation-triangle-fill me-2"></i>Falha ao carregar topologia.</span>';
    });

  function applyFilter(f){
    currentFilter = f;
    if (!cy) return;

    // Um filtro só é confiável se olhar TODOS os hosts — expande qualquer
    // cluster "+N" ainda fechado antes de decidir o que aparece.
    if (f !== 'all' || currentRede !== 'all') {
      let expandiuAlgum = false;
      Object.keys(window.TOPO_HIDDEN_CLUSTERS).forEach(clusterId => {
        const node = cy.$id(clusterId);
        if (node.nonempty() && expandirDados(node)) expandiuAlgum = true;
      });
      if (expandiuAlgum) runTreeLayout(cy, null, 'none');
    }

    cy.nodes('[kind="host"]').forEach(node => {
      const n = window.TOPO_NODES[node.id()];
      let mostrar = true;
      if (currentRede !== 'all' && n.rede_id !== currentRede) mostrar = false;
      else if (f === 'all') mostrar = true;
      else if (f === 'alerts') mostrar = (n.status === 'warn' || n.status === 'unknown' || (n.badges && n.badges.length > 0));
      else mostrar = (FILTER_TIPOS[f] || []).includes(n.tipo);
      node.style('display', mostrar ? 'element' : 'none');
      const el = cyEl.querySelector('.tnc[data-node-id="' + node.id() + '"]');
      if (el) el.style.display = mostrar ? '' : 'none';
    });

    // Grupo sem nenhum host visível dentro dele some junto — evita caixas vazias.
    cy.nodes('[kind="group"]').forEach(g => {
      const n = window.TOPO_NODES[g.id()];
      let visivel;
      if (currentRede !== 'all' && n.rede_id !== currentRede) visivel = false;
      else visivel = f === 'all' || g.successors('[kind="host"]').some(h => h.style('display') !== 'none');
      g.style('display', visivel ? 'element' : 'none');
    });

    // Raiz de outra rede some quando uma rede específica está selecionada.
    cy.nodes('[kind="root"]').forEach(r => {
      r.style('display', (currentRede === 'all' || r.id() === currentRede) ? 'element' : 'none');
    });

    const visiveis = cy.elements().filter(e => e.style('display') !== 'none');
    fitComPiso(cy, visiveis, 60);
  }

  document.querySelectorAll('.topo-chip[data-filtro]').forEach(chip => {
    chip.addEventListener('click', () => {
      document.querySelectorAll('.topo-chip[data-filtro]').forEach(c => c.classList.remove('active'));
      chip.classList.add('active');
      applyFilter(chip.dataset.filtro);
    });
  });

  const layoutPicker = document.getElementById('layoutPicker');
  layoutPicker.value = currentLayoutName;
  layoutPicker.addEventListener('change', () => {
    if (!cy) return;
    runTreeLayout(cy, layoutPicker.value);
  });

  function zoomBy(fator){
    if (!cy) return;
    const w = cyEl.clientWidth, h = cyEl.clientHeight;
    cy.zoom({ level: cy.zoom() * fator, renderedPosition: { x: w / 2, y: h / 2 } });
  }
  document.getElementById('cyZoomIn').addEventListener('click', () => zoomBy(1.25));
  document.getElementById('cyZoomOut').addEventListener('click', () => zoomBy(0.8));
  document.getElementById('cyFit').addEventListener('click', () => { if (cy) cy.fit(undefined, 30); });

  function sairTelaCheia(){
    document.body.classList.remove('topo-fullscreen');
    if (cy) { cy.resize(); cy.fit(undefined, 30); }
  }
  document.getElementById('btnFullscreen').addEventListener('click', () => {
    document.body.classList.toggle('topo-fullscreen');
    if (cy) { cy.resize(); cy.fit(undefined, 30); }
  });
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && document.body.classList.contains('topo-fullscreen')) sairTelaCheia();
  });

  // Recalcula cores ao trocar tema claro/escuro
  new MutationObserver(() => { if (cy) cy.style(buildStyle()).update(); })
    .observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] });

  // ── Painel lateral ──────────────────────────────────────
  const painelEl = document.getElementById('painelAtivo');

  function kvHtml(obj){
    return Object.entries(obj).filter(([,v]) => v !== null && v !== undefined && v !== '')
      .map(([k,v]) => `<div class="pa-kv"><span class="k">${k}</span><span class="v">${v}</span></div>`).join('')
      || '<div class="pa-kv"><span class="k">Sem dados</span></div>';
  }

  function abrirPainel(n){
    if (!n) return;
    document.getElementById('paIcone').innerHTML = `<i class="bi ${n.icon}" style="color:${n.color}"></i>`;
    document.getElementById('paNome').textContent = n.label;
    document.getElementById('paSub').textContent = n.tipo + (n.fabricante ? ' · ' + n.fabricante : '');
    const statusEl = document.getElementById('paStatus');
    statusEl.textContent = STATUS_LABEL[n.status] || 'DESCONHECIDO';
    statusEl.style.color = STATUS_COLOR[n.status];
    statusEl.style.background = STATUS_BG[n.status];

    const tabs = ['Overview', 'Rede'];
    if (n.is_printer) tabs.push('Suprimentos');
    tabs.push('Histórico', 'Ações');

    const tabsEl = document.getElementById('paTabs');
    tabsEl.innerHTML = tabs.map((t,i) => `
      <li class="nav-item"><button class="nav-link ${i===0?'active':''}" data-pa-tab="${t}" type="button">${t}</button></li>
    `).join('');
    tabsEl.querySelectorAll('[data-pa-tab]').forEach(btn => {
      btn.addEventListener('click', () => {
        tabsEl.querySelectorAll('.nav-link').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        renderPaBody(btn.dataset.paTab, n);
      });
    });
    renderPaBody(tabs[0], n);
    bootstrap.Offcanvas.getOrCreateInstance(painelEl).show();
  }

  function renderPaBody(tab, n){
    const body = document.getElementById('paBody');
    if (tab === 'Overview'){
      let html = '<div class="pa-section-title">Identificação</div>' + kvHtml({
        'Hostname': n.label,
        'Tipo': n.tipo,
        'Fabricante': n.fabricante,
        'Setor': n.setor,
        'Primeiro visto': n.primeiro_visto,
        'Última vez visto': n.ultimo_visto,
      });
      if (n.badges && n.badges.length){
        html += '<div class="pa-section-title">Alertas</div>' + n.badges.map(b =>
          `<span class="badge ${b.sev==='crit'?'bg-danger':(b.sev==='new'?'bg-info text-dark':'bg-warning text-dark')} me-1 mb-1">${b.label}</span>`
        ).join('');
      }
      html += '<div class="pa-section-title">Inventário</div>';
      html += n.inventario_id
        ? kvHtml({ 'Item': (n.inv_marca||'') + ' ' + (n.inv_modelo||''), 'Patrimônio': n.patrimonio })
        : `<div class="pa-kv"><span class="k">Não vinculado ao inventário</span></div><a href="hosts_rede.php?busca=${encodeURIComponent(n.ip)}" class="btn btn-sm btn-outline-primary mt-2"><i class="bi bi-link-45deg me-1"></i>Vincular em Hosts de Rede</a>`;
      body.innerHTML = html;
    } else if (tab === 'Rede'){
      body.innerHTML = kvHtml({ 'IP': n.ip, 'MAC': n.mac, 'Portas': n.portas }) +
        '<div class="pa-section-title">Acesso</div>' +
        `<div class="pa-access">
           <a href="http://${n.ip}" target="_blank" class="btn btn-sm btn-outline-secondary"><i class="bi bi-globe me-1"></i>Abrir HTTP</a>
           ${n.is_printer ? `<a href="impressoras.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-printer me-1"></i>Monitoramento SNMP</a>` : ''}
         </div>`;
    } else if (tab === 'Suprimentos'){
      const t = n.toner || {};
      body.innerHTML = '<div class="pa-section-title">Nível de toner</div>' + kvHtml({
        'Preto': t.preto !== null && t.preto !== undefined ? t.preto + '%' : '—',
        'Ciano': t.ciano !== null && t.ciano !== undefined ? t.ciano + '%' : '—',
        'Magenta': t.magenta !== null && t.magenta !== undefined ? t.magenta + '%' : '—',
        'Amarelo': t.amarelo !== null && t.amarelo !== undefined ? t.amarelo + '%' : '—',
      });
    } else if (tab === 'Histórico'){
      body.innerHTML = '<div class="pa-section-title">Linha do tempo</div>' + kvHtml({
        'Primeira vez visto': n.primeiro_visto,
        'Última vez visto': n.ultimo_visto,
      });
    } else if (tab === 'Ações'){
      body.innerHTML = `
        <div class="pa-section-title">Ações</div>
        <a href="hosts_rede.php?busca=${encodeURIComponent(n.ip)}" class="btn btn-sm btn-outline-primary w-100 mb-2"><i class="bi bi-table me-1"></i>Gerenciar em Hosts de Rede</a>
        <a href="http://${n.ip}" target="_blank" class="btn btn-sm btn-outline-secondary w-100"><i class="bi bi-globe me-1"></i>Abrir interface web</a>
      `;
    }
  }
})();
</script>

<?php layoutFooter(); ?>
