#!/usr/bin/env bash
# Instala/atualiza a Agenda de unhas na VPS (Ubuntu + Nginx).
#   Primeira vez:   sudo bash agenda-unhas/instalar.sh studiocarinaaquino.com.br
#   Atualizar:      sudo bash agenda-unhas/instalar.sh          (lembra o domínio da primeira vez)
# Pode rodar de novo quantas vezes quiser: mantém banco, fotos da equipe e config.php.
set -euo pipefail
trap 'echo -e "\e[31mParou com erro na linha $LINENO. Mande um print disto.\e[0m"' ERR

DESTINO="/var/www/agenda-unhas"
ORIGEM="$(cd "$(dirname "$0")" && pwd)"
ARQ_DOMINIO="/etc/agenda-unhas.dominio"
BANCO="agenda_unhas"
USUARIO_BANCO="agenda"
verde(){ echo -e "\e[32m$*\e[0m"; }; amarelo(){ echo -e "\e[33m$*\e[0m"; }; vermelho(){ echo -e "\e[31m$*\e[0m"; }

[ "$(id -u)" = 0 ] || { vermelho "Rode como root:  sudo bash instalar.sh"; exit 1; }
command -v apt-get >/dev/null || { vermelho "Esse script é pra Ubuntu/Debian."; exit 1; }

# ---------- 1. Domínio ----------
DOMINIO="${1:-}"
[ -z "$DOMINIO" ] && [ -f "$ARQ_DOMINIO" ] && DOMINIO="$(cat "$ARQ_DOMINIO")"
[ -z "$DOMINIO" ] && read -rp "Domínio do estúdio (ex.: studiocarinaaquino.com.br): " DOMINIO
DOMINIO="$(echo "$DOMINIO" | tr '[:upper:]' '[:lower:]' | sed -e 's#^https\?://##' -e 's#^www\.##' -e 's#/.*$##')"
echo "$DOMINIO" | grep -qE '^[a-z0-9.-]+\.[a-z]{2,}$' || { vermelho "Domínio inválido: $DOMINIO"; exit 1; }
echo "$DOMINIO" > "$ARQ_DOMINIO"
verde "==> Agenda de unhas $(cat "$ORIGEM/VERSION") em $DOMINIO"

if systemctl is-active --quiet apache2 2>/dev/null; then
  vermelho "O Apache está rodando nessa VPS. Esse instalador é pro Nginx. Mande um print disto."
  exit 1
fi

# ---------- 2. Pacotes ----------
verde "==> Pacotes (pode demorar uns minutos na primeira vez)"
apt-get update -qq
PKGS="nginx php-fpm php-mysql php-mbstring php-curl php-gd php-cli unzip curl certbot python3-certbot-nginx dnsutils"
if ! command -v mysql >/dev/null && ! command -v mariadb >/dev/null; then PKGS="$PKGS mariadb-server"; fi
DEBIAN_FRONTEND=noninteractive apt-get install -y -qq $PKGS >/dev/null
systemctl enable --now nginx >/dev/null 2>&1 || true
systemctl enable --now mariadb >/dev/null 2>&1 || systemctl enable --now mysql >/dev/null 2>&1 || true
PHPV="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"
systemctl enable --now "php${PHPV}-fpm" >/dev/null
verde "    PHP $PHPV ok"

# Usuário só do site: o PHP roda com ele e só consegue gravar na pasta das fotos
id agenda >/dev/null 2>&1 || useradd --system --no-create-home --home-dir /nonexistent --shell /usr/sbin/nologin agenda
mkdir -p /var/log/agenda-unhas /var/lib/php/agenda-sessoes
chown agenda:agenda /var/log/agenda-unhas /var/lib/php/agenda-sessoes
chmod 750 /var/log/agenda-unhas; chmod 700 /var/lib/php/agenda-sessoes

# ---------- 3. Arquivos ----------
verde "==> Copiando arquivos para $DESTINO"
mkdir -p "$DESTINO/uploads/profissionais"
for item in index.php api.php manifest.php favicon.ico VERSION config.exemplo.php assets admin equipe includes cron; do
  rm -rf "${DESTINO:?}/${item:?}"; cp -r "$ORIGEM/$item" "$DESTINO/"
done
cp "$ORIGEM/uploads/.htaccess" "$DESTINO/uploads/" 2>/dev/null || true
rm -rf "$DESTINO/previa" "$DESTINO/sql" "$DESTINO/instalar.bat" "$DESTINO/LEIA-ME.md"   # nunca no ar

