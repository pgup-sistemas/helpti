<?php
declare(strict_types=1);

/**
 * Desligar / reiniciar / ligar estações da rede local.
 *
 * - Desligar/reiniciar/cancelar: `net rpc` (pacote samba-common-bin) contra o
 *   RPC do Windows, autenticando com a conta de SHUTDOWN_USER/PASS/DOMAIN.
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
        return SHUTDOWN_USER !== '' && SHUTDOWN_PASS !== '';
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
            return ['ok' => false, 'saida' => 'Credenciais de desligamento não configuradas (config.local.php).'];
        }

        $segundos = max(0, min(3600, $segundos));
        $cred     = SHUTDOWN_USER . '%' . SHUTDOWN_PASS;

        $partes = ['net', 'rpc'];
        if ($acao === 'cancelar') {
            $partes[] = 'abortshutdown';
        } else {
            $partes[] = 'shutdown';
            $partes[] = '-f';
            $partes[] = '-t';
            $partes[] = (string) $segundos;
            if ($acao === 'reiniciar') {
                $partes[] = '-r';
            }
            $msg = trim($mensagem);
            if ($msg !== '') {
                $partes[] = '-C';
                $partes[] = mb_substr($msg, 0, 240);
            }
        }
        $partes[] = '-I';
        $partes[] = $ip;
        $partes[] = '-U';
        $partes[] = $cred;
        if (SHUTDOWN_DOMAIN !== '') {
            $partes[] = '-W';
            $partes[] = SHUTDOWN_DOMAIN;
        }

        $cmd = implode(' ', array_map('escapeshellarg', $partes)) . ' 2>&1';

        $saida = shell_exec('timeout 20 ' . $cmd);
        $saida = trim((string) $saida);
        $ok    = $saida === ''
              || stripos($saida, 'completed successfully') !== false
              || stripos($saida, 'succes') !== false;   // "success"/"successfully"

        // Sanitiza a credencial de qualquer eco na saída.
        $saida = str_replace([SHUTDOWN_PASS, $cred], ['***', SHUTDOWN_USER . '%***'], $saida);

        return ['ok' => $ok, 'saida' => $saida !== '' ? $saida : ($ok ? 'OK' : 'Sem resposta do host.')];
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
