# HelpTI — Documentação de Arquitetura, Modelo de Dados e Regras de Negócio

> Documento de referência para auditorias de estrutura, arquitetura e escalabilidade.
> Gerado a partir do estado real do código e do banco em produção/desenvolvimento.
> Última atualização: 03/09/2026.

---

## 1. Visão Geral

HelpTI é um sistema de gestão de TI para uma clínica (Alphaclin), cobrindo:

- Chamados de suporte (help desk)
- Inventário de equipamentos
- Impressoras com monitoramento SNMP (páginas + toner)
- Suprimentos com controle de estoque
- Contratos & licenças (com renovação automática)
- Manutenções de impressoras
- Descoberta e reconciliação de hosts de rede
- Termos de guarda/uso de equipamentos
- Base de conhecimento
- Portal público (sem login) para colaboradores

**Desenvolvido por:** PageUp Sistemas (`APP_VENDOR`).
**Domínio de produção:** `helpti.pageup.net.br` (referência em `config.php`).

---

## 2. Stack Tecnológica

| Camada | Tecnologia |
|---|---|
| Backend | PHP 8.3, procedural (sem framework), PDO/MySQL |
| Banco | MySQL 8.0 |
| Frontend | Bootstrap 5.3.2, Bootstrap Icons 1.11.3, Chart.js 4.4.0 — via CDN, sem build step |
| Rede | Python 3 (`scanner_rede.py`, varredura ARP), `snmpget` (net-snmp) via `shell_exec` |
| Exportação | `SimpleXLSXGen.php` (biblioteca vendorizada, sem Composer) |
| IA opcional | Google Gemini API (classificação de chamados) |
| Servidor dev | `php -S localhost:8080` (embutido) |
| Deploy alvo | Hostgator/cPanel (Apache + PHP-FPM), sem containerização |

**Não há:** gerenciador de dependências (Composer/NPM), testes automatizados, CI/CD, ORM, camada de migração versionada (usa `.sql` aplicado manualmente).

---

## 3. Arquitetura de Código

### 3.1 Padrão geral
Aplicação **monolítica multi-página** (MPA) — cada `.php` na raiz é uma página completa e autocontida: consulta o banco no topo, processa POST se houver, e renderiza HTML inline via `layoutHeader()`/`layoutFooter()`. Não há roteador central nem separação MVC.

```
requisição → arquivo.php
                ├─ require db.php (conexão + helpers globais)
                ├─ requireLogin() / requireGestora() / requireAdmin()
                ├─ processa $_POST (se houver) → csrfVerify() → grava no banco
                ├─ consulta dados (SELECT)
                ├─ require layout.php → layoutHeader() (abre <html>, sidebar, topbar)
                ├─ HTML da página
                └─ layoutFooter() (scripts globais, fecha <html>)
```

### 3.2 Arquivos centrais

| Arquivo | Responsabilidade |
|---|---|
| `db.php` | Conexão PDO, `usuario()`, `requireLogin()/requireGestora()/requireAdmin()`, `csrfField()/csrfVerify()`, `flash()/getFlash()`, `h()` (escape), rate limiting de login, envio de e-mail (fila) |
| `config.php` | Constantes globais (`APP_NOME`, `APP_VENDOR`, `DB_*`, `APP_URL`, `GEMINI_API_KEY` etc.) — lê `config.local.php` se existir |
| `config.local.php` | **Gitignored.** Credenciais reais (banco, e-mail, API key) |
| `layout.php` | `layoutHeader()`/`layoutFooter()` — sidebar, topbar, tema claro/escuro, busca global, notificações, tabelas ordenáveis, `breadcrumb()`, `copyBtn()` |
| `sync_inventario.php` | Sincronização inventário ↔ impressoras ↔ chamados |
| `estoque_helpers.php` | Motor de movimentação de estoque (`estoque_movimentar()`, `estoque_debitar_pedido()`) |
| `impressoras_helpers.php` | Funções compartilhadas de badge/toner entre telas de impressoras |

### 3.3 Convenções de nomenclatura
- `X.php` — listagem/visualização
- `novo_X.php` / `editar_X.php` — formulários de criação/edição
- `excluir_X.php` — exclusão (normalmente um endpoint POST-only)
- `relatorio_X.php` — relatório dedicado com gráficos + export XLSX/CSV próprio
- `exportar_X.php` — exportação isolada (sem UI própria)
- `importar_X.php` — upload em massa via CSV com preview/confirmação
- `cron_X.php` — scripts para execução via cron (não expostos à navegação)
- `api_X.php` — endpoints JSON chamados via `fetch()`

