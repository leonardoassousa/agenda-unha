@echo off
title Instalar Agenda de Unhas no XAMPP
setlocal

set "XAMPP=C:\xampp"
set "DESTINO=%XAMPP%\htdocs\agenda-unhas"
set "ORIGEM=%~dp0"
if "%ORIGEM:~-1%"=="\" set "ORIGEM=%ORIGEM:~0,-1%"
set "MYSQL=%XAMPP%\mysql\bin\mysql.exe"

echo.
echo  ===== Agenda de Unhas - instalacao no XAMPP =====
echo.

if not exist "%MYSQL%" (
  echo  [ERRO] Nao achei o XAMPP em %XAMPP%.
  echo         Instale o XAMPP em C:\xampp e rode de novo.
  goto fim
)

rem ---- MySQL ligado? ----
"%MYSQL%" -u root -e "SELECT 1" >nul 2>&1
if errorlevel 1 (
  echo  [!] O MySQL esta desligado.
  echo      Abra o XAMPP Control Panel e clique em Start no MySQL e no Apache.
  echo      Depois aperte qualquer tecla aqui.
  pause >nul
  "%MYSQL%" -u root -e "SELECT 1" >nul 2>&1
  if errorlevel 1 (
    echo  [ERRO] O MySQL continua desligado ^(ou o root tem senha^). Instalacao cancelada.
    goto fim
  )
)

rem ---- Copia os arquivos ----
if /I "%ORIGEM%"=="%DESTINO%" (
  echo  [ok] Os arquivos ja estao em %DESTINO%
) else (
  echo  Copiando os arquivos para %DESTINO% ...
  robocopy "%ORIGEM%" "%DESTINO%" /E /NFL /NDL /NJH /NJS /NP /XF instalar.bat config.php >nul
  if not exist "%DESTINO%\config.php" copy "%ORIGEM%\config.php" "%DESTINO%\config.php" >nul
  echo  [ok] Arquivos copiados.
)
if not exist "%DESTINO%\config.php" copy "%DESTINO%\config.exemplo.php" "%DESTINO%\config.php" >nul

rem ---- Banco de dados ----
"%MYSQL%" -u root -e "CREATE DATABASE IF NOT EXISTS agenda_unhas CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
set "TABELAS=0"
for /f %%t in ('call "%MYSQL%" -u root -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='agenda_unhas'"') do set "TABELAS=%%t"
if "%TABELAS%"=="0" (
  echo  Criando as tabelas e os dados do studio ...
  "%MYSQL%" -u root --default-character-set=utf8mb4 agenda_unhas < "%DESTINO%\sql\schema.sql"
  if errorlevel 1 (
    echo  [ERRO] Falhou ao importar o sql\schema.sql.
    goto fim
  )
  echo  [ok] Banco agenda_unhas criado.
) else (
  echo  [ok] O banco agenda_unhas ja existe. Nao mexi nele ^(seus dados continuam^).
)

echo.
echo  Pronto! Abrindo no navegador...
echo.
echo   Cliente: http://localhost/agenda-unhas/
echo   Login:   http://localhost/agenda-unhas/equipe/  ^(dona e equipe^)
echo   Primeiro acesso da dona: http://localhost/agenda-unhas/admin/  ^(senha do config.php^)
echo.
echo  Se a pagina nao abrir, de Start no Apache no XAMPP e atualize.
start "" "http://localhost/agenda-unhas/"
start "" "http://localhost/agenda-unhas/admin/"

:fim
echo.
pause
