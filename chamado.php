<?php
require 'db.php';
requireLogin();
require 'layout.php';
require_once 'sync_inventario.php';

$pdo = db();
$u   = usuario();
$id  = (int)($_GET['id'] ?? 0);

$c = $pdo->prepare("SELECT c.*, u.nome AS resp_nome FROM chamados c
    LEFT JOIN usuarios u ON u.id=c.responsavel_id WHERE c.id=? AND c.deleted_at IS NULL");
$c->execute([$id]);
$chamado = $c->fetch();
if (!$chamado) { header('Location: chamados.php'); exit; }

$tecnicos = $pdo->query("SELECT id,nome FROM usuarios WHERE ativo=1 AND perfil IN ('tecnico','admin') ORDER BY nome")->fetchAll();

// Histórico
$hist = $pdo->prepare("SELECT h.*, u.nome AS usu FROM historico h LEFT JOIN usuarios u ON u.id=h.usuario_id WHERE h.chamado_id=? ORDER BY h.criado_em");
$hist->execute([$id]);
$historico = $hist->fetchAll();

// Avaliação do solicitante (dada via portal público)
$av = $pdo->prepare("SELECT nota, comentario, criado_em FROM avaliacoes WHERE chamado_id=? ORDER BY id DESC LIMIT 1");
$av->execute([$id]);
$avaliacao = $av->fetch();

// POST — atualizar chamado
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerify();
    $action = $_POST['action'] ?? '';

    if ($action === 'atualizar') {
        $resp     = ($_POST['responsavel_id'] ?? '') !== '' ? (int) $_POST['responsavel_id'] : null;
        $nomeResp = $resp ? (array_column($tecnicos, 'nome', 'id')[$resp] ?? '—') : null;

        try {
            $r = ChamadoWorkflow::atualizar($pdo, $chamado, [
                'status'         => $_POST['status'] ?? $chamado['status'],
                'responsavel_id' => $resp,
                'nivel'          => $_POST['nivel'] ?? $chamado['nivel'],
                'resolucao'      => $_POST['resolucao'] ?? '',
            ], $u['id'] ?? null, $nomeResp);

            auditLog('chamado_atualizado', 'chamados', $id,
                "Status: {$r['status']}" . ($r['reaberto'] ? ' (reaberto)' : ''));

            $dadosChamado = [
                'id' => $id, 'numero' => $chamado['numero'], 'setor' => $chamado['setor'],
                'descricao' => $chamado['descricao'], 'solicitante' => $chamado['solicitante'],
                'avaliacao_token' => $chamado['avaliacao_token'] ?? null,
            ];
            if ($r['responsavel_novo']) {
                $st = $pdo->prepare("SELECT email FROM usuarios WHERE id=?");
                $st->execute([$r['responsavel_novo']]);
                if ($email = $st->fetchColumn()) notificarChamado('atribuido', $dadosChamado, $email);
            }
            if ($r['concluido'] && $resp) {
                $st = $pdo->prepare("SELECT email FROM usuarios WHERE id=?");
                $st->execute([$resp]);
                if ($email = $st->fetchColumn()) notificarChamado('concluido', $dadosChamado, $email);
            }

            sync_inventario_status_chamado($id, $r['status']);
            flash('Chamado atualizado com sucesso.');
        } catch (WorkflowException $e) {
            flash($e->getMessage(), 'danger');
        }
        header("Location: chamado.php?id=$id"); exit;
    }

    if ($action === 'comentar') {
        if (ChamadoWorkflow::comentar($pdo, $id, $u['id'] ?? null, $_POST['observacao'] ?? '')) {
            flash('Observação adicionada.');
        }
        header("Location: chamado.php?id=$id"); exit;
    }
}

// Recarregar após update
$c->execute([$id]);
$chamado = $c->fetch();

// Equipamento vinculado
$equipamento_vinculado = null;
if (!empty($chamado['inventario_id'])) {
    $eq = $pdo->prepare("SELECT * FROM inventario WHERE id=?");
    $eq->execute([$chamado['inventario_id']]);
    $equipamento_vinculado = $eq->fetch();
}