### 3.4 Segurança implementada
- **CSRF:** token de sessão validado em todo POST (`csrfField()`/`csrfVerify()`)
- **Rate limiting de login:** bloqueio após 5 tentativas em 5 min por IP (tabela `login_attempts`)
- **Senhas:** `password_hash()`/`password_verify()` (bcrypt), nunca texto puro
- **Session fixation:** `session_regenerate_id(true)` no login
- **Segredos:** isolados em `config.local.php` (gitignored); `.gitignore` também bloqueia dumps `.sql` e `scan_ultimo.json` (topologia de rede real)
- **Auditoria:** tabela `audit_log` (uso ainda parcial — 13 registros)

---

## 4. Front-End

### 4.1 Abordagem
Sem build step, sem bundler, sem framework JS (nada de React/Vue). Bootstrap 5 + Bootstrap Icons + Chart.js carregados via CDN em cada página; JavaScript é **vanilla**, escrito inline em `<script>` dentro de `layout.php` (funções globais) ou no final de cada página específica. Não há minificação nem versionamento de assets — mudança em CSS/JS exige apenas editar o `.php` e recarregar.

### 4.2 Sistema de tema (claro/escuro)
Implementado com CSS custom properties (`--var`) e um atributo `data-theme` no `<html>`:

```css
:root { --brand:#1D3557; --bg-page:#F1FAEE; --tx-primary:#111111; ... }   /* tema claro (padrão) */
[data-theme="dark"] { --bg-page:#0f172a; --tx-primary:#e2e8f0; ... }     /* tema escuro */
```

- Alternância via botão na topbar → `toggleTheme()` grava a escolha em `localStorage('theme')`
- Paleta de marca: `--brand:#1D3557` (navy), `--brand-dark:#457B9D`, `--brand-light:#A8DADC`, `--danger:#E63946`
- Tokens semânticos (`--bg-surface`, `--tx-primary`, `--tx-muted`, `--border` etc.) são redefinidos por tema — nenhum componente usa cor fixa diretamente, todos consomem os tokens

### 4.3 Layout estrutural (`layout.php`)
```
<aside class="sidebar">          — 230px, recolhível (ícones only), menu por seções (Bootstrap collapse)
<div class="topbar">             — fixa, 60px, busca global + notificações + tema + usuário
<main class="main-wrap">         — conteúdo da página, margin-left compensando a sidebar
```
- Sidebar recolhida: estado salvo em `localStorage`, aplicado **antes da primeira pintura** (script no `<body>`, evita FOUC)
- Seções do menu usam o componente `collapse` nativo do Bootstrap (`data-bs-toggle="collapse"`) — não é JS customizado
- Breadcrumbs: `breadcrumb($items)` (helper PHP em `layout.php`) gera `<nav aria-label="breadcrumb">` padrão Bootstrap, presente na maioria das páginas internas (não em telas de auth/impressão)

### 4.4 Componentes/utilitários JS reutilizáveis (globais, em `layout.php`)
| Função | Faz |
|---|---|
| `toggleTheme()` | Alterna claro/escuro |
| `carregarNotificacoes()` | Poll periódico em `notificacoes.php`, popula o sino |
| Busca global (IIFE) | Autocomplete com debounce, navegação por teclado (setas/Enter/Esc), consome `busca_global.php` |
| Tabelas ordenáveis (IIFE) | Qualquer `<table class="table-sortable">` com `<th data-sort>` vira clicável; suporta `data-sort-type="number"` e `data-sort-value` por célula; ordenação persiste em `localStorage` por página+tabela; cabeçalho fica `position:sticky` ao rolar |
| `copiarTexto(btn, texto)` | Clipboard API + feedback visual (ícone vira ✓ por 1.5s) — usado via helper PHP `copyBtn($valor)` |
| Flash/Toast | `flash()` (PHP, grava em sessão) + Bootstrap Toast no próximo request — padrão POST-redirect-GET |

