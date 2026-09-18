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
      <a href="#sec-termos"><i class="bi bi-file-earmark-check me-1"></i>Termos de Uso</a>
      <a href="#sec-impressoras"><i class="bi bi-printer me-1"></i>Impressoras</a>
      <a href="#sec-contratos"><i class="bi bi-file-earmark-text me-1"></i>Contratos</a>
      <a href="#sec-kb"><i class="bi bi-journal-richtext me-1"></i>Base de Conhecimento</a>
      <a href="#sec-auditoria"><i class="bi bi-clipboard2-check me-1"></i>Auditoria</a>
      <a href="#sec-unidades"><i class="bi bi-geo-alt me-1"></i>Unidades</a>
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

    <div class="ajuda-step"><div class="n">1</div><div><b>Classificar</b><span>Ao abrir um chamado sem nível (vindo do portal), defina a <strong>Complexidade</strong> (Baixa/Média/Alta) — isso calcula o prazo de SLA automaticamente. O prazo também considera o multiplicador da unidade do solicitante.</span></div></div>
    <div class="ajuda-step"><div class="n">2</div><div><b>Atribuir um responsável</b><span>Antes de sair de "Aberto" para qualquer outro status, o chamado precisa de um responsável — o sistema bloqueia a transição sem isso.</span></div></div>
    <div class="ajuda-step"><div class="n">3</div><div><b>Atualizar o status</b><span>Aberto → Em Andamento/Pendente → Concluído. É possível reabrir um chamado concluído; a reabertura fica registrada no histórico.</span></div></div>
    <div class="ajuda-step"><div class="n">4</div><div><b>Concluir</b><span>Só é permitido concluir com um responsável definido. Preencha a <strong>Resolução</strong> — é o que aparece pro colaborador na avaliação.</span></div></div>
    <div class="ajuda-step"><div class="n">5</div><div><b>Comentar / histórico</b><span>Cada mudança de status e cada comentário ficam na <em>linha do tempo</em> do chamado — visível também (de forma resumida) pro colaborador no Portal.</span></div></div>
    <div class="ajuda-step"><div class="n">6</div><div><b>Classificação por IA</b><span>Se a chave Gemini estiver configurada, o sistema sugere categoria, complexidade e setor ao abrir um novo chamado — você pode aceitar ou ajustar antes de salvar.</span></div></div>

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
    <div class="ajuda-step"><div class="n">4</div><div><b>Importação em massa</b><span>Em <a href="importar_tipos_suprimentos.php">Importar Suprimentos</a> você sobe um CSV com vários itens de uma vez — útil na carga inicial do catálogo.</span></div></div>
  </div>
</div>

<!-- INVENTÁRIO -->
<div class="card mb-4 ajuda-sec" id="sec-inventario">
  <div class="card-header"><i class="bi bi-hdd-stack me-2 text-primary"></i><strong>Inventário</strong></div>
  <div class="card-body">
    <div class="ajuda-step"><div class="n">1</div><div><b>Cadastro de equipamentos</b><span>Tipo, marca, modelo, número de série, patrimônio, setor, unidade e status (Em Uso, Disponível, Em Manutenção, Descartado).</span></div></div>
    <div class="ajuda-step"><div class="n">2</div><div><b>Importar em lote</b><span>Em <a href="importar_inventario.php">Importar Inventário</a> — aceita o CSV gerado pelo Scanner de Rede (Ferramentas) ou uma planilha própria.</span></div></div>
    <div class="ajuda-step"><div class="n">3</div><div><b>QR Code</b><span>Cada equipamento tem um QR Code imprimível que leva direto à ficha dele — útil para etiquetagem física do parque.</span></div></div>
    <div class="ajuda-step"><div class="n">4</div><div><b>Termos de uso / empréstimo</b><span>Ao emprestar um notebook, celular etc. a alguém, registre o <a href="termos.php">Termo de Uso</a> com data prevista de devolução — o dashboard avisa quando estiver vencendo.</span></div></div>
  </div>
</div>

