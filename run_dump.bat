@echo off
set PGPASSWORD=070998
"C:\Program Files\PostgreSQL\18\bin\pg_dump.exe" -U postgres -d enjoyfun --schema-only --no-owner --no-privileges -f "c:\Users\Administrador\Desktop\enjoyfun\database\schema_dump_20260313.sql"
if %ERRORLEVEL% EQU 0 (
    echo DUMP_SUCCESS
) else (
    echo DUMP_FAILED error=%ERRORLEVEL%
)
