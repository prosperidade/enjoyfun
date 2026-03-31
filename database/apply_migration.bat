@echo off
setlocal
REM ============================================================================
REM apply_migration.bat — Aplicar migration no banco real
REM Uso: cmd /c apply_migration.bat <arquivo>
REM Ex: cmd /c "database\apply_migration.bat database\NNN_nome.sql"
REM ============================================================================
set PGPASSWORD=070998
set PSQL="C:\Program Files\PostgreSQL\18\bin\psql.exe"
set PGDUMP="C:\Program Files\PostgreSQL\18\bin\pg_dump.exe"
set DBHOST=127.0.0.1
set DBPORT=5432
set DBNAME=enjoyfun
set DBUSER=postgres
set "LOGFILE=%~dp0migrations_applied.log"
set "DUMPLOGFILE=%~dp0dump_history.log"

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

%PSQL% -h %DBHOST% -p %DBPORT% -U %DBUSER% -d %DBNAME% -f "%MIGFILE%" -v ON_ERROR_STOP=1

if %ERRORLEVEL% NEQ 0 (
    echo [ERRO] Migration falhou. Verifique o log acima.
    exit /b 1
)

echo [OK] Migration aplicada com sucesso: %MIGFILE%
echo %date% %time% - APLICADA: %MIGFILE% >> "%LOGFILE%"

set "TODAY=%date:~-4%%date:~3,2%%date:~0,2%"
set "DUMPFILE=%~dp0schema_dump_%TODAY%.sql"
set "CURRENTFILE=%~dp0schema_current.sql"

echo [INFO] Atualizando baseline schema_current.sql...

%PGDUMP% -h %DBHOST% -p %DBPORT% -U %DBUSER% -d %DBNAME% --schema-only --no-owner --no-privileges -f "%DUMPFILE%"

if %ERRORLEVEL% NEQ 0 (
    echo [ERRO] Migration aplicada, mas o dump de schema falhou. Regerar schema_current.sql manualmente.
    exit /b 2
)

copy /Y "%DUMPFILE%" "%CURRENTFILE%" >nul
if %ERRORLEVEL% NEQ 0 (
    echo [ERRO] Migration aplicada, mas falhou ao atualizar schema_current.sql.
    exit /b 2
)

echo %date% %time% - DUMP: schema_dump_%TODAY%.sql ^| BASELINE: schema_current.sql >> "%DUMPLOGFILE%"
echo [OK] Baseline atualizado: %CURRENTFILE%