<!-- TERMOS DE USO -->
<div class="card mb-4 ajuda-sec" id="sec-termos">
  <div class="card-header"><i class="bi bi-file-earmark-check me-2 text-primary"></i><strong>Termos de Uso</strong></div>
  <div class="card-body">
    <p class="text-muted" style="font-size:13px">Registre formalmente o empréstimo ou cessão de equipamentos para colaboradores — notebook, celular, headset, etc.</p>
    <div class="ajuda-step"><div class="n">1</div><div><b>Criar termo</b><span>Em <a href="termos.php">Termos de Uso</a>, selecione o equipamento do inventário, o colaborador responsável e defina a data prevista de devolução.</span></div></div>
    <div class="ajuda-step"><div class="n">2</div><div><b>Imprimir / assinar</b><span>O sistema gera um documento formatado para impressão e assinatura — acessível pelo ícone de impressão na lista de termos.</span></div></div>
    <div class="ajuda-step"><div class="n">3</div><div><b>Encerrar</b><span>Quando o equipamento for devolvido, marque o termo como encerrado para retirar do painel de alertas de vencimento.</span></div></div>
    <div class="ajuda-step"><div class="n">4</div><div><b>Termos LGPD</b><span>Além dos termos de equipamento, o sistema suporta termos de responsabilidade LGPD configuráveis em <a href="config_termo.php">Configuração de Termos</a>.</span></div></div>
  </div>
</div>

<!-- IMPRESSORAS -->
<div class="card mb-4 ajuda-sec" id="sec-impressoras">
  <div class="card-header"><i class="bi bi-printer me-2 text-primary"></i><strong>Impressoras</strong></div>
  <div class="card-body">
    <div class="ajuda-step"><div class="n">1</div><div><b>Monitoramento automático</b><span>Um cron consulta as impressoras com IP cadastrado via SNMP (páginas impressas, nível de toner) periodicamente. Modelos HP com SNMP bloqueado usam fallback HTTP automático.</span></div></div>
    <div class="ajuda-step"><div class="n">2</div><div><b>Forçar uma coleta agora</b><span>Não precisa esperar o cron — em <a href="ferramentas.php">Ferramentas de TI → Coleta SNMP</a> tem um botão "Coletar agora".</span></div></div>
    <div class="ajuda-step"><div class="n">3</div><div><b>Relatório</b><span>Em <a href="relatorio_impressoras.php">Relatório de Impressoras</a> — toner crítico já vem destacado nas linhas da tabela. Exportável em Excel.</span></div></div>
    <div class="ajuda-step"><div class="n">4</div><div><b>Manutenções</b><span>Registre trocas de peça, limpeza, chamados técnicos pela ficha da impressora — fica no histórico dela com custo e técnico responsável.</span></div></div>
  </div>
</div>

<!-- CONTRATOS -->
<div class="card mb-4 ajuda-sec" id="sec-contratos">
  <div class="card-header"><i class="bi bi-file-earmark-text me-2 text-primary"></i><strong>Contratos</strong> <span class="ajuda-tag ajuda-tag-gestora">Gestora+</span></div>
  <div class="card-body">
    <div class="ajuda-step"><div class="n">1</div><div><b>Cadastro</b><span>Fornecedor, valor, periodicidade e vencimento. Marque "Renovação automática" se o contrato se renova sozinho por período (mensal, anual etc.).</span></div></div>
    <div class="ajuda-step"><div class="n">2</div><div><b>Renovação</b><span>Contratos com renovação automática são avançados por um job noturno. Um histórico de renovações fica registrado em cada contrato.</span></div></div>
    <div class="ajuda-step"><div class="n">3</div><div><b>Alertas</b><span>O dashboard mostra contratos vencendo nos próximos 30 dias em "Lembretes".</span></div></div>
    <div class="ajuda-step"><div class="n">4</div><div><b>Exportação</b><span>Exporte a lista de contratos em Excel direto de <a href="contratos.php">Contratos</a> para envio a gestores ou auditorias.</span></div></div>
  </div>
</div>

<!-- BASE DE CONHECIMENTO -->
<div class="card mb-4 ajuda-sec" id="sec-kb">
  <div class="card-header"><i class="bi bi-journal-richtext me-2 text-primary"></i><strong>Base de Conhecimento</strong></div>
  <div class="card-body">
    <p class="text-muted" style="font-size:13px">Artigos internos para documentar soluções recorrentes, procedimentos e tutoriais — acessível por todos os perfis.</p>
    <div class="ajuda-step"><div class="n">1</div><div><b>Criar artigo</b><span>Em <a href="kb.php">Base de Conhecimento</a>, clique em "Novo Artigo". Defina título, categoria e conteúdo. A busca full-text encontra pelo conteúdo do artigo.</span></div></div>
    <div class="ajuda-step"><div class="n">2</div><div><b>Categorias</b><span>Organize por categorias como "Redes", "Impressoras", "Sistemas" — facilita a navegação para a equipe.</span></div></div>
    <div class="ajuda-step"><div class="n">3</div><div><b>Vincular a chamados</b><span>Ao resolver um chamado com uma solução documentada, referencie o artigo na resolução para padronizar o atendimento.</span></div></div>
  </div>
