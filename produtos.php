<?php
require_once 'funcoes.php';
exigir_login(['admin']);

const PROD_POR_PAGINA = 15;

// ===== Filtros (vêm da URL e são preservados após cada ação) =====
$cat = (int)($_GET['cat'] ?? 0);
$q   = mb_substr(trim($_GET['q'] ?? ''), 0, 60);
$sit = in_array($_GET['sit'] ?? '', ['ativos', 'inativos', 'semfoto'], true) ? $_GET['sit'] : '';
$pag = max(1, (int)($_GET['p'] ?? 1));
$filtros = array_filter(['cat' => $cat ?: null, 'q' => $q !== '' ? $q : null, 'sit' => $sit ?: null, 'p' => $pag > 1 ? $pag : null]);

// ===== Ações =====
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validar();
    $acao = $_POST['acao'] ?? '';
    $id   = (int)($_POST['id'] ?? 0);
    $st = $pdo->prepare("SELECT * FROM produtos WHERE id = ?");
    $st->execute([$id]);
    $prod = $st->fetch();
    $destino = 'produtos.php' . ($filtros ? '?' . http_build_query($filtros) : '');

    if ($prod && $acao === 'alternar') {
        $pdo->prepare("UPDATE produtos SET ativo = 1 - ativo WHERE id = ?")->execute([$id]);
        registrar($pdo, 'produto', "alternou ativo do produto $id");
        $_SESSION['ok'] = 'Disponibilidade atualizada.';

    } elseif ($prod && $acao === 'duplicar') {
        $base = mb_substr($prod['nome'], 0, 90) . ' (cópia)';
        $nome = $base; $n = 1;
        $ex = $pdo->prepare("SELECT 1 FROM produtos WHERE nome = ?");
        while (true) { $ex->execute([$nome]); if (!$ex->fetchColumn()) break; $nome = $base . ' ' . (++$n); }
        $pdo->prepare("INSERT INTO produtos (categoria_id, nome, descricao, preco, ativo, imagem) VALUES (?,?,?,?,0,NULL)")
            ->execute([$prod['categoria_id'], $nome, $prod['descricao'], $prod['preco']]);
        $novo = (int)$pdo->lastInsertId();
        registrar($pdo, 'produto', "duplicou produto $id");
        $_SESSION['ok'] = 'Produto duplicado como indisponível. Ajuste os dados e a foto, depois ative-o.';
        header("Location: produto_form.php?id=$novo"); exit;

    } elseif ($prod && $acao === 'excluir') {
        $st = $pdo->prepare("SELECT COUNT(*) FROM itens_pedido WHERE produto_id = ?");
        $st->execute([$id]);
        if ((int)$st->fetchColumn() > 0) {
            $_SESSION['erro'] = 'Este produto já aparece em pedidos e não pode ser excluído. Desative-o para tirá-lo do cardápio.';
        } else {
            $pdo->prepare("DELETE FROM produtos WHERE id = ?")->execute([$id]);
            apagar_imagem($prod['imagem']);
            registrar($pdo, 'produto', "excluiu produto $id");
            $_SESSION['ok'] = 'Produto excluído.';
        }
    }
    header('Location: ' . $destino); exit;
}

$erro = $_SESSION['erro'] ?? null; unset($_SESSION['erro']);
$ok   = $_SESSION['ok'] ?? null;   unset($_SESSION['ok']);

// ===== Indicadores =====
$r = $pdo->query("SELECT COUNT(*) AS total, SUM(ativo = 1) AS ativos, SUM(imagem IS NULL OR imagem = '') AS semfoto FROM produtos")->fetch();
$tot = (int)$r['total']; $ativos = (int)$r['ativos']; $semfoto = (int)$r['semfoto'];

// ===== Listagem =====
$where = []; $par = [];
if ($cat)  { $where[] = 'p.categoria_id = ?'; $par[] = $cat; }
if ($q !== '') { $where[] = '(p.nome LIKE ? OR p.descricao LIKE ?)'; $like = '%' . addcslashes($q, '%_\\') . '%'; array_push($par, $like, $like); }
if ($sit === 'ativos')   $where[] = 'p.ativo = 1';
if ($sit === 'inativos') $where[] = 'p.ativo = 0';
if ($sit === 'semfoto')  $where[] = "(p.imagem IS NULL OR p.imagem = '')";
$cond = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$st = $pdo->prepare("SELECT COUNT(*) FROM produtos p $cond");
$st->execute($par);
$totalFiltrado = (int)$st->fetchColumn();
$paginas = max(1, (int)ceil($totalFiltrado / PROD_POR_PAGINA));
$pag = min($pag, $paginas);

