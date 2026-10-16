@echo off
rem Starts Hamsukhan locally at http://127.0.0.1:8123
rem Runs from public\ (Laravel's router expects that) with OPcache on, so pages stay fast on the slow F: drive.
cd /d %~dp0public
echo Hamsukhan running at http://127.0.0.1:8123  (Ctrl+C to stop)
php -d max_execution_time=180 -d zend_extension=opcache -d opcache.enable_cli=1 -S 127.0.0.1:8123 ..\vendor\laravel\framework\src\Illuminate\Foundation\resources\server.php