### 4.5 Padrões de UI recorrentes
- **Cards com abas** (Bootstrap `nav-tabs`/`tab-pane`) para agrupar conteúdo relacionado sem competir por espaço horizontal — usado em `impressora.php` (Especificações / Histórico / Suprimentos)
- **Modais Bootstrap** para ações rápidas sem sair da página: renovação de contrato, entrada de estoque, vínculo manual de host
- **Badges semânticos** por status (`badge-aberto`, `badge-andamento`, `badge-pendente`, `badge-concluido`), com paleta própria por tema
- **Gráficos:** Chart.js, sempre com `maintainAspectRatio:false` dentro de um container de altura fixa (280px) — evita o bug clássico de gráfico "gigante" quando o card é mais largo que alto
- **Exportação:** botões diretos para `?fmt=csv` / `?fmt=xlsx` na própria URL da página (sem tela intermediária)

### 4.6 Responsividade
Grid do Bootstrap (`col-md-*`, `col-lg-*`) usado nas páginas internas; poucos breakpoints customizados. A sidebar vira "gaveta" (`transform:translateX`) abaixo de 768px. Não há um design mobile-first dedicado — o sistema é otimizado para uso em desktop/tablet (perfil de uso: equipe interna de TI), exceto o **portal público**, que é mobile-first de fato (formulários de colaboradores, testado majoritariamente em celular).

### 4.7 Acessibilidade
Nível básico: uso de `aria-label`/`aria-current` nos breadcrumbs, `role="tab"`/`aria-selected` nos componentes de aba do Bootstrap (herdado do framework), `alt`/`title` em ícones interativos. Não há testes de acessibilidade formais nem auditoria WCAG.

### 4.8 Fontes e ícones
- Tipografia do sistema (`'Segoe UI', system-ui, sans-serif`) no admin — sem webfont carregada
- Portal público usa Google Fonts (`Manrope`) para um visual mais cuidado, carregada só naquela página
- Ícones: Bootstrap Icons (`bi-*`) em todo o sistema, via CDN

---

## 5. Modelo de Dados

25 tabelas MySQL. Nem todo relacionamento tem FK declarada no schema — ver seção 5.9 (gaps).

### 5.1 Domínio: Usuários & Acesso

```
usuarios (id, nome, email, senha[hash], perfil[tecnico|gestora|admin], ativo)
login_attempts (ip, tentativas, ultima_tentativa)      — rate limit
password_resets (email, token, usado)                  — recuperação de senha
audit_log (usuario_id→usuarios, acao, tabela, registro_id, detalhe, ip)
```

**Perfis e acesso:**
| Perfil | Alcance |
|---|---|
| `tecnico` | Dashboard, chamados, impressoras, suprimentos (operação diária) |
| `gestora` | + relatórios, contratos, inventário completo |
| `admin` | + usuários, setores, ferramentas de TI (scanner, coleta SNMP manual) |

### 5.2 Domínio: Chamados (Help Desk)

```
chamados (id, numero[UNIQUE], descricao, setor, solicitante, telefone_solicitante,
          responsavel_id→usuarios, nivel[enum], categoria_id→categorias,
          inventario_id[sem FK], status[enum], semana, resolucao, origem[enum],
          imagens[JSON], criado_em, fechado_em, deleted_at, sla_alerta_enviado)
historico (id, chamado_id→chamados, usuario_id→usuarios, acao, criado_em)
avaliacoes (id, chamado_id→chamados [UNIQUE], nota[1-5], comentario, criado_em)
categorias (id, nome, icone, ativo)
```

**Regras de negócio:**
- Numeração: `CHM-XXXXXXX` (gerada via tabela `sequences`, não AUTO_INCREMENT simples — evita colisão em concorrência)
- **Soft delete:** `deleted_at` — chamados excluídos nunca somem fisicamente
- **Timeline (`historico`):** toda mudança de status/responsável/nível gera uma linha, incluindo abertura via portal
- **SLA:** `sla_alerta_enviado` marca chamados já notificados por atraso; `cron_sla.php` varre periodicamente
- **Avaliação:** 1 por chamado (constraint UNIQUE em `chamado_id`), só pode ser dada pelo solicitante via portal público (`avaliar.php`), **visível no admin** em `chamado.php` (card Resumo) e agregada em `relatorio_chamados.php` (nota média + lista)
- **Atribuição:** notificação personalizada no sino ("Chamado X foi atribuído a você") quando `responsavel_id` = usuário logado e `status = 'Aberto'`; card "Meus chamados em aberto" no dashboard para **qualquer perfil** atribuído (não só técnico)
- **Categorização por IA (opcional):** `api_ia.php` chama Gemini server-side (chave nunca exposta ao client) para sugerir descrição/categoria no portal público

