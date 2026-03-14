@echo off
REM ============================================================================
REM apply_migration.bat — Aplicar migration no banco real
REM Uso: cmd /c apply_migration.bat <arquivo>
REM Ex: cmd /c "database\apply_migration.bat database\NNN_nome.sql"
REM ============================================================================
set PGPASSWORD=070998
set PSQL="C:\Program Files\PostgreSQL\18\bin\psql.exe"
set DBNAME=enjoyfun
set DBUSER=postgres
set "LOGFILE=%~dp0migrations_applied.log"

if "%1"=="" (
    echo [ERRO] Informe o arquivo de migration como argumento.
    echo Uso: apply_migration.bat caminho\para\arquivo.sql
    exit /b 1
)

set MIGFILE=%1

if not exist "%MIGFILE%" (
    echo [ERRO] Arquivo nao encontrado: %MIGFILE%
    exit /b 1
)

echo [%date% %time%] Aplicando migration: %MIGFILE%

%PSQL% -U %DBUSER% -d %DBNAME% -f "%MIGFILE%" -v ON_ERROR_STOP=1

if %ERRORLEVEL% NEQ 0 (
    echo [ERRO] Migration falhou. Verifique o log acima.
    exit /b 1
)

echo [OK] Migration aplicada com sucesso: %MIGFILE%
echo %date% %time% - APLICADA: %MIGFILE% >> "%LOGFILE%"
