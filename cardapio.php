<?php
require_once 'funcoes.php';

$cat = (int)($_GET['cat'] ?? 0);
$q   = mb_substr(trim($_GET['q'] ?? ''), 0, 60);

$sql = "SELECT p.*, c.nome AS categoria FROM produtos p JOIN categorias c ON c.id = p.categoria_id WHERE p.ativo = 1";
$par = [];
if ($cat) { $sql .= " AND p.categoria_id = ?"; $par[] = $cat; }
if ($q !== '') {
    $like = '%' . addcslashes($q, '%_\\') . '%';
    $sql .= " AND (p.nome LIKE ? OR p.descricao LIKE ?)";
    array_push($par, $like, $like);
}
$st = $pdo->prepare($sql . " ORDER BY c.id, p.nome");
$st->execute($par);

$grupos = [];
foreach ($st->fetchAll() as $p) $grupos[$p['categoria']][] = $p;

$categorias  = $pdo->query("SELECT id, nome FROM categorias ORDER BY id")->fetchAll();
$noCarrinho  = $_SESSION['carrinho'] ?? [];
$erro = $_SESSION['erro'] ?? null; unset($_SESSION['erro']);
$extraQ = $q !== '' ? '&q=' . urlencode($q) : '';

topo('Cardápio', 'Escolha os itens e adicione ao carrinho.', 'cardapio');
if ($erro) echo '<div class="alerta">' . e($erro) . '</div>';
?>
<div class="filtros">
    <div class="abas">
        <a href="cardapio.php<?= $q !== '' ? '?q=' . urlencode($q) : '' ?>" class="<?= !$cat ? 'ativa' : '' ?>">Todos</a>
        <?php foreach ($categorias as $c): ?>
            <a href="?cat=<?= $c['id'] . $extraQ ?>" class="<?= $cat == $c['id'] ? 'ativa' : '' ?>"><?= e($c['nome']) ?></a>
        <?php endforeach; ?>
    </div>
    <form class="busca" method="get">
        <?php if ($cat): ?><input type="hidden" name="cat" value="<?= $cat ?>"><?php endif; ?>
        <input type="search" name="q" value="<?= e($q) ?>" maxlength="60" placeholder="Buscar item" aria-label="Buscar item">
        <button class="btn btn-secondary" type="submit">Buscar</button>
    </form>
</div>

<?php if (!$grupos): ?>
    <div class="vazio"><h2>Nenhum item encontrado</h2><p>Tente outra busca ou veja todas as categorias.</p>
    <a class="btn btn-secondary" href="cardapio.php">Limpar filtros</a></div>
<?php endif; ?>

<?php foreach ($grupos as $nomeCat => $lista): ?>
<section class="secao">
    <div class="secao-titulo"><h2><?= e($nomeCat) ?></h2><small><?= count($lista) ?> <?= count($lista) == 1 ? 'item' : 'itens' ?></small></div>
    <div class="grade">
    <?php foreach ($lista as $p): $n = $noCarrinho[$p['id']] ?? 0; ?>
        <article class="card item" id="p<?= $p['id'] ?>">
            <?php if (!empty($p['imagem'])): ?>
                <img class="item-img" src="uploads/produtos/<?= e($p['imagem']) ?>" alt="<?= e($p['nome']) ?>" loading="lazy">
            <?php else: ?>
                <div class="item-img sem-imagem"><?= icone('imagem', 28) ?></div>
            <?php endif; ?>
            <div class="item-corpo">
                <div>
                    <h3><?= e($p['nome']) ?><?php if ($n): ?><span class="tag"><?= $n ?> no carrinho</span><?php endif; ?></h3>
                    <p><?= e($p['descricao']) ?></p>
                </div>
                <div class="item-rodape">
                    <span class="preco"><?= brl($p['preco']) ?></span>
                    <a class="btn btn-primary btn-sm" href="carrinho.php?acao=adicionar&id=<?= $p['id'] ?>&volta=cardapio"><?= icone('mais', 16) ?>Adicionar</a>
                </div>
            </div>
        </article>
    <?php endforeach; ?>
    </div>
</section>
<?php endforeach; rodape();
