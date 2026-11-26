<?php
require_once 'funcoes.php';
exigir_login(['admin']);

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$p  = ['id' => 0, 'categoria_id' => 0, 'nome' => '', 'descricao' => '', 'preco' => '', 'ativo' => 1, 'imagem' => null];
if ($id) {
    $st = $pdo->prepare("SELECT * FROM produtos WHERE id = ?");
    $st->execute([$id]);
    $row = $st->fetch();
    if (!$row) { header('Location: produtos.php'); exit; }
    $p = $row;
    $p['preco'] = number_format((float)$p['preco'], 2, ',', '');
}
$categorias = $pdo->query("SELECT id, nome FROM categorias ORDER BY id")->fetchAll();
$catSel = (string)$p['categoria_id'];
$novaCat = '';
$erro = '';
$ok = $_SESSION['ok'] ?? ''; unset($_SESSION['ok']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validar();
    $nome    = trim($_POST['nome'] ?? '');
    $desc    = trim($_POST['descricao'] ?? '');
    $catSel  = (string)($_POST['categoria_id'] ?? '');
    $novaCat = trim($_POST['nova_categoria'] ?? '');
    $preco   = parse_preco($_POST['preco'] ?? '');
    $ativo   = isset($_POST['ativo']) ? 1 : 0;
    $p = array_merge($p, ['nome' => $nome, 'descricao' => $desc, 'preco' => trim($_POST['preco'] ?? ''), 'ativo' => $ativo]);

    $cat = (int)$catSel;
    if ($catSel === 'nova') {
        if (mb_strlen($novaCat) < 2 || mb_strlen($novaCat) > 50) $erro = 'O nome da nova categoria deve ter entre 2 e 50 caracteres.';
    } else {
        $st = $pdo->prepare("SELECT 1 FROM categorias WHERE id = ?"); $st->execute([$cat]);
        if (!$st->fetchColumn()) $erro = 'Escolha uma categoria.';
    }
    if (!$erro) {
        $st = $pdo->prepare("SELECT 1 FROM produtos WHERE nome = ? AND id <> ?"); $st->execute([$nome, $id]);
        if (mb_strlen($nome) < 2 || mb_strlen($nome) > 100)  $erro = 'O nome deve ter entre 2 e 100 caracteres.';
        elseif ($st->fetchColumn())                           $erro = 'Já existe um produto com esse nome.';
        elseif (mb_strlen($desc) > 255)                       $erro = 'A descrição deve ter no máximo 255 caracteres.';
        elseif ($preco <= 0 || $preco > 9999.99)              $erro = 'Informe um preço entre R$ 0,01 e R$ 9.999,99.';
    }

    $novaImg = null;
    $temUpload = isset($_FILES['imagem']) && $_FILES['imagem']['error'] !== UPLOAD_ERR_NO_FILE;
    if (!$erro && $temUpload) {
        try { $novaImg = salvar_imagem($_FILES['imagem']); }
        catch (Exception $ex) { $erro = $ex->getMessage(); }
    }

    if (!$erro) {
        try {
            if ($catSel === 'nova') {
                $st = $pdo->prepare("SELECT id FROM categorias WHERE nome = ?"); $st->execute([$novaCat]);
                $cat = (int)$st->fetchColumn();
                if (!$cat) { $pdo->prepare("INSERT INTO categorias (nome) VALUES (?)")->execute([$novaCat]); $cat = (int)$pdo->lastInsertId(); }
            }
            $imgAtual = $p['imagem'];
            $imgFinal = $imgAtual;
            if ($novaImg) $imgFinal = $novaImg;
            elseif (isset($_POST['remover_imagem'])) $imgFinal = null;

            if ($id) {
                $pdo->prepare("UPDATE produtos SET categoria_id=?, nome=?, descricao=?, preco=?, ativo=?, imagem=? WHERE id=?")
                    ->execute([$cat, $nome, $desc, $preco, $ativo, $imgFinal, $id]);
                registrar($pdo, 'produto', "editou produto $id");
            } else {
                $pdo->prepare("INSERT INTO produtos (categoria_id, nome, descricao, preco, ativo, imagem) VALUES (?,?,?,?,?,?)")
                    ->execute([$cat, $nome, $desc, $preco, $ativo, $imgFinal]);
                registrar($pdo, 'produto', "novo: $nome");
            }
            if ($imgAtual && $imgFinal !== $imgAtual) apagar_imagem($imgAtual);

            $_SESSION['ok'] = $id ? 'Produto atualizado.' : 'Produto cadastrado.';
            header('Location: ' . (!$id && isset($_POST['outro']) ? 'produto_form.php' : 'produtos.php')); exit;
        } catch (Throwable $t) {
            if ($novaImg) apagar_imagem($novaImg);
            $erro = 'Não foi possível salvar o produto. Tente novamente.';
        }
    }
}

