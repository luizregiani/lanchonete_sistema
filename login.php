<?php
require_once 'funcoes.php';

// Quem já está logado vai direto ao painel
if (!empty($_SESSION['usuario'])) { header('Location: painel.php'); exit; }

$erro = '';
$ip = $_SERVER['REMOTE_ADDR'] ?? '';

// Bloqueio por IP (persistente, não depende do cookie): 8 falhas em 15 minutos
$st = $pdo->prepare("SELECT COUNT(*) FROM log_acoes WHERE acao = 'login_falha' AND ip = ? AND criado_em > (NOW() - INTERVAL 15 MINUTE)");
$st->execute([$ip]);
$bloqueado = (int)$st->fetchColumn() >= 8;

if ($bloqueado) {
    $erro = 'Muitas tentativas. Aguarde 15 minutos para tentar novamente.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validar();
    $usuario = mb_substr(trim($_POST['usuario'] ?? ''), 0, 40);
    $consulta = $pdo->prepare("SELECT * FROM usuarios WHERE usuario = :u AND ativo = 1");
    $consulta->execute([':u' => $usuario]);
    $u = $consulta->fetch();
    if ($u && password_verify(mb_substr((string)($_POST['senha'] ?? ''), 0, 72), $u['senha'])) {
        $csrf = $_SESSION['csrf'] ?? null;
        session_regenerate_id(true);
        $_SESSION['usuario'] = ['id' => $u['id'], 'nome' => $u['nome'], 'perfil' => $u['perfil']];
        registrar($pdo, 'login', $u['usuario']);
        header('Location: painel.php'); exit;
    }
    registrar($pdo, 'login_falha', $usuario);
    $erro = 'Usuário ou senha incorretos.';
}
topo('Acesso da equipe', 'Área restrita a funcionários.');
if ($erro) echo '<div class="alerta">' . e($erro) . '</div>';
?>
<form method="post" class="card formulario estreito">
    <?= csrf_campo() ?>
    <label>Usuário<input name="usuario" maxlength="40" required autofocus autocomplete="username"></label>
    <label>Senha<input name="senha" type="password" maxlength="72" required autocomplete="current-password"></label>
    <button class="btn btn-primary btn-block" type="submit">Entrar</button>
</form>
<?php rodape();
