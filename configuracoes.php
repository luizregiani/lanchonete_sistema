<?php
require_once 'funcoes.php';
exigir_login(['admin']);

$erro = ''; $ok = $_SESSION['ok'] ?? ''; unset($_SESSION['ok']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validar();
    $acao = $_POST['acao'] ?? '';

    if ($acao === 'loja') {
        $chave  = trim($_POST['pix_chave'] ?? '');
        $nome   = trim($_POST['pix_nome'] ?? '');
        $cidade = trim($_POST['pix_cidade'] ?? '');
        $taxa   = parse_preco($_POST['taxa_entrega'] ?? '0');

        if ($chave !== '' && !preg_match('/^[A-Za-z0-9@.+_\-]{1,77}$/', $chave))
            $erro = 'Chave Pix inválida: use até 77 caracteres, sem espaços (e-mail, CPF/CNPJ, telefone com +55 ou chave aleatória).';
        elseif (mb_strlen($nome) > 25 || mb_strlen($cidade) > 15)
            $erro = 'Nome do recebedor: máximo 25 caracteres. Cidade: máximo 15.';
        elseif ($taxa < 0 || $taxa > 99.99)
            $erro = 'A taxa de entrega deve estar entre R$ 0,00 e R$ 99,99.';
        else {
            foreach (['pix_chave' => $chave, 'pix_nome' => ascii_maiusc($nome) ?: ascii_maiusc(NOME_LANCHONETE),
                      'pix_cidade' => ascii_maiusc($cidade) ?: 'CIDADE',
                      'taxa_entrega' => number_format($taxa, 2, '.', '')] as $k => $val) cfg_salvar($pdo, $k, $val);
            registrar($pdo, 'config', 'configurações da loja atualizadas');
            $_SESSION['ok'] = 'Configurações salvas.';
            header('Location: configuracoes.php'); exit;
        }
    } elseif ($acao === 'senha') {
        $atual = (string)($_POST['atual'] ?? ''); $nova = (string)($_POST['nova'] ?? ''); $conf = (string)($_POST['conf'] ?? '');
        $st = $pdo->prepare("SELECT senha FROM usuarios WHERE id = ?");
        $st->execute([$_SESSION['usuario']['id']]);
        if (!password_verify($atual, (string)$st->fetchColumn()))      $erro = 'A senha atual está incorreta.';
        elseif (strlen($nova) < 8 || strlen($nova) > 72)               $erro = 'A nova senha deve ter entre 8 e 72 caracteres.';
        elseif ($nova !== $conf)                                       $erro = 'A confirmação não confere com a nova senha.';
        else {
            $pdo->prepare("UPDATE usuarios SET senha = ? WHERE id = ?")->execute([password_hash($nova, PASSWORD_DEFAULT), $_SESSION['usuario']['id']]);
            session_regenerate_id(true);
            registrar($pdo, 'senha', 'usuário ' . $_SESSION['usuario']['id']);
            $_SESSION['ok'] = 'Senha alterada.';
            header('Location: configuracoes.php'); exit;
        }
    }
}

$v = fn($k) => $_POST[$k] ?? cfg($k);
$taxaCampo = isset($_POST['taxa_entrega']) ? $_POST['taxa_entrega'] : number_format(taxa_entrega(), 2, ',', '');

topo('Configurações', 'Pagamento por Pix, taxa de entrega e segurança da conta.', 'config');
if ($erro) echo '<div class="alerta">' . e($erro) . '</div>';
if ($ok)   echo '<div class="alerta">' . e($ok) . '</div>';
?>
<form method="post" class="card formulario" style="max-width:720px;margin-bottom:24px">
    <?= csrf_campo() ?><input type="hidden" name="acao" value="loja">

    <h3 class="form-titulo">Entrega</h3>
    <label>Taxa de entrega (R$)
        <input name="taxa_entrega" value="<?= e($taxaCampo) ?>" inputmode="decimal" maxlength="5" style="max-width:160px">
        <small>Valor fixo cobrado em todos os pedidos.</small>
    </label>

    <h3 class="form-titulo">Pix</h3>
    <label>Chave Pix da loja
        <input name="pix_chave" value="<?= e($v('pix_chave')) ?>" maxlength="77" placeholder="Deixe vazio para desativar o Pix">
        <small>Máximo de 77 caracteres. Sem a chave, a opção Pix não aparece no checkout.</small>
    </label>
    <div class="grade-form">
        <label>Nome do recebedor<input name="pix_nome" value="<?= e($v('pix_nome')) ?>" maxlength="25"></label>
        <label>Cidade do recebedor<input name="pix_cidade" value="<?= e($v('pix_cidade')) ?>" maxlength="15"></label>
    </div>
    <div><button class="btn btn-primary" type="submit">Salvar configurações</button></div>
</form>

<form method="post" class="card formulario" style="max-width:720px">
    <?= csrf_campo() ?><input type="hidden" name="acao" value="senha">
    <h3 class="form-titulo">Alterar minha senha</h3>
    <div class="grade-form">
        <label>Senha atual<input type="password" name="atual" maxlength="72" required autocomplete="current-password"></label>
        <label>Nova senha<input type="password" name="nova" maxlength="72" minlength="8" required autocomplete="new-password"></label>
        <label>Confirmar nova senha<input type="password" name="conf" maxlength="72" minlength="8" required autocomplete="new-password"></label>
    </div>
    <div><button class="btn btn-primary" type="submit">Alterar senha</button></div>
</form>
<?php rodape();
