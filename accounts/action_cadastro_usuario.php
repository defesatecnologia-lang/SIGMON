<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../inc/check_access.php';

requererAcesso('admin');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['submit'])) {
    header('Location: index.php?page=cadastroUsuario');
    exit;
}

$csrfToken = $_POST['csrf_token'] ?? null;
if (!validateCsrfToken($csrfToken)) {
    $_SESSION['erro_cadastro'] = 'Falha de validacao CSRF.';
    header('Location: index.php?page=cadastroUsuario');
    exit;
}

$db = db();

$nome = trim($_POST['text_nome'] ?? '');
$usuario = trim($_POST['text_usuario'] ?? '');
$email = trim($_POST['text_email'] ?? '');
$senha = $_POST['text_senha'] ?? '';
$nivel_acesso = $_POST['text_nivel_acesso'] ?? 'user';
if (!in_array($nivel_acesso, ['admin', 'supervisor', 'user'], true)) {
    $nivel_acesso = 'user';
}

$secretaria_id = $_POST['text_secretaria_id'] ?? '';
if ($secretaria_id !== '') {
    $secretaria_id = (int)$secretaria_id;
    if ($secretaria_id < 1) $secretaria_id = null;
} else {
    $secretaria_id = null;
}

/**
 * Mantem os dados preenchidos quando o formulario e reaberto por erro.
 * A senha nunca e persistida em sessao.
 *
 * @param array<string, string> $overrides
 */
$flashForm = static function (array $overrides = []) use ($nome, $usuario, $email, $nivel_acesso, $secretaria_id): void {
    $_SESSION['form_cadastro'] = array_merge([
        'nome' => $nome,
        'usuario' => $usuario,
        'email' => $email,
        'nivel_acesso' => $nivel_acesso,
        'secretaria_id' => $secretaria_id === null ? '' : (string)$secretaria_id,
    ], $overrides);
};

if ($nivel_acesso !== 'admin' && $secretaria_id === null) {
    $flashForm();
    $_SESSION['erro_cadastro'] = 'Secretaria e obrigatoria para usuarios e supervisores.';
    header('Location: index.php?page=cadastroUsuario');
    exit;
}

// Administradores podem ter secretaria vinculada (opcional). O valor
// informado e preservado em vez de zerado, para nao perder a selecao feita
// pelo operador no formulario.

if (mb_strlen($nome) > 255 || mb_strlen($usuario) > 100) {
    $flashForm();
    $_SESSION['erro_cadastro'] = 'Nome ou usuario muito longo.';
    header('Location: index.php?page=cadastroUsuario');
    exit;
}

if ($email !== '') {
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $flashForm();
        $_SESSION['erro_cadastro'] = 'E-mail invalido.';
        header('Location: index.php?page=cadastroUsuario');
        exit;
    }
    if (mb_strlen($email) > 150) {
        $flashForm();
        $_SESSION['erro_cadastro'] = 'E-mail muito longo.';
        header('Location: index.php?page=cadastroUsuario');
        exit;
    }
    $email = strtolower($email);
} else {
    $email = null;
}

$nivel_acesso_id = 3;
if ($nivel_acesso === 'admin') {
    $nivel_acesso_id = 1;
} elseif ($nivel_acesso === 'supervisor') {
    $nivel_acesso_id = 2;
}

if (empty($nome) || empty($usuario) || empty($senha)) {
    $flashForm();
    $_SESSION['erro_cadastro'] = 'Todos os campos sao obrigatorios!';
    header('Location: index.php?page=cadastroUsuario');
    exit;
}

$passwordErrors = [];
if (!validatePasswordPolicy($senha, $passwordErrors)) {
    $flashForm();
    $_SESSION['erro_cadastro'] = implode(' ', $passwordErrors);
    header('Location: index.php?page=cadastroUsuario');
    exit;
}

try {
    $sql = "SELECT COUNT(*) AS total FROM usuarios WHERE usuario = :usuario";
    $result = $db->query($sql, [':usuario' => $usuario]);
    if ($result['status'] === 'success' && !empty($result['data']) && $result['data'][0]->total >= 1) {
        $flashForm();
        $_SESSION['usuario_existe'] = true;
        header('Location: index.php?page=cadastroUsuario');
        exit;
    }
} catch (Exception $ex) {
    error_log('Erro ao verificar usuario: ' . $ex->getMessage());
}

if ($secretaria_id !== null) {
    try {
        $sql = "SELECT id, ativo FROM secretarias WHERE id = :id LIMIT 1";
        $result = $db->query($sql, [':id' => $secretaria_id]);
        if ($result['status'] !== 'success' || empty($result['data']) || (int)$result['data'][0]->ativo !== 1) {
            $flashForm(['secretaria_id' => '']);
            $_SESSION['erro_cadastro'] = 'Secretaria invalida ou inativa.';
            header('Location: index.php?page=cadastroUsuario');
            exit;
        }
    } catch (Exception $ex) {
        error_log('Erro ao verificar secretaria: ' . $ex->getMessage());
    }
}

if ($email !== null) {
    try {
        $sql = "SELECT COUNT(*) AS total FROM usuarios WHERE email = :email";
        $result = $db->query($sql, [':email' => $email]);
        if ($result['status'] === 'success' && !empty($result['data']) && $result['data'][0]->total >= 1) {
            $flashForm();
            $_SESSION['email_existe'] = true;
            header('Location: index.php?page=cadastroUsuario');
            exit;
        }
    } catch (Exception $ex) {
        error_log('Erro ao verificar email: ' . $ex->getMessage());
    }
}

try {
    $sql = "SELECT COUNT(*) AS total FROM usuarios WHERE nome = :nome";
    $result = $db->query($sql, [':nome' => $nome]);
    if ($result['status'] === 'success' && !empty($result['data']) && $result['data'][0]->total >= 1) {
        $flashForm();
        $_SESSION['nome_existe'] = true;
        header('Location: index.php?page=cadastroUsuario');
        exit;
    }
} catch (Exception $ex) {
    error_log('Erro ao verificar nome: ' . $ex->getMessage());
}

$senha_hash = hashPassword($senha);

try {
    enableAuditTriggers($db, 'web');

    $sql = "INSERT INTO usuarios (nome, usuario, email, senha, nivel_acesso_id, secretaria_id, senha_temporaria) VALUES (:nome, :usuario, :email, :senha, :nivel_acesso_id, :secretaria_id, 1)";
    $params = [
        ':nome' => $nome,
        ':usuario' => $usuario,
        ':email' => $email,
        ':senha' => $senha_hash,
        ':nivel_acesso_id' => $nivel_acesso_id,
        ':secretaria_id' => $secretaria_id,
    ];

    $result = $db->query($sql, $params);
} catch (Throwable $ex) {
    error_log('Erro ao cadastrar usuario: ' . $ex->getMessage());
    $flashForm();
    $_SESSION['erro_cadastro'] = 'Erro ao cadastrar usuario! Tente novamente.';
    header('Location: index.php?page=cadastroUsuario');
    exit;
}

if (($result['status'] ?? 'error') === 'success') {
    unset($_SESSION['form_cadastro']);
    $_SESSION['status_cadastro'] = true;
} else {
    $flashForm();
    $_SESSION['erro_cadastro'] = 'Erro ao cadastrar usuario!';
}

header('Location: index.php?page=cadastroUsuario');
exit;