# ---------- 4. Banco ----------
SQL="mysql"; command -v mariadb >/dev/null && SQL="mariadb"
$SQL -e "CREATE DATABASE IF NOT EXISTS $BANCO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
NOVO_CONFIG=0
[ -f "$DESTINO/config.php" ] || NOVO_CONFIG=1
if [ "$NOVO_CONFIG" = 1 ]; then
  verde "==> Usuário do banco e config.php"
  SENHA_DB="$(php -r 'echo bin2hex(random_bytes(14));')"
  SENHA_ADMIN="$(php -r 'echo substr(str_replace(["+","/","="],"",base64_encode(random_bytes(12))),0,12);')"
  $SQL -e "CREATE USER IF NOT EXISTS '$USUARIO_BANCO'@'localhost' IDENTIFIED BY '$SENHA_DB';
           ALTER USER '$USUARIO_BANCO'@'localhost' IDENTIFIED BY '$SENHA_DB';
           GRANT ALL ON $BANCO.* TO '$USUARIO_BANCO'@'localhost'; FLUSH PRIVILEGES;"
  cp "$ORIGEM/config.exemplo.php" "$DESTINO/config.php"
  php -r '
    $f = $argv[1]; $s = file_get_contents($f);
    $s = preg_replace("/(\x27usuario\x27\s*=>\s*)\x27[^\x27]*\x27/", "\${1}\x27".$argv[2]."\x27", $s, 1);
    $s = preg_replace("/(\x27senha\x27\s*=>\s*)\x27[^\x27]*\x27/", "\${1}\x27".$argv[3]."\x27", $s, 1);
    $s = preg_replace("/(\x27admin_senha\x27\s*=>\s*)\x27[^\x27]*\x27/", "\${1}\x27".$argv[4]."\x27", $s, 1);
    file_put_contents($f, $s);' "$DESTINO/config.php" "$USUARIO_BANCO" "$SENHA_DB" "$SENHA_ADMIN"
fi
TABELAS="$($SQL -N -e "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='$BANCO'")"
if [ "$TABELAS" = 0 ]; then
  verde "==> Criando as tabelas e os dados do estúdio"
  $SQL --default-character-set=utf8mb4 "$BANCO" < "$ORIGEM/sql/schema.sql"
else
  verde "==> Banco já existe: não apaguei nada ($TABELAS tabelas). Colunas novas o sistema cria sozinho."
fi

# ---------- 5. Permissões: o código é do root (o site não consegue se alterar); só as fotos são graváveis ----------
chown -R root:agenda "$DESTINO"
find "$DESTINO" -type d -exec chmod 755 {} +
find "$DESTINO" -type f -exec chmod 644 {} +
chown root:agenda "$DESTINO/config.php"; chmod 640 "$DESTINO/config.php"
chown -R agenda:agenda "$DESTINO/uploads/profissionais"

# ---------- 6. PHP só do site ----------
verde "==> PHP do site (usuário próprio, sem comandos do sistema)"
POOL="/etc/php/$PHPV/fpm/pool.d/agenda.conf"
cp "$ORIGEM/servidor/php-pool.conf" "$POOL"
if "php-fpm$PHPV" -t >/dev/null 2>&1; then
  systemctl reload "php${PHPV}-fpm"
  for _ in 1 2 3 4 5 6 7 8 9 10; do [ -S /run/php/agenda.sock ] && break; sleep 1; done
fi
[ -S /run/php/agenda.sock ] || { rm -f "$POOL"; systemctl reload "php${PHPV}-fpm" || true; vermelho "O PHP da agenda não subiu. Mande um print disto."; exit 1; }
verde "    pool agenda ok"

# ---------- 7. Nginx ----------
verde "==> Nginx"
CONF="/etc/nginx/sites-available/agenda-unhas"
mkdir -p /root/nginx-backup
[ -f "$CONF" ] && cp "$CONF" /root/nginx-backup/agenda-unhas.antes-da-atualizacao
cp "$ORIGEM/servidor/nginx-seguranca.conf" /etc/nginx/snippets/agenda-seguranca.conf
cp "$ORIGEM/servidor/nginx-http.conf" /etc/nginx/conf.d/agenda-http.conf
if ! grep -Rqs "^\s*server_tokens" /etc/nginx/nginx.conf /etc/nginx/conf.d/; then echo "server_tokens off;" >> /etc/nginx/conf.d/agenda-http.conf; fi
if [ -f "$CONF" ] && grep -q "ssl_certificate" "$CONF"; then
  : # já tem HTTPS (certbot): mantém o arquivo e só atualiza as regras do snippet
