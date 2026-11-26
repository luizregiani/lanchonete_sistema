<?php
require_once 'funcoes.php';

// ===== Tela de sucesso =====
if (isset($_GET['ok'])) {
    $num = (int)$_GET['ok'];
    $ped = null;
    // Só mostra dados de pagamento para quem acabou de fazer este pedido
    if (($_SESSION['ultimo_pedido'] ?? 0) === $num) {
        $st = $pdo->prepare("SELECT total, pagamento FROM pedidos WHERE id = ?");
        $st->execute([$num]);
        $ped = $st->fetch();
    }
    topo('Pedido confirmado', '', 'carrinho');
    echo '<div class="vazio">' . icone('check', 28) . '<h2>Pedido #' . $num . ' confirmado</h2>
    <p>Previsão de entrega: ' . TEMPO_ENTREGA . ' minutos.</p>';
    if ($ped && $ped['pagamento'] === 'pix' && cfg('pix_chave') !== '') {
        $payload = pix_payload(cfg('pix_chave'), cfg('pix_nome'), cfg('pix_cidade'), (float)$ped['total'], "PED$num");
        echo '<div class="pix-box"><strong>Pague com Pix: ' . brl($ped['total']) . '</strong>
            <small>No app do seu banco, escolha Pix copia e cola e cole o código abaixo.</small>
            <textarea id="pix" readonly rows="4">' . e($payload) . '</textarea>
            <button class="btn btn-secondary" type="button" onclick="navigator.clipboard.writeText(document.getElementById(\'pix\').value);this.textContent=\'Código copiado\'">Copiar código Pix</button></div>';
    } elseif ($ped) {
        echo '<p>Pagamento na entrega: ' . e(rotulo_pagamento($ped['pagamento'])) . ' - ' . brl($ped['total']) . '</p>';
    }
    echo '<a class="btn btn-primary" href="cardapio.php">Voltar ao cardápio</a></div>';
    rodape(); exit;
}

if (empty($_SESSION['carrinho'])) { header('Location: carrinho.php'); exit; }
[$itens, $subtotal] = carrinho_detalhado($pdo);
if (!$itens) { header('Location: carrinho.php'); exit; }

$taxa  = taxa_entrega();
$total = $subtotal + $taxa;

$pagamentos = ['cartao_credito' => 'Cartão de crédito', 'cartao_debito' => 'Cartão de débito', 'dinheiro' => 'Dinheiro'];
if (cfg('pix_chave') !== '') $pagamentos = ['pix' => 'Pix'] + $pagamentos;

$erro = '';
$v = ['cliente' => '', 'telefone' => '', 'rua' => '', 'numero' => '', 'bairro' => '', 'complemento' => '',
      'pagamento' => array_key_first($pagamentos), 'troco_para' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validar();
    foreach ($v as $k => $_) $v[$k] = trim((string)($_POST[$k] ?? ''));
    $tel = preg_replace('/\D/', '', $v['telefone']);

    if (mb_strlen($v['cliente']) < 2 || mb_strlen($v['cliente']) > 80)   $erro = 'Informe seu nome (até 80 caracteres).';
    elseif (!in_array(strlen($tel), [10, 11], true))                       $erro = 'Telefone inválido. Use DDD + número (10 ou 11 dígitos).';
    elseif (mb_strlen($v['rua']) < 3 || mb_strlen($v['rua']) > 100)        $erro = 'Informe a rua (de 3 a 100 caracteres).';
    elseif ($v['numero'] === '' || mb_strlen($v['numero']) > 10)           $erro = 'Informe o número do endereço (até 10 caracteres).';
    elseif (mb_strlen($v['bairro']) < 2 || mb_strlen($v['bairro']) > 60)   $erro = 'Informe o bairro (de 2 a 60 caracteres).';
    elseif (mb_strlen($v['complemento']) > 60)                             $erro = 'O complemento deve ter no máximo 60 caracteres.';
    elseif (!isset($pagamentos[$v['pagamento']]))                          $erro = 'Escolha uma forma de pagamento.';

    $troco = null;
    if (!$erro && $v['pagamento'] === 'dinheiro' && $v['troco_para'] !== '') {
        $t = parse_preco($v['troco_para']);
        if ($t < $total || $t > 9999.99) $erro = 'O valor para troco deve ser igual ou maior que o total (' . brl($total) . ').';
        else $troco = $t;
    }

    if (!$erro) {
        try {
            $pdo->beginTransaction();
            // Preços sempre vêm do banco, nunca do navegador
            $ids = array_keys($_SESSION['carrinho']);
            $st = $pdo->prepare("SELECT id, preco FROM produtos WHERE ativo = 1 AND id IN (" . implode(',', array_fill(0, count($ids), '?')) . ")");
            $st->execute($ids);
            $precos = array_column($st->fetchAll(), 'preco', 'id');
            if (!$precos) throw new Exception('Carrinho inválido');
            $sub = 0;
            foreach ($precos as $pid => $pr) $sub += $pr * $_SESSION['carrinho'][$pid];

            $endereco = mb_substr($v['rua'] . ', ' . $v['numero'] . ($v['complemento'] !== '' ? ' (' . $v['complemento'] . ')' : '') . ' - ' . $v['bairro'], 0, 200);

            $pdo->prepare("INSERT INTO pedidos (cliente, telefone, endereco, numero, complemento, bairro, pagamento, troco_para, subtotal, taxa, total)
                           VALUES (?,?,?,?,?,?,?,?,?,?,?)")
                ->execute([$v['cliente'], $tel, $endereco, $v['numero'], $v['complemento'], $v['bairro'],
                           $v['pagamento'], $troco, $sub, $taxa, $sub + $taxa]);
            $pedido = (int)$pdo->lastInsertId();
            $ins = $pdo->prepare("INSERT INTO itens_pedido (pedido_id, produto_id, quantidade, preco_unit) VALUES (?,?,?,?)");
            foreach ($precos as $pid => $pr) $ins->execute([$pedido, $pid, $_SESSION['carrinho'][$pid], $pr]);
            $pdo->commit();
            $_SESSION['carrinho'] = [];
            $_SESSION['ultimo_pedido'] = $pedido;
            header("Location: finalizar.php?ok=$pedido"); exit;
        } catch (Throwable $t) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $erro = 'Não foi possível salvar o pedido. Tente novamente.';
        }
    }
}

