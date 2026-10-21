<?php
// Proteção dos formulários (CSRF), usada no painel e na área da equipe.

function prepararCsrf(): void
{
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
}

function conferirCsrf(): void
{
    if (!hash_equals($_SESSION['csrf'] ?? '', (string) ($_POST['csrf'] ?? ''))) {
        http_response_code(400);
        exit('Sessão expirou. Volte e tente de novo.');
    }
}

function campoCsrf(): string
{
    return '<input type="hidden" name="csrf" value="' . e($_SESSION['csrf']) . '">';
}
