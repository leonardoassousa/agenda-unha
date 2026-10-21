<?php
// ====== Configuração do estúdio — edite aqui ======
return [
    // Banco de dados
    'db' => [
        'host' => 'localhost',
        'nome' => 'agenda_unhas',
        'usuario' => 'root',
        'senha' => '',
    ],

    // Dados do estúdio
    'nome_estudio' => 'Studio de Unhas',
    'instagram'    => '@studiodeunhas',
    'endereco'     => 'Rua Exemplo, 123 - Centro',
    // WhatsApp do estúdio com DDI+DDD, só números (deixe '' pra esconder o botão)
    'whatsapp'     => '5573999999999',

    'fuso' => 'America/Bahia',

    // Horário de funcionamento por dia da semana (0 = domingo ... 6 = sábado)
    // null = fechado
    'horarios' => [
        0 => null,
        1 => ['09:00', '19:00'],
        2 => ['09:00', '19:00'],
        3 => ['09:00', '19:00'],
        4 => ['09:00', '19:00'],
        5 => ['09:00', '19:00'],
        6 => ['08:00', '14:00'],
    ],
    // Pausa de almoço de todas (null pra desligar)
    'almoco' => ['12:00', '13:00'],

    'intervalo_slots_min' => 30, // de quanto em quanto tempo aparecem os horários
    'antecedencia_min'    => 60, // não deixa marcar com menos de 1h de antecedência
    'dias_a_frente'       => 30, // até quantos dias pra frente dá pra marcar

    // Aviso no WhatsApp do estúdio a cada agendamento (veja o LEIA-ME)
    'aviso_whatsapp' => [
        'modo' => 'desligado',          // 'desligado', 'callmebot' ou 'evolution'
        'telefone' => '5573999999999',  // número que RECEBE o aviso, com 55 + DDD

        // CallMeBot (grátis): mande "I allow callmebot to send me messages" pro bot e cole a apikey aqui
        'callmebot_apikey' => '',

        // Evolution API (se tiver rodando na sua VPS)
        'evolution_url' => '',          // ex.: https://evo.seudominio.com.br
        'evolution_instancia' => '',
        'evolution_apikey' => '',
    ],

    // Lembrete no WhatsApp da CLIENTE antes do horário (só funciona com a Evolution API)
    'lembrete_cliente' => [
        'ativo' => true,
        'horas_antes' => 3,   // manda 3 horas antes
    ],

    // Quantos horários futuros a mesma cliente (mesmo WhatsApp) pode ter ao mesmo tempo. 0 = sem limite
    'max_agendamentos_por_cliente' => 3,

    // Até quantas horas antes a cliente pode cancelar pelo site (depois disso, só pelo WhatsApp)
    'cancelar_ate_horas' => 2,

    // Senha do painel (/admin). TROQUE antes de colocar no ar.
    'admin_senha' => 'troque-esta-senha',
];
