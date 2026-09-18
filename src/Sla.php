<?php
declare(strict_types=1);

/**
 * Regras de SLA — fonte única dos números e do cálculo de prazo.
 * Prazo contado em HORÁRIO COMERCIAL (seg–sex, 08h–18h, America/Sao_Paulo).
 */
final class Sla
{
    private const ABRE  = 8;
    private const FECHA = 18;

    /** Horas de SLA por nível de complexidade. null = sem SLA definido. */
    public static function horas(string $nivel): ?int
    {
        return match (true) {
            str_contains($nivel, 'Alta')  => 2,
            str_contains($nivel, 'Média') => 4,
            str_contains($nivel, 'Baixa') => 8,
            default => null,
        };
    }

    /** Timestamp do prazo, consumindo apenas horas úteis a partir de $criadoEm. */
    public static function deadline(string $criadoEm, int $horas): int
    {
        $cursor = (new DateTime('@' . strtotime($criadoEm)))
            ->setTimezone(new DateTimeZone('America/Sao_Paulo'));
        $restante = $horas * 3600;
        $guard = 0;

        while ($restante > 0 && $guard++ < 2000) {
            $dow = (int) $cursor->format('N'); // 1=seg .. 7=dom
            $h   = (int) $cursor->format('G');

            if ($dow >= 6 || $h >= self::FECHA) {
                $cursor->modify('+1 day')->setTime(self::ABRE, 0);
                continue;
            }
            if ($h < self::ABRE) { $cursor->setTime(self::ABRE, 0); continue; }

            $fimDia = (clone $cursor)->setTime(self::FECHA, 0);
            $passo  = min($fimDia->getTimestamp() - $cursor->getTimestamp(), $restante);
            $cursor->modify('+' . $passo . ' seconds');
            $restante -= $passo;
        }
        return $cursor->getTimestamp();
    }

    /** Badge HTML de status do SLA para listagens. */
    public static function badge(string $nivel, string $criadoEm, string $status): string
    {
        $horas = self::horas($nivel);
        if (!$horas || $status === 'Concluído') return '';

        $diff = self::deadline($criadoEm, $horas) - time();

        if ($diff < 0) {
            $atraso = abs($diff);
            $label  = $atraso < 3600 ? floor($atraso / 60) . 'min' : floor($atraso / 3600) . 'h';
            return '<span class="badge badge-pendente ms-1" title="SLA vencido há ' . $label
                 . '"><i class="bi bi-exclamation-circle me-1"></i>+' . $label . '</span>';
        }
        if ($diff < 3600) {
            $label = floor($diff / 60) . 'min';
            return '<span class="badge badge-andamento ms-1" title="SLA vence em ' . $label
                 . '"><i class="bi bi-clock me-1"></i>' . $label . '</span>';
        }
        $label = floor($diff / 3600) . 'h ' . floor(($diff % 3600) / 60) . 'min';
        return '<span class="badge bg-light text-muted border ms-1" title="Prazo SLA">'
             . '<i class="bi bi-clock me-1"></i>' . $label . '</span>';
    }
}
