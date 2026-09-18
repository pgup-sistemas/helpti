<?php
declare(strict_types=1);

/** Transição de estado recusada por violar as regras do fluxo de chamado. */
final class WorkflowException extends RuntimeException {}