topo('Finalizar pedido', 'Informe os dados de entrega e a forma de pagamento.', 'carrinho');
if ($erro) echo '<div class="alerta">' . e($erro) . '</div>';
?>
<div class="duas-colunas">
    <form method="post" class="formulario" autocomplete="on">
        <?= csrf_campo() ?>

        <div class="card formulario">
            <h3 class="form-titulo">Seus dados</h3>
            <div class="grade-form">
                <label>Nome<input name="cliente" value="<?= e($v['cliente']) ?>" maxlength="80" required></label>
                <label>Telefone<input name="telefone" id="telefone" value="<?= e($v['telefone']) ?>" maxlength="15" inputmode="tel" placeholder="(00) 00000-0000" required></label>
            </div>
        </div>

        <div class="card formulario">
            <h3 class="form-titulo">Endereço de entrega</h3>
            <div class="grade-form">
                <label>Rua<input name="rua" value="<?= e($v['rua']) ?>" maxlength="100" required></label>
                <label>Número<input name="numero" value="<?= e($v['numero']) ?>" maxlength="10" required></label>
            </div>
            <div class="grade-form">
                <label>Bairro<input name="bairro" value="<?= e($v['bairro']) ?>" maxlength="60" required></label>
                <label>Complemento<input name="complemento" value="<?= e($v['complemento']) ?>" maxlength="60" placeholder="Apto, bloco, referência (opcional)"></label>
            </div>
        </div>

        <div class="card formulario">
            <h3 class="form-titulo">Pagamento</h3>
            <div class="opcoes-pagamento">
            <?php foreach ($pagamentos as $k => $nome): ?>
                <label class="opcao">
                    <input type="radio" name="pagamento" value="<?= $k ?>" <?= $v['pagamento'] === $k ? 'checked' : '' ?>>
                    <span><strong><?= e($nome) ?></strong>
                    <small><?= $k === 'pix' ? 'Código copia e cola após confirmar' : 'Pagamento na entrega' ?></small></span>
                </label>
            <?php endforeach; ?>
            </div>
            <label id="campo-troco" hidden>Troco para quanto? (opcional)
                <input name="troco_para" value="<?= e($v['troco_para']) ?>" maxlength="7" inputmode="decimal" placeholder="Ex.: 100,00">
            </label>
        </div>

        <div class="acoes">
            <button class="btn btn-primary" type="submit">Confirmar pedido</button>
            <a class="btn btn-secondary" href="carrinho.php">Voltar ao carrinho</a>
        </div>
    </form>

    <aside class="card resumo">
        <h3>Resumo do pedido</h3>
        <?php foreach ($itens as $i): ?>
            <div class="resumo-linha"><span><?= $i['qtd'] ?>x <?= e($i['nome']) ?></span><span><?= brl($i['total']) ?></span></div>
        <?php endforeach; ?>
        <div class="resumo-linha"><span>Subtotal</span><span><?= brl($subtotal) ?></span></div>
        <div class="resumo-linha"><span>Taxa de entrega</span><span><?= brl($taxa) ?></span></div>
        <div class="resumo-total" style="margin-bottom:0"><span>Total</span><span><?= brl($total) ?></span></div>
    </aside>
</div>

<script>
const $ = id => document.getElementById(id);

// Máscara de telefone: (00) 00000-0000
$('telefone').addEventListener('input', e => {
    const d = e.target.value.replace(/\D/g, '').slice(0, 11);
    e.target.value = d.length > 10 ? d.replace(/(\d{2})(\d{5})(\d{0,4})/, '($1) $2-$3')
                   : d.length > 6  ? d.replace(/(\d{2})(\d{4})(\d{0,4})/, '($1) $2-$3')
                   : d.length > 2  ? d.replace(/(\d{2})(\d{0,5})/, '($1) $2') : d;
});

// Troco só aparece para pagamento em dinheiro
function atualizarPagamento() {
    const din = document.querySelector('input[name=pagamento]:checked')?.value === 'dinheiro';
    $('campo-troco').hidden = !din;
}
document.querySelectorAll('input[name=pagamento]').forEach(r => r.addEventListener('change', atualizarPagamento));
atualizarPagamento();
</script>
<?php rodape();
