<?php
require_once 'funcoes.php';

$categorias = $pdo->query("SELECT c.id, c.nome, COUNT(p.id) AS n
    FROM categorias c LEFT JOIN produtos p ON p.categoria_id = c.id AND p.ativo = 1
    GROUP BY c.id, c.nome ORDER BY c.id")->fetchAll();

topo('Início', '', 'inicio');
?>
<section class="hero">
    <h2>Peça agora, receba em casa</h2>
    <p>Hambúrgueres, porções, bebidas e sobremesas preparados na hora.</p>
    <a class="btn btn-primary" href="cardapio.php">Ver cardápio</a>
</section>

<div class="info-grid">
    <div class="card info-item"><?= icone('relogio', 22) ?><div><strong>~<?= TEMPO_ENTREGA ?> minutos</strong><small>Tempo médio de entrega</small></div></div>
    <div class="card info-item"><?= icone('moto', 22) ?><div><strong><?= brl(taxa_entrega()) ?></strong><small>Taxa de entrega</small></div></div>
    <div class="card info-item"><?= icone('carrinho', 22) ?><div><strong>Até <?= LIMITE_ITENS ?> itens</strong><small>Por pedido</small></div></div>
</div>

<div class="secao-titulo"><h2>Categorias</h2></div>
<div class="categorias-grid">
<?php foreach ($categorias as $c): ?>
    <a class="card categoria-link" href="cardapio.php?cat=<?= (int)$c['id'] ?>">
        <strong><?= e($c['nome']) ?></strong>
        <small><?= (int)$c['n'] ?> <?= $c['n'] == 1 ? 'item' : 'itens' ?></small>
    </a>
<?php endforeach; ?>
</div>
<?php rodape();
