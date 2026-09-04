<?php
require 'db.php';
requireLogin();
require 'layout.php';

layoutHeader('Ajuda', 'ajuda');
$u = usuario();
$ehGestora = in_array($u['perfil'], ['gestora','admin'], true);
$ehAdmin   = $u['perfil'] === 'admin';
?>

<div class="page-header">
  <h1 class="page-title"><i class="bi bi-question-circle-fill me-2 text-primary"></i>Ajuda — Como usar o HelpTI</h1>
</div>

<style>
.ajuda-toc{display:flex;flex-wrap:wrap;gap:.5rem}
.ajuda-toc a{font-size:12.5px;font-weight:600;padding:.35rem .8rem;border-radius:20px;border:1.5px solid var(--border);color:var(--tx-secondary);text-decoration:none;transition:.15s}
.ajuda-toc a:hover{border-color:var(--brand);color:var(--brand)}
.ajuda-sec{scroll-margin-top:80px}
.ajuda-step{display:flex;gap:.75rem;padding:.7rem 0;border-bottom:1px dashed var(--border)}
.ajuda-step:last-child{border-bottom:none}
.ajuda-step .n{width:24px;height:24px;border-radius:50%;background:var(--brand-light,#e0eefc);color:var(--brand);font-weight:800;font-size:11.5px;display:flex;align-items:center;justify-content:center;flex-shrink:0}
.ajuda-step b{display:block;font-size:13px}
.ajuda-step span{font-size:12.5px;color:var(--tx-muted)}
.ajuda-tag{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;padding:.15rem .5rem;border-radius:10px;margin-left:.4rem;vertical-align:middle}
.ajuda-tag-gestora{background:#fef3c7;color:#92400e}
.ajuda-tag-admin{background:#fee2e2;color:#991b1b}
.ajuda-faq-q{cursor:pointer;font-weight:600;font-size:13px;padding:.6rem 0;display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid var(--border)}
.ajuda-faq-a{font-size:12.5px;color:var(--tx-muted);padding:.1rem 0 .8rem;line-height:1.55;display:none}
.ajuda-faq.open .ajuda-faq-a{display:block}
.ajuda-faq.open .ajuda-faq-q .chev{transform:rotate(180deg)}
.ajuda-faq-q .chev{transition:.2s;color:var(--tx-faint)}
</style>

<!-- Sumário -->
<div class="card mb-4">
  <div class="card-body">
    <div class="ajuda-toc">
      <a href="#sec-chamados"><i class="bi bi-headset me-1"></i>Chamados</a>
      <a href="#sec-suprimentos"><i class="bi bi-box-seam me-1"></i>Suprimentos</a>
      <a href="#sec-inventario"><i class="bi bi-hdd-stack me-1"></i>Inventário</a>
      <a href="#sec-impressoras"><i class="bi bi-printer me-1"></i>Impressoras</a>
      <a href="#sec-contratos"><i class="bi bi-file-earmark-text me-1"></i>Contratos</a>
      <a href="#sec-ferramentas"><i class="bi bi-tools me-1"></i>Ferramentas de TI</a>
      <a href="#sec-portal"><i class="bi bi-box-arrow-up-right me-1"></i>Portal do Colaborador</a>
      <?php if ($ehAdmin): ?><a href="#sec-admin"><i class="bi bi-people me-1"></i>Administração</a><?php endif; ?>
      <a href="#sec-faq"><i class="bi bi-patch-question me-1"></i>Perguntas frequentes</a>
    </div>
  </div>
</div>

<!-- CHAMADOS -->
<div class="card mb-4 ajuda-sec" id="sec-chamados">
  <div class="card-header"><i class="bi bi-headset me-2 text-primary"></i><strong>Chamados</strong></div>
  <div class="card-body">
    <p class="text-muted" style="font-size:13px">Um chamado nasce de duas formas: um colaborador abre pelo <a href="portal.php" target="_blank">Portal</a> (sem login), ou um técnico cadastra direto pelo sistema em <a href="novo_chamado.php">Novo Chamado</a>.</p>

    <div class="ajuda-step"><div class="n">1</div><div><b>Classificar</b><span>Ao abrir um chamado sem nível (vindo do portal), defina a <strong>Complexidade</strong> (Baixa/Média/Alta) — isso calcula o prazo de SLA automaticamente.</span></div></div>
    <div class="ajuda-step"><div class="n">2</div><div><b>Atribuir um responsável</b><span>Antes de sair de "Aberto" para qualquer outro status, o chamado precisa de um responsável — o sistema bloqueia a transição sem isso.</span></div></div>
    <div class="ajuda-step"><div class="n">3</div><div><b>Atualizar o status</b><span>Aberto → Em Andamento/Pendente → Concluído. É possível reabrir um chamado concluído; a reabertura fica registrada no histórico.</span></div></div>
    <div class="ajuda-step"><div class="n">4</div><div><b>Concluir</b><span>Só é permitido concluir com um responsável definido. Preencha a <strong>Resolução</strong> — é o que aparece pro colaborador na avaliação.</span></div></div>
    <div class="ajuda-step"><div class="n">5</div><div><b>Comentar / histórico</b><span>Cada mudança de status e cada comentário ficam na <em>linha do tempo</em> do chamado — visível também (de forma resumida) pro colaborador no Portal.</span></div></div>

    <div class="alert alert-light border mt-3 mb-0" style="font-size:12.5px">
      <i class="bi bi-info-circle me-1 text-primary"></i>
      O status não é livre: o sistema só permite as transições válidas (ex. não dá pra pular de "Aberto" direto pra "Concluído" sem passar por um responsável). Se a mudança for bloqueada, a mensagem de erro explica o motivo.
    </div>
  </div>
</div>

<!-- SUPRIMENTOS -->
<div class="card mb-4 ajuda-sec" id="sec-suprimentos">
  <div class="card-header"><i class="bi bi-box-seam me-2 text-primary"></i><strong>Suprimentos</strong></div>
  <div class="card-body">
    <p class="text-muted" style="font-size:13px">Fluxo do pedido: <strong>Pendente</strong> (aguardando aprovação) → <strong>Aprovado</strong> (aguardando entrega) → <strong>Entregue</strong> (baixa automática no estoque).</p>
    <div class="ajuda-step"><div class="n">1</div><div><b>Aprovar / recusar</b><span>Em <a href="pedidos_suprimentos.php">Pedidos de Suprimentos</a>, revise os itens e quantidades antes de aprovar.</span></div></div>
    <div class="ajuda-step"><div class="n">2</div><div><b>Entregar</b><span>Ao marcar "Entregue", o sistema debita o estoque de cada item automaticamente. Clicar duas vezes não debita duas vezes — a entrega é protegida contra duplicidade.</span></div></div>
    <div class="ajuda-step"><div class="n">3</div><div><b>Estoque</b><span>Em <a href="tipos_suprimentos.php">Tipos de Suprimentos</a> você cadastra os itens e ajusta o estoque manualmente quando necessário (entrada de compra, correção de contagem).</span></div></div>
  </div>
</div>

<!-- INVENTÁRIO -->
<div class="card mb-4 ajuda-sec" id="sec-inventario">
  <div class="card-header"><i class="bi bi-hdd-stack me-2 text-primary"></i><strong>Inventário</strong></div>
  <div class="card-body">
    <div class="ajuda-step"><div class="n">1</div><div><b>Cadastro de equipamentos</b><span>Tipo, marca, modelo, número de série, patrimônio, setor e status (Em Uso, Disponível, Em Manutenção, Descartado).</span></div></div>
    <div class="ajuda-step"><div class="n">2</div><div><b>Importar em lote</b><span>Em <a href="importar_inventario.php">Importar Inventário</a> — aceita o CSV gerado pelo Scanner de Rede (Ferramentas) ou uma planilha própria.</span></div></div>
    <div class="ajuda-step"><div class="n">3</div><div><b>Termos de uso / empréstimo</b><span>Ao emprestar um notebook, celular etc. a alguém, registre o <a href="termos.php">Termo de Uso</a> com data prevista de devolução — o dashboard avisa quando estiver vencendo.</span></div></div>
  </div>
</div>

<!-- IMPRESSORAS -->
<div class="card mb-4 ajuda-sec" id="sec-impressoras">
  <div class="card-header"><i class="bi bi-printer me-2 text-primary"></i><strong>Impressoras</strong></div>
  <div class="card-body">
    <div class="ajuda-step"><div class="n">1</div><div><b>Monitoramento automático</b><span>Um cron consulta as impressoras com IP cadastrado via SNMP (páginas impressas, nível de toner) periodicamente.</span></div></div>
    <div class="ajuda-step"><div class="n">2</div><div><b>Forçar uma coleta agora</b><span>Não precisa esperar o cron — em <a href="ferramentas.php">Ferramentas de TI → Coleta SNMP</a> tem um botão "Coletar agora".</span></div></div>
    <div class="ajuda-step"><div class="n">3</div><div><b>Relatório</b><span>Em <a href="relatorio_impressoras.php">Relatório de Impressoras</a> — toner crítico já vem destacado nas linhas da tabela.</span></div></div>
    <div class="ajuda-step"><div class="n">4</div><div><b>Manutenções</b><span>Registre trocas de peça, limpeza, chamados técnicos pela ficha da impressora — fica no histórico dela.</span></div></div>
  </div>
</div>

<!-- CONTRATOS -->
<div class="card mb-4 ajuda-sec" id="sec-contratos">
  <div class="card-header"><i class="bi bi-file-earmark-text me-2 text-primary"></i><strong>Contratos</strong> <span class="ajuda-tag ajuda-tag-gestora">Gestora+</span></div>
  <div class="card-body">
    <div class="ajuda-step"><div class="n">1</div><div><b>Cadastro</b><span>Fornecedor, valor, periodicidade e vencimento. Marque "Renovação automática" se o contrato se renova sozinho por período (mensal, anual etc.).</span></div></div>
    <div class="ajuda-step"><div class="n">2</div><div><b>Renovação</b><span>Contratos com renovação automática são avançados por um job noturno (não acontece mais ao simplesmente abrir a página). Um histórico de renovações fica registrado no contrato.</span></div></div>
    <div class="ajuda-step"><div class="n">3</div><div><b>Alertas</b><span>O dashboard mostra contratos vencendo nos próximos 30 dias em "Lembretes".</span></div></div>
  </div>
</div>

<!-- FERRAMENTAS -->
<div class="card mb-4 ajuda-sec" id="sec-ferramentas">
  <div class="card-header"><i class="bi bi-tools me-2 text-primary"></i><strong>Ferramentas de TI</strong> <span class="ajuda-tag ajuda-tag-admin">Admin</span></div>
  <div class="card-body">
    <p class="text-muted" style="font-size:13px">Tudo em <a href="ferramentas.php">Ferramentas de TI</a>, acesso restrito a administradores:</p>
    <div class="ajuda-step"><div class="n">1</div><div><b>Scanner de Rede</b><span>Descobre os hosts da rede local (ARP), identifica tipo/marca/hostname e gera um CSV pronto pra importar no inventário.</span></div></div>
    <div class="ajuda-step"><div class="n">2</div><div><b>Verificar Host</b><span>Ping, portas abertas, hostname e MAC de um IP específico — útil pra diagnosticar sem sair do sistema.</span></div></div>
    <div class="ajuda-step"><div class="n">3</div><div><b>Coleta SNMP</b><span>Roda a coleta de impressoras na hora, sem esperar o cron.</span></div></div>
    <div class="ajuda-step"><div class="n">4</div><div><b>Exportar Inventário</b><span>CSV filtrável por setor/tipo/status, pronto pro Excel.</span></div></div>
    <div class="ajuda-step"><div class="n">5</div><div><b>Carga por Técnico</b><span>Quantos chamados cada técnico tem abertos, concluídos no mês e com SLA vencido.</span></div></div>
    <div class="ajuda-step"><div class="n">6</div><div><b>Ligar / Desligar Estações</b><span>Desliga, reinicia ou liga (Wake-on-LAN) PCs Windows da rede. Exige credencial configurada em <code>config.local.php</code> e a estação com firewall/compartilhamento liberado — veja o resultado de cada alvo na caixa de log após executar.</span></div></div>

    <div class="alert alert-light border mt-3 mb-0" style="font-size:12.5px">
      <i class="bi bi-shield-exclamation me-1 text-warning"></i>
      Ligar/desligar estações é uma ação que afeta o trabalho de quem está usando o PC — confira o alvo antes de confirmar, e use uma carência (segundos) que dê tempo da pessoa salvar o que estiver fazendo.
    </div>
  </div>
</div>

<!-- PORTAL -->
<div class="card mb-4 ajuda-sec" id="sec-portal">
  <div class="card-header"><i class="bi bi-box-arrow-up-right me-2 text-primary"></i><strong>Portal do Colaborador</strong></div>
  <div class="card-body">
    <p class="text-muted" style="font-size:13px">
      É a porta de entrada pública (sem login) em <a href="portal.php" target="_blank">portal.php</a>, onde qualquer colaborador abre chamados e pede suprimentos.
      Existe um guia próprio pra eles em <a href="ajuda.php" target="_blank">Ajuda do Portal</a> — vale compartilhar o link com quem tiver dúvida.
    </p>
    <div class="ajuda-step"><div class="n">1</div><div><b>Rastreio por token</b><span>Cada chamado/pedido tem um link de acompanhamento único. Sem esse link, o colaborador só vê o status básico (não a descrição completa nem o histórico detalhado) — é uma proteção de privacidade.</span></div></div>
    <div class="ajuda-step"><div class="n">2</div><div><b>Avaliação</b><span>Assim que o chamado é concluído, o colaborador pode avaliar de 1 a 5 estrelas direto pelo link de acompanhamento.</span></div></div>
  </div>
</div>

<?php if ($ehAdmin): ?>
<!-- ADMINISTRAÇÃO -->
<div class="card mb-4 ajuda-sec" id="sec-admin">
  <div class="card-header"><i class="bi bi-people me-2 text-primary"></i><strong>Administração</strong> <span class="ajuda-tag ajuda-tag-admin">Admin</span></div>
  <div class="card-body">
    <div class="ajuda-step"><div class="n">1</div><div><b>Usuários</b><span>Cadastro de técnicos/gestoras/admins em <a href="usuarios.php">Usuários</a>. Desativar um usuário revoga o acesso — na próxima checagem de sessão (até 60s) ele é deslogado.</span></div></div>
    <div class="ajuda-step"><div class="n">2</div><div><b>Setores</b><span>Lista oficial de setores da clínica, usada nos formulários de chamado/pedido. Renomear um setor aqui atualiza os chamados já vinculados.</span></div></div>
    <div class="ajuda-step"><div class="n">3</div><div><b>Saúde do sistema</b><span>O endpoint <code>health.php</code> (com token) reporta banco, fila de e-mail, cron e disco — usado por monitoramento externo, não é uma tela do menu.</span></div></div>
  </div>
</div>
<?php endif; ?>

<!-- FAQ -->
<div class="card mb-0 ajuda-sec" id="sec-faq">
  <div class="card-header"><i class="bi bi-patch-question me-2 text-primary"></i><strong>Perguntas frequentes</strong></div>
  <div class="card-body">

    <div class="ajuda-faq">
      <div class="ajuda-faq-q">O sistema não deixa eu mudar o status do chamado. Por quê?<i class="bi bi-chevron-down chev"></i></div>
      <div class="ajuda-faq-a">O fluxo de status é controlado: não dá pra sair de "Aberto" sem um responsável definido, nem concluir sem responsável. A mensagem de erro que aparece explica exatamente qual regra bloqueou.</div>
    </div>

    <div class="ajuda-faq">
      <div class="ajuda-faq-q">Entreguei um pedido de suprimento e o estoque não bateu.<i class="bi bi-chevron-down chev"></i></div>
      <div class="ajuda-faq-a">A entrega só debita uma vez por pedido, mesmo com cliques duplicados. Se o número parecer errado, confira o histórico de movimentações do item em Tipos de Suprimentos — pode ter havido um ajuste manual.</div>
    </div>

    <div class="ajuda-faq">
      <div class="ajuda-faq-q">Um chamado do portal chegou sem nível de complexidade.<i class="bi bi-chevron-down chev"></i></div>
      <div class="ajuda-faq-a">É normal — o colaborador não escolhe isso. Classifique manualmente ao abrir o chamado; até lá, o cálculo de SLA fica em espera (o chamado não conta prazo vencido).</div>
    </div>

    <div class="ajuda-faq">
      <div class="ajuda-faq-q">Não consigo desligar uma estação pela ferramenta de rede.<i class="bi bi-chevron-down chev"></i></div>
      <div class="ajuda-faq-a">A mensagem de erro na caixa de log diz o motivo: credencial recusada (usuário/senha não batem naquela máquina), sem permissão (falta liberar admin remoto pra conta local) ou sem conexão (firewall/rede bloqueando a porta 445). Fale com quem administra a rede pra liberar a estação.</div>
    </div>

    <div class="ajuda-faq">
      <div class="ajuda-faq-q">Como o colaborador encontra o chamado dele de novo?<i class="bi bi-chevron-down chev"></i></div>
      <div class="ajuda-faq-a">Se ele abriu pelo mesmo navegador, o Portal guarda uma lista "Abertos neste navegador". Senão, ele precisa do número (formato CHM-2026-00000) ou do link recebido; você também pode localizar pelo nome/setor direto no sistema.</div>
    </div>

  </div>
</div>

<script>
document.querySelectorAll('.ajuda-faq-q').forEach(q => {
  q.addEventListener('click', () => q.parentElement.classList.toggle('open'));
});
</script>

<?php layoutFooter(); ?>
