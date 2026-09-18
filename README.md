# 🖥️ HelpTI

Sistema de gestão de TI para clínica — chamados, inventário, impressoras (com monitoramento SNMP), suprimentos com controle de estoque, contratos, manutenções e hosts de rede. PHP 8.3 + MySQL, sem framework.

---

## Módulos

| Módulo | O que faz |
|---|---|
| **Chamados** | Abertura via portal público ou painel, atribuição, SLA, avaliação do solicitante, classificação por IA (opcional) |
| **Inventário** | Cadastro de equipamentos, categorização automática, importação em massa via CSV, QR Code por item |
| **Impressoras** | Monitoramento de páginas e toner via SNMP (com fallback HTTP para modelos HP com SNMP bloqueado), relatório mensal exportável |
| **Suprimentos** | Catálogo de insumos com estoque, débito automático na entrega de pedidos, entrada manual e importação em massa |
| **Contratos & Licenças** | Vencimento, renovação manual (com escolha de período) e renovação automática, alertas |
| **Manutenções** | Registro de OS de impressoras, técnico responsável, histórico |
| **Hosts de Rede** | Descoberta automática via scanner Python (ARP), reconciliação com o inventário |
| **Relatórios** | Um hub com relatório dedicado por módulo, gráficos e exportação em Excel/CSV própria |
| **Portal do Colaborador** | Formulário público (sem login) para abrir chamados e solicitar suprimentos, com acompanhamento por código |

---

## Instalação

### 1. Banco de dados
Crie um banco MySQL 8 e rode, nesta ordem:
```bash
mysql -u usuario -p nome_do_banco < migrations.sql
mysql -u usuario -p nome_do_banco < missing_tables.sql
```
Tabelas adicionais (`hosts_rede`, `estoque_movimentos`, `contratos_renovacoes` etc.) são criadas automaticamente pelas próprias páginas no primeiro uso.

### 2. Configuração
```bash
cp config.local.php.example config.local.php
```
Edite `config.local.php` com os dados reais do banco, URL da aplicação e (opcional) a `GEMINI_API_KEY`. Esse arquivo **nunca** deve ser commitado — já está no `.gitignore`.

### 3. Instalação inicial (usuário admin)
Acesse `https://seudominio.com.br/setup.php?token=helpti2026` — troque o token no próprio arquivo antes de subir para produção.

**⚠️ Delete `setup.php` do servidor imediatamente após rodar.**

### 4. Crons obrigatórios
```cron
* * * * *      php /caminho/cron_email.php      # fila de e-mail (notificações, alertas)
*/30 * * * *   php /caminho/cron_sla.php        # verifica SLA vencido
0 */4 * * *    php /caminho/snmp_coletar.php    # coleta páginas/toner das impressoras
0 6 * * *      php /caminho/cron_scanner.php    # descoberta de hosts de rede (roda o scanner_rede.py)
```
Sem esses crons o sistema funciona, mas perde e-mails, alertas de SLA, histórico de páginas/toner e a sincronização automática de hosts.

### 5. Acesso
- **Painel (equipe de TI):** `https://seudominio.com.br/`
- **Portal público (colaboradores):** `https://seudominio.com.br/portal.php`

---

## Perfis de acesso

| Perfil | Acesso |
|---|---|
| **técnico** | Dashboard, chamados, impressoras, suprimentos (uso do dia a dia) |
| **gestora** | Tudo do técnico + relatórios, contratos, inventário completo |
| **admin** | Tudo + usuários, setores, ferramentas de TI (scanner, coleta SNMP manual) |

---

## Divulgação do portal público

Sugestão de texto para grupo de WhatsApp / mural dos setores:

```
🖥️ SUPORTE DE TI
Para abrir um chamado ou pedir suprimento, acesse:
👉 https://seudominio.com.br/portal.php

Preencha o formulário — sem necessidade de login.
```

---

## Stack

- **Backend:** PHP 8.3, PDO/MySQL, sem framework
- **Frontend:** Bootstrap 5, Chart.js, Bootstrap Icons
- **Rede:** Python 3 (`scanner_rede.py`) para descoberta ARP, `snmpget` (net-snmp) para coleta de impressoras
- **Exportação:** SimpleXLSXGen para planilhas Excel

---

by **PageUp Sistemas**
