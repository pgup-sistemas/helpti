<?php
declare(strict_types=1);

/**
 * Máquina de estados do chamado (bloqueante).
 *
 * Fluxo:
 *   Aberto ──▶ Em Andamento ──▶ Pendente ──▶ Concluído
 *      │            ▲   │           ▲             │
 *      └────────────┴───┴───────────┴─────────────┘  (reabertura / retorno)
 *
 * Regras duras:
 *  - só transições listadas em TRANSICOES (ou permanecer no mesmo status);
 *  - não conclui sem responsável;
 *  - não sai de "Aberto" (para iniciar atendimento) sem responsável;
 *  - reabertura de "Concluído" é registrada como evento próprio no histórico;
 *  - toda mudança relevante gera linha de histórico.
 */
final class ChamadoWorkflow
{
    public const STATUSES = ['Aberto', 'Em Andamento', 'Pendente', 'Concluído'];

    private const TRANSICOES = [
        'Aberto'       => ['Em Andamento', 'Pendente', 'Concluído'],
        'Em Andamento' => ['Pendente', 'Concluído', 'Aberto'],
        'Pendente'     => ['Em Andamento', 'Concluído', 'Aberto'],
        'Concluído'    => ['Em Andamento', 'Aberto'],
    ];

    public static function podeTransicionar(string $de, string $para): bool
    {
        if ($de === $para) return true;
        return in_array($para, self::TRANSICOES[$de] ?? [], true);
    }

    /** Lista de próximos status válidos (para montar o <select> na view). */
    public static function proximos(string $atual): array
    {
        return array_values(array_unique([$atual, ...(self::TRANSICOES[$atual] ?? [])]));
    }

    /** @throws WorkflowException */
    public static function validar(array $chamado, string $novoStatus, ?int $respId): void
    {
        if (!in_array($novoStatus, self::STATUSES, true)) {
            throw new WorkflowException("Status desconhecido: {$novoStatus}.");
        }
        $atual = (string) $chamado['status'];

        if (!self::podeTransicionar($atual, $novoStatus)) {
            throw new WorkflowException("Transição não permitida: {$atual} → {$novoStatus}.");
        }
        if ($novoStatus === 'Concluído' && !$respId) {
            throw new WorkflowException('Defina um responsável antes de concluir o chamado.');
        }
        if ($atual === 'Aberto' && $novoStatus !== 'Aberto' && !$respId) {
            throw new WorkflowException('Atribua um responsável para iniciar o atendimento.');
        }
    }

    /**
     * Aplica a atualização de forma transacional (chamado + histórico).
     * $dados: ['status'=>string, 'responsavel_id'=>mixed, 'nivel'=>string, 'resolucao'=>?string]
     *
     * @return array{status:string, reaberto:bool, concluido:bool, responsavel_novo:?int}
     * @throws WorkflowException
     */
    public static function atualizar(PDO $pdo, array $chamado, array $dados, ?int $usuarioId, ?string $nomeResp): array
    {
        $novoStatus = (string) $dados['status'];
        $respId = ($dados['responsavel_id'] ?? '') !== '' && $dados['responsavel_id'] !== null
            ? (int) $dados['responsavel_id'] : null;
        $nivel     = (string) ($dados['nivel'] ?? $chamado['nivel']);
        $resolucao = trim((string) ($dados['resolucao'] ?? ''));

        self::validar($chamado, $novoStatus, $respId);

        $atual        = (string) $chamado['status'];
        $respAnterior = $chamado['responsavel_id'] !== null ? (int) $chamado['responsavel_id'] : null;
        $reaberto     = $atual === 'Concluído' && $novoStatus !== 'Concluído';
        $concluido    = $novoStatus === 'Concluído' && $atual !== 'Concluído';
        $chamadoId    = (int) $chamado['id'];

        $pdo->beginTransaction();
        try {
            $pdo->prepare("
                UPDATE chamados
                SET responsavel_id = ?, nivel = ?, status = ?, resolucao = ?,
                    fechado_em = IF(status <> 'Concluído' AND ? = 'Concluído', NOW(), fechado_em)
                WHERE id = ?
            ")->execute([$respId, $nivel, $novoStatus, $resolucao, $novoStatus, $chamadoId]);

            if ($reaberto) {
                self::historico($pdo, $chamadoId, $usuarioId, '🔄 Chamado reaberto (estava Concluído)');
            }
            self::historico(
                $pdo, $chamadoId, $usuarioId,
                "Status: {$novoStatus} | Responsável: " . ($nomeResp ?: '—') . " | Nível: {$nivel}",
            );

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }

        return [
            'status'           => $novoStatus,
            'reaberto'         => $reaberto,
            'concluido'        => $concluido,
            'responsavel_novo' => ($respId && $respId !== $respAnterior) ? $respId : null,
        ];
    }

    /** Comentário livre do técnico → linha de histórico. Retorna false se vazio. */
    public static function comentar(PDO $pdo, int $chamadoId, ?int $usuarioId, string $texto): bool
    {
        $texto = trim($texto);
        if ($texto === '') return false;
        self::historico($pdo, $chamadoId, $usuarioId, '💬 ' . $texto);
        return true;
    }

    private static function historico(PDO $pdo, int $chamadoId, ?int $usuarioId, string $acao): void
    {
        $pdo->prepare("INSERT INTO historico (chamado_id, usuario_id, acao) VALUES (?, ?, ?)")
            ->execute([$chamadoId, $usuarioId, $acao]);
    }
}
