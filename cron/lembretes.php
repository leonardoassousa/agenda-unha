<?php
// Envia os lembretes no WhatsApp das clientes.
// Rode a cada 5 ou 10 minutos (opcional: o sistema também dispara sozinho quando alguém usa o link).
//   VPS (crontab):   */10 * * * * php /caminho/agenda-unhas/cron/lembretes.php
//   Windows/XAMPP:   Agendador de Tarefas -> C:\xampp\php\php.exe C:\xampp\htdocs\agenda-unhas\cron\lembretes.php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Só pela linha de comando.'); }
require __DIR__ . '/../includes/app.php';
require __DIR__ . '/../includes/whatsapp.php';
$n = rodarLembretes();
echo date('Y-m-d H:i') . " lembretes enviados: $n\n";
