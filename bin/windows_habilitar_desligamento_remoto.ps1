# ============================================================
# HelpTI — habilita desligamento/reinício remoto nesta estação
# Rodar 1x, como Administrador, em cada PC fora do domínio.
# (PCs no domínio ALPHACORP não precisam disso.)
# ============================================================

# --- ajuste aqui se trocar a senha no config.local.php do HelpTI ---
$usuario = "helpti_admin"
$senha   = "Ti@Desliga2026"
# --------------------------------------------------------------

Write-Host "== HelpTI: preparando esta estação para desligamento remoto ==" -ForegroundColor Cyan

# 1) Rede como Privada (senão o Windows bloqueia SMB/RPC por padrão)
try {
    Get-NetConnectionProfile | Where-Object { $_.NetworkCategory -ne 'Private' } |
        Set-NetConnectionProfile -NetworkCategory Private
    Write-Host "[OK] Perfil de rede: Privada"
} catch { Write-Host "[AVISO] Não consegui mudar o perfil de rede: $_" -ForegroundColor Yellow }

# 2) Firewall: libera Compartilhamento de Arquivo e Impressora (PT ou EN)
$grupo = "Compartilhamento de Arquivo e Impressora"
if (-not (Get-NetFirewallRule -DisplayGroup $grupo -ErrorAction SilentlyContinue)) {
    $grupo = "File and Printer Sharing"
}
netsh advfirewall firewall set rule group="$grupo" new enable=Yes | Out-Null
Write-Host "[OK] Firewall liberado ($grupo)"

# 3) Conta local de administração (cria se não existir, senão só reseta a senha)
$existe = Get-LocalUser -Name $usuario -ErrorAction SilentlyContinue
if ($existe) {
    net user $usuario "$senha" | Out-Null
    Write-Host "[OK] Usuário '$usuario' já existia — senha atualizada"
} else {
    net user $usuario "$senha" /add | Out-Null
    Write-Host "[OK] Usuário '$usuario' criado"
}
net localgroup Administradores $usuario /add 2>$null | Out-Null
net localgroup Administrators  $usuario /add 2>$null | Out-Null
Write-Host "[OK] '$usuario' no grupo de Administradores"

# 4) Libera admin remoto para conta local (senão o Windows nega mesmo sendo admin)
reg add "HKLM\SOFTWARE\Microsoft\Windows\CurrentVersion\Policies\System" `
    /v LocalAccountTokenFilterPolicy /t REG_DWORD /d 1 /f | Out-Null
Write-Host "[OK] LocalAccountTokenFilterPolicy = 1"

Write-Host ""
Write-Host "== Pronto. Esta estação já pode ser desligada/reiniciada pelo HelpTI. ==" -ForegroundColor Green