### 5.3 Domínio: Inventário

```
inventario (id, tipo, marca, modelo, numero_serie, patrimonio, setor, responsavel_nome,
            status[Em Uso|Disponível|Em Manutenção|Descartado], data_aquisicao, valor,
            garantia_ate, imei, observacoes, ip, mac_address, criado_em, atualizado_em)
tipos_inventario (id, nome[UNIQUE], icone, ativo)      — catálogo de categorias
termos_uso (id, inventario_id→inventario, responsavel_nome, responsavel_cpf,
            responsavel_matricula, setor, data_entrega, data_prevista_devolucao,
            data_devolucao, condicao_entrega, condicao_devolucao, status[Ativo|Devolvido])
```

**Regras de negócio:**
- **Reconciliação automática com hosts de rede:** quando um host detectado (`hosts_rede`) responde ao ping/scan e está vinculado a um `inventario_id`, o status vira `Em Uso` automaticamente (o alerta na tela avisa quando há itens "Disponível" que na verdade estão online)
- **IP/MAC legados:** registros antigos guardavam IP/MAC dentro do campo `observacoes` (texto livre, formato `IP: x.x.x.x | MAC: xx:xx:xx`); `cron_scanner.php` faz a extração e migra para as colunas dedicadas (`ip`, `mac_address`) a cada execução
- **Categorização visual:** dashboard agrupa por `tipo`, com contagem por status (Em Uso ✓ / Disponível ▫ / Manutenção ⚙), ordenável
- **Importação em massa:** `importar_inventario.php` — CSV com preview, detecção de duplicatas (série/patrimônio/MAC já cadastrados ou repetidos no próprio arquivo)
- **QR Code:** `qrcode_equipamento.php` gera etiquetas para abertura de chamado direto no equipamento

### 5.4 Domínio: Impressoras (SNMP)

```
impressoras (id, inventario_id[SEM FK — gap], nome, marca_modelo, numero_serie, ip, setor,
             modelo_toner, status[Ativa|Em Manutenção|Inativa], alerta_toner_em, alerta_offline_em)
impressoras_snapshot (id, impressora_id→impressoras, coletado_em, paginas_total,
                       toner_preto_pct, toner_ciano_pct, toner_magenta_pct, toner_amarelo_pct, raw_snmp)
manutencoes_impressoras (id, impressora_id→impressoras, tecnico_id→usuarios, tipo[Corretiva|Preventiva],
                          descricao_problema, solucao, pecas_trocadas, data_manutencao,
                          status[Pendente|Em Realização|Concluída])
```

**Regras de negócio (as mais complexas do sistema):**
- **Coleta SNMP** (`snmp_coletar.php`, cron a cada 4h): lê OIDs padrão Printer-MIB
  - Páginas: `1.3.6.1.2.1.43.10.2.1.4.1.1`
  - Toner nível/capacidade: `1.3.6.1.2.1.43.11.1.1.9.1.{idx}` / `.8.1.{idx}` (idx 1=preto, 2=ciano, 3=magenta, 4=amarelo)
  - Nome do dispositivo: `1.3.6.1.2.1.25.3.2.1.3.1` (hrDeviceDescr)
- **Toner `-2` = "unknown"**: cartuchos remanufaturados/genéricos não reportam nível real via SNMP — tratado como `NULL` no snapshot (exibido como `—`, não como 0%)
- **Fallback HTTP para HP:** quando SNMP não responde (algumas HPs bloqueiam SNMP por segurança mas mantêm o EWS ativo), o sistema tenta:
  - `http://{ip}/DevMgmt/ConsumableConfigDyn.xml` → nível de toner
  - `http://{ip}/DevMgmt/ProductConfigDyn.xml` → modelo + número de série (`MakeAndModel`, `SerialNumber`)
- **Auto-atualização de nome "Desconhecido":** ao coletar, se nome/marca ainda for "Desconhecido", atualiza `impressoras` e propaga para `inventario.marca/modelo` vinculado
- **Alertas:** e-mail (fila `email_queue`) quando toner ≤15% (`alerta_toner_em`, cooldown 24h) ou impressora para de responder (`alerta_offline_em`, só dispara para quem já respondeu ao menos 1 vez)
- **Vínculo com inventário:** `impressoras.inventario_id` — **não tem FK declarada no banco**, é mantida só por convenção de aplicação (ver gaps)

