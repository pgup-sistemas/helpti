<?php
require 'db.php';
requireGestora();

$pdo = db();
$mes    = (int)($_GET['mes'] ?? date('m'));
$ano    = (int)($_GET['ano'] ?? date('Y'));
$fmt    = $_GET['fmt'] ?? 'xlsx'; // xlsx | csv
$params = ['mes' => $mes, 'ano' => $ano];

$meses_labels = ['Jan','Fev','Mar','Abr','Mai','Jun','Jul','Ago','Set','Out','Nov','Dez'];
$nome_mes = $meses_labels[$mes-1] ?? $mes;

// ── Helpers ────────────────────────────────────────────────
function bold(string $s): string { return "<b>$s</b>"; }
function dt(?string $v): string  { return $v ? date('d/m/Y H:i', strtotime($v)) : '—'; }
function d(?string $v): string   { return $v ? date('d/m/Y', strtotime($v)) : '—'; }

// ── 1. Todos os chamados do mês ────────────────────────────
$stmt = $pdo->prepare("SELECT c.numero, c.descricao, c.setor, c.solicitante,
    u.nome AS responsavel, c.criado_em, c.status, c.nivel, c.semana, c.resolucao
    FROM chamados c LEFT JOIN usuarios u ON u.id=c.responsavel_id
    WHERE MONTH(c.criado_em)=:mes AND YEAR(c.criado_em)=:ano ORDER BY c.criado_em DESC");
$stmt->execute($params);
$sheet1 = [[bold('Nº'),bold('Descrição'),bold('Setor'),bold('Solicitante'),bold('Responsável'),bold('Abertura'),bold('Status'),bold('Nível'),bold('Semana'),bold('Resolução')]];
foreach ($stmt->fetchAll() as $r)
    $sheet1[] = [$r['numero'],$r['descricao'],$r['setor'],$r['solicitante'],$r['responsavel']?:'A Definir',dt($r['criado_em']),$r['status'],$r['nivel'],$r['semana'],$r['resolucao']];
if (count($sheet1)===1) $sheet1[] = ['Nenhum chamado neste mês.','','','','','','','','',''];

// ── 2. Resumo chamados ─────────────────────────────────────
$stmt = $pdo->prepare("SELECT COUNT(*) total,SUM(status='Concluído') concluidos,SUM(status='Aberto') abertos,
    SUM(status='Pendente') pendentes,SUM(nivel='Alta Complexidade') alta,
    SUM(nivel='Média Complexidade') media,SUM(nivel='Baixa Complexidade') baixa
    FROM chamados WHERE MONTH(criado_em)=:mes AND YEAR(criado_em)=:ano");
$stmt->execute($params); $t = $stmt->fetch();
$sheet2 = [
    [bold('Indicador'),bold('Quantidade')],
    ['Total de Chamados',$t['total']??0],['Concluídos',$t['concluidos']??0],
    ['Abertos',$t['abertos']??0],['Pendentes',$t['pendentes']??0],
    ['Alta Complexidade',$t['alta']??0],['Média Complexidade',$t['media']??0],['Baixa Complexidade',$t['baixa']??0],
];

// ── 3. Por setor ───────────────────────────────────────────
$stmt = $pdo->prepare("SELECT setor,COUNT(*) total FROM chamados WHERE MONTH(criado_em)=:mes AND YEAR(criado_em)=:ano GROUP BY setor ORDER BY total DESC");
$stmt->execute($params);
$sheet3 = [[bold('Setor'),bold('Total')]];
foreach ($stmt->fetchAll() as $r) $sheet3[] = [$r['setor'],$r['total']];

// ── 4. Por técnico ─────────────────────────────────────────
$stmt = $pdo->prepare("SELECT u.nome,COUNT(*) total,SUM(c.status='Concluído') concluidos
    FROM chamados c LEFT JOIN usuarios u ON u.id=c.responsavel_id
    WHERE MONTH(c.criado_em)=:mes AND YEAR(c.criado_em)=:ano GROUP BY c.responsavel_id ORDER BY total DESC");
$stmt->execute($params);
$sheet4 = [[bold('Técnico'),bold('Total Atribuído'),bold('Concluídos')]];
foreach ($stmt->fetchAll() as $r) $sheet4[] = [$r['nome']?:'Sem Atribuição',$r['total'],$r['concluidos']??0];

// ── 5. Top solicitantes ────────────────────────────────────
$stmt = $pdo->prepare("SELECT solicitante,setor,COUNT(*) total FROM chamados WHERE MONTH(criado_em)=:mes AND YEAR(criado_em)=:ano GROUP BY solicitante,setor ORDER BY total DESC");
$stmt->execute($params);
$sheet5 = [[bold('Solicitante'),bold('Setor'),bold('Chamados')]];
foreach ($stmt->fetchAll() as $r) $sheet5[] = [$r['solicitante'],$r['setor'],$r['total']];

// ── 6. Pedidos de suprimentos ──────────────────────────────
$stmt = $pdo->prepare("SELECT s.numero,s.setor,s.solicitante,i.nome AS impressora,s.criado_em,s.status,s.observacoes,s.observacoes_entrega
    FROM pedidos_suprimentos s LEFT JOIN impressoras i ON i.id=s.impressora_id
    WHERE MONTH(s.criado_em)=:mes AND YEAR(s.criado_em)=:ano ORDER BY s.criado_em DESC");
$stmt->execute($params);
$sheet6 = [[bold('Nº'),bold('Setor'),bold('Solicitante'),bold('Impressora'),bold('Data'),bold('Status'),bold('Observações'),bold('Notas TI')]];
foreach ($stmt->fetchAll() as $r)
    $sheet6[] = [$r['numero'],$r['setor'],$r['solicitante'],$r['impressora']?:'Geral',dt($r['criado_em']),$r['status'],$r['observacoes'],$r['observacoes_entrega']];
if (count($sheet6)===1) $sheet6[] = ['Nenhum pedido neste mês.','','','','','','',''];

// ── 7. Insumos mais consumidos ─────────────────────────────
$stmt = $pdo->prepare("SELECT COALESCE(ts.nome,pi.descricao_livre,'Outros') AS insumo,SUM(pi.quantidade) AS qtd
    FROM pedidos_suprimentos s JOIN pedidos_suprimentos_itens pi ON s.id=pi.pedido_id
    LEFT JOIN tipos_suprimentos ts ON ts.id=pi.tipo_suprimento_id
    WHERE MONTH(s.criado_em)=:mes AND YEAR(s.criado_em)=:ano GROUP BY insumo ORDER BY qtd DESC");
$stmt->execute($params);
$sheet7 = [[bold('Insumo'),bold('Quantidade')]];
foreach ($stmt->fetchAll() as $r) $sheet7[] = [$r['insumo'],$r['qtd']];
if (count($sheet7)===1) $sheet7[] = ['Nenhum consumo neste mês.',''];

// ── 8. Inventário de TI (snapshot atual) ──────────────────
$rows8 = $pdo->query("SELECT tipo,marca,modelo,numero_serie,patrimonio,setor,status,
    responsavel,data_aquisicao,garantia_ate,valor,observacoes
    FROM inventario ORDER BY tipo,marca,modelo")->fetchAll();
$sheet8 = [[bold('Tipo'),bold('Marca'),bold('Modelo'),bold('S/N'),bold('Patrimônio'),bold('Setor'),bold('Status'),bold('Responsável'),bold('Aquisição'),bold('Garantia até'),bold('Valor (R$)'),bold('Observações')]];
foreach ($rows8 as $r)
    $sheet8[] = [$r['tipo'],$r['marca'],$r['modelo'],$r['numero_serie']?:'—',$r['patrimonio']?:'—',$r['setor'],$r['status'],$r['responsavel']?:'—',d($r['data_aquisicao']),d($r['garantia_ate']),$r['valor']?number_format($r['valor'],2,',','.'):'',$r['observacoes']];
if (count($sheet8)===1) $sheet8[] = ['Nenhum equipamento cadastrado.','','','','','','','','','','',''];

// ── 9. Contratos & Licenças (snapshot atual) ───────────────
$rows9 = $pdo->query("SELECT nome,tipo,fornecedor,numero_contrato,data_inicio,data_vencimento,valor_mensal,valor_total,status,renovacao_auto,alerta_dias,observacoes
    FROM contratos ORDER BY data_vencimento ASC")->fetchAll();
$sheet9 = [[bold('Nome'),bold('Tipo'),bold('Fornecedor'),bold('Nº Contrato'),bold('Início'),bold('Vencimento'),bold('Valor Mensal'),bold('Valor Total'),bold('Status'),bold('Renovação Auto'),bold('Alerta (dias)'),bold('Observações')]];
foreach ($rows9 as $r)
    $sheet9[] = [$r['nome'],$r['tipo'],$r['fornecedor'],$r['numero_contrato']?:'—',d($r['data_inicio']),d($r['data_vencimento']),
        $r['valor_mensal']?number_format($r['valor_mensal'],2,',','.'):'',$r['valor_total']?number_format($r['valor_total'],2,',','.'):'',$r['status'],$r['renovacao_auto']?'Sim':'Não',$r['alerta_dias'],$r['observacoes']];
if (count($sheet9)===1) $sheet9[] = ['Nenhum contrato cadastrado.','','','','','','','','','','',''];

// ── 10. Termos de uso ──────────────────────────────────────
$rows10 = $pdo->query("SELECT t.id,i.tipo,i.marca,i.modelo,i.numero_serie,i.patrimonio,
    t.responsavel_nome,t.responsavel_cpf,t.responsavel_matricula,t.setor,
    t.data_entrega,t.data_prevista_devolucao,t.data_devolucao,t.status,t.condicao_entrega,t.condicao_devolucao,t.observacoes
    FROM termos_uso t JOIN inventario i ON i.id=t.inventario_id ORDER BY t.data_entrega DESC")->fetchAll();
$sheet10 = [[bold('ID'),bold('Tipo'),bold('Marca/Modelo'),bold('S/N'),bold('Patrimônio'),bold('Responsável'),bold('CPF'),bold('Matrícula'),bold('Setor'),bold('Entrega'),bold('Prev. Devolução'),bold('Devolvido em'),bold('Status'),bold('Condição Entrega'),bold('Condição Devolução'),bold('Observações')]];
foreach ($rows10 as $r)
    $sheet10[] = [$r['id'],$r['tipo'],$r['marca'].' '.$r['modelo'],$r['numero_serie']?:'—',$r['patrimonio']?:'—',
        $r['responsavel_nome'],$r['responsavel_cpf']?:'—',$r['responsavel_matricula']?:'—',$r['setor'],
        d($r['data_entrega']),d($r['data_prevista_devolucao']),d($r['data_devolucao']),$r['status'],$r['condicao_entrega'],$r['condicao_devolucao'],$r['observacoes']];
if (count($sheet10)===1) $sheet10[] = ['Nenhum termo cadastrado.','','','','','','','','','','','','','','',''];

// ── 11. Manutenções de impressoras ─────────────────────────
$rows11 = $pdo->query("SELECT p.nome AS impressora,p.marca_modelo,p.setor,
    m.tipo,m.descricao_problema,m.solucao,m.data_manutencao,m.status,
    u.nome AS tecnico, m.custo
    FROM manutencoes_impressoras m JOIN impressoras p ON p.id=m.impressora_id
    LEFT JOIN usuarios u ON u.id=m.tecnico_id ORDER BY m.data_manutencao DESC")->fetchAll();
$sheet11 = [[bold('Impressora'),bold('Marca/Modelo'),bold('Setor'),bold('Tipo'),bold('Problema'),bold('Solução'),bold('Data'),bold('Status'),bold('Técnico'),bold('Custo (R$)')]];
foreach ($rows11 as $r)
    $sheet11[] = [$r['impressora'],$r['marca_modelo'],$r['setor'],$r['tipo'],$r['descricao_problema'],$r['solucao'],d($r['data_manutencao']),$r['status'],$r['tecnico']?:'—',$r['custo']?number_format($r['custo'],2,',','.'):'' ];
if (count($sheet11)===1) $sheet11[] = ['Nenhuma manutenção registrada.','','','','','','','','',''];

// ══════════════════════════════════════════════════════════
// SAÍDA: XLSX ou CSV
// ══════════════════════════════════════════════════════════
$base = "Relatorio_HelpTI_{$nome_mes}_{$ano}";

if ($fmt === 'csv') {
    // CSV: envia apenas chamados (aba principal) em UTF-8 com BOM
    header('Content-Type: text/csv; charset=utf-8');
    header("Content-Disposition: attachment; filename=\"{$base}_Chamados.csv\"");
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF)); // BOM para Excel
    foreach ($sheet1 as $row)
        fputcsv($out, array_map(fn($v) => strip_tags((string)$v), $row), ';');
    fclose($out);
    exit;
}

// XLSX
require_once 'SimpleXLSXGen.php';
$xlsx = Shuchkin\SimpleXLSXGen::fromArray($sheet1,  'Chamados');
$xlsx->addSheet($sheet2,  'Resumo Chamados');
$xlsx->addSheet($sheet3,  'Por Setor');
$xlsx->addSheet($sheet4,  'Por Técnico');
$xlsx->addSheet($sheet5,  'Solicitantes');
$xlsx->addSheet($sheet6,  'Pedidos Suprimentos');
$xlsx->addSheet($sheet7,  'Insumos Consumidos');
$xlsx->addSheet($sheet8,  'Inventário TI');
$xlsx->addSheet($sheet9,  'Contratos e Licenças');
$xlsx->addSheet($sheet10, 'Termos de Uso');
$xlsx->addSheet($sheet11, 'Manutenções Impressoras');
$xlsx->downloadAs("{$base}.xlsx");