</div>

<!-- AUDITORIA DE ATIVOS -->
<div class="card mb-4 ajuda-sec" id="sec-auditoria">
  <div class="card-header"><i class="bi bi-clipboard2-check me-2 text-primary"></i><strong>Auditoria de Ativos</strong> <span class="ajuda-tag ajuda-tag-gestora">Gestora+</span></div>
  <div class="card-body">
    <p class="text-muted" style="font-size:13px">Reconcilie o inventário do sistema com a realidade física — identifique equipamentos não localizados, mal cadastrados ou em local errado.</p>
    <div class="ajuda-step"><div class="n">1</div><div><b>Criar ciclo</b><span>Em <a href="ciclos_auditoria.php">Auditoria</a>, inicie um novo ciclo definindo o escopo (todos os ativos ou por setor/tipo).</span></div></div>
    <div class="ajuda-step"><div class="n">2</div><div><b>Registrar</b><span>Para cada equipamento físico encontrado, confirme ou aponte discrepância (localização diferente, estado diferente, não encontrado).</span></div></div>
    <div class="ajuda-step"><div class="n">3</div><div><b>Relatório de auditoria</b><span>Em <a href="relatorio_auditoria.php">Relatório de Auditoria</a> veja o resumo do ciclo: conformes, discrepantes e não localizados.</span></div></div>
  </div>
</div>

<!-- UNIDADES / FILIAIS -->
<div class="card mb-4 ajuda-sec" id="sec-unidades">
  <div class="card-header"><i class="bi bi-geo-alt me-2 text-primary"></i><strong>Unidades / Filiais</strong> <span class="ajuda-tag ajuda-tag-admin">Admin</span></div>
  <div class="card-body">
    <p class="text-muted" style="font-size:13px">Gerencie as filiais atendidas pelo sistema. Cada chamado pode ser associado a uma unidade, e o SLA é ajustado pelo multiplicador da unidade.</p>
    <div class="ajuda-step"><div class="n">1</div><div><b>Cadastrar unidade</b><span>Em <a href="unidades.php">Unidades / Filiais</a>, informe nome, código, endereço completo e gestor responsável.</span></div></div>
    <div class="ajuda-step"><div class="n">2</div><div><b>Busca por CEP</b><span>Digite o CEP no campo e aguarde — o sistema preenche logradouro, bairro, cidade e UF automaticamente via ViaCEP. Se a API falhar, preencha manualmente.</span></div></div>
    <div class="ajuda-step"><div class="n">3</div><div><b>Multiplicador de SLA</b><span>Um valor maior que 1.00 aumenta proporcionalmente o prazo dos chamados dessa unidade (ex: 1.5 = 50% a mais de prazo). Útil para filiais com atendimento remoto.</span></div></div>
    <div class="ajuda-step"><div class="n">4</div><div><b>Usuários por unidade</b><span>Em <a href="usuarios.php">Usuários</a>, vincule técnicos às unidades que eles atendem — controla o que cada um vê nos chamados filtrados por unidade.</span></div></div>
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
    <div class="ajuda-step"><div class="n">4</div><div><b>Topologia de Rede</b><span>Visualização gráfica dos hosts descobertos — equipamentos, switches e roteadores mapeados na rede.</span></div></div>
    <div class="ajuda-step"><div class="n">5</div><div><b>Exportar Inventário</b><span>CSV filtrável por setor/tipo/status, pronto pro Excel.</span></div></div>
    <div class="ajuda-step"><div class="n">6</div><div><b>Carga por Técnico</b><span>Quantos chamados cada técnico tem abertos, concluídos no mês e com SLA vencido.</span></div></div>
    <div class="ajuda-step"><div class="n">7</div><div><b>Ligar / Desligar Estações</b><span>Desliga, reinicia ou liga (Wake-on-LAN) PCs Windows da rede. Exige credencial configurada em <code>config.local.php</code> e a estação com firewall/compartilhamento liberado.</span></div></div>

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
    <div class="ajuda-step"><div class="n">1</div><div><b>Rastreio por token</b><span>Cada chamado/pedido tem um link de acompanhamento único. Sem esse link, o colaborador só vê o status básico — é uma proteção de privacidade.</span></div></div>
    <div class="ajuda-step"><div class="n">2</div><div><b>Avaliação</b><span>Assim que o chamado é concluído, o colaborador pode avaliar de 1 a 5 estrelas direto pelo link de acompanhamento.</span></div></div>
    <div class="ajuda-step"><div class="n">3</div><div><b>Histórico no navegador</b><span>O portal guarda no navegador do colaborador a lista dos chamados abertos por ele — sem precisar do link, desde que use o mesmo dispositivo.</span></div></div>
  </div>
