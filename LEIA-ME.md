# Agenda de unhas · Studio Carina Aquino

**Versão 1.9** (o número fica no arquivo `VERSION` e em tags no GitHub, ex.: `v1.9`)

## Versões
- **1.9** (04/11/2026): pedidos da Carina depois da apresentação. **Marcar horário pelo painel**: busca ou cadastra a cliente (nome e celular), escolhe serviço, manicure, dia e horário e digita o valor combinado (é o que vai pro caixa). **Horário fixo**: "repetir toda semana até tal dia"; semana ocupada fica de fora e o painel avisa; dá pra cancelar só um dia ou esse e os próximos. **Dupla escolhida pela cliente**: no pé e mão com duas manicures ela marca as duas que quer, entre as que atendem no dia, e vê só os horários em que as duas estão livres. **Caixa só da dona**: a equipe não tem mais "Meu caixa" nem o caixa do dia. **WhatsApp**: aviso de cada horário marcado e cancelado no grupo da equipe (mensagem com cliente, visita, serviço, valor, dia, hora, quem atende, horário fixo, observação e link do painel), lembrete da cliente 2 h antes com endereço, envio com pausa entre mensagens. `servidor/evolution/` com o docker-compose da Evolution API.
- **1.8** (03/11/2026): "Esqueci a senha" por e-mail. A cliente digita o e-mail da conta, recebe um link (vale 1 hora, uso único) e cria a senha nova sozinha, já entrando na conta. Continua dando pra Carina gerar senha nova pelo painel e mandar no WhatsApp. Envio por SMTP (Gmail do estúdio ou e-mail do domínio), bloco `email` no `config.php`. Barra de abas do computador com fundo sólido e vitrine compacta em tela de notebook.
- **1.7** (03/11/2026): pronto pra VPS. `instalar.sh` + pasta `servidor/` (Nginx, regras de segurança, PHP com usuário próprio, sessões de 30 dias que o Ubuntu não apaga, backup diário, cron dos lembretes, HTTPS). Ícone na tela inicial do celular (app das clientes e app da equipe; pelo ícone a dona cai direto no painel). Login da equipe também trava por aparelho/rede depois de 20 erros. CSS e JS formatados no padrão (Prettier), com sumário de seções no topo de cada CSS.
- **1.6** (02/11/2026): a equipe aparece em bolinhas redondas (antes era em formato de unha), na capa, na escolha da profissional e na confirmação.
- **1.5** (02/11/2026): tela da cliente nova. Capa em laca escura com a equipe em formato de unha (foto ou cor de cada uma), horário de funcionamento e endereço logo na capa, serviços agrupados por tipo (Mãos e pés, Gel e alongamento, Spa e relaxamento) com atalhos, selo "Um dos mais pedidos" (com base nos agendamentos dos últimos 60 dias), duração em horas (2h, 1h30) e link de dúvida no WhatsApp. Fontes Bodoni Moda e Hanken Grotesk servidas do próprio site (`assets/fonts`). A coluna `servicos.categoria` é criada sozinha (mesmo que `sql/atualizar-v9.sql`).
- **1.4** (02/11/2026): login único em `/equipe/` pra dona e pra equipe. A dona (quem tem acesso ao painel) cai direto no painel com o faturamento; as manicures caem na agenda delas. `/admin/` sem login manda pro login único. O painel é de uma dona só: depois que ela tem acesso, ninguém mais recebe acesso ao painel.
- **1.3** (02/11/2026): instalador `instalar.bat`: dois cliques e o sistema fica pronto no XAMPP (copia os arquivos, cria o banco e abre no navegador).
- **1.2** (26/10/2026): cada uma com a própria conta (celular + senha) e a dona entra no painel com essa mesma conta ("acesso ao painel" em Ajustes); a senha do `config.php` vira só de primeiro acesso. Layout de computador: vitrine do estúdio à esquerda na tela da cliente, serviços em duas colunas, dias em calendário e horários do lado; painel e área da equipe com barra lateral e calendário ao lado do dia.
- **1.1** (26/10/2026): dados reais do Studio Carina Aquino (as 3 manicures, os dias de cada uma, horário de terça a sábado, procedimentos, preços e tempos), formas de pagamento na tela da cliente, pé e mão com duas manicures ao mesmo tempo, código formatado.
- **1.0** (21/10/2026): sistema completo com dados de exemplo (agendamento com login, painel em calendário, caixa, área da equipe, horários de cada colaboradora, lembrete no WhatsApp).