layoutHeader('Chamado '.$chamado['numero'], 'chamados');
?>

<div class="page-header">
  <div>
    <a href="chamados.php" class="text-muted small"><i class="bi bi-arrow-left me-1"></i>Chamados</a>
    <h1 class="page-title mt-1"><code style="font-size:16px"><?= h($chamado['numero']) ?></code></h1>
  </div>
  <span class="badge <?php
    $m=['Aberto'=>'badge-aberto','Em Andamento'=>'badge-andamento','Pendente'=>'badge-pendente','Concluído'=>'badge-concluido'];
    echo $m[$chamado['status']]??'bg-secondary text-white';
  ?>" style="font-size:13px;padding:.4rem .85rem"><?= h($chamado['status']) ?></span>
</div>

<div class="row g-3">
  <!-- Coluna esquerda: detalhes -->
  <div class="col-md-8">
    <div class="card mb-3">
      <div class="card-header">Descrição do problema</div>
      <div class="card-body">
        <p class="mb-2" style="font-size:15px"><?= nl2br(h($chamado['descricao'])) ?></p>
        <?php if (!empty($chamado['imagens'])): ?>
          <?php $imgs = json_decode($chamado['imagens'], true); ?>
          <?php if (!empty($imgs)): ?>
            <div class="mt-3 p-2 rounded border" style="background:var(--bg-surface-alt)">
              <div class="fw-semibold text-secondary mb-2" style="font-size:12px">
                <i class="bi bi-images me-1"></i> Imagens anexadas (<?= count($imgs) ?>):
              </div>
              <div class="d-flex gap-2 flex-wrap">
                <?php foreach ($imgs as $img): ?>
                  <a href="<?= h($img) ?>" target="_blank" title="Clique para abrir imagem em tamanho real">
                    <img src="<?= h($img) ?>" alt="Anexo" class="img-thumbnail" style="max-height: 120px; max-width: 120px; object-fit: cover;">
                  </a>
                <?php endforeach; ?>
              </div>
            </div>
          <?php endif; ?>
        <?php endif; ?>
        <hr class="my-3">
        <div class="row g-3">
          <div class="col-sm-6">
            <div class="field-label" style="font-size:12px">Solicitante</div>
            <div class="fw-semibold"><?= h($chamado['solicitante']) ?></div>
          </div>
          <div class="col-sm-6">
            <div class="field-label" style="font-size:12px">Setor</div>
            <div class="fw-semibold"><?= h($chamado['setor']) ?></div>
          </div>
          <div class="col-sm-6">
            <div class="field-label" style="font-size:12px">Aberto em</div>
            <div><?= date('d/m/Y H:i', strtotime($chamado['criado_em'])) ?></div>
          </div>
          <div class="col-sm-6">
            <div class="field-label" style="font-size:12px">Semana</div>
            <div><?= h($chamado['semana']) ?></div>
          </div>
          <div class="col-sm-6">
            <div class="field-label" style="font-size:12px">Origem</div>
            <div><?= h($chamado['origem']) ?></div>
          </div>
        </div>
      </div>
    </div>

    <?php if ($equipamento_vinculado): ?>
    <!-- Equipamento vinculado -->
    <div class="card mb-3 border-info">
      <div class="card-header text-info-emphasis bg-info-subtle">
        <i class="bi bi-pc-display me-2"></i>Equipamento vinculado
        <a href="inventario.php?id=<?= $equipamento_vinculado['id'] ?>" class="btn btn-outline-info btn-xs float-end" style="font-size:11px;padding:1px 8px">Ver no inventário</a>
      </div>
      <div class="card-body py-2">
        <div class="row g-2" style="font-size:13px">
          <div class="col-sm-3"><span class="text-muted">Tipo</span><br><strong><?= h($equipamento_vinculado['tipo']) ?></strong></div>
          <div class="col-sm-3"><span class="text-muted">Marca/Modelo</span><br><?= h(trim($equipamento_vinculado['marca'].' '.$equipamento_vinculado['modelo'])) ?></div>
          <div class="col-sm-3"><span class="text-muted">Nº Série</span><br><?= h($equipamento_vinculado['numero_serie'] ?: '—') ?></div>
          <div class="col-sm-3"><span class="text-muted">Status atual</span><br>
            <span class="badge bg-<?= match($equipamento_vinculado['status']) { 'Em Uso'=>'success', 'Em Manutenção'=>'warning', 'Disponível'=>'info', 'Descartado'=>'danger', default=>'secondary' } ?>"><?= h($equipamento_vinculado['status']) ?></span>
          </div>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <!-- Formulário de atualização -->
    <div class="card mb-3">
      <div class="card-header">Atualizar chamado</div>
      <div class="card-body">
        <form method="post">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="atualizar">
          <div class="row g-3">
            <div class="col-sm-4">
              <label class="form-label fw-semibold" style="font-size:12px">Responsável</label>
              <select name="responsavel_id" class="form-select form-select-sm">
                <option value="">Sem atribuição</option>
                <?php foreach($tecnicos as $t): ?>
                  <option value="<?= $t['id'] ?>" <?= $chamado['responsavel_id']==$t['id']?'selected':'' ?>><?= h($t['nome']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-sm-4">
              <label class="form-label fw-semibold" style="font-size:12px">Nível</label>
              <select name="nivel" class="form-select form-select-sm">
                <?php foreach(['A Definir','Baixa Complexidade','Média Complexidade','Alta Complexidade'] as $n): ?>
                  <option <?= $chamado['nivel']===$n?'selected':'' ?>><?= $n ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-sm-4">
              <label class="form-label fw-semibold" style="font-size:12px">Status</label>
              <select name="status" class="form-select form-select-sm">
                <?php foreach (ChamadoWorkflow::proximos($chamado['status']) as $s): ?>
                  <option <?= $chamado['status']===$s?'selected':'' ?>><?= h($s) ?></option>
                <?php endforeach; ?>
              </select>
              <div class="form-text" style="font-size:11px">Só transições válidas do fluxo são exibidas.</div>
            </div>
            <div class="col-12">
              <div class="d-flex justify-content-between align-items-center mb-1">
                <label class="form-label fw-semibold mb-0" style="font-size:12px">Resolução / Observação técnica</label>
                <button type="button" id="btn-ia-resposta" class="btn btn-outline-secondary btn-xs" style="font-size:11px;padding:2px 10px">
                  <i class="bi bi-stars me-1"></i>Sugerir resposta (IA)
                </button>
              </div>
              <textarea name="resolucao" id="resolucao" class="form-control form-control-sm" rows="3" placeholder="Descreva o que foi feito para resolver..."><?= h($chamado['resolucao'] ?? '') ?></textarea>
              <div id="ia-resposta-status" class="text-muted mt-1" style="font-size:12px;display:none"><i class="bi bi-hourglass-split me-1"></i>Gerando sugestão...</div>
            </div>
          </div>
          <div class="mt-3">
            <button type="submit" class="btn btn-primary btn-sm me-2">
              <i class="bi bi-save me-1"></i>Salvar alterações
            </button>
            <?php if ($chamado['status'] !== 'Concluído'): ?>
            <button type="button" id="btn-concluir-ia" class="btn btn-success btn-sm">
              <i class="bi bi-check-circle me-1"></i>Marcar como concluído
            </button>
            <?php endif; ?>
          </div>
        </form>
      </div>
    </div>

    <!-- Timeline -->
    <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-clock-history me-2 text-primary"></i>Timeline do chamado</span>
        <span class="badge bg-light text-dark border"><?= count($historico) ?> evento(s)</span>
      </div>
      <div class="card-body" style="padding:1.25rem 1.25rem .5rem">

        <?php if ($historico): ?>
        <div style="position:relative;padding-left:36px">
          <!-- Linha vertical -->
          <div style="position:absolute;left:13px;top:0;bottom:0;width:2px;background:var(--border);border-radius:2px"></div>

          <?php foreach (array_reverse($historico) as $ev):
            $acao = $ev['acao'];
            $isComentario = str_starts_with($acao, '💬');
            $isStatus     = str_contains($acao, 'Status:');
            $isAtrib      = str_contains($acao, 'Responsável:') && !str_contains($acao, 'Status:');

            if ($isComentario) {
              $ico = 'bi-chat-left-text-fill'; $cor = '#0ea5e9'; $bg = '#eff6ff';
              $texto = trim(substr($acao, mb_strlen('💬')));
            } elseif ($isStatus) {
              // detecta qual status
              preg_match('/Status:\s*([^|]+)/i', $acao, $ms);
              $st = trim($ms[1] ?? '');
              [$ico, $cor, $bg] = match(true) {
                str_contains($st,'Concluído')    => ['bi-check-circle-fill','#22c55e','#f0fdf4'],
                str_contains($st,'Andamento')    => ['bi-arrow-repeat','#f59e0b','#fffbeb'],
                str_contains($st,'Pendente')     => ['bi-pause-circle-fill','#ef4444','#fef2f2'],
                default                           => ['bi-record-circle','#6366f1','#eef2ff'],
              };
              $texto = $acao;
            } else {
              $ico = 'bi-person-check-fill'; $cor = '#8b5cf6'; $bg = '#f5f3ff';
              $texto = $acao;
            }

            $iniciais = strtoupper(mb_substr($ev['usu'] ?? 'S', 0, 1));
          ?>
          <div style="position:relative;margin-bottom:1.1rem">
            <!-- Bolinha na linha -->
            <div style="position:absolute;left:-30px;top:3px;width:20px;height:20px;border-radius:50%;background:<?= $cor ?>;display:flex;align-items:center;justify-content:center;box-shadow:0 0 0 3px var(--bg-surface),0 0 0 5px <?= $cor ?>44">
              <i class="bi <?= $ico ?>" style="font-size:9px;color:#fff"></i>
            </div>
            <!-- Card do evento -->
            <div style="background:var(--bg-surface-alt);border:1px solid var(--border);border-left:3px solid <?= $cor ?>;border-radius:8px;padding:.6rem .85rem">
              <div class="d-flex justify-content-between align-items-center mb-1" style="gap:8px">
                <div style="display:flex;align-items:center;gap:6px">
                  <span style="width:22px;height:22px;border-radius:50%;background:<?= $cor ?>;color:#fff;font-size:10px;font-weight:700;display:inline-flex;align-items:center;justify-content:center"><?= $iniciais ?></span>
                  <strong style="font-size:12.5px"><?= h($ev['usu'] ?? 'Sistema') ?></strong>
                </div>
                <span style="font-size:10.5px;color:#94a3b8;white-space:nowrap"><?= date('d/m/Y H:i', strtotime($ev['criado_em'])) ?></span>
              </div>
              <div class="ev-texto"><?= nl2br(h($texto)) ?></div>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
        <?php else: ?>
          <div class="text-center text-muted py-4" style="font-size:13px"><i class="bi bi-clock" style="font-size:28px;opacity:.2;display:block;margin-bottom:8px"></i>Nenhum evento registrado ainda.</div>
        <?php endif; ?>

        <!-- Novo comentário -->
        <div class="border-top pt-3 mt-1">
          <form method="post" class="d-flex gap-2">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="comentar">
            <input type="text" name="observacao" class="form-control form-control-sm" placeholder="Adicionar observação ou comentário...">
            <button type="submit" class="btn btn-outline-primary btn-sm px-3"><i class="bi bi-send"></i></button>
          </form>
        </div>
      </div>
    </div>
  </div>

  <!-- Coluna direita: info resumo -->
  <div class="col-md-4">
    <div class="card">
      <div class="card-header">Resumo</div>
      <div class="card-body" style="font-size:13.5px">
        <div class="mb-3">
          <div class="field-label">Status</div>
          <div class="mt-1"><?php
            $m=['Aberto'=>'badge-aberto','Em Andamento'=>'badge-andamento','Pendente'=>'badge-pendente','Concluído'=>'badge-concluido'];
            echo "<span class=\"badge ".($m[$chamado['status']]??'')."\">".h($chamado['status'])."</span>";
          ?></div>
        </div>
        <div class="mb-3">
          <div class="field-label">Responsável</div>
          <div class="mt-1 fw-semibold"><?= h($chamado['resp_nome'] ?? '—') ?></div>
        </div>
        <div class="mb-3">
          <div class="field-label">Nível</div>
          <div class="mt-1"><?php
            $n=$chamado['nivel'];
            if (str_contains($n,'Baixa')) echo '<span class="badge badge-nivel-baixa">Baixa Complexidade</span>';
            elseif (str_contains($n,'Média')) echo '<span class="badge badge-nivel-media">Média Complexidade</span>';
            elseif (str_contains($n,'Alta'))  echo '<span class="badge badge-nivel-alta">Alta Complexidade</span>';
            else echo '<span class="badge bg-light text-muted">A Definir</span>';
          ?></div>
        </div>
        <div class="mb-3">
          <div class="field-label">Semana</div>
          <div class="mt-1"><?= h($chamado['semana']) ?></div>
        </div>
        <?php if ($chamado['resolucao']): ?>
        <div class="mb-3">
          <div class="field-label">Resolução</div>
          <div class="mt-1" style="color:var(--tx-primary)"><?= nl2br(h($chamado['resolucao'])) ?></div>
        </div>
        <?php endif; ?>
        <?php if ($avaliacao): ?>
        <div>
          <div class="field-label">Avaliação do solicitante</div>
          <div class="mt-1">
            <?php for ($i=1;$i<=5;$i++): ?><span style="color:<?= $i<=$avaliacao['nota']?'#f59e0b':'#e5e9f2' ?>;font-size:16px">★</span><?php endfor; ?>
            <span class="text-muted ms-1" style="font-size:11px"><?= date('d/m/Y', strtotime($avaliacao['criado_em'])) ?></span>
          </div>
          <?php if ($avaliacao['comentario']): ?>
            <div class="mt-1 fst-italic" style="color:var(--tx-secondary);font-size:13px">"<?= h($avaliacao['comentario']) ?>"</div>
          <?php endif; ?>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<script>
(function() {
  const chamadoId = <?= $id ?>;
  const csrf = () => document.querySelector('input[name=csrf_token]')?.value || '';

  // ── Sugerir resposta (IA) ──────────────────────────────────────
  document.getElementById('btn-ia-resposta')?.addEventListener('click', function() {
    const status = document.getElementById('ia-resposta-status');
    const textarea = document.getElementById('resolucao');
    this.disabled = true;
    status.style.display = 'block';
    fetch('api_ia.php', {
      method: 'POST',
      headers: {'Content-Type': 'application/x-www-form-urlencoded'},
      body: new URLSearchParams({action:'sugerir_resposta', chamado_id: chamadoId, csrf_token: csrf()})
    })
    .then(r => r.json())
    .then(d => {
      this.disabled = false;
      status.style.display = 'none';
      if (!d.ok) { alert('IA: ' + (d.erro || 'Erro')); return; }
      if (textarea) {
        textarea.value = d.texto;
        textarea.focus();
      }
    })
    .catch(() => { this.disabled = false; status.style.display = 'none'; alert('Erro ao contactar IA.'); });
  });

  // ── Marcar como concluído com auto-resumo ─────────────────────
  document.getElementById('btn-concluir-ia')?.addEventListener('click', function() {
    const textarea = document.getElementById('resolucao');
    const form = this.closest('form');
    const statusSelect = form?.querySelector('[name=status]');

    if (textarea && !textarea.value.trim()) {
      // Gerar resumo antes de submeter
      this.disabled = true;
      this.innerHTML = '<i class="bi bi-hourglass-split me-1"></i>Gerando resumo...';
      fetch('api_ia.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: new URLSearchParams({action:'resumir', chamado_id: chamadoId, csrf_token: csrf()})
      })
      .then(r => r.json())
      .then(d => {
        if (d.ok && d.texto) textarea.value = d.texto;
        if (statusSelect) statusSelect.value = 'Concluído';
        form.submit();
      })
      .catch(() => {
        if (statusSelect) statusSelect.value = 'Concluído';
        form.submit();
      });
    } else {
      if (statusSelect) statusSelect.value = 'Concluído';
      form?.submit();
    }
  });
})();
</script>
<?php layoutFooter(); ?>