</div>

<?php if ($ehAdmin): ?>
<!-- ADMINISTRAÇÃO -->
<div class="card mb-4 ajuda-sec" id="sec-admin">
  <div class="card-header"><i class="bi bi-people me-2 text-primary"></i><strong>Administração</strong> <span class="ajuda-tag ajuda-tag-admin">Admin</span></div>
  <div class="card-body">
    <div class="ajuda-step"><div class="n">1</div><div><b>Usuários</b><span>Cadastro de técnicos/gestoras/admins em <a href="usuarios.php">Usuários</a>. Desativar um usuário revoga o acesso imediatamente.</span></div></div>
    <div class="ajuda-step"><div class="n">2</div><div><b>Setores</b><span>Lista oficial de setores, usada nos formulários de chamado/pedido. Renomear um setor aqui atualiza os chamados já vinculados.</span></div></div>
    <div class="ajuda-step"><div class="n">3</div><div><b>Unidades / Filiais</b><span>Cadastro de filiais com endereço completo, CEP, multiplicador de SLA e gestor responsável. Veja a seção <a href="#sec-unidades">Unidades</a> acima.</span></div></div>
    <div class="ajuda-step"><div class="n">4</div><div><b>Auditoria de ações</b><span>Em <a href="relatorio_auditoria.php">Auditoria</a> você vê quem fez o quê no sistema — criações, edições, exclusões, trocas de status.</span></div></div>
    <div class="ajuda-step"><div class="n">5</div><div><b>Configuração de Termos</b><span>Em <a href="config_termo.php">Configuração de Termos</a> personalize o texto do termo LGPD exibido no portal e nos formulários de cadastro.</span></div></div>
    <div class="ajuda-step"><div class="n">6</div><div><b>Saúde do sistema</b><span>O endpoint <code>health.php</code> (com token) reporta banco, fila de e-mail, cron e disco — usado por monitoramento externo.</span></div></div>
    <div class="ajuda-step"><div class="n">7</div><div><b>Migrations / Atualizações</b><span>Para aplicar atualizações do banco sem SSH, acesse <code>install.php?token=SEU_TOKEN</code> — lista as migrations pendentes e aplica com um clique.</span></div></div>
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
      <div class="ajuda-faq-q">A busca de CEP não preencheu os campos.<i class="bi bi-chevron-down chev"></i></div>
      <div class="ajuda-faq-a">Certifique-se de digitar os 8 dígitos e sair do campo (Tab ou clicar em outro lugar). O sistema consulta via servidor — se o CEP não for encontrado, uma mensagem aparece abaixo do campo e você pode preencher manualmente sem problema.</div>
    </div>

    <div class="ajuda-faq">
      <div class="ajuda-faq-q">Não consigo desligar uma estação pela ferramenta de rede.<i class="bi bi-chevron-down chev"></i></div>
      <div class="ajuda-faq-a">A mensagem de erro na caixa de log diz o motivo: credencial recusada (usuário/senha não batem naquela máquina), sem permissão (falta liberar admin remoto pra conta local) ou sem conexão (firewall/rede bloqueando a porta 445).</div>
    </div>

    <div class="ajuda-faq">
      <div class="ajuda-faq-q">Como o colaborador encontra o chamado dele de novo?<i class="bi bi-chevron-down chev"></i></div>
      <div class="ajuda-faq-a">Se ele abriu pelo mesmo navegador, o Portal guarda uma lista "Abertos neste navegador". Senão, ele precisa do número (formato CHM-2026-00000) ou do link recebido; você também pode localizar pelo nome/setor direto no sistema.</div>
    </div>

    <div class="ajuda-faq">
      <div class="ajuda-faq-q">O SLA de um chamado está diferente do esperado.<i class="bi bi-chevron-down chev"></i></div>
      <div class="ajuda-faq-a">O prazo de SLA é calculado com base na complexidade do chamado multiplicada pelo fator da unidade do solicitante. Se a unidade tiver multiplicador 1.5, todos os chamados dela têm 50% a mais de prazo. Verifique o multiplicador em Unidades / Filiais.</div>
    </div>

  </div>
</div>

<script>
document.querySelectorAll('.ajuda-faq-q').forEach(q => {
  q.addEventListener('click', () => q.parentElement.classList.toggle('open'));
});
</script>

<?php layoutFooter(); ?>