> Os arquivos `sql/atualizar-v2.sql` … `atualizar-v9.sql` são etapas do **banco de dados**, não a versão do sistema.

**Equipe:** Carina Aquino (terça a sábado, 9h às 18h), Victoria Aquino (só sábado, 9h às 18h) e Ana Oliveira (quinta a sábado, 9h às 17h). O estúdio abre de terça a sábado, das 9h às 18h. Cada uma pode mudar os próprios dias em `/equipe`, aba Meus horários.

**Procedimentos:** pé e mão R$ 55, pé R$ 35, mão R$ 35, esmaltação convencional R$ 5, spa dos pés R$ 100, massagem nos pés R$ 20, alongamento R$ 200, banho em gel R$ 150, esmaltação em gel R$ 75, manutenção natural R$ 115, manutenção encapsulada a partir de R$ 135 (o que passar disso, a colaboradora lança como "valor a mais" ao concluir).

**Tempos (passados pela Carina):** pé e mão 2 h (com uma manicure só), pé 1 h, mão 1 h, alongamento 2 h, manutenção natural 1h30, manutenção encapsulada (com decoração) 2 h, esmaltação convencional 10 min, esmaltação em gel 1 h. Ficam em `servicos.duracao_min` e definem quanto tempo cada horário ocupa.

**Pé e mão com duas manicures (1 h):** uma faz o pé e a outra a mão, ao mesmo tempo. A cliente vê um cartão só, "Duas manicures", com os horários em que pelo menos duas estão livres juntas, e o sistema escolhe as duas (quem tem menos atendimentos no dia). Viram dois atendimentos, um na agenda de cada uma, com o valor dividido meio a meio no caixa. Cancelar ou marcar falta vale pras duas; concluir e valor a mais são de cada uma. A cliente recebe um lembrete só. Na tabela `servicos`, qualquer serviço com `dupla = 1` funciona assim.

**Pagamento:** dinheiro, Pix, cartão de crédito ou débito, sem parcelamento. Aparece pra cliente antes de confirmar e depois de marcar (`pagamento` e `pagamento_obs` no `config.php`).

> O `config.php` não vai pro Git (tem senhas). Use o `config.exemplo.php` como base.

Link de agendamento pra bio do Instagram. A cliente escolhe o serviço e o dia, e vê as profissionais com foto, cada uma com os próprios horários livres. Pra confirmar, ela entra na conta (celular + senha) ou cria uma (nome completo, celular, e-mail e senha). Logada, ela vê e cancela os próprios horários. O painel tem agenda, resumo financeiro e lista de clientes.

## Instalação rápida (Windows + XAMPP)
1. Extraia o zip em qualquer pasta (ex.: Downloads).
2. No XAMPP Control Panel, dê **Start** no **Apache** e no **MySQL**.
3. Abra a pasta `agenda-unhas` e dê dois cliques em **`instalar.bat`**.

Ele copia tudo pra `C:\xampp\htdocs\agenda-unhas`, cria o banco `agenda_unhas` com os dados do studio e abre o sistema no navegador. Se o banco já existir, ele não mexe (os dados ficam). Rodar de novo serve pra atualizar os arquivos sem perder o `config.php`.

