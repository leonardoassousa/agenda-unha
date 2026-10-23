<?php
// Contas das clientes: cadastro, login com celular + senha, sessão de 30 dias.
require_once __DIR__ . '/app.php';

const SESSAO_CLIENTE_DIAS = 30;

function iniciarSessaoCliente(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;
    $vida = SESSAO_CLIENTE_DIAS * 86400;
    ini_set('session.gc_maxlifetime', (string) $vida);
    session_set_cookie_params([
        'lifetime' => $vida, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax',
        'secure' => !empty($_SERVER['HTTPS']),
    ]);
    session_name('agenda_cliente');
    session_start();
}

/** Cliente logada ou null. Confere no banco (conta apagada = deslogada). */
function clienteAtual(): ?array
{
    static $cache = false;
    if ($cache !== false) return $cache;
    $id = (int) ($_SESSION['cliente_id'] ?? 0);
    if (!$id) return $cache = null;
    $st = db()->prepare('SELECT id, nome, telefone, email FROM clientes WHERE id = ?');
    $st->execute([$id]);
    $c = $st->fetch() ?: null;
    if (!$c) unset($_SESSION['cliente_id']);
    return $cache = $c;
}

function clientePublico(?array $c): ?array
{
    if (!$c) return null;
    $partes = preg_split('/\s+/', trim($c['nome']));
    return [
        'nome' => $c['nome'],
        'primeiro_nome' => $partes[0],
        'telefone' => telefoneBonito($c['telefone']),
        'email' => $c['email'],
    ];
}

function logarCliente(int $id): void
{
    session_regenerate_id(true);
    $_SESSION['cliente_id'] = $id;
    db()->prepare('UPDATE clientes SET ultimo_login = NOW() WHERE id = ?')->execute([$id]);
}

function ipCliente(): string
{
    return substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
}

/** Bloqueia depois de 5 erros em 15 minutos no mesmo celular (ou 20 no mesmo IP, pra robô testando vários). */
function loginBloqueado(string $tel): bool
{
    db()->exec('DELETE FROM login_tentativas WHERE criado_em < NOW() - INTERVAL 1 DAY');
    $st = db()->prepare('SELECT COUNT(*) FROM login_tentativas WHERE chave = ? AND criado_em > NOW() - INTERVAL 15 MINUTE');
    $st->execute(['tel:' . $tel]);
    if ((int) $st->fetchColumn() >= 5) return true;
    $st->execute(['ip:' . ipCliente()]);
    return (int) $st->fetchColumn() >= 20;
}

function registrarErroLogin(string $tel): void
{
    $st = db()->prepare('INSERT INTO login_tentativas (chave) VALUES (?)');
    $st->execute(['tel:' . $tel]);
    $st->execute(['ip:' . ipCliente()]);
}

function entrarCliente(string $telefone, string $senha): array
{
    $tel = normalizarTelefone($telefone);
    if ($tel === '') throw new InvalidArgumentException('Digite o celular com DDD, ex.: (73) 99999-9999.');
    if ($senha === '') throw new InvalidArgumentException('Digite sua senha.');
    if (loginBloqueado($tel)) throw new RuntimeException('Muitas tentativas erradas. Espere 15 minutos ou fale com o estúdio pelo WhatsApp.');

    $st = db()->prepare('SELECT id, senha_hash FROM clientes WHERE telefone = ?');
    $st->execute([$tel]);
    $c = $st->fetch();
    if (!$c || !password_verify($senha, $c['senha_hash'])) {
        registrarErroLogin($tel);
        if (!$c) throw new RuntimeException('Não achamos conta com esse celular. Toque em "Criar conta".');
        throw new RuntimeException('Senha incorreta. Se esqueceu, fale com o estúdio pelo WhatsApp que elas geram uma nova.');
    }
    if (password_needs_rehash($c['senha_hash'], PASSWORD_DEFAULT)) {
        db()->prepare('UPDATE clientes SET senha_hash = ? WHERE id = ?')->execute([password_hash($senha, PASSWORD_DEFAULT), $c['id']]);
    }
    logarCliente((int) $c['id']);
    return clienteAtual();
}

