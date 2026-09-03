<?php
declare(strict_types=1);

/**
 * Desligar / reiniciar / ligar estações da rede local.
 *
 * - Desligar/reiniciar/cancelar: `net rpc` (pacote samba-common-bin) contra o
 *   RPC do Windows. Tenta primeiro a conta de domínio (SHUTDOWN_USER/PASS/DOMAIN)
 *   e, se ela for recusada, tenta a conta local (SHUTDOWN_LOCAL_USER/PASS) —
 *   útil para máquinas em WORKGROUP.
 * - Ligar: Wake-on-LAN (magic packet UDP), não precisa de credenciais.
 *
 * Todos os alvos são restritos a IPs privados (RFC1918) — a ferramenta é para
 * a LAN da clínica, não para a internet.
 */
final class RedePower
{
    /** Ações que exigem `net` + credenciais. */
    public const ACOES_RPC = ['desligar', 'reiniciar', 'cancelar'];
    public const ACOES     = ['desligar', 'reiniciar', 'cancelar', 'ligar'];

    public static function configurado(): bool
    {
        return (SHUTDOWN_USER !== '' && SHUTDOWN_PASS !== '')
            || (SHUTDOWN_LOCAL_USER !== '' && SHUTDOWN_LOCAL_PASS !== '');
    }