## Rodar no XAMPP (manual)
1. Copie a pasta `agenda-unhas` pra `C:\xampp\htdocs\`.
2. No phpMyAdmin, crie o banco `agenda_unhas` (utf8mb4) e importe `sql/schema.sql`.
3. Copie `config.exemplo.php` com o nome `config.php`, abra e ajuste o banco, o nome do estúdio, o WhatsApp, os horários e a **senha de primeiro acesso** (`admin_senha`).
4. Cliente: http://localhost/agenda-unhas/
   Login (dona e equipe): http://localhost/agenda-unhas/equipe/
5. Primeiro acesso ao painel: abra http://localhost/agenda-unhas/admin/ e entre com a `admin_senha`. Em **Ajustes**, na ficha da dona, toque em **Dar acesso ao painel** e depois em **Criar acesso** com o celular dela. Daí em diante todo mundo entra pelo login único (`/equipe/`) e a `admin_senha` para de valer.

## Colocar no ar (VPS com Ubuntu + Nginx)
O `instalar.sh` faz tudo: pacotes, banco com usuário e senha próprios, `config.php`, Nginx, HTTPS, backup e lembretes. Rodar de novo atualiza sem apagar banco, fotos nem `config.php`.

1. No painel do domínio (Hostinger), em DNS, crie `A  @  IP-da-VPS` e `A  www  IP-da-VPS`.
2. No CMD do Windows (troque a versão e o domínio):
   ```
   scp C:\Users\leona\Downloads\agenda-unhas\agenda-unhas-v1.8.zip root@IP-da-VPS:/root/
   ssh -t root@IP-da-VPS "cd /root && rm -rf agenda-unhas && unzip -q agenda-unhas-v1.8.zip && bash agenda-unhas/instalar.sh studiocarinaaquino.com.br"
   ```
3. Na primeira vez ele mostra a **senha de primeiro acesso** do painel. Anote.
4. Ajuste os dados do estúdio: `nano /var/www/agenda-unhas/config.php` (nome, Instagram, endereço, WhatsApp, horários).
5. Pra atualizar depois: mesmos dois comandos com o zip novo, sem o domínio no fim.

Onde fica cada coisa na VPS:
- site: `/var/www/agenda-unhas` (código é do root; o PHP roda com o usuário `agenda` e só grava nas fotos)
- Nginx: `/etc/nginx/sites-available/agenda-unhas` + `/etc/nginx/snippets/agenda-seguranca.conf`
- PHP: `/etc/php/<versão>/fpm/pool.d/agenda.conf` (erros em `/var/log/agenda-unhas/php.log`)
- backup diário (3h40, guarda 14 dias): `/root/backups/agenda-AAAAMMDD.sql.gz` e `agenda-fotos-AAAAMMDD.tgz`
- lembretes no WhatsApp: cron do usuário `agenda` a cada 10 min (`/var/log/agenda-unhas/lembretes.log`)

Os `.htaccess` das pastas só valem no XAMPP (Apache). Na VPS quem bloqueia `includes/`, `sql/`, `cron/`, `config.php` etc. é o `servidor/nginx-seguranca.conf`.

## Ícone na tela inicial do celular
- **Clientes:** abrir o site e tocar em "Adicionar à tela inicial" (Chrome: menu ⋮; iPhone/Safari: botão Compartilhar). Abre como app, com o ícone CA em laca escura.
- **Equipe:** abrir `/equipe/` e fazer o mesmo. O ícone é o CA em fundo claro, com o nome "Agenda". Pelo ícone, a dona cai direto no painel e as outras na agenda delas.
- Os ícones ficam em `assets/icones/` (cliente-* e equipe-*); o nome que aparece vem do `nome_estudio` do `config.php` (`manifest.php`).

## Já tinha instalado uma versão anterior?
Substitua os arquivos (menos o seu `config.php`; copie dele só o bloco novo `aviso_whatsapp` e `max_agendamentos_por_cliente`) e rode no phpMyAdmin, nesta ordem:
- veio da 1.8: nada pra rodar; as colunas e a tabela novas (`atualizar-v11.sql`) são criadas sozinhas na primeira vez que o painel abre. No `config.php`, coloque `'grupo' => ''` no bloco `aviso_whatsapp` e mude `horas_antes` pra 2
- veio da 1.7: nada pra rodar; as colunas do link de senha (`atualizar-v10.sql`) são criadas sozinhas. No `config.php`, copie os blocos novos `url_site` e `email`
- veio da versão 8: nada pra rodar; a coluna `categoria` dos serviços (`atualizar-v9.sql`) é criada e preenchida sozinha na primeira vez que a tela da cliente abre. Serviço novo sem categoria aparece no grupo "Outros"
- veio da versão 7: nada pra rodar; a coluna nova (`atualizar-v8.sql`, acesso ao painel) é criada sozinha na primeira vez que alguém abre o painel ou a área da equipe
- veio da versão 6: só `sql/atualizar-v7.sql` (pé e mão com duas manicures)
- veio da versão 5: `atualizar-v6.sql` (dados do Studio Carina Aquino) e `atualizar-v7.sql`
- veio da versão 4: `atualizar-v5.sql` até `atualizar-v7.sql`
- veio da versão 3: `atualizar-v4.sql` até `atualizar-v7.sql`
- veio da versão 2: `atualizar-v3.sql` até `atualizar-v7.sql`
(versão 1: rode todos, do v2 ao v7, nessa ordem.) No `config.php`, copie também o bloco novo `lembrete_cliente`.

## Telas da cliente
Barra de abas embaixo (onde o dedão alcança): **Agendar**, **Meus horários** e **Conta**.
1. **Serviço**: capa com o nome do estúdio e se está aberto agora. Tocar no serviço já avança.
2. **Horário**: escolhe o dia, toca na profissional (foto + quantas vagas ela tem no dia) e escolhe o horário dela. Se ela estiver sem vaga, tem o botão "Ver o próximo dia livre dela". No serviço em dupla, ela marca as **duas** manicures que quer (quem não atende no dia fica apagada) e aparecem só os horários em que as duas estão livres juntas.
3. Se não estiver logada, abre **Falta pouco** (entrar ou criar conta), mostrando o horário escolhido.
4. **Confira e confirme** e depois **Horário marcado!**
O botão voltar do celular funciona entre as telas. Visual em `assets/cliente.css` (tem tema escuro automático).

## Painel do estúdio (`/admin`)
O login é um só, em `/equipe/`: quem tem **acesso ao painel** (a dona, marcado em Ajustes) entra com o celular e a senha dela e cai direto no painel; as outras caem na agenda delas. Só a dona vê o faturamento, e o painel é de uma dona só (depois que ela tem acesso, o botão some das outras). Abrir `/admin/` sem estar logada manda pro login. Uma conta só: no painel tem o atalho **Minha agenda** e na área da equipe aparece **Painel do estúdio**. Ninguém tira o próprio acesso, então o painel nunca fica sem dona.
- **Calendário**: o mês inteiro, com o número de horários marcados em cada dia e cor mais forte nos dias mais cheios. No topo escolhe **Todas** ou uma profissional. Tocando no dia aparecem: atendimentos, faturamento, faltas, ocupação da agenda, quantos de cada serviço, quanto cada uma fez e a lista de horários (com Concluir, valor a mais, Faltou, Cancelar e Lembrar no WhatsApp).
- **Caixa**: escolhe a pessoa (ou Todas) e o mês. Mostra o caixa do mês, quanto cada uma fez e todos os serviços dia a dia, com o total de cada dia. Tocando num dia, aparece só ele, com o total do dia e do mês. Entra no caixa só o que foi **concluído** (valor do serviço + valor a mais).
- **Resumo**: faturamento por período (hoje, semana, mês, mês passado ou datas escolhidas).
- **Marcar horário**: pra cliente que marcou por WhatsApp, telefone ou no balcão. Busca a cliente (ou cadastra com nome e celular), escolhe serviço, manicure (duas, se for em dupla), dia e horário (mostra os livres, mas dá pra digitar outro pra encaixar) e o **valor combinado**, que é o que entra no caixa. Marcando **Repetir toda semana** e o "até", vira horário fixo: o sistema marca todas as semanas e pula (e avisa) as que já estiverem ocupadas. Na agenda, o horário fixo mostra "Horário fixo: toda sexta, até 18/12" e os botões **Cancelar só esse dia** e **Cancelar esse e os próximos**. Também tem o botão **+ Marcar horário nesse dia** no detalhe do dia.
- **Clientes**: busca, **Marcar horário** pra ela e senha nova pra quem esqueceu. Ficha feita pelo estúdio fica sem senha: quando a cliente for em "Criar conta" com o mesmo celular, ela cria a senha e continua com a mesma ficha e os mesmos horários.
- **Ajustes**: acesso da equipe (criar senha de cada colaboradora), horários de cada uma, bloquear horário, fotos, teste do aviso no WhatsApp e sair.

## Área da equipe (`/equipe`)
Cada colaboradora entra com o celular e a senha que a dona cria em **Ajustes → Equipe: acesso e horários** (a senha aparece uma vez, com botão pra mandar no WhatsApp dela).
- **Minha agenda**: os atendimentos do dia. Ao terminar, ela toca em **Concluir**; se fez algo a mais, escreve o que foi e o valor, que soma no serviço. Também marca falta, cancela e manda **lembrete no WhatsApp** da cliente com um toque.
- **Meus horários**: a **semana de trabalho** (dias, entrada, saída e pausa) e **dias específicos** (folga ou horário diferente numa data). O link da cliente só mostra horários dentro disso. Se ela nunca configurar, vale o horário do estúdio do `config.php`.
Ela só enxerga e mexe nos atendimentos dela. O caixa e o faturamento ficam só no painel da dona (desde a 1.9).

## Lembrete no WhatsApp da cliente
2 horas antes do horário (`lembrete_cliente` no `config.php`) a cliente recebe, do número do estúdio: "Oi, Maria! 💅 Passando pra lembrar do seu horário *hoje* no *Studio Carina Aquino*", com serviço, hora, quem atende e o endereço. Se houver vários pra mandar, sai um de cada vez, com alguns segundos de pausa.
- Precisa da **Evolution API** (o CallMeBot só manda pro número do estúdio).
- Cada horário recebe um lembrete só. Quem marcou em cima da hora não recebe.
- Dispara sozinho quando alguém usa o link de agendamento. Pra ficar no horário certinho mesmo sem visitas, agende o `cron/lembretes.php` a cada 10 minutos (VPS: crontab; Windows: Agendador de Tarefas com `C:\xampp\php\php.exe C:\xampp\htdocs\agenda-unhas\cron\lembretes.php`).
- Sem a API, o botão **Lembrar no WhatsApp** (na agenda) abre a conversa com a mensagem pronta.

## Conta da cliente
- O login é obrigatório só na hora de confirmar. Ela pode olhar serviços e horários sem conta.
- Na aba Conta ela vê nome, celular e e-mail, troca a senha e sai.
- Cadastro: nome completo, celular (WhatsApp), e-mail e senha (mínimo 6). Celular e e-mail não se repetem entre contas.
- Fica logada por 30 dias no mesmo celular.
- "Meus horários" (no topo, quando logada): mostra os próximos horários e deixa cancelar até `cancelar_ate_horas` antes (padrão 2 h). O estúdio recebe aviso do cancelamento no WhatsApp.
- Esqueceu a senha, dois caminhos:
  - **Sozinha, por e-mail** (precisa do bloco `email` ligado no `config.php`): em "Esqueci a senha" ela digita o e-mail da conta e recebe um link. O link vale 1 hora e funciona uma vez; ao criar a senha nova ela já entra na conta. A resposta é sempre a mesma, tendo conta ou não (ninguém descobre quem é cliente), e dá pra pedir no máximo 3 links por hora no mesmo e-mail.
  - **Pelo estúdio**: no painel, aba **Clientes**, toque em "Gerar nova senha". A senha aparece uma vez, com botão pra mandar pro WhatsApp da cliente. Com o e-mail desligado, o "Esqueci a senha" manda a cliente falar no WhatsApp.
- 5 senhas erradas seguidas no mesmo celular travam o login por 15 minutos. Gerar uma senha nova destrava na hora.
- Senhas são guardadas com hash (`password_hash`), nunca em texto.
- Se ela já tinha marcado horário antes de ter conta, com o mesmo celular, esses horários passam a aparecer na conta dela.

## E-mail de "esqueci a senha"
No `config.php`, bloco `email`, troque `'modo' => 'desligado'` por `'smtp'` e preencha:
- **Gmail do estúdio** (mais fácil): na conta Google, ative a verificação em 2 etapas e crie uma **senha de app** em myaccount.google.com/apppasswords. Use `smtp.gmail.com`, porta `465`, `'ssl'`, usuário = o Gmail e senha = os 16 caracteres da senha de app. O remetente tem que ser o próprio Gmail. Limite do Gmail: 500 e-mails por dia (sobra).
- **E-mail do domínio** (ex.: contato@studiocarinaaquino.com.br, se tiver caixa de e-mail na Hostinger): `smtp.hostinger.com`, porta `465`, `'ssl'`, usuário e senha da caixa.
- Na VPS, o `instalar.sh` já preenche o `url_site` com o domínio (é o endereço que vai no link do e-mail). No XAMPP pode deixar vazio.
- Se o e-mail não sair, o motivo fica no log do PHP (na VPS: `/var/log/agenda-unhas/php.log`, procure "e-mail de senha nova").

## WhatsApp: avisos no grupo da equipe
A cada horário marcado (pelo link ou pelo painel) e a cada cancelamento chega uma mensagem no **grupo da equipe**, com cliente (e se é a primeira vez ou qual visita), WhatsApp dela, serviço, valor, duração, dia, horário, quem atende, horário fixo, observação, se foi marcado pelo site ou pelo painel e o link do painel naquele dia. O aviso sai depois que a tela já respondeu, então se o WhatsApp estiver fora do ar nada trava.

**Evolution API (o jeito escolhido: sai do número do estúdio)**
1. Na VPS, com Docker instalado: copie `servidor/evolution/` pra `/opt/evolution`, crie o `.env` a partir do `.env.exemplo` (troque as duas senhas e confira a versão mais nova da imagem) e rode `docker compose up -d`. Ela fica só em `127.0.0.1:8080`, sem porta aberta pra internet.
2. Crie a instância e conecte o WhatsApp do estúdio lendo o QR code (igual WhatsApp Web). O celular continua funcionando normal.
3. No WhatsApp, crie o grupo da equipe com o número do estúdio e as três.
4. No `config.php`, bloco `aviso_whatsapp`: `modo` = `evolution`, `evolution_url` = `http://127.0.0.1:8080`, `evolution_instancia` e `evolution_apikey`. Rode `php cron/whatsapp-grupos.php` pra ver o código do grupo (termina em `@g.us`) e coloque em `grupo`.
5. No painel, Ajustes → WhatsApp → **Enviar mensagem de teste**.
Sem `grupo`, o aviso vai pro número em `telefone`.