$imgUrl = !empty($p['imagem']) ? 'uploads/produtos/' . $p['imagem'] : '';

topo($id ? 'Editar produto' : 'Novo produto', 'A prévia ao lado mostra como o item aparece no cardápio.', 'produtos',
     '<a class="btn btn-secondary" href="produtos.php">Voltar</a>');
if ($erro) echo '<div class="alerta">' . e($erro) . '</div>';
if ($ok)   echo '<div class="alerta">' . e($ok) . '</div>';
?>
<form method="post" enctype="multipart/form-data" class="produto-form">
    <?= csrf_campo() ?>
    <input type="hidden" name="id" value="<?= (int)$id ?>">

    <div class="card formulario">
        <h3 class="form-titulo">Informações</h3>

        <label>
            <span class="campo-rotulo">Nome <small class="contador" data-de="nome"></small></span>
            <input id="nome" name="nome" value="<?= e($p['nome']) ?>" maxlength="100" required>
        </label>

        <div class="grade-form">
            <label>Categoria
                <select id="categoria" name="categoria_id" required>
                    <?php foreach ($categorias as $c): ?>
                        <option value="<?= $c['id'] ?>" <?= $catSel === (string)$c['id'] ? 'selected' : '' ?>><?= e($c['nome']) ?></option>
                    <?php endforeach; ?>
                    <option value="nova" <?= $catSel === 'nova' ? 'selected' : '' ?>>+ Nova categoria...</option>
                </select>
            </label>
            <label>Preço
                <span class="input-prefixo"><span>R$</span>
                    <input id="preco" name="preco" value="<?= e($p['preco']) ?>" inputmode="decimal" maxlength="7" placeholder="0,00" required>
                </span>
            </label>
        </div>

        <label id="campo-nova-cat" <?= $catSel === 'nova' ? '' : 'hidden' ?>>
            <span class="campo-rotulo">Nome da nova categoria <small class="contador" data-de="nova_categoria"></small></span>
            <input id="nova_categoria" name="nova_categoria" value="<?= e($novaCat) ?>" maxlength="50" placeholder="Ex.: Combos">
        </label>

        <label>
            <span class="campo-rotulo">Descrição <small class="contador" data-de="descricao"></small></span>
            <textarea id="descricao" name="descricao" rows="3" maxlength="255" placeholder="Ingredientes, tamanho, sabores..."><?= e($p['descricao']) ?></textarea>
        </label>

        <label class="checagem"><input type="checkbox" name="ativo" <?= $p['ativo'] ? 'checked' : '' ?>> Disponível no cardápio</label>

        <div class="acoes">
            <button class="btn btn-primary" type="submit">Salvar produto</button>
            <?php if (!$id): ?><button class="btn btn-secondary" type="submit" name="outro" value="1">Salvar e cadastrar outro</button><?php endif; ?>
            <a class="btn btn-link" href="produtos.php">Cancelar</a>
        </div>
    </div>

    <div class="lateral">
        <div class="card formulario">
            <h3 class="form-titulo">Foto</h3>
            <label class="dropzone" id="dz" for="imagem">
                <img id="previa" alt="Prévia da foto" <?= $imgUrl ? 'src="' . e($imgUrl) . '"' : 'hidden' ?>>
                <span class="dz-vazio" id="dz-vazio" <?= $imgUrl ? 'hidden' : '' ?>>
                    <?= icone('upload', 26) ?>
                    <strong>Arraste uma imagem ou clique para escolher</strong>
                    <small>JPG, PNG ou WEBP, até <?= IMG_MAX_BYTES / 1048576 ?> MB</small>
                </span>
            </label>
            <input class="sr-only" type="file" id="imagem" name="imagem" accept="image/jpeg,image/png,image/webp">
            <small class="msg-erro" id="img-msg" aria-live="polite"></small>
            <?php if ($imgUrl): ?>
                <label class="checagem"><input type="checkbox" id="remover" name="remover_imagem"> Remover foto atual</label>
            <?php endif; ?>
        </div>

        <div class="card item" aria-hidden="true">
            <img class="item-img" id="pv-img" alt="" hidden>
            <div class="item-img sem-imagem" id="pv-vazio"><?= icone('imagem', 28) ?></div>
            <div class="item-corpo">
                <div><h3 id="pv-nome">Nome do produto</h3><p id="pv-desc">Descrição do produto</p></div>
                <div class="item-rodape"><span class="preco" id="pv-preco">R$ 0,00</span><span class="btn btn-primary btn-sm"><?= icone('mais', 16) ?>Adicionar</span></div>
            </div>
        </div>
    </div>