### 5.5 Domínio: Suprimentos (com controle de estoque)

```
tipos_suprimentos (id, nome, estoque_minimo, estoque_atual, ativo)
pedidos_suprimentos (id, numero[UNIQUE], impressora_id→impressoras, setor, solicitante,
                      status[Pendente|Aprovado|Entregue|Cancelado], observacoes, observacoes_entrega)
pedidos_suprimentos_itens (id, pedido_id→pedidos_suprimentos, tipo_suprimento_id→tipos_suprimentos,
                            descricao_livre, quantidade)
estoque_movimentos (id, tipo_suprimento_id→tipos_suprimentos, tipo[entrada|saida|ajuste],
                     quantidade, motivo, pedido_id, usuario_id, criado_em)
```

**Regras de negócio:**
- **Sincronização de estoque (implementada recentemente):** ao marcar um pedido como "Entregue", o sistema debita automaticamente a quantidade de cada item de `estoque_atual`, nunca deixa negativo (`GREATEST(0, ...)`), e registra o movimento vinculado ao pedido
- **Entrada manual:** botão dedicado (modal) para registrar compra/reposição, sempre auditado
- **Ajuste manual:** editar o número direto na ficha do insumo gera um movimento tipo `ajuste` com o delta, não sobrescreve silenciosamente
- **Importação em massa:** CSV — se o nome já existe (case-insensitive), a linha vira **entrada de estoque** somada; senão cria novo insumo
- **Solicitação:** via portal público (colaborador) ou tela admin dedicada (`pedir_suprimento.php`) vinculada a uma impressora específica

### 5.6 Domínio: Contratos & Licenças

```
contratos (id, tipo[enum], nome, fornecedor, numero_contrato, valor, periodicidade[enum],
           data_inicio, data_vencimento, renovacao_auto, alerta_dias,
           status[Ativo|Vencido|Cancelado|Em Renovação], observacoes, corpo, arquivo_url)
contratos_renovacoes (id, contrato_id→contratos, data_anterior, data_nova,
                       tipo[auto|manual], usuario_id, criado_em)
```

**Regras de negócio:**
- **Renovação automática:** a cada carregamento de `contratos.php`, contratos com `renovacao_auto=1` vencidos têm a data avançada pelo período (`periodicidade`: Mensal +1m, Trimestral +3m, Semestral +6m, Anual +1a) até ficar no futuro — contratos "Único" não podem se renovar sozinhos, viram `Vencido` de verdade
- **Renovação manual:** modal dedicado com escolha de período (ou data personalizada), sempre auditado em `contratos_renovacoes`
- **Notificação:** renovações automáticas das últimas 24h aparecem no sino de notificações

### 5.7 Domínio: Hosts de Rede / Descoberta

```
hosts_rede (id, ip, mac_address[UNIQUE], hostname, fabricante, tipo, marca, portas[JSON],
            rede, setor, inventario_id→inventario, primeiro_visto, ultimo_visto, online)
```

**Regras de negócio:**
- **Fonte:** `scanner_rede.py` (varredura ARP) roda via cron ou sob demanda (`ferramentas.php`), grava `scan_ultimo.json`
- **Reconciliação:** `cron_scanner.php` lê o JSON, faz upsert por `mac_address` (chave estável mesmo se IP mudar via DHCP), tenta vincular a `inventario` por MAC primeiro, depois por IP
- **Offline:** todo host é marcado `online=0` antes de cada scan; só os detectados na varredura atual voltam a `online=1` — hosts com MAC aleatório/local (VMs, VPNs) não conseguem vínculo estável (~2% dos casos observados)

### 5.8 Domínio: Suporte à Configuração

```
setores (id, nome[UNIQUE], ativo)
sequences (name[PK], value)                — gerador de numeração (chamados, suprimentos)
config_termos (chave[UNIQUE], valor)        — texto editável do termo de guarda padrão
knowledge_base (id, titulo, conteudo, categoria_id, publico, visualizacoes, autor_id)
email_queue (id, destinatario, assunto, corpo, tentativas, enviado_em, erro)
```

### 5.9 Gaps de integridade referencial (relevante para auditoria)

Colunas que funcionam como FK na lógica da aplicação mas **não têm constraint declarada no banco** — risco de dados órfãos se algo for excluído fora do fluxo normal:

