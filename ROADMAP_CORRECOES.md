# Roadmap de Correções — HelpTI

Ordem de execução dos achados da [AUDITORIA_FORENSE.md](AUDITORIA_FORENSE.md).
Regra: correção cirúrgica, sem reescrever, preservando compatibilidade com produção.

Status: ⬜ pendente · 🔧 em andamento · ✅ feito · ⏭️ requer ação no servidor (não-código)

---

## P0 — Emergencial

| # | Achado | Arquivos | Status |
|---|---|---|---|
| P0-1 | `setup.php` acessível → account takeover | `setup.php` | ✅ |
| P0-2 | Arquivos sensíveis serviíveis por HTTP | `.htaccess` (novo), `uploads/`, limpeza repo | ✅ |
| P0-3 | XSS armazenado portal→admin | `portal.php`, `layout.php`, `busca_global.php`, `notificacoes.php` | ✅ |
| P0-4 | Portal lista todos os chamados/pedidos + PII | `portal.php`, `db.php`, migration token | ✅ |
| P0-5 | Verificar exposição em produção + rotacionar segredos | — | ⏭️ |

## P1 — Crítico

| # | Achado | Arquivos | Status |
|---|---|---|---|
| P1-1 | `avaliar.php` IDOR por id sequencial | `avaliar.php`, `portal.php`, migration | ✅ |
| P1-2 | Entrega de pedido não-idempotente (estoque 2×) | `pedidos_suprimentos.php`, `estoque_helpers.php` | ✅ |
| P1-3 | Renovação de contrato em GET, sem lock | `contratos.php`, `bin/cron_contratos.php` (novo) | ✅ |
| P1-4 | `cron_email.php` sem lock/claim | `cron_email.php`, migration | ✅ |
| P1-5 | Chave Gemini na URL + endpoint IA sem throttle | `gemini.php`, `api_ia.php` | ✅ |
| P1-6 | Schema fragmentado, DDL em runtime | `database/migrations/`, `bin/migrate.php` | ✅ |
| P1-7 | `avaliacoes` regras divergentes + UNIQUE | `avaliar.php`, `portal.php`, migration | ✅ |
| P1-8 | Sem rate limit em reset senha / abrir chamado | `esqueci_senha.php`, `portal.php`, `db.php` | ✅ |
| P1-9 | Sem timeout de sessão / privilégio snapshot | `db.php` | ✅ |
| P1-10 | Backup + DR | `bin/backup.sh` (novo), doc | ✅ |

## P2 — Evolução

| # | Achado | Arquivos | Status |
|---|---|---|---|
| P2-1 | `ERRMODE_SILENT` em produção | `db.php` | ✅ |
| P2-2 | `flock` em todos os crons + guarda de SAPI | `cron_*.php`, `snmp_coletar.php`, `bin/` | ✅ |
| P2-3 | SSRF no fallback HP | `snmp_coletar.php` | ✅ |
| P2-4 | CDN sem SRI/CSP | `layout.php`, `portal.php`, `.htaccess` | ✅ |
| P2-5 | SLA relógio-de-parede → horário comercial | `db.php`, `cron_sla.php` | ✅ |
| P2-6 | Máquina de estados de chamado (bloqueante) | `src/ChamadoWorkflow.php`, `chamado.php` | ✅ |
| P2-7 | Extrair camada `src/` (Auth, Session, Sla, Estoque, Mailer, Seq, Log, RateLimiter) | `src/`, `db.php` (fachada) | ✅ |
| P2-8 | `declare(strict_types=1)` | `src/*` (todos), `gemini.php`, `estoque_helpers.php`, `impressoras_helpers.php`, `sync_inventario.php` | ✅ (controllers de página seguem non-strict de propósito) |
| P2-9 | Health check + heartbeat de cron | `health.php` (novo), migration | ✅ |

## P3 — Futuro (condicionado a crescimento)

| # | Achado | Status |
|---|---|---|
| P3-1 | Poda de `impressoras_snapshot` / `audit_log` | ✅ (`bin/cron_poda.php`) |
| P3-2 | Tabela materializada de último snapshot | ✅ (`impressoras_ultimo_snapshot`, migration 0007) |
| P3-3 | FULLTEXT search | ✅ (3 índices, `busca_global.php` híbrido FULLTEXT/LIKE) |
| P3-4 | Fila de e-mail com dead-letter / backoff | ✅ parcial (status `falhou` + `tentativas` + recuperação de worker) |
| P3-5 | `chamados.setor` → FK | ✅ (`fk_chamado_setor`, ON UPDATE CASCADE, migration 0008) |

---

## Migrations criadas (aplicadas e validadas no banco de dev)

- `0001_baseline.sql` — schema canônico (consolidação do antigo setup.php)
- `0002_consolidacao_runtime.sql` — tabelas antes criadas em runtime (estoque_movimentos, password_resets, email_queue, avaliacoes, hosts_rede, contratos_renovacoes, impressoras_snapshot, sequences, login_attempts, audit_log, knowledge_base)
- `0003_auditoria_correcoes.sql` — `chamados.acompanhamento_token`/`avaliacao_token`, `email_queue.status`/`locked_at`/`lote`, `rate_limits`, `cron_runs`, dedup + `UNIQUE` em `estoque_movimentos(pedido_id,tipo_suprimento_id,tipo)`
- `0004_avaliacoes_unique_e_reconciliacao.sql` — `UNIQUE(avaliacoes.chamado_id)` + reconciliação de `estoque_atual`
- `0005_gap_custo_manutencao.sql` — coluna `manutencoes_impressoras.custo` (faltava no banco real)
- `0006_pedidos_token.sql` — `pedidos_suprimentos.acompanhamento_token`

Rodar em produção: `php bin/migrate.php` (idempotente; tolera colunas já existentes).
**Antes:** substituir `0001_baseline.sql` pelo `mysqldump --no-data` real e rodar `php bin/migrate.php --dry-run`.

## Arquivos novos

- `.htaccess` (raiz) — bloqueia acesso a fontes/dumps/config e diretórios internos
- `database/migrations/*.sql` + `bin/migrate.php` (registra em `schema_migrations`)
- `bin/lib_cron.php` — `cron_guard()` (flock + CLI) e `cron_finish()` (heartbeat)
- `bin/cron_contratos.php` — renovação automática de contratos (lock + optimistic)
- `bin/cron_poda.php` — retenção de snapshots / audit_log / email_queue
- `bin/backup.sh` — dump + uploads → destino externo (rclone)
- `health.php` — endpoint de saúde (token)

### Camada de aplicação (`src/`, strict_types)

- `src/bootstrap.php` — autoloader
- `src/Log.php` · `src/Session.php` · `src/Auth.php` · `src/RateLimiter.php`
- `src/Seq.php` · `src/Sla.php` · `src/Estoque.php` · `src/Mailer.php`
- `src/ChamadoWorkflow.php` + `src/WorkflowException.php` — máquina de estados bloqueante

`db.php` e `estoque_helpers.php` viraram **fachada fina** que delega para `src/`;
todo o código existente segue chamando `requireLogin()`, `queueEmail()`, etc.