    public static function ipPrivado(string $ip): bool
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return false;
        }
        // Só faixas privadas RFC1918: 10/8, 172.16/12, 192.168/16.
        $n = ip2long($ip);
        return ($n & 0xFF000000) === 0x0A000000            // 10.0.0.0/8
            || ($n & 0xFFF00000) === 0xAC100000            // 172.16.0.0/12
            || ($n & 0xFFFF0000) === 0xC0A80000;           // 192.168.0.0/16
    }

    public static function macValido(string $mac): bool
    {
        return (bool) preg_match('/^([0-9a-f]{2}[:-]){5}[0-9a-f]{2}$/i', trim($mac));
    }

    /**
     * @return array{ok:bool, saida:string}
     */
    public static function executar(string $acao, string $ip, int $segundos = 30, string $mensagem = ''): array
    {
        if (!in_array($acao, self::ACOES_RPC, true)) {
            return ['ok' => false, 'saida' => 'Ação inválida.'];
        }
        if (!self::ipPrivado($ip)) {
            return ['ok' => false, 'saida' => 'IP fora da faixa privada da rede local.'];
        }
        if (!self::configurado()) {
            return ['ok' => false, 'saida' => 'Nenhuma credencial configurada (config.local.php).'];
        }

        $segundos = max(0, min(3600, $segundos));

        // Argumentos da ação (sem credencial).
        $base = ['net', 'rpc'];
        if ($acao === 'cancelar') {
            $base[] = 'abortshutdown';
        } else {
            array_push($base, 'shutdown', '-f', '-t', (string) $segundos);
            if ($acao === 'reiniciar') {
                $base[] = '-r';
            }
            $msg = trim($mensagem);
            if ($msg !== '') {
                array_push($base, '-C', mb_substr($msg, 0, 240));
            }
        }
        array_push($base, '-I', $ip);

        // 1ª tentativa: conta de domínio.
        $tentativas = [];
        if (SHUTDOWN_USER !== '' && SHUTDOWN_PASS !== '') {
            $tentativas[] = [SHUTDOWN_USER, SHUTDOWN_PASS, SHUTDOWN_DOMAIN, 'domínio'];
        }
        // 2ª tentativa: conta local (WORKGROUP). Usa o nome NetBIOS da máquina
        // como "domínio" para forçar autenticação na SAM local.
        if (SHUTDOWN_LOCAL_USER !== '' && SHUTDOWN_LOCAL_PASS !== '') {
            $wg = self::netbiosNome($ip);
            $tentativas[] = [SHUTDOWN_LOCAL_USER, SHUTDOWN_LOCAL_PASS, $wg, 'conta local'];
        }

        $ultima = ['ok' => false, 'saida' => 'Sem resposta do host.'];
        foreach ($tentativas as [$user, $pass, $wg, $rotulo]) {
            $ultima = self::rodarNet($base, $user, $pass, $wg, $acao);
            if ($ultima['ok']) {
                if (count($tentativas) > 1 && $rotulo === 'conta local') {
                    $ultima['saida'] .= ' (via conta local)';
                }
                return $ultima;
            }
            // Só cai para a próxima credencial se o erro foi de autenticação.
            $s = strtolower($ultima['saida']);
            $authFail = str_contains($s, 'logon_failure')
                     || (str_contains($s, 'bad smb2') && str_contains($s, 'access_denied'))
                     || str_contains($s, 'no_such_user');
            if (!$authFail) {
                break;
            }
        }

        return self::humanizarErro($ultima, $acao);
    }

    /**
     * @param list<string> $base
     * @return array{ok:bool, saida:string}
     */
    private static function rodarNet(array $base, string $user, string $pass, string $workgroup, string $acao): array
    {
        $partes = $base;
        array_push($partes, '-U', $user . '%' . $pass);
        if ($workgroup !== '') {
            array_push($partes, '-W', $workgroup);
        }
        $cmd = 'timeout 20 ' . implode(' ', array_map('escapeshellarg', $partes)) . ' 2>&1';

        $saida = trim((string) shell_exec($cmd));
        $ok = stripos($saida, 'succe') !== false      // succeeded / successfully
           || stripos($saida, 'aborted') !== false
           || ($saida === '' && $acao === 'cancelar');

        // Nunca deixa a senha vazar na saída.
        $saida = str_replace([$pass, $user . '%' . $pass], ['***', $user . '%***'], $saida);

        return ['ok' => $ok, 'saida' => $saida];
    }

    /**
     * @param array{ok:bool, saida:string} $res
     * @return array{ok:bool, saida:string}
     */
    private static function humanizarErro(array $res, string $acao): array
    {
        if ($res['ok']) {
            return ['ok' => true, 'saida' => $res['saida'] !== '' ? $res['saida'] : 'OK'];
        }
        $s = strtolower($res['saida']);
        if (str_contains($s, 'logon_failure') || (str_contains($s, 'bad smb2') && str_contains($s, 'access_denied')) || str_contains($s, 'no_such_user')) {
            $msg = 'Credencial recusada nesta máquina (usuário/senha não conferem no domínio nem na conta local).';
        } elseif (str_contains($s, 'access_denied')) {
            $msg = 'Autenticou, mas a conta não pode desligar remotamente — precisa ser Administrador local e ter LocalAccountTokenFilterPolicy=1.';
        } elseif (str_contains($s, 'connection_refused') || str_contains($s, 'could not connect') || str_contains($s, 'timed out') || str_contains($s, 'unreachable') || str_contains($s, 'host_unreachable')) {
            $msg = 'Sem conexão — host desligado ou firewall bloqueando RPC/445.';
        } else {
            $msg = $res['saida'] !== '' ? $res['saida'] : 'Falha desconhecida.';
        }
        return ['ok' => false, 'saida' => $msg];
    }

    /** Nome NetBIOS da máquina (registro <20> = File Server, senão <00> único). */
    private static function netbiosNome(string $ip): string
    {
        $out = (string) shell_exec('timeout 5 nmblookup -A ' . escapeshellarg($ip) . ' 2>/dev/null');
        if (preg_match('/^\s*([A-Za-z0-9_-]{1,15})\s+<20>/m', $out, $m)) {
            return strtoupper($m[1]);
        }
        if (preg_match('/^\s*([A-Za-z0-9_-]{1,15})\s+<00>\s+-\s+[BHMP]/m', $out, $m)) {
            return strtoupper($m[1]);
        }
        return '';
    }

    /**
     * Wake-on-LAN: envia o magic packet para o broadcast da rede.
     * @return array{ok:bool, saida:string}
     */
    public static function ligar(string $mac, string $broadcast = '255.255.255.255'): array
    {
        $mac = strtoupper(str_replace('-', ':', trim($mac)));
        if (!self::macValido($mac)) {
            return ['ok' => false, 'saida' => 'MAC inválido.'];
        }
        if (!filter_var($broadcast, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $broadcast = '255.255.255.255';
        }

        $bytes = '';
        foreach (explode(':', $mac) as $par) {
            $bytes .= chr((int) hexdec($par));
        }
        $pacote = str_repeat("\xFF", 6) . str_repeat($bytes, 16);

        $sock = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        if ($sock === false) {
            return ['ok' => false, 'saida' => 'Falha ao criar socket UDP.'];
        }
        socket_set_option($sock, SOL_SOCKET, SO_BROADCAST, 1);
        $enviado = @socket_sendto($sock, $pacote, strlen($pacote), 0, $broadcast, 9);
        socket_close($sock);

        return $enviado === strlen($pacote)
            ? ['ok' => true, 'saida' => "Magic packet enviado para {$mac}."]
            : ['ok' => false, 'saida' => 'Falha ao enviar o magic packet.'];
    }
}
