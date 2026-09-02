# 🖥️ Sistema de Chamados TI — Clínica

## Instalação no Hostgator (10 minutos)

### 1. Crie o banco de dados no cPanel
1. Acesse o **cPanel** do Hostgator
2. Vá em **MySQL Databases**
3. Crie um banco: ex. `clinica_ti`
4. Crie um usuário MySQL e anote login/senha
5. Vincule o usuário ao banco com **All Privileges**

### 2. Configure o arquivo `db.php`
Edite o arquivo `db.php` e preencha:
```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'clinica_ti');        // nome do banco
define('DB_USER', 'seu_usuario_mysql');
define('DB_PASS', 'sua_senha_mysql');
define('SITE_URL', 'https://seusite.com.br/ti');
define('CLINICA_NOME', 'Nome da Clínica — T.I.');
```

### 3. Suba os arquivos via FTP ou File Manager
- Pasta recomendada: `public_html/ti/`
- Suba TODOS os arquivos `.php`

### 4. Execute a instalação
Acesse no navegador:
```
https://seusite.com.br/ti/install.php
```
Isso cria as tabelas e os usuários iniciais.

**⚠️ IMEDIATAMENTE após a instalação, DELETE o arquivo `install.php` do servidor!**

### 5. Acesse o sistema
- **Login da equipe:** `https://seusite.com.br/ti/`
- **Formulário público:** `https://seusite.com.br/ti/abrir.php`

---

## Usuários iniciais

Os usuários são criados pelo `setup.php` com senhas geradas automaticamente.
Consulte a saída do setup ou redefina as senhas pelo painel de usuários (`usuarios.php`)
imediatamente após a instalação.

> **Nunca armazene senhas em documentação ou repositório.**

---

## Estratégia WhatsApp (IMPORTANTE)

### Mensagem padrão para o grupo
Cole este texto na descrição do grupo e fixe como mensagem:

```
🖥️ SUPORTE DE TI
Para abrir um chamado, acesse:
👉 https://seusite.com.br/ti/abrir.php

Descreva o problema no formulário.
Não envie mensagens aqui — use o link!
```

### Auto-resposta WhatsApp Business
Configure no WhatsApp Business:
1. **Mensagem de ausência / auto-resposta:**
```
Olá! Para suporte de T.I., abra seu chamado em:
🔗 https://seusite.com.br/ti/abrir.php

Preencha: seu nome, setor e descrição do problema.
Nossa equipe responderá em breve!
```

### QR Code para os setores
1. Acesse https://qr-code-generator.com (gratuito)
2. Gere o QR para `https://seusite.com.br/ti/abrir.php`
3. Imprima e cole na parede de cada setor com o texto:
   **"TI COM PROBLEMA? Escaneie e abra seu chamado"**

---

## Perfis de acesso

| Perfil | Acesso |
|--------|--------|
| **tecnico** | Dashboard, chamados, criar/editar chamados |
| **gestora** | Tudo do técnico + relatórios completos |
| **admin** | Tudo + gerenciamento de usuários |

---

## Arquivos do sistema

```
ti/
├── db.php          ← CONFIGURAR antes de subir
├── install.php     ← Executar UMA vez, depois DELETAR
├── index.php       ← Redireciona login/dashboard
├── login.php       ← Acesso da equipe TI
├── logout.php
├── abrir.php       ← LINK PÚBLICO (WhatsApp/QR Code)
├── dashboard.php   ← Painel principal
├── chamados.php    ← Lista com filtros
├── chamado.php     ← Visualizar/editar chamado
├── novo_chamado.php← Técnico cria manualmente
├── relatorios.php  ← Relatórios para gestão
├── usuarios.php    ← CRUD de usuários (admin)
└── layout.php      ← Template compartilhado
```
