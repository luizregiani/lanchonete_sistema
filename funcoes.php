<?php
// Incluído no topo de TODAS as páginas: sessão + conexão + utilitários
require_once __DIR__ . '/conexao.php';

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'secure' => !empty($_SERVER['HTTPS'])]);
    session_start();
}
if (!headers_sent()) {
    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
}

/* ===================== Atualização automática do banco ===================== */
/** Cria colunas/tabelas novas que faltarem (roda uma vez por sessão). Evita erros "coluna desconhecida". */
function garantir_esquema(PDO $pdo): void {
    if (!empty($_SESSION['esquema_ok'])) return;
    try {
        $tem = function (string $tabela, string $coluna) use ($pdo): bool {
            $st = $pdo->prepare("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
            $st->execute([$tabela, $coluna]);
            return (bool)$st->fetchColumn();
        };
        $novas = [
            'produtos' => ['imagem' => "VARCHAR(40) NULL"],
            'pedidos'  => ['numero' => "VARCHAR(10) NULL", 'complemento' => "VARCHAR(60) NULL", 'bairro' => "VARCHAR(80) NULL",
                           'pagamento' => "ENUM('pix','cartao_credito','cartao_debito','dinheiro') NOT NULL DEFAULT 'dinheiro'",
                           'troco_para' => "DECIMAL(8,2) NULL"],
        ];
        foreach ($novas as $tabela => $colunas)
            foreach ($colunas as $coluna => $def)
                if (!$tem($tabela, $coluna)) $pdo->exec("ALTER TABLE $tabela ADD COLUMN $coluna $def");
        $pdo->exec("CREATE TABLE IF NOT EXISTS configuracoes (chave VARCHAR(40) PRIMARY KEY, valor VARCHAR(255) NOT NULL DEFAULT '')");
        $_SESSION['esquema_ok'] = true;
    } catch (Throwable $t) { /* sem permissão para alterar: segue e deixa o erro real aparecer */ }
}
garantir_esquema($pdo);

/* ===================== Básico ===================== */
function e($t) { return htmlspecialchars((string)$t, ENT_QUOTES, 'UTF-8'); }
function brl($v) { return 'R$ ' . number_format((float)$v, 2, ',', '.'); }

function registrar(PDO $pdo, string $acao, string $detalhe = '') {
    $pdo->prepare("INSERT INTO log_acoes (acao, detalhe, ip) VALUES (?,?,?)")
        ->execute([$acao, mb_substr($detalhe, 0, 120), $_SERVER['REMOTE_ADDR'] ?? '']);
}

function fmt_tel(string $t): string {
    $d = preg_replace('/\D/', '', $t);
    if (strlen($d) === 11) return sprintf('(%s) %s-%s', substr($d, 0, 2), substr($d, 2, 5), substr($d, 7));
    if (strlen($d) === 10) return sprintf('(%s) %s-%s', substr($d, 0, 2), substr($d, 2, 4), substr($d, 6));
    return $t;
}
function rotulo_pagamento(string $p): string {
    return ['pix' => 'Pix', 'cartao_credito' => 'Cartão de crédito', 'cartao_debito' => 'Cartão de débito', 'dinheiro' => 'Dinheiro'][$p] ?? $p;
}

/* ===================== Segurança ===================== */
function csrf_token(): string { return $_SESSION['csrf'] ??= bin2hex(random_bytes(32)); }
function csrf_campo(): string { return '<input type="hidden" name="csrf" value="' . csrf_token() . '">'; }
function csrf_validar(): void {
    if (!hash_equals($_SESSION['csrf'] ?? '', (string)($_POST['csrf'] ?? ''))) {
        http_response_code(403);
        die('Sessão expirada ou requisição inválida. Volte à página anterior, atualize e tente novamente.');
    }
}

/**
 * Restringe a página à equipe. O perfil é conferido no banco a cada acesso:
 * se o usuário for desativado ou mudar de perfil, o efeito é imediato.
 * Visitantes sem login vão para o login; logados sem permissão veem erro 403.
 */
function exigir_login(array $perfis = []) {
    global $pdo;
    $u = $_SESSION['usuario'] ?? null;
    if ($u) {
        $st = $pdo->prepare("SELECT id, nome, perfil FROM usuarios WHERE id = ? AND ativo = 1");
        $st->execute([(int)$u['id']]);
        $db = $st->fetch();
        if ($db) { $u = $_SESSION['usuario'] = ['id' => $db['id'], 'nome' => $db['nome'], 'perfil' => $db['perfil']]; }
        else     { unset($_SESSION['usuario']); $u = null; }
    }
    if (!$u) { header('Location: login.php'); exit; }
    if ($perfis && !in_array($u['perfil'], $perfis, true)) {
        http_response_code(403);
        topo('Acesso restrito', 'Seu perfil não tem permissão para acessar esta página.');
        echo '<div class="vazio">' . icone('usuario', 28) . '<h2>Acesso restrito</h2><p>Fale com um administrador se precisar deste acesso.</p><a class="btn btn-primary" href="painel.php">Voltar ao painel</a></div>';
        rodape(); exit;
    }
}

/* ===================== Configurações da loja (tabela configuracoes) ===================== */
function cfg(string $chave): string {
    global $pdo;
    static $c = null;
    if ($c === null) {
        $c = [
            'pix_chave' => '', 'pix_nome' => ascii_maiusc(NOME_LANCHONETE), 'pix_cidade' => 'CIDADE',
            'taxa_entrega' => number_format(TAXA_ENTREGA, 2, '.', ''),
        ];
        try {
            foreach ($pdo->query("SELECT chave, valor FROM configuracoes") as $l) $c[$l['chave']] = $l['valor'];
        } catch (Throwable $t) { /* tabela ainda não criada: usa padrões */ }
    }
    return $c[$chave] ?? '';
}
function cfg_salvar(PDO $pdo, string $chave, string $valor): void {
    $pdo->prepare("INSERT INTO configuracoes (chave, valor) VALUES (?, ?) ON DUPLICATE KEY UPDATE valor = VALUES(valor)")->execute([$chave, $valor]);
}

/* ===================== Valores ===================== */
/** Converte "1.234,50", "12,5" ou "12.50" em float com 2 casas. */
function parse_preco($raw): float {
    $s = trim((string)$raw);
    if (strpos($s, ',') !== false) $s = str_replace('.', '', $s);
    return round((float)str_replace(',', '.', $s), 2);
}
function taxa_entrega(): float { return (float)cfg('taxa_entrega'); }

/* ===================== Pix (BR Code estático, "copia e cola") ===================== */
function ascii_maiusc(string $s): string {
    $s = strtr($s, ['á'=>'a','à'=>'a','â'=>'a','ã'=>'a','ä'=>'a','é'=>'e','è'=>'e','ê'=>'e','ë'=>'e','í'=>'i','ì'=>'i','î'=>'i','ï'=>'i',
        'ó'=>'o','ò'=>'o','ô'=>'o','õ'=>'o','ö'=>'o','ú'=>'u','ù'=>'u','û'=>'u','ü'=>'u','ç'=>'c',
        'Á'=>'A','À'=>'A','Â'=>'A','Ã'=>'A','Ä'=>'A','É'=>'E','È'=>'E','Ê'=>'E','Ë'=>'E','Í'=>'I','Ì'=>'I','Î'=>'I','Ï'=>'I',
        'Ó'=>'O','Ò'=>'O','Ô'=>'O','Õ'=>'O','Ö'=>'O','Ú'=>'U','Ù'=>'U','Û'=>'U','Ü'=>'U','Ç'=>'C']);
    return strtoupper(preg_replace('/[^A-Za-z0-9 ]/', '', $s));
}
function pix_payload(string $chave, string $nome, string $cidade, float $valor, string $txid): string {
    $f = fn(string $id, string $v) => $id . str_pad((string)strlen($v), 2, '0', STR_PAD_LEFT) . $v;
    $nome   = substr(ascii_maiusc($nome), 0, 25) ?: 'LOJA';
    $cidade = substr(ascii_maiusc($cidade), 0, 15) ?: 'BRASIL';
    $txid   = substr(preg_replace('/[^A-Za-z0-9]/', '', $txid), 0, 25) ?: '***';
    $p = $f('00', '01')
       . $f('26', $f('00', 'br.gov.bcb.pix') . $f('01', $chave))
       . $f('52', '0000') . $f('53', '986')
       . $f('54', number_format($valor, 2, '.', ''))
       . $f('58', 'BR') . $f('59', $nome) . $f('60', $cidade)
       . $f('62', $f('05', $txid)) . '6304';
    $crc = 0xFFFF;
    for ($i = 0, $n = strlen($p); $i < $n; $i++) {
        $crc ^= ord($p[$i]) << 8;
        for ($j = 0; $j < 8; $j++) $crc = ($crc & 0x8000) ? ((($crc << 1) ^ 0x1021) & 0xFFFF) : (($crc << 1) & 0xFFFF);
    }
    return $p . strtoupper(str_pad(dechex($crc), 4, '0', STR_PAD_LEFT));
}

/* ===================== Imagens de produtos ===================== */
/** Valida e salva a imagem (reencodada em JPG, máx. 900px). Retorna o nome do arquivo. */
function salvar_imagem(array $f): string {
    if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new Exception(in_array($f['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
            ? 'A imagem excede o tamanho máximo permitido.' : 'Falha ao enviar a imagem. Tente novamente.');
    }
    if ($f['size'] > IMG_MAX_BYTES) throw new Exception('A imagem deve ter no máximo ' . (IMG_MAX_BYTES / 1048576) . ' MB.');
    $info  = @getimagesize($f['tmp_name']);
    $tipos = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'];
    if (!$info || !isset($tipos[$info[2]])) throw new Exception('Envie uma imagem nos formatos JPG, PNG ou WEBP.');
    if ($info[0] > 6000 || $info[1] > 6000) throw new Exception('Dimensões muito grandes (máximo 6000 x 6000 px).');

    $dir = __DIR__ . '/uploads/produtos';
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) throw new Exception('Não foi possível criar a pasta de imagens.');
    $base = bin2hex(random_bytes(12));

    $loaders = [IMAGETYPE_JPEG => 'imagecreatefromjpeg', IMAGETYPE_PNG => 'imagecreatefrompng', IMAGETYPE_WEBP => 'imagecreatefromwebp'];
    $fn = $loaders[$info[2]];
    if (extension_loaded('gd') && function_exists($fn) && ($src = @$fn($f['tmp_name']))) {
        $w = imagesx($src); $h = imagesy($src);
        $r = min(1, 900 / $w);
        $nw = max(1, (int)round($w * $r)); $nh = max(1, (int)round($h * $r));
        $dst = imagecreatetruecolor($nw, $nh);
        imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
        $arq = "$base.jpg";
        $ok = imagejpeg($dst, "$dir/$arq", 82);
        imagedestroy($src); imagedestroy($dst);
        if ($ok) return $arq;
    }
    $arq = $base . '.' . $tipos[$info[2]];
    if (!move_uploaded_file($f['tmp_name'], "$dir/$arq")) throw new Exception('Não foi possível salvar a imagem.');
    return $arq;
}
function apagar_imagem(?string $nome): void {
    if ($nome && preg_match('/^[a-f0-9]{24}\.(jpg|png|webp)$/', $nome)) @unlink(__DIR__ . '/uploads/produtos/' . $nome);
}

/* ===================== Carrinho ===================== */
/** Itens do carrinho com dados atuais do banco. Retorna [itens, subtotal]. */
function carrinho_detalhado(PDO $pdo): array {
    $carrinho = $_SESSION['carrinho'] ?? [];
    $itens = []; $subtotal = 0;
    if ($carrinho) {
        $ids = array_keys($carrinho);
        $st = $pdo->prepare("SELECT id, nome, preco FROM produtos WHERE ativo = 1 AND id IN (" . implode(',', array_fill(0, count($ids), '?')) . ") ORDER BY nome");
        $st->execute($ids);
        foreach ($st->fetchAll() as $p) {
            $p['qtd'] = $carrinho[$p['id']];
            $p['total'] = $p['preco'] * $p['qtd'];
            $subtotal += $p['total'];
            $itens[] = $p;
        }
    }
    return [$itens, $subtotal];
}

/* ===================== Interface ===================== */
/** Ícones de linha, simples e consistentes (stroke herda a cor do texto). */
function icone(string $nome, int $tam = 18): string {
    static $d = [
        'inicio'   => '<path d="M3 10.5 12 3l9 7.5"/><path d="M5 9.5V21h14V9.5"/>',
        'cardapio' => '<path d="M2 4h6a4 4 0 0 1 4 4v13a3 3 0 0 0-3-3H2z"/><path d="M22 4h-6a4 4 0 0 0-4 4v13a3 3 0 0 1 3-3h7z"/>',
        'carrinho' => '<circle cx="9" cy="20" r="1"/><circle cx="18" cy="20" r="1"/><path d="M2 3h3l2.7 12.4a2 2 0 0 0 2 1.6h8.1a2 2 0 0 0 2-1.6L21 7H6"/>',
        'pedidos'  => '<rect x="6" y="4" width="12" height="17" rx="2"/><path d="M9 4h6v3H9z"/><path d="M9 12h6M9 16h4"/>',
        'produtos' => '<path d="M21 8 12 3 3 8v8l9 5 9-5z"/><path d="M3 8l9 5 9-5M12 13v8"/>',
        'config'   => '<path d="M4 6h10M18 6h2M4 12h2M10 12h10M4 18h12M20 18h0"/><circle cx="16" cy="6" r="2"/><circle cx="8" cy="12" r="2"/><circle cx="18" cy="18" r="2"/>',
        'usuario'  => '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
        'sair'     => '<path d="M9 21H5V3h4"/><path d="M16 17l5-5-5-5M21 12H9"/>',
        'download' => '<path d="M12 3v12M7 10l5 5 5-5"/><path d="M4 21h16"/>',
        'mais'     => '<path d="M12 5v14M5 12h14"/>',
        'menos'    => '<path d="M5 12h14"/>',
        'lixeira'  => '<path d="M4 7h16M10 11v6M14 11v6"/><path d="M6 7l1 13h10l1-13M9 7V4h6v3"/>',
        'check'    => '<path d="M5 12l5 5L20 7"/>',
        'menu'     => '<path d="M4 6h16M4 12h16M4 18h16"/>',
        'relogio'  => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'moto'     => '<circle cx="6" cy="17" r="3"/><circle cx="18" cy="17" r="3"/><path d="M6 17h5l3-7h3M14 10l-2-4H9"/>',
        'copiar'   => '<rect x="9" y="9" width="11" height="11" rx="2"/><path d="M5 15V6a2 2 0 0 1 2-2h9"/>',
        'upload'   => '<path d="M12 16V4M7 9l5-5 5 5"/><path d="M4 20h16"/>',
        'imagem'   => '<rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="9" cy="10" r="1.5"/><path d="M21 16l-5-5-9 9"/>',
        'editar'   => '<path d="M4 20h4L19 9l-4-4L4 16z"/><path d="M13.5 6.5l4 4"/>',
    ];
    return '<svg class="ic" width="' . $tam . '" height="' . $tam . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($d[$nome] ?? '') . '</svg>';
}

function badge_status(string $s): string {
    return '<span class="badge badge-' . str_replace(' ', '-', e($s)) . '">' . e(ucfirst($s)) . '</span>';
}

/**
 * Abre a página: sidebar fixa + cabeçalho da página.
 * $ativo: inicio | cardapio | carrinho | pedidos | produtos | config
 * $sub e $acoes aceitam HTML (escape antes de passar).
 * A área da equipe só aparece no menu para quem está logado.
 */
function topo(string $titulo, string $sub = '', string $ativo = '', string $acoes = '') {
    $qtd = array_sum($_SESSION['carrinho'] ?? []);
    $u = $_SESSION['usuario'] ?? null;
    $link = function ($k, $href, $ic, $txt, $extra = '') use ($ativo) {
        return '<a href="' . $href . '" class="nav-link' . ($ativo === $k ? ' ativo' : '') . '">' . icone($ic) . '<span>' . $txt . '</span>' . $extra . '</a>';
    };

    echo '<!DOCTYPE html><html lang="pt-BR"><head><meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>' . e($titulo) . ' - ' . e(NOME_LANCHONETE) . '</title>
<link rel="stylesheet" href="estilo.css?v=5"></head><body>
<div class="topbar-mobile"><button class="btn-icon" type="button" id="btn-menu" aria-label="Abrir menu">' . icone('menu') . '</button><strong>' . e(NOME_LANCHONETE) . '</strong></div>
<div class="overlay" id="overlay"></div>
<aside class="sidebar">
    <div class="marca"><span class="marca-logo">' . e(mb_substr(NOME_LANCHONETE, 0, 1)) . '</span><span>' . e(NOME_LANCHONETE) . '</span></div>
    <nav class="nav">
        <span class="nav-titulo">Loja</span>'
        . $link('inicio', 'index.php', 'inicio', 'Início')
        . $link('cardapio', 'cardapio.php', 'cardapio', 'Cardápio')
        . $link('carrinho', 'carrinho.php', 'carrinho', 'Carrinho', $qtd ? '<em class="contador">' . $qtd . '</em>' : '');

    if ($u) {
        echo '<span class="nav-titulo">Administração</span>'
            . $link('pedidos', 'painel.php', 'pedidos', 'Pedidos')
            . ($u['perfil'] === 'admin' ? $link('produtos', 'produtos.php', 'produtos', 'Produtos') . $link('config', 'configuracoes.php', 'config', 'Configurações') : '');
    }
    echo '</nav>';
    if ($u) {
        echo '<div class="sidebar-rodape"><div class="usuario-info">' . e($u['nome']) . '<small>' . e(ucfirst($u['perfil'])) . '</small></div>'
            . $link('sair', 'logout.php', 'sair', 'Sair') . '</div>';
    }
    echo '</aside>
<main class="conteudo"><div class="container">
<div class="pagina-topo"><div><h1>' . e($titulo) . '</h1>' . ($sub ? '<p>' . $sub . '</p>' : '') . '</div>'
        . ($acoes ? '<div class="acoes">' . $acoes . '</div>' : '') . '</div>';
}

function rodape() {
    echo '</div></main><script>
const b = document.body;
document.getElementById("btn-menu").onclick = () => b.classList.toggle("menu-aberto");
document.getElementById("overlay").onclick = () => b.classList.remove("menu-aberto");
</script></body></html>';
}
