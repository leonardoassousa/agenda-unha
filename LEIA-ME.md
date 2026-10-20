# Agenda de unhas

> O `config.php` não vai pro Git (tem senhas). Use o `config.exemplo.php` como base.

Link de agendamento pra bio do Instagram. A cliente escolhe o serviço e o dia, e vê as profissionais com foto, cada uma com os próprios horários livres. Pra confirmar, ela entra na conta (celular + senha) ou cria uma (nome completo, celular, e-mail e senha). Logada, ela vê e cancela os próprios horários. O painel tem agenda, resumo financeiro e lista de clientes.

## Rodar no XAMPP
1. Copie a pasta `agenda-unhas` pra `C:\xampp\htdocs\`.
2. No phpMyAdmin, crie o banco `agenda_unhas` (utf8mb4) e importe `sql/schema.sql`.
3. Copie `config.exemplo.php` com o nome `config.php`, abra e ajuste o banco, o nome do estúdio, o WhatsApp, os horários e a **senha do painel**.
4. Cliente: http://localhost/agenda-unhas/
   Painel: http://localhost/agenda-unhas/admin/

## Já tinha instalado uma versão anterior?
Substitua os arquivos (menos o seu `config.php`; copie dele só o bloco novo `aviso_whatsapp` e `max_agendamentos_por_cliente`) e rode no phpMyAdmin, nesta ordem:
- veio da versão 2: `sql/atualizar-v3.sql` e depois `sql/atualizar-v4.sql`
- veio da versão 3: só `sql/atualizar-v4.sql`
(versão 1: rode os três, v2, v3 e v4, nessa ordem.)

## Telas da cliente
Barra fixa no topo com três abas: **Serviços**, **Horários** (meus horários) e **Conta**.
1. Serviços: escolhe serviço, dia e horário e toca em **Avançar**.
2. Se não estiver logada, abre a tela **Falta pouco** (entrar ou criar conta), mostrando o horário escolhido.
3. Tela **Confira e confirme**: resumo, observação e o botão **Confirmar agendamento**.
4. **Horário marcado**.
O botão voltar do celular funciona entre as telas.

## Conta da cliente
- O login é obrigatório só na hora de confirmar. Ela pode olhar serviços e horários sem conta.
- Na aba Conta ela vê nome, celular e e-mail, troca a senha e sai.
- Cadastro: nome completo, celular (WhatsApp), e-mail e senha (mínimo 6). Celular e e-mail não se repetem entre contas.
- Fica logada por 30 dias no mesmo celular.
- "Meus horários" (no topo, quando logada): mostra os próximos horários e deixa cancelar até `cancelar_ate_horas` antes (padrão 2 h). O estúdio recebe aviso do cancelamento no WhatsApp.
- Esqueceu a senha: no painel, aba **Clientes**, toque em "Gerar nova senha". A senha aparece uma vez, com botão pra mandar pro WhatsApp da cliente.
- 5 senhas erradas seguidas no mesmo celular travam o login por 15 minutos. Gerar uma senha nova destrava na hora.
- Senhas são guardadas com hash (`password_hash`), nunca em texto.
- Se ela já tinha marcado horário antes de ter conta, com o mesmo celular, esses horários passam a aparecer na conta dela.

## Aviso no WhatsApp do estúdio
A cada horário marcado chega uma mensagem no WhatsApp do estúdio com cliente, telefone, serviço, valor, dia, hora e profissional.
O aviso sai depois que a cliente já viu a confirmação, então se o WhatsApp estiver fora do ar o agendamento não trava.

Escolha um dos dois jeitos no `config.php`, bloco `aviso_whatsapp`:

**CallMeBot (grátis, mais simples)**
1. No celular do estúdio, salve o contato do CallMeBot (o número atual está em callmebot.com, na página do WhatsApp).
2. Mande pra ele: `I allow callmebot to send me messages`
3. Ele responde com a sua apikey. Coloque em `callmebot_apikey`, o número do estúdio em `telefone` (55 + DDD + número) e `modo` = `callmebot`.
A mensagem chega vinda do número do CallMeBot e pode demorar até 1 minuto.

**Evolution API (se já tiver rodando na VPS)**
Preencha `evolution_url`, `evolution_instancia`, `evolution_apikey`, o `telefone` que recebe e `modo` = `evolution`. A mensagem sai do número conectado na instância.

Depois de configurar, entre no painel e toque em **Enviar mensagem de teste** (na página Agenda). Se algum aviso falhar, o horário aparece marcado na agenda.

## Resumo (painel)
Em `/admin`, aba **Resumo**: faturamento, atendimentos, clientes, ticket médio e faltas, por hoje, ontem, semana, mês, mês passado ou um período escolhido. Mostra quanto cada profissional fez, os serviços que mais deram dinheiro e o dia a dia.
- O valor de cada horário fica gravado no momento em que a cliente marca. Se mudar o preço depois, o histórico não muda.
- Na agenda, depois que o horário começa, aparece o botão **Faltou**. Falta não entra no faturamento.

## Fotos das profissionais
No painel, em "Fotos da equipe", toque em "Enviar foto". A foto é cortada em quadrado (400x400) automaticamente. Sem foto, aparece a inicial do nome.
A pasta `uploads/profissionais` precisa ter permissão de escrita no servidor.

## Personalizar
- **Profissionais e serviços**: edite as tabelas `profissionais` e `servicos` (nome, cor do "esmalte", duração, preço, `ativo`, `ordem`).
- **Horário de funcionamento, almoço, antecedência mínima e dias pra frente**: `config.php`.
- **Folga ou saída mais cedo**: pelo painel, em "Bloquear horário". Pode bloquear uma profissional só ou o estúdio todo.

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
- `admin/index.php`: painel (agenda do dia, faltou/cancelar, fotos, teste do aviso, bloquear horários)
- `admin/resumo.php`: resumo financeiro
- `includes/app.php`: regras da agenda
- `includes/whatsapp.php`: aviso no WhatsApp
- `includes/admin.php`: login e cabeçalho do painel
- `includes/cliente.php`: contas das clientes
- `admin/clientes.php`: lista de clientes e senha nova
- `assets/`: CSS e JS
