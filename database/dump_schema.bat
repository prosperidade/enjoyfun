@echo off
REM ============================================================================
REM dump_schema.bat — EnjoyFun Daily Schema Dump
REM Uso: cmd /c "database\dump_schema.bat"
REM Gera schema_dump_YYYYMMDD.sql e atualiza schema_current.sql
REM ============================================================================

set PGPASSWORD=070998
set PGDUMP="C:\Program Files\PostgreSQL\18\bin\pg_dump.exe"
set DBNAME=enjoyfun
set DBUSER=postgres
set "OUTDIR=%~dp0"
set "LOGFILE=%OUTDIR%dump_history.log"

for /f %%i in ('powershell -NoProfile -Command "Get-Date -Format yyyyMMdd"') do set TODAY=%%i

set DUMPFILE=%OUTDIR%\schema_dump_%TODAY%.sql
set CURRENTFILE=%OUTDIR%\schema_current.sql

echo [%date% %time%] Iniciando dump de schema...

%PGDUMP% -U %DBUSER% -d %DBNAME% --schema-only --no-owner --no-privileges -f "%DUMPFILE%"

if %ERRORLEVEL% NEQ 0 (
    echo [ERRO] pg_dump falhou. Verifique conexão e credenciais.
    exit /b 1
)

echo [OK] Dump gerado: %DUMPFILE%

REM Copia como schema_current.sql (referência sempre atualizada)
copy /Y "%DUMPFILE%" "%CURRENTFILE%"
if %ERRORLEVEL% NEQ 0 (
    echo [ERRO] Falha ao atualizar schema_current.sql.
    exit /b 1
)
echo [OK] schema_current.sql atualizado.

REM Registra no log de dumps
echo %date% %time% - DUMP: schema_dump_%TODAY%.sql ^| BASELINE: schema_current.sql >> "%LOGFILE%"

echo.
echo [DONE] Schema dump completo.
echo   - Arquivo do dia: %DUMPFILE%
echo   - Referencia atual: %CURRENTFILE%
echo   - Log: %LOGFILE%
echo.
echo Próximos passos:
echo   1. Revisar o diff: git diff database/schema_current.sql
echo   2. Se houve mudanca estrutural inesperada, criar migration dedicada
echo   3. git add database/ e git commit com mensagem "schema: dump YYYYMMDD"