**CallMeBot (alternativa grátis, só avisos, sem lembrete pra cliente)**: no celular que vai receber, mande `I allow callmebot to send me messages` pro contato do CallMeBot, coloque a apikey em `callmebot_apikey`, o número em `telefone` e `modo` = `callmebot`. Não manda pra grupo nem pra cliente.

## Resumo (painel)
Em `/admin`, aba **Resumo**: faturamento, atendimentos, clientes, ticket médio e faltas, por hoje, ontem, semana, mês, mês passado ou um período escolhido. Mostra quanto cada profissional fez, os serviços que mais deram dinheiro e o dia a dia.
- O valor de cada horário fica gravado no momento em que a cliente marca. Se mudar o preço depois, o histórico não muda.
- Na agenda, depois que o horário começa, aparece o botão **Faltou**. Falta não entra no faturamento.

## Fotos das profissionais
No painel, aba Ajustes, em "Fotos da equipe", toque em "Enviar foto". A foto é cortada em quadrado (400x400) automaticamente. Sem foto, aparece a inicial do nome.
A pasta `uploads/profissionais` precisa ter permissão de escrita no servidor.

## Personalizar
- **Profissionais e serviços**: edite as tabelas `profissionais` e `servicos` (nome, cor do "esmalte", duração, preço, `ativo`, `ordem`).
- **Horário de funcionamento, almoço, antecedência mínima e dias pra frente**: `config.php`.
- **Folga ou saída mais cedo**: painel, aba Ajustes, "Bloquear horário" (ou pelo botão no detalhe do dia). Pode bloquear uma profissional só ou o estúdio todo.

