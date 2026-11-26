<?php
require_once 'funcoes.php';
exigir_login();
$status_validos = ['recebido', 'em preparo', 'saiu para entrega', 'entregue', 'cancelado'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validar();
    $novo = $_POST['status'] ?? '';
    $pid  = (int)($_POST['id'] ?? 0);
    if ($pid > 0 && in_array($novo, $status_validos, true)) {
        $pdo->prepare("UPDATE pedidos SET status = ? WHERE id = ?")->execute([$novo, $pid]);
        registrar($pdo, 'status', "pedido $pid → $novo");
    }
    header('Location: painel.php?' . http_build_query(['f' => $_GET['f'] ?? '', 'p' => $_GET['p'] ?? 1])); exit;
}

$f   = in_array($_GET['f'] ?? '', $status_validos, true) ? $_GET['f'] : '';
$pag = max(1, (int)($_GET['p'] ?? 1));
$st = $pdo->prepare("SELECT COUNT(*) FROM pedidos" . ($f ? " WHERE status = ?" : ""));
$st->execute($f ? [$f] : []);
$paginas = max(1, (int)ceil($st->fetchColumn() / POR_PAGINA));
$pag = min($pag, $paginas);

$st = $pdo->prepare("SELECT * FROM pedidos" . ($f ? " WHERE status = :f" : "") . " ORDER BY id DESC LIMIT :l OFFSET :o");
if ($f) $st->bindValue(':f', $f);
$st->bindValue(':l', POR_PAGINA, PDO::PARAM_INT);
$st->bindValue(':o', ($pag - 1) * POR_PAGINA, PDO::PARAM_INT);
$st->execute();
$pedidos = $st->fetchAll();

// Indicadores
$hoje = $pdo->query("SELECT COUNT(*) AS n, COALESCE(SUM(CASE WHEN status <> 'cancelado' THEN total END), 0) AS fat
    FROM pedidos WHERE DATE(criado_em) = CURDATE()")->fetch();
$andamento = (int)$pdo->query("SELECT COUNT(*) FROM pedidos WHERE status IN ('recebido','em preparo','saiu para entrega')")->fetchColumn();

// Produtos em pedidos ativos agora
$ativos = $pdo->query("SELECT p.nome, SUM(i.quantidade) AS qtd
    FROM itens_pedido i JOIN pedidos ped ON ped.id = i.pedido_id JOIN produtos p ON p.id = i.produto_id
    WHERE ped.status IN ('em preparo','saiu para entrega') GROUP BY p.id, p.nome ORDER BY qtd DESC LIMIT 8")->fetchAll();

$exportar = '';
if (in_array($_SESSION['usuario']['perfil'], ['admin', 'atendente'], true)) {
    $exportar = '<a class="btn btn-secondary" href="exportar.php' . ($f ? '?f=' . urlencode($f) : '') . '">' . icone('download', 16) . 'Exportar CSV</a>';
}
topo('Pedidos', 'Acompanhe e atualize o andamento dos pedidos.', 'pedidos', $exportar);
?>
<div class="stats">
    <div class="card stat"><span>Pedidos hoje</span><strong><?= (int)$hoje['n'] ?></strong></div>
    <div class="card stat"><span>Faturamento hoje</span><strong><?= brl($hoje['fat']) ?></strong></div>
    <div class="card stat"><span>Em andamento</span><strong><?= $andamento ?></strong></div>
</div>

<div class="filtros">
    <div class="abas">
        <a href="painel.php" class="<?= !$f ? 'ativa' : '' ?>">Todos</a>
        <?php foreach ($status_validos as $s): ?>
            <a href="?f=<?= urlencode($s) ?>" class="<?= $f === $s ? 'ativa' : '' ?>"><?= e(ucfirst($s)) ?></a>
        <?php endforeach; ?>
    </div>
</div>

<?php if ($ativos): ?>
<div class="card producao">
    <strong>Na cozinha e em entrega</strong>
    <?php foreach ($ativos as $a): ?><span class="badge"><?= e($a['nome']) ?> x <?= (int)$a['qtd'] ?></span><?php endforeach; ?>
</div>
<?php endif; ?>

<div class="tabela">
    <table>
        <thead><tr><th>Pedido</th><th>Cliente</th><th>Pagamento</th><th class="num">Total</th><th>Status</th><th>Atualizar</th></tr></thead>
        <tbody>
        <?php foreach ($pedidos as $p): ?>
            <tr>
                <td><strong>#<?= $p['id'] ?></strong><br><small><?= date('d/m H:i', strtotime($p['criado_em'])) ?></small></td>
                <td><strong><?= e($p['cliente']) ?></strong><br><small><?= e(fmt_tel($p['telefone'])) ?> - <?= e($p['endereco']) ?></small></td>
                <td><?= e(rotulo_pagamento($p['pagamento'])) ?>
                    <?php if ($p['pagamento'] === 'dinheiro' && $p['troco_para'] !== null): ?><br><small>Troco para <?= brl($p['troco_para']) ?></small><?php endif; ?></td>
                <td class="num"><?= brl($p['total']) ?><br><small>entrega <?= brl($p['taxa']) ?></small></td>
                <td><?= badge_status($p['status']) ?></td>
                <td>
                    <form method="post" class="inline">
                        <?= csrf_campo() ?>
                        <input type="hidden" name="id" value="<?= $p['id'] ?>">
                        <select name="status" onchange="this.form.submit()" aria-label="Status do pedido <?= $p['id'] ?>">
                            <?php foreach ($status_validos as $s): ?><option <?= $s === $p['status'] ? 'selected' : '' ?>><?= e($s) ?></option><?php endforeach; ?>
                        </select>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$pedidos): ?><tr><td colspan="6" class="vazio-linha">Nenhum pedido encontrado.</td></tr><?php endif; ?>
        </tbody>
    </table>
</div>

<?php if ($paginas > 1): ?>
<div class="paginacao">
    <?php for ($i = 1; $i <= $paginas; $i++): ?>
        <a class="<?= $i == $pag ? 'ativa' : '' ?>" href="?p=<?= $i ?><?= $f ? '&f=' . urlencode($f) : '' ?>"><?= $i ?></a>
    <?php endfor; ?>
</div>
<?php endif; rodape();