</form>

<script>
const $ = id => document.getElementById(id);
const MAX = <?= (int)IMG_MAX_BYTES ?>;
const TIPOS = ['image/jpeg', 'image/png', 'image/webp'];
let imgUrl = <?= json_encode($imgUrl) ?>;

// Contadores de caracteres
document.querySelectorAll('[data-de]').forEach(c => {
    const i = $(c.dataset.de), f = () => c.textContent = i.value.length + '/' + i.maxLength;
    i.addEventListener('input', f); f();
});

// Máscara do preço: só dígitos e uma vírgula, até 2 casas
$('preco').addEventListener('input', e => {
    let v = e.target.value.replace(/[^\d,]/g, '');
    const i = v.indexOf(',');
    if (i >= 0) v = v.slice(0, i + 1) + v.slice(i + 1).replace(/,/g, '').slice(0, 2);
    e.target.value = v; prever();
});

// Nova categoria
function catNova() { $('campo-nova-cat').hidden = $('categoria').value !== 'nova'; if (!$('campo-nova-cat').hidden) $('nova_categoria').focus(); }
$('categoria').addEventListener('change', catNova);

// Prévia ao vivo do cartão do cardápio
function prever() {
    $('pv-nome').textContent = $('nome').value.trim() || 'Nome do produto';
    $('pv-desc').textContent = $('descricao').value.trim() || 'Descrição do produto';
    const n = parseFloat($('preco').value.replace(',', '.')) || 0;
    $('pv-preco').textContent = 'R$ ' + n.toLocaleString('pt-BR', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    const url = $('remover') && $('remover').checked ? '' : imgUrl;
    $('pv-img').hidden = !url; if (url) $('pv-img').src = url;
    $('pv-vazio').hidden = !!url;
    $('previa').hidden = !url; if (url) $('previa').src = url;
    $('dz-vazio').hidden = !!url;
}
['nome', 'descricao'].forEach(k => $(k).addEventListener('input', prever));
if ($('remover')) $('remover').addEventListener('change', prever);

// Seleção da imagem (clique ou arrastar) com validação no navegador
$('imagem').addEventListener('change', () => {
    const f = $('imagem').files[0];
    $('img-msg').textContent = '';
    if (!f) return;
    if (!TIPOS.includes(f.type)) { $('img-msg').textContent = 'Formato inválido. Use JPG, PNG ou WEBP.'; $('imagem').value = ''; return; }
    if (f.size > MAX) { $('img-msg').textContent = 'A imagem excede ' + (MAX / 1048576) + ' MB.'; $('imagem').value = ''; return; }
    imgUrl = URL.createObjectURL(f);
    if ($('remover')) $('remover').checked = false;
    prever();
});
const dz = $('dz');
['dragenter', 'dragover'].forEach(ev => dz.addEventListener(ev, e => { e.preventDefault(); dz.classList.add('sobre'); }));
['dragleave', 'drop'].forEach(ev => dz.addEventListener(ev, e => { e.preventDefault(); dz.classList.remove('sobre'); }));
dz.addEventListener('drop', e => {
    if (e.dataTransfer.files.length) { $('imagem').files = e.dataTransfer.files; $('imagem').dispatchEvent(new Event('change')); }
});

prever();
</script>
<?php rodape();