| Coluna | Deveria referenciar | Risco |
|---|---|---|
| `chamados.inventario_id` | `inventario.id` | Chamado pode ficar "vinculado" a um equipamento já excluído |
| `impressoras.inventario_id` | `inventario.id` | Idem — é o vínculo central do módulo de impressoras |
| `knowledge_base.categoria_id` | `categorias.id` | Menor risco (artigo sem categoria não quebra nada visualmente) |
| `knowledge_base.autor_id` | `usuarios.id` | Idem |

Todas as outras 15 relações identificadas têm `FOREIGN KEY` real no schema (com `ON DELETE CASCADE` ou `SET NULL` conforme o caso).

---

## 6. Cron Jobs (obrigatórios em produção)

```cron
* * * * *      php cron_email.php      # processa fila de e-mail (email_queue)
*/30 * * * *   php cron_sla.php        # verifica chamados com SLA vencido
0 */4 * * *    php snmp_coletar.php    # coleta SNMP de páginas/toner
0 6 * * *      php cron_scanner.php    # roda scanner_rede.py + reconcilia hosts_rede
```

Sem esses crons: o sistema continua funcional, mas perde e-mails, alertas de SLA, histórico de páginas/toner e sincronização de rede.

---

## 7. Integrações Externas

| Integração | Uso | Onde |
|---|---|---|
| SNMP (net-snmp `snmpget`) | Coleta de páginas/toner das impressoras | `snmp_coletar.php`, `api_ferramentas.php` |
| HTTP EWS da HP | Fallback quando SNMP falha (nome, série, toner) | `snmp_coletar.php` |
| Python `scapy`/ARP | Descoberta de hosts na rede | `scanner_rede.py` |
| Google Gemini API | Classificação assistida de chamados (opcional) | `api_ia.php`, `gemini.php` — chave só no servidor |
| SMTP (fila própria) | Notificações por e-mail | `email_queue` + `cron_email.php` |

---

## 8. Fluxos entre Módulos

```
Scanner de Rede (Python) ──► hosts_rede ──► reconcilia ──► inventario (IP/MAC, status)
                                                                │
                                                                ▼
                                              sync_impressoras_from_inventario()
                                                                │
                                                                ▼
                                                          impressoras ◄── snmp_coletar.php (cron)
                                                                │              │
                                                                ▼              ▼
                                                    manutencoes_impressoras  impressoras_snapshot
                                                                │
                                                                ▼
                                              pedidos_suprimentos (vinculado à impressora)
                                                                │
                                                    status='Entregue' ──► debita estoque_movimentos
                                                                              │
                                                                              ▼
                                                                    tipos_suprimentos.estoque_atual
```

```
Portal público (sem login) ──► chamados / pedidos_suprimentos
        │
        ▼
  avaliacoes (pós-conclusão) ──► visível em chamado.php + relatorio_chamados.php
```

---

## 9. Inventário de Páginas (76 arquivos `.php`)

### Autenticação / Público
`login.php`, `logout.php`, `esqueci_senha.php`, `resetar_senha.php`, `setup.php` (instalação inicial, deve ser removido após uso), `portal.php` (hub público sem login), `abrir.php`/`acompanhar.php`/`acompanhar_suprimentos.php` (redirects legados → `portal.php`), `avaliar.php`, `status_chamado.php` (polling AJAX), `qrcode_equipamento.php`

### Dashboard / Núcleo
`dashboard.php`, `index.php`, `busca_global.php`, `notificacoes.php`, `api_stats.php`

### Chamados
`chamados.php`, `chamado.php`, `novo_chamado.php`, `editar_chamado.php`, `excluir_chamado.php`, `categorias.php`

### Inventário
`inventario.php`, `importar_inventario.php`, `tipos_inventario.php`, `termos.php`, `novo_termo.php`, `config_termo.php`, `imprimir_termo.php`

### Impressoras
`impressoras.php`, `impressora.php`, `nova_impressora.php`, `editar_impressora.php`, `excluir_impressora.php`, `impressoras_helpers.php`, `manutencoes.php`, `nova_manutencao.php`, `editar_manutencao.php`, `agenda_manutencoes.php`, `snmp_coletar.php` (cron)

### Suprimentos
`tipos_suprimentos.php`, `novo_tipo_suprimento.php`, `editar_tipo_suprimento.php`, `importar_tipos_suprimentos.php`, `pedidos_suprimentos.php`, `pedir_suprimento.php`, `estoque_helpers.php`

### Contratos
`contratos.php`, `exportar_contratos.php`, `imprimir_contrato.php`