## Proteções na hora de marcar
- Se uma profissional não tiver horário no dia escolhido, aparece o botão "Ver próximo dia livre" no cartão dela.
- Duas clientes no mesmo horário da mesma profissional ao mesmo tempo: só uma consegue. A outra recebe aviso pra escolher outro.
- Cliente que toca duas vezes no botão, ou cuja internet cai na hora: fica um agendamento só, e um aviso só.
- A mesma cliente não consegue marcar dois horários que se sobrepõem, nem passar de 3 horários futuros (muda em `max_agendamentos_por_cliente`).
- Telefone colado com +55, com 0 na frente ou com espaços é corrigido sozinho.
- Dia passado, dia fechado, horário de almoço e horário que não cabe antes de fechar são recusados também no servidor, não só na tela.
- Depois de marcar, a cliente tem um botão que abre o WhatsApp do estúdio com o agendamento escrito.

## Arquivos
- `index.php`: página da cliente
- `api.php`: horários livres e criação do agendamento
- `instalar.sh` e `servidor/`: instalação na VPS (Nginx, PHP, segurança)
- `manifest.php` e `assets/icones/`: ícone na tela inicial do celular
- `admin/index.php`: calendário do mês e detalhe do dia
- `admin/ajustes.php`: bloqueios, fotos, teste do aviso, sair
- `admin/resumo.php`: resumo financeiro
- `admin/agendar.php`, `assets/marcar.js` e `includes/painel_agenda.php`: marcar horário pelo painel e horário fixo
- `includes/app.php`: regras da agenda
- `includes/whatsapp.php`: mensagens e envio no WhatsApp (avisos no grupo e lembrete)
- `includes/admin.php`: login e cabeçalho do painel
- `includes/cliente.php`: contas das clientes
- `admin/clientes.php`: lista de clientes e senha nova
- `admin/caixa.php` e `includes/caixa.php`: caixa
- `admin/disponibilidade.php`, `equipe/disponibilidade.php` e `includes/disponibilidade.php`: horários de cada colaboradora
- `equipe/`: área da equipe (`includes/equipe.php`)
- `includes/atendimento.php`: concluir, valor a mais, lembrete
- `cron/lembretes.php`: envio dos lembretes
- `cron/whatsapp-grupos.php`: lista os grupos do WhatsApp conectado (pra achar o código do grupo da equipe)
- `servidor/evolution/`: Evolution API (WhatsApp) em Docker, só no próprio servidor
- `assets/cliente.css` e `assets/app.js`: tela da cliente
- `assets/style.css` e `assets/admin.css`: painel

## Prévia na Vercel (`previa/`)
A pasta `previa/` é uma versão de demonstração que roda sem PHP e sem banco: tela da cliente + painel em calendário, com dados de exemplo e nada salvo. Serve pra mostrar pra cliente antes de subir o sistema de verdade.
Na Vercel: **Add New → Project → importar este repositório → Root Directory: `previa`** → Deploy. A cada push ela atualiza sozinha.
