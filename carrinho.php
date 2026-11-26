<?php
require_once 'funcoes.php';
$_SESSION['carrinho'] ??= [];
$acao = $_GET['acao'] ?? '';
$id   = (int)($_GET['id'] ?? 0);

if ($acao === 'limpar') { $_SESSION['carrinho'] = []; header('Location: carrinho.php'); exit; }

if ($id > 0 && in_array($acao, ['adicionar', 'reduzir', 'remover'], true)) {
    $st = $pdo->prepare("SELECT 1 FROM produtos WHERE id = ? AND ativo = 1");
    $st->execute([$id]);
    if ($st->fetchColumn()) {
        if ($acao === 'adicionar') {
            if (array_sum($_SESSION['carrinho']) >= LIMITE_ITENS) {
                $_SESSION['erro'] = "Limite de " . LIMITE_ITENS . " itens por pedido atingido.";
            } else {
                $_SESSION['carrinho'][$id] = ($_SESSION['carrinho'][$id] ?? 0) + 1;
            }
        } elseif ($acao === 'reduzir' && isset($_SESSION['carrinho'][$id])) {
            if (--$_SESSION['carrinho'][$id] <= 0) unset($_SESSION['carrinho'][$id]);
        } elseif ($acao === 'remover') {
            unset($_SESSION['carrinho'][$id]);
        }
    }
    // Quem veio do cardápio volta para o mesmo item
    header('Location: ' . (($_GET['volta'] ?? '') === 'cardapio' ? "cardapio.php#p$id" : 'carrinho.php')); exit;
}

$erro = $_SESSION['erro'] ?? null; unset($_SESSION['erro']);
[$itens, $subtotal] = carrinho_detalhado($pdo);
$taxa  = taxa_entrega();
$total = $subtotal > 0 ? $subtotal + $taxa : 0;

topo('Carrinho', $itens ? 'Revise os itens antes de fechar o pedido.' : '', 'carrinho');
if ($erro) echo '<div class="alerta">' . e($erro) . '</div>';

if (!$itens): ?>
    <div class="vazio"><?= icone('carrinho', 28) ?><h2>Seu carrinho está vazio</h2><p>Adicione itens do cardápio para começar.</p>
    <a class="btn btn-primary" href="cardapio.php">Ver cardápio</a></div>
<?php else: ?>
<div class="duas-colunas">
    <div class="tabela">
        <table>
            <thead><tr><th>Item</th><th>Preço</th><th>Quantidade</th><th class="num">Total</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($itens as $i): ?>
                <tr>
                    <td><strong><?= e($i['nome']) ?></strong></td>
                    <td><?= brl($i['preco']) ?></td>
                    <td><div class="qtd">
                        <a class="btn-icon" href="?acao=reduzir&id=<?= $i['id'] ?>" aria-label="Diminuir"><?= icone('menos', 14) ?></a>
                        <span><?= $i['qtd'] ?></span>
                        <a class="btn-icon" href="?acao=adicionar&id=<?= $i['id'] ?>" aria-label="Aumentar"><?= icone('mais', 14) ?></a>
                    </div></td>
                    <td class="num"><?= brl($i['total']) ?></td>
                    <td class="num"><a class="btn-icon" href="?acao=remover&id=<?= $i['id'] ?>" aria-label="Remover <?= e($i['nome']) ?>"><?= icone('lixeira', 14) ?></a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <aside class="card resumo">
        <h3>Resumo do pedido</h3>
        <div class="resumo-linha"><span>Itens</span><span><?= brl($subtotal) ?></span></div>
        <div class="resumo-linha"><span>Taxa de entrega</span><span><?= brl($taxa) ?></span></div>
        <div class="resumo-total"><span>Total</span><span><?= brl($total) ?></span></div>
        <a class="btn btn-primary btn-block" href="finalizar.php">Fechar pedido</a>
        <a class="btn btn-secondary btn-block" href="cardapio.php">Continuar pedindo</a>
        <a class="btn btn-link btn-block" href="?acao=limpar">Limpar carrinho</a>
    </aside>
</div>
<?php endif; rodape();
