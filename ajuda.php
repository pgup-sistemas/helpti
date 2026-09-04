<?php
// ============================================================
//  ajuda.php — Central de Ajuda pública (sem login)
//  Guia rápido para colaboradores usarem o Portal (portal.php)
// ============================================================
require 'db.php';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
<title>Ajuda — <?= CLINICA_NOME ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
:root{
  --brand:#1D3557; --brand-dark:#457B9D; --brand-light:#A8DADC;
  --bg-color:#F1FAEE; --danger:#E63946;
}
*{box-sizing:border-box}
body{background:var(--bg-color);font-family:'Manrope',system-ui,sans-serif;min-height:100vh;margin:0;color:#1f2937}
a{color:var(--brand)}
.help-body{max-width:720px;margin:0 auto;padding:1.5rem 1rem 3rem}
.help-brand{display:flex;align-items:center;justify-content:center;gap:7px;font-size:13px;font-weight:700;color:var(--brand);opacity:.85;margin-bottom:1rem}
.help-back{display:inline-flex;align-items:center;gap:5px;font-size:13px;font-weight:600;color:#6c757d;text-decoration:none;margin-bottom:1rem}
.help-back:hover{color:var(--brand)}

.help-hero{background:linear-gradient(135deg,var(--brand),var(--brand-dark));color:#fff;border-radius:16px;padding:1.8rem 1.5rem;text-align:center;margin-bottom:1.5rem;box-shadow:0 4px 24px rgba(29,53,87,.18)}
.help-hero .ico{width:52px;height:52px;border-radius:50%;background:rgba(255,255,255,.16);display:flex;align-items:center;justify-content:center;font-size:24px;margin:0 auto .6rem}
.help-hero h1{font-size:19px;font-weight:800;margin:0}
.help-hero p{font-size:13px;opacity:.8;margin:.4rem 0 0}

/* Passo a passo */
.steps{display:grid;grid-template-columns:repeat(3,1fr);gap:.75rem;margin-bottom:1.5rem}
.step-card{background:#fff;border-radius:12px;padding:1rem .8rem;text-align:center;box-shadow:0 2px 12px rgba(0,0,0,.06)}
.step-card .n{width:26px;height:26px;border-radius:50%;background:var(--brand-light);color:var(--brand);font-weight:800;font-size:12px;display:flex;align-items:center;justify-content:center;margin:0 auto .5rem}
.step-card .ico{font-size:20px;color:var(--brand-dark);margin-bottom:.35rem}
.step-card b{display:block;font-size:12.5px;color:#1f2937;margin-bottom:.15rem}
.step-card span{font-size:11px;color:#6b7280;line-height:1.35;display:block}

/* Seção */
.help-section{background:#fff;border-radius:14px;box-shadow:0 2px 16px rgba(0,0,0,.07);overflow:hidden;margin-bottom:1.25rem}
.help-section-head{padding:.9rem 1.25rem;display:flex;align-items:center;gap:10px;font-weight:700;font-size:14.5px;color:var(--brand);border-bottom:1px solid #eef1f6}
.help-section-head i{font-size:17px}
.help-section-body{padding:.4rem 1.25rem}

/* Acordeão simples (FAQ) */
.faq-item{border-bottom:1px solid #f1f5f9}
.faq-item:last-child{border-bottom:none}
.faq-q{width:100%;text-align:left;background:none;border:none;padding:.9rem .1rem;font-size:13.5px;font-weight:600;color:#374151;display:flex;justify-content:space-between;align-items:center;gap:10px;cursor:pointer}
.faq-q:hover{color:var(--brand)}
.faq-q .chev{transition:transform .2s;color:#9ca3af;flex-shrink:0}
.faq-item.open .faq-q .chev{transform:rotate(180deg);color:var(--brand)}
.faq-a{max-height:0;overflow:hidden;transition:max-height .25s ease}
.faq-a-inner{font-size:13px;color:#6b7280;line-height:1.55;padding:0 .1rem 1rem}
.faq-a-inner code{background:#f1f5f9;padding:.1rem .4rem;border-radius:5px;font-size:12px;color:#374151}
.faq-a-inner ul{margin:.3rem 0 0;padding-left:1.1rem}
.faq-a-inner li{margin-bottom:.25rem}

.badge-nivel-baixa{background:#f0fdf4;color:#166534}
.badge-nivel-media{background:#fef9c3;color:#713f12}
.badge-nivel-alta{background:#fef2f2;color:var(--danger)}

.help-cta{text-align:center;margin-top:.5rem}
.help-cta a{display:inline-flex;align-items:center;gap:7px;background:var(--brand);color:#fff;text-decoration:none;font-weight:700;font-size:13.5px;padding:.65rem 1.4rem;border-radius:10px;transition:.15s}
.help-cta a:hover{background:var(--brand-dark)}

.help-footer{text-align:center;margin-top:1.5rem;font-size:11px;color:#9ca3af}

@media(max-width:576px){
  .steps{grid-template-columns:1fr}
  .help-section-body{padding:.2rem 1rem}
}
</style>
</head>
<body>

<div class="help-body">

  <a href="portal.php" class="help-back"><i class="bi bi-arrow-left"></i>Voltar ao Portal</a>

  <div class="help-brand"><i class="bi bi-life-preserver"></i><?= APP_NOME ?> — Central de Ajuda</div>

  <div class="help-hero">
    <div class="ico"><i class="bi bi-question-circle"></i></div>
    <h1>Como podemos ajudar?</h1>
    <p>Guia rápido para abrir chamados de TI e pedir suprimentos.</p>
  </div>

  <!-- Passo a passo: abrir chamado -->
  <div class="steps">
    <div class="step-card">
      <div class="n">1</div>
      <div class="ico"><i class="bi bi-pencil-square"></i></div>
      <b>Descreva o problema</b>
      <span>No Portal, aba "Suporte T.I." → "Abrir". Conte o que está acontecendo.</span>
    </div>
    <div class="step-card">
      <div class="n">2</div>
      <div class="ico"><i class="bi bi-hash"></i></div>
      <b>Guarde o número</b>
      <span>Ao enviar, você recebe um número tipo <code>CHM-2026-00042</code>. Anote ou copie.</span>
    </div>
    <div class="step-card">
      <div class="n">3</div>
      <div class="ico"><i class="bi bi-clock-history"></i></div>
      <b>Acompanhe</b>
      <span>Volte ao Portal → "Acompanhar" e informe o número quando quiser ver o andamento.</span>
    </div>
  </div>

  <!-- FAQ: Chamados de TI -->
  <div class="help-section">
    <div class="help-section-head"><i class="bi bi-headset"></i>Suporte de T.I.</div>
    <div class="help-section-body">

      <div class="faq-item">
        <button class="faq-q">Como abro um chamado?<i class="bi bi-chevron-down chev"></i></button>
        <div class="faq-a"><div class="faq-a-inner">
          Acesse o <a href="portal.php?aba=ti&subaba=abrir">Portal</a>, escolha seu setor e descreva o problema com o máximo
          de detalhes (o que estava fazendo, o que apareceu na tela, desde quando acontece). Quanto mais claro, mais rápido a TI entende
          e resolve. Você pode anexar prints/fotos.
        </div></div>
      </div>

      <div class="faq-item">
        <button class="faq-q">Perdi o número do chamado. E agora?<i class="bi bi-chevron-down chev"></i></button>
        <div class="faq-a"><div class="faq-a-inner">
          Se você abriu pelo mesmo computador/navegador, o Portal mostra uma lista <strong>"Abertos neste navegador"</strong> logo
          abaixo do formulário — é só clicar. Se não aparecer (trocou de computador, limpou o navegador), procure o e-mail de
          confirmação ou peça pra TI localizar pelo seu nome/setor e data aproximada.
        </div></div>
      </div>

      <div class="faq-item">
        <button class="faq-q">O que significa Baixa / Média / Alta Complexidade?<i class="bi bi-chevron-down chev"></i></button>
        <div class="faq-a"><div class="faq-a-inner">
          É a urgência que a TI classifica depois de ler o chamado — você não precisa escolher isso ao abrir.
          <ul>
            <li><span class="badge badge-nivel-baixa">Baixa</span> — não trava seu trabalho, pode esperar.</li>
            <li><span class="badge badge-nivel-media">Média</span> — atrapalha, mas você consegue seguir de outro jeito.</li>
            <li><span class="badge badge-nivel-alta">Alta</span> — impede totalmente o trabalho, tratado com prioridade.</li>
          </ul>
        </div></div>
      </div>

      <div class="faq-item">
        <button class="faq-q">Como sei se meu chamado já foi atendido?<i class="bi bi-chevron-down chev"></i></button>
        <div class="faq-a"><div class="faq-a-inner">
          No Portal → aba "Suporte T.I." → "Acompanhar", digite o número (ou cole o link que você recebeu). A tela mostra a
          situação atual: Aberto, Em Andamento, Pendente ou Concluído, além da linha do tempo do atendimento.
        </div></div>
      </div>

      <div class="faq-item">
        <button class="faq-q">Posso avaliar o atendimento?<i class="bi bi-chevron-down chev"></i></button>
        <div class="faq-a"><div class="faq-a-inner">
          Sim — assim que o chamado for marcado como <strong>Concluído</strong>, a tela de acompanhamento mostra um espaço para
          dar uma nota de 1 a 5 estrelas e deixar um comentário. Isso ajuda a equipe de TI a melhorar o atendimento.
        </div></div>
      </div>

    </div>
  </div>

  <!-- FAQ: Suprimentos -->
  <div class="help-section">
    <div class="help-section-head"><i class="bi bi-box-seam"></i>Suprimentos</div>
    <div class="help-section-body">

      <div class="faq-item">
        <button class="faq-q">Como peço material (toner, papel, etc.)?<i class="bi bi-chevron-down chev"></i></button>
        <div class="faq-a"><div class="faq-a-inner">
          No Portal, aba "Suprimentos" → "Solicitar". Escolha o setor, adicione os itens e a quantidade, e envie. O pedido
          entra na fila para aprovação da TI/gestão.
        </div></div>
      </div>

      <div class="faq-item">
        <button class="faq-q">Como acompanho meu pedido?<i class="bi bi-chevron-down chev"></i></button>
        <div class="faq-a"><div class="faq-a-inner">
          Aba "Suprimentos" → "Acompanhar", com o número do pedido (formato <code>SUP-2026-00012</code>). Mostra se está
          Pendente, Aprovado ou já Entregue.
        </div></div>
      </div>

    </div>
  </div>

  <!-- Outras dúvidas -->
  <div class="help-section">
    <div class="help-section-head"><i class="bi bi-info-circle"></i>Outras dúvidas</div>
    <div class="help-section-body">

      <div class="faq-item">
        <button class="faq-q">Abri o chamado errado / no setor errado. Posso editar?<i class="bi bi-chevron-down chev"></i></button>
        <div class="faq-a"><div class="faq-a-inner">
          O Portal não permite editar depois de enviado. Abra um novo chamado com a informação correta e, se puder, mencione
          o número do chamado anterior na descrição — a equipe de TI ajusta por lá.
        </div></div>
      </div>

      <div class="faq-item">
        <button class="faq-q">É urgente e ninguém me respondeu. O que fazer?<i class="bi bi-chevron-down chev"></i></button>
        <div class="faq-a"><div class="faq-a-inner">
          Confirme primeiro se o chamado foi realmente registrado (tela "Acompanhar"). Se estiver registrado e for realmente
          urgente, procure a equipe de TI diretamente pelo canal interno da clínica além do chamado.
        </div></div>
      </div>

      <div class="faq-item">
        <button class="faq-q">Meus dados ficam salvos em algum lugar?<i class="bi bi-chevron-down chev"></i></button>
        <div class="faq-a"><div class="faq-a-inner">
          O Portal guarda, só no seu navegador, a lista de chamados/pedidos que você abriu por ali (para reencontrá-los depois).
          Nada disso é enviado a terceiros. Os dados do chamado em si (nome, setor, descrição) ficam no sistema da TI da clínica,
          acessíveis apenas pela equipe responsável.
        </div></div>
      </div>

    </div>
  </div>

  <div class="help-cta">
    <a href="portal.php"><i class="bi bi-box-arrow-in-right"></i>Ir para o Portal</a>
  </div>

  <div class="help-footer">by <?= APP_VENDOR ?></div>

</div>

<script>
document.querySelectorAll('.faq-item').forEach(item => {
  const q = item.querySelector('.faq-q');
  const a = item.querySelector('.faq-a');
  q.addEventListener('click', () => {
    const abrir = !item.classList.contains('open');
    item.classList.toggle('open', abrir);
    a.style.maxHeight = abrir ? a.scrollHeight + 'px' : '0px';
  });
});
</script>
</body>
</html>
