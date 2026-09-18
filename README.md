# HelpTI

Sistema de gestão de TI — chamados, inventário, impressoras, suprimentos, contratos, manutenções, auditoria de ativos, base de conhecimento e hosts de rede. PHP 8.3 + MariaDB, sem framework, sem Composer.

**Produção:** [helpti.pageup.net.br](https://helpti.pageup.net.br)

---

## Módulos

| Módulo | O que faz |
|---|---|
| **Chamados** | Abertura via portal público ou painel, fluxo de status controlado, SLA por complexidade e multiplicador por unidade, atribuição, comentários, histórico, avaliação do solicitante, classificação por IA (Gemini, opcional) |
| **Portal do Colaborador** | Formulário público sem login para abrir chamados e pedir suprimentos; acompanhamento por token único; avaliação pós-conclusão |
| **Inventário** | Cadastro de equipamentos com tipo, marca, modelo, patrimônio, setor e status; importação em massa via CSV; QR Code por item; auditoria de ativos com ciclos e discrepâncias |
| **Termos de Uso** | Geração de termos de empréstimo/uso de equipamentos com assinatura, vencimento e histórico |
| **Impressoras** | Monitoramento de páginas e toner via SNMP (com fallback HTTP para modelos HP); coleta automática por cron; relatório exportável; histórico de manutenções |
| **Suprimentos** | Catálogo de insumos com estoque; pedidos via portal ou painel; fluxo Pendente → Aprovado → Entregue com baixa automática; importação em massa |
| **Contratos & Licenças** | Fornecedor, valor, periodicidade, vencimento, renovação manual ou automática; histórico de renovações; alertas no dashboard |
| **Manutenções** | Ordens de serviço de impressoras: técnico responsável, tipo de serviço, custo, histórico |
| **Base de Conhecimento** | Artigos internos por categoria, busca full-text, visibilidade por perfil |
| **Auditoria de Ativos** | Ciclos de auditoria, reconciliação inventário × realidade, registro de discrepâncias |
| **Hosts de Rede** | Descoberta automática via scanner Python (ARP), reconciliação com inventário, topologia visual |
| **Unidades / Filiais** | Cadastro de filiais com endereço completo (busca por CEP via ViaCEP), multiplicador de SLA por unidade, gestor responsável |
| **Relatórios** | Hub com relatório dedicado por módulo; gráficos; exportação Excel/CSV |
| **Ferramentas de TI** | Scanner de rede, ping/diagnóstico de host, coleta SNMP manual, exportação de inventário, carga por técnico, ligar/desligar estações Windows (WoL + shutdown remoto) |
| **Administração** | Usuários, setores, configuração de termos LGPD, auditoria de ações, saúde do sistema |

---

## Instalação

### 1. Pré-requisitos

- PHP 8.1+ com extensões: `pdo_mysql`, `mbstring`, `openssl`, `snmp` (opcional)
- MariaDB 10.6+ ou MySQL 8+
- Python 3 + `scapy` (opcional, para scanner de rede)
- `snmpget` / net-snmp (opcional, para monitoramento de impressoras)

### 2. Banco de dados

Crie um banco e rode as migrations pelo script web (sem SSH):

```
https://seudominio.com.br/install.php?token=SEU_TOKEN
```

O token é definido em `config.local.php` como `INSTALL_TOKEN`. As migrations ficam em `database/migrations/` e são aplicadas em ordem pelo script.

### 3. Configuração

```bash
cp config.local.php.example config.local.php
```

Edite `config.local.php` com os dados reais:

```php
// Banco
'DB_HOST'        => 'localhost',
'DB_NAME'        => 'helpti',
'DB_USER'        => 'usuario',
'DB_PASS'        => 'senha',

// Aplicação
'APP_URL'        => 'https://seudominio.com.br',
'APP_EMAIL_FROM' => 'HelpTI <noreply@seudominio.com.br>',

// Opcional
'GEMINI_API_KEY' => '',   // classificação de chamados por IA
'INSTALL_TOKEN'  => '',   // token para install.php
'HEALTH_TOKEN'   => '',   // token para health.php
```

`config.local.php` **nunca** deve ser commitado — já está no `.gitignore`.

### 4. Usuário admin inicial

Acesse após rodar as migrations:

```
https://seudominio.com.br/setup_admin.php?token=SEU_TOKEN
```

### 5. Crons recomendados

```cron
* * * * *      php /caminho/cron_email.php       # fila de e-mail (notificações, alertas de SLA)
*/30 * * * *   php /caminho/cron_sla.php         # verifica SLA vencido
0 */4 * * *    php /caminho/snmp_coletar.php     # coleta páginas/toner das impressoras
0 6 * * *      php /caminho/cron_scanner.php     # descoberta de hosts de rede
0 2 * * *      php /caminho/bin/cron_contratos.php  # renovação automática de contratos
```

Sem esses crons o sistema funciona normalmente, mas perde e-mails automáticos, alertas de SLA, histórico de toner/páginas, descoberta de hosts e renovação automática de contratos.

### 6. Acesso

| URL | O quê |
|---|---|
| `https://seudominio.com.br/` | Painel da equipe de TI (requer login) |
| `https://seudominio.com.br/portal.php` | Portal público dos colaboradores (sem login) |
| `https://seudominio.com.br/health.php?token=TOKEN` | Endpoint de saúde para monitoramento externo |

---

## Perfis de acesso

| Perfil | Acesso |
|---|---|
| **tecnico** | Dashboard, chamados, impressoras, suprimentos, inventário (leitura), base de conhecimento |
| **gestora** | Tudo do técnico + relatórios, contratos, inventário completo, termos, auditoria |
| **admin** | Tudo + usuários, setores, ferramentas de TI, unidades, configurações do sistema |

---

## Stack

| Camada | Tecnologia |
|---|---|
| Backend | PHP 8.3, PDO/MariaDB, sem framework, sem Composer |
| Frontend | Bootstrap 5.3, Bootstrap Icons 1.11, Chart.js, CSS tokens customizados |
| Rede | Python 3 + Scapy (scanner ARP), net-snmp (coleta impressoras) |
| Exportação | SimpleXLSXGen (planilhas Excel sem dependências) |
| CEP / Endereço | ViaCEP (via proxy PHP server-side) + IBGE Municípios (autocomplete) |
| IA (opcional) | Google Gemini API (classificação automática de chamados) |

---

## Estrutura de diretórios

```
helpti/
├── src/                  # Classes PHP (Auth, Session, Mailer, Sla, Estoque…)
├── database/migrations/  # SQL versionado (0001_baseline … 0015_…)
├── bin/                  # Scripts CLI (migrate, crons, seeds, diagnósticos)
├── storage/scans/        # CSVs de scanner de rede (fora do git)
├── uploads/              # Arquivos enviados (fora do git)
├── logs/                 # Logs de aplicação (fora do git)
├── cep.js                # Busca de CEP + autocomplete de cidades (reutilizável)
├── cep_proxy.php         # Proxy server-side para ViaCEP
├── install.php           # Aplicador de migrations via browser
├── layout.php            # Layout admin (sidebar, topbar, footer)
├── design-tokens.css     # Tokens de cor/tipografia (tema claro e escuro)
└── config.local.php      # Credenciais locais (não commitado)
```

---

## Divulgação do portal para colaboradores

```
🖥️ SUPORTE DE TI
Para abrir um chamado ou pedir suprimento, acesse:
👉 https://seudominio.com.br/portal.php

Preencha o formulário — sem necessidade de login.
Guarde o número do chamado para acompanhar.
```

---

by **PageUp Sistemas** — pageupsistemas@gmail.com