function cadastrarCliente(string $nome, string $telefone, string $email, string $senha): array
{
    $nome = trim(preg_replace('/\s+/', ' ', strip_tags($nome)));
    $email = mb_strtolower(trim($email));
    $tel = normalizarTelefone($telefone);

    if (mb_strlen($nome) < 5 || count(explode(' ', $nome)) < 2) throw new InvalidArgumentException('Digite seu nome completo (nome e sobrenome).');
    if (mb_strlen($nome) > 100) throw new InvalidArgumentException('Nome muito longo.');
    if ($tel === '') throw new InvalidArgumentException('Digite o celular com DDD, ex.: (73) 99999-9999.');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 150) throw new InvalidArgumentException('Digite um e-mail válido, ex.: maria@gmail.com.');
    if (mb_strlen($senha) < 6) throw new InvalidArgumentException('A senha precisa ter pelo menos 6 caracteres.');
    if (mb_strlen($senha) > 72) throw new InvalidArgumentException('Senha muito longa.');

    $st = db()->prepare('SELECT telefone = ? tel, email = ? em FROM clientes WHERE telefone = ? OR email = ? LIMIT 1');
    $st->execute([$tel, $email, $tel, $email]);
    if ($dup = $st->fetch()) {
        throw new RuntimeException($dup['tel']
            ? 'Já existe uma conta com esse celular. Toque em "Entrar".'
            : 'Esse e-mail já está em outra conta. Use outro ou toque em "Entrar".');
    }

    try {
        db()->prepare('INSERT INTO clientes (nome, telefone, email, senha_hash) VALUES (?, ?, ?, ?)')
            ->execute([$nome, $tel, $email, password_hash($senha, PASSWORD_DEFAULT)]);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') throw new RuntimeException('Já existe uma conta com esse celular ou e-mail. Toque em "Entrar".');
        throw $e;
    }
    $id = (int) db()->lastInsertId();
    // Horários marcados antes com esse celular passam a aparecer na conta
    db()->prepare('UPDATE agendamentos SET cliente_id = ? WHERE cliente_telefone = ? AND cliente_id IS NULL')->execute([$id, $tel]);
    logarCliente($id);
    return clienteAtual();
}

function sairCliente(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', ['expires' => time() - 3600, 'path' => $p['path'], 'httponly' => true, 'samesite' => 'Lax', 'secure' => $p['secure']]);
    }
    session_destroy();
}

/** Próximos horários da cliente logada. */
function horariosDaCliente(int $clienteId): array
{
    // Serviço em dupla vira uma linha só (as duas profissionais juntas e o valor inteiro)
    $st = db()->prepare("SELECT MIN(a.id) id, MIN(a.inicio) inicio, SUM(a.preco) preco, MIN(s.nome) servico,
               GROUP_CONCAT(p.nome ORDER BY a.id SEPARATOR '|') nomes, MIN(p.id) profissional_id, COUNT(*) partes
        FROM agendamentos a JOIN servicos s ON s.id = a.servico_id JOIN profissionais p ON p.id = a.profissional_id
        WHERE a.cliente_id = ? AND a.status = 'confirmado' AND a.fim > NOW()
        GROUP BY COALESCE(a.grupo, a.id) ORDER BY inicio LIMIT 20");
    $st->execute([$clienteId]);
    $limite = (int) (cfg('cancelar_ate_horas') ?? 2) * 3600;
    return array_map(fn($a) => [
        'id' => (int) $a['id'],
        'servico' => $a['servico'],
        'profissional' => nomesJuntos(explode('|', $a['nomes'])),
        'profissional_id' => (int) $a['partes'] > 1 ? 0 : (int) $a['profissional_id'],
        'data_extenso' => dataExtenso(substr($a['inicio'], 0, 10)),
        'hora' => substr($a['inicio'], 11, 5),
        'preco_fmt' => preco((float) $a['preco']),
        'pode_cancelar' => strtotime($a['inicio']) - time() >= $limite,
    ], $st->fetchAll());
}

/** Cliente cancela o próprio horário. Retorna os dados pra avisar o estúdio. */
function cancelarPelaCliente(int $clienteId, int $agId): array
{
    $st = db()->prepare("SELECT id, inicio, grupo FROM agendamentos WHERE id = ? AND cliente_id = ? AND status = 'confirmado'");
    $st->execute([$agId, $clienteId]);
    $a = $st->fetch();
    if (!$a) throw new RuntimeException('Esse horário não foi encontrado ou já foi cancelado.');
    $horas = (int) (cfg('cancelar_ate_horas') ?? 2);
    if (strtotime($a['inicio']) - time() < $horas * 3600) {
        throw new RuntimeException("Pelo site dá pra cancelar até $horas h antes. Agora, fale com o estúdio pelo WhatsApp.");
    }
    $dados = dadosDoAgendamento($agId); // antes de cancelar, pra pegar as duas profissionais se for em dupla
    db()->prepare("UPDATE agendamentos SET status = 'cancelado' WHERE (id = ? OR grupo = ?) AND cliente_id = ? AND status = 'confirmado'")
        ->execute([$agId, $a['grupo'] ?: $agId, $clienteId]);
    return $dados;
}

function trocarSenhaCliente(int $clienteId, string $atual, string $nova): void
{
    $st = db()->prepare('SELECT telefone, senha_hash FROM clientes WHERE id = ?');
    $st->execute([$clienteId]);
    $c = $st->fetch();
    if (!$c) throw new RuntimeException('Conta não encontrada.');
    if (loginBloqueado($c['telefone'])) throw new RuntimeException('Muitas tentativas erradas. Espere 15 minutos.');
    if (!password_verify($atual, $c['senha_hash'])) {
        registrarErroLogin($c['telefone']);
        throw new RuntimeException('A senha atual está errada.');
    }
    if (mb_strlen($nova) < 6) throw new InvalidArgumentException('A nova senha precisa ter pelo menos 6 caracteres.');
    if (mb_strlen($nova) > 72) throw new InvalidArgumentException('Senha muito longa.');
    db()->prepare('UPDATE clientes SET senha_hash = ? WHERE id = ?')->execute([password_hash($nova, PASSWORD_DEFAULT), $clienteId]);
    session_regenerate_id(true);
}