$st = $pdo->prepare("SELECT p.*, c.nome AS categoria FROM produtos p JOIN categorias c ON c.id = p.categoria_id $cond
                     ORDER BY c.id, p.nome LIMIT " . PROD_POR_PAGINA . " OFFSET " . (($pag - 1) * PROD_POR_PAGINA));
$st->execute($par);
$produtos = $st->fetchAll();
$categorias = $pdo->query("SELECT id, nome FROM categorias ORDER BY id")->fetchAll();

$urlBase = fn(array $extra = []) => 'produtos.php?' . http_build_query(array_filter(array_merge(['cat' => $cat ?: null, 'q' => $q !== '' ? $q : null, 'sit' => $sit ?: null], $extra)));
$acaoForm = 'produtos.php' . ($filtros ? '?' . http_build_query($filtros) : '');

topo('Produtos', 'Cadastre itens, envie fotos e controle a disponibilidade no cardápio.', 'produtos',
     '<a class="btn btn-secondary" href="cardapio.php">' . icone('cardapio', 16) . 'Ver cardápio</a>'
   . '<a class="btn btn-primary" href="produto_form.php">' . icone('mais', 16) . 'Novo produto</a>');
if ($erro) echo '<div class="alerta">' . e($erro) . '</div>';
if ($ok)   echo '<div class="alerta">' . e($ok) . '</div>';
?>
<div class="stats">
    <div class="card stat"><span>Produtos cadastrados</span><strong><?= $tot ?></strong></div>
    <div class="card stat"><span>Disponíveis no cardápio</span><strong><?= $ativos ?></strong></div>
    <div class="card stat"><span>Sem foto</span><strong><?= $semfoto ?></strong></div>
</div>

<div class="filtros">
    <div class="abas">
        <a href="<?= e($urlBase(['cat' => null])) ?>" class="<?= !$cat ? 'ativa' : '' ?>">Todas</a>
        <?php foreach ($categorias as $c): ?>
            <a href="<?= e($urlBase(['cat' => $c['id']])) ?>" class="<?= $cat == $c['id'] ? 'ativa' : '' ?>"><?= e($c['nome']) ?></a>
        <?php endforeach; ?>
    </div>
    <form class="busca" method="get">
        <?php if ($cat): ?><input type="hidden" name="cat" value="<?= $cat ?>"><?php endif; ?>
        <input type="search" name="q" value="<?= e($q) ?>" maxlength="60" placeholder="Buscar produto" aria-label="Buscar produto">
        <select name="sit" aria-label="Situação" style="width:auto" onchange="this.form.submit()">
            <option value="">Todos</option>
            <option value="ativos" <?= $sit === 'ativos' ? 'selected' : '' ?>>Disponíveis</option>
            <option value="inativos" <?= $sit === 'inativos' ? 'selected' : '' ?>>Indisponíveis</option>
            <option value="semfoto" <?= $sit === 'semfoto' ? 'selected' : '' ?>>Sem foto</option>
        </select>
        <button class="btn btn-secondary" type="submit">Buscar</button>
    </form>
</div>

<div class="tabela">
    <table>
        <thead><tr><th></th><th>Produto</th><th>Categoria</th><th class="num">Preço</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($produtos as $p): ?>
            <tr>
                <td style="width:64px">
                    <?php if (!empty($p['imagem'])): ?><img class="thumb" src="uploads/produtos/<?= e($p['imagem']) ?>" alt="">
                    <?php else: ?><div class="thumb sem-imagem"><?= icone('imagem', 18) ?></div><?php endif; ?>
                </td>
                <td><strong><?= e($p['nome']) ?></strong><br><small><?= e($p['descricao']) ?></small></td>
                <td><?= e($p['categoria']) ?></td>
                <td class="num"><?= brl($p['preco']) ?></td>
                <td><span class="badge badge-<?= $p['ativo'] ? 'ativo' : 'inativo' ?>"><?= $p['ativo'] ? 'Disponível' : 'Indisponível' ?></span></td>
                <td class="num">
                    <form method="post" action="<?= e($acaoForm) ?>" class="inline">
                        <?= csrf_campo() ?>
                        <input type="hidden" name="id" value="<?= $p['id'] ?>">
                        <a class="btn btn-secondary btn-sm" href="produto_form.php?id=<?= $p['id'] ?>"><?= icone('editar', 14) ?>Editar</a>
                        <button class="btn btn-secondary btn-sm" type="submit" name="acao" value="alternar"><?= $p['ativo'] ? 'Desativar' : 'Ativar' ?></button>
                        <button class="btn-icon" type="submit" name="acao" value="duplicar" title="Duplicar" aria-label="Duplicar <?= e($p['nome']) ?>"><?= icone('copiar', 14) ?></button>
                        <button class="btn-icon" type="submit" name="acao" value="excluir" title="Excluir" aria-label="Excluir <?= e($p['nome']) ?>"
                                onclick="return confirm('Excluir este produto? Esta ação não pode ser desfeita.')"><?= icone('lixeira', 14) ?></button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$produtos): ?><tr><td colspan="6" class="vazio-linha">Nenhum produto encontrado.</td></tr><?php endif; ?>
        </tbody>
    </table>
</div>

<?php if ($paginas > 1): ?>
<div class="paginacao">
    <?php for ($i = 1; $i <= $paginas; $i++): ?>
        <a class="<?= $i == $pag ? 'ativa' : '' ?>" href="<?= e($urlBase(['p' => $i > 1 ? $i : null])) ?>"><?= $i ?></a>
    <?php endfor; ?>
</div>
<?php endif; rodape();