else
  sed "s/__DOMINIO__/$DOMINIO/g" "$ORIGEM/servidor/nginx.conf" > "$CONF"
fi
ln -sf "$CONF" /etc/nginx/sites-enabled/agenda-unhas
if ! nginx -t 2>/tmp/nginx-teste.txt; then
  vermelho "    A configuração nova do Nginx deu erro. Voltei a anterior; os outros sites seguem no ar."
  cat /tmp/nginx-teste.txt
  if [ -f /root/nginx-backup/agenda-unhas.antes-da-atualizacao ]; then cp /root/nginx-backup/agenda-unhas.antes-da-atualizacao "$CONF"; else rm -f /etc/nginx/sites-enabled/agenda-unhas; fi
  nginx -t && systemctl reload nginx
  exit 1
fi
systemctl reload nginx

# ---------- 8. Lembretes (cron, usuário agenda) e backup diário ----------
LINHA="*/10 * * * * php $DESTINO/cron/lembretes.php >> /var/log/agenda-unhas/lembretes.log 2>&1"
( crontab -u agenda -l 2>/dev/null | grep -v "lembretes.php" || true; echo "$LINHA" ) | crontab -u agenda -
mkdir -p /root/backups; chmod 700 /root/backups
DUMP="$(command -v mariadb-dump || command -v mysqldump)"
cat > /usr/local/bin/agenda-backup <<BKP
#!/bin/sh
# Backup da agenda: banco + fotos da equipe, guarda 14 dias em /root/backups
D=\$(date +%Y%m%d)
$DUMP --single-transaction $BANCO | gzip > /root/backups/agenda-\$D.sql.gz
tar czf /root/backups/agenda-fotos-\$D.tgz -C $DESTINO uploads 2>/dev/null
find /root/backups -name 'agenda-*.sql.gz' -mtime +14 -delete
find /root/backups -name 'agenda-fotos-*.tgz' -mtime +14 -delete
BKP
chmod 700 /usr/local/bin/agenda-backup
( crontab -l 2>/dev/null | grep -v "agenda-backup" || true; echo "40 3 * * * /usr/local/bin/agenda-backup" ) | crontab -
/usr/local/bin/agenda-backup || true
verde "==> Lembretes (a cada 10 min) e backup diário (3h40, guarda 14 dias) ok"

# ---------- 9. DNS + HTTPS ----------
IP_VPS="$(curl -4 -s --max-time 5 https://ifconfig.me || hostname -I | awk '{print $1}')"
IP_DNS="$(dig +short A "$DOMINIO" @1.1.1.1 | tail -1 || true)"
IP_WWW="$(dig +short A "www.$DOMINIO" @1.1.1.1 | tail -1 || true)"
echo
echo "    IP desta VPS:            $IP_VPS"
echo "    $DOMINIO aponta pra:     ${IP_DNS:-nada}"
echo "    www.$DOMINIO aponta pra: ${IP_WWW:-nada}"
if [ "$IP_DNS" = "$IP_VPS" ]; then
  if ! grep -q ssl_certificate "$CONF"; then
    verde "==> Gerando o cadeado (HTTPS)"
    DOMS="-d $DOMINIO"; [ "$IP_WWW" = "$IP_VPS" ] && DOMS="$DOMS -d www.$DOMINIO"
    certbot --nginx $DOMS --redirect --non-interactive --agree-tos --register-unsafely-without-email --cert-name "$DOMINIO" || amarelo "    O certbot falhou. Me mande um print."
  fi
  echo; verde "PRONTO! Versão $(cat "$DESTINO/VERSION") no ar em https://$DOMINIO"
  echo "    Cliente:  https://$DOMINIO"
  echo "    Equipe:   https://$DOMINIO/equipe/"
else
  echo
  amarelo "O domínio ainda não aponta pra esta VPS."
  amarelo "No painel do domínio (Hostinger), em DNS, crie:  A  @  $IP_VPS   e   A  www  $IP_VPS"
  amarelo "Espere uns minutos e rode de novo:  sudo bash agenda-unhas/instalar.sh   (ele só vai fazer o HTTPS)"
fi
if [ "$NOVO_CONFIG" = 1 ]; then
  echo
  amarelo "ANOTE: senha de primeiro acesso do painel (só vale até a dona criar a conta dela):  $SENHA_ADMIN"
  amarelo "Depois ajuste nome, WhatsApp, endereço e horários em:  nano $DESTINO/config.php"
fi
