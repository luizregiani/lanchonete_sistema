<?php
require_once 'funcoes.php';
exigir_login(['admin', 'atendente']);

$validos = ['recebido', 'em preparo', 'saiu para entrega', 'entregue', 'cancelado'];
$f = in_array($_GET['f'] ?? '', $validos, true) ? $_GET['f'] : '';
$st = $pdo->prepare("SELECT * FROM pedidos" . ($f ? " WHERE status = ?" : "") . " ORDER BY id DESC");
$st->execute($f ? [$f] : []);

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="pedidos-' . date('Y-m-d') . '.csv"');

// Proteção contra injeção de fórmula no Excel
function csv_seguro($t) { return preg_match('/^[=+\-@\t\r]/', (string)$t) ? "'" . $t : $t; }

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");
fputcsv($out, ['Pedido', 'Data', 'Cliente', 'Telefone', 'Endereço', 'Pagamento', 'Troco para', 'Subtotal', 'Taxa', 'Total', 'Status'], ';');
foreach ($st as $l) {
    fputcsv($out, [
        $l['id'], date('d/m/Y H:i', strtotime($l['criado_em'])), csv_seguro($l['cliente']), csv_seguro(fmt_tel($l['telefone'])),
        csv_seguro($l['endereco']), rotulo_pagamento($l['pagamento'] ?? 'dinheiro'),
        ($l['troco_para'] ?? null) !== null ? number_format($l['troco_para'], 2, ',', '') : '',
        number_format($l['subtotal'], 2, ',', ''), number_format($l['taxa'], 2, ',', ''),
        number_format($l['total'], 2, ',', ''), $l['status'],
    ], ';');
}
fclose($out);