### Rede
`hosts_rede.php`, `cron_scanner.php` (cron), `scanner_rede.py`, `ferramentas.php` (painel admin: scan manual, coleta SNMP manual, verificação de host)

### Relatórios
`relatorios.php` (hub), `relatorio_chamados.php`, `relatorio_suprimentos.php`, `relatorio_manutencoes.php`, `relatorio_contratos.php`, `relatorio_inventario.php`, `relatorio_impressoras.php`, `exportar_relatorio.php` (planilha consolidada de todos os módulos)

### Administração
`usuarios.php`, `setores.php`, `kb.php` (base de conhecimento)

### Infraestrutura
`db.php`, `config.php`, `layout.php`, `sync_inventario.php`, `SimpleXLSXGen.php`, `api_ferramentas.php`, `api_ia.php`, `gemini.php`, `cron_email.php`, `cron_sla.php`

---

## 10. Considerações de Escalabilidade

**Pontos de atenção identificados:**

1. **Sem cache** — toda página consulta o banco a cada request; páginas com múltiplos agregados (dashboard, relatórios) fazem 5-10 queries síncronas cada carregamento
2. **`impressoras_snapshot`** já tem 470 linhas com coleta a cada 4h/impressora — crescimento linear previsível (~47 impressoras × 6/dia = ~282 linhas/dia); sem particionamento ou expurgo automático definido
3. **`hosts_rede`** com 251 registros reflete 1 rede `/22`; se a clínica expandir para múltiplas unidades/redes, o scanner (`scanner_rede.py`) e a UI precisam de um conceito de "site/unidade" que hoje não existe
4. **Sem fila de jobs real** — `email_queue` é uma tabela simples processada por cron a cada minuto; funciona no volume atual (26 linhas), mas não escala para picos (ex: alerta em massa)
3. **SNMP síncrono** — `snmp_coletar.php` varre impressoras uma a uma via `shell_exec`; com o crescimento do parque, o tempo total de execução cresce linearmente (hoje ~47 impressoras, aceitável; centenas exigiriam paralelização)
4. **Sem testes automatizados** — todo o QA observado nesta base foi feito manualmente via browser; qualquer refatoração maior tem risco de regressão silenciosa
5. **Sem versionamento de schema** — `migrations.sql`/`missing_tables.sql` são snapshots, não migrações incrementais; não há como saber com certeza qual script já rodou em produção
6. **FKs faltantes** (seção 5.9) — sob volume alto, dados órfãos ficam mais prováveis e mais difíceis de auditar retroativamente

**Pontos já bem resolvidos:**
- Numeração via tabela `sequences` evita colisão de `numero` em concorrência (mais robusto que `MAX(id)+1`)
- Soft delete em `chamados` preserva histórico para auditoria
- Reconciliação por MAC (não IP) em `hosts_rede` é resiliente a mudanças de DHCP
- Estoque com log de movimentos (`estoque_movimentos`) permite reconstruir o motivo de qualquer saldo, não só o valor atual

---

## 11. Funcionalidades Ativas (checklist de auditoria funcional)

- [x] Login com rate limiting e recuperação de senha
- [x] Chamados: abertura (admin + portal público), atribuição, SLA, avaliação do solicitante
- [x] Notificação personalizada de atribuição (sino + dashboard)
- [x] Inventário: CRUD, importação CSV, QR Code, categorização visual
- [x] Impressoras: monitoramento SNMP + fallback HTTP (HP), auto-nome, manutenções
- [x] Suprimentos: catálogo, pedidos, estoque sincronizado (entrada/saída/ajuste auditados), importação CSV
- [x] Contratos: renovação automática e manual (com histórico), alertas de vencimento
- [x] Hosts de rede: descoberta automática, reconciliação com inventário
- [x] Relatórios dedicados por módulo com export Excel/CSV próprio + hub resumido
- [x] Portal público: abrir chamado, pedir suprimento, acompanhar, avaliar
- [x] Tabelas ordenáveis (client-side, com persistência por localStorage)
- [x] Tema claro/escuro, busca global, breadcrumbs
- [x] Classificação de chamados por IA (opcional, requer `GEMINI_API_KEY`)
- [ ] Testes automatizados — **não implementado**
- [ ] CI/CD — **não implementado**
- [ ] Cache de queries — **não implementado**
- [ ] Multi-unidade/multi-site — **não implementado**
