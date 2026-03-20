<?php

declare(strict_types=1);

$root = getenv('FIREBIRD_TEST_ROOT') ?: 'C:\\firebird5';
$user = getenv('FIREBIRD_TEST_USER') ?: 'SYSDBA';
$password = getenv('FIREBIRD_TEST_PASSWORD') ?: '1619092230';
$database = getenv('FIREBIRD_TEST_DB') ?: __DIR__.'/test.fdb';
$isql = rtrim($root, "\\/").DIRECTORY_SEPARATOR.'isql.exe';

if (! file_exists($isql)) {
    fwrite(STDERR, "isql.exe not found at {$isql}".PHP_EOL);
    exit(1);
}

if (file_exists($database) && ! unlink($database)) {
    fwrite(STDERR, "Unable to remove existing database: {$database}".PHP_EOL);
    exit(1);
}

$sql = sprintf(
    "CREATE DATABASE '%s' user '%s' password '%s';\nQUIT;\n",
    str_replace('\\', '/', $database),
    str_replace("'", "''", $user),
    str_replace("'", "''", $password)
);

$script = __DIR__.'/create_test_db.sql';
file_put_contents($script, $sql);

$descriptors = [
    0 => ['pipe', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
];

$process = proc_open([$isql, '-i', $script], $descriptors, $pipes, dirname(__DIR__));

if (! is_resource($process)) {
    fwrite(STDERR, "Unable to start isql.exe".PHP_EOL);
    exit(1);
}

fclose($pipes[0]);
$output = stream_get_contents($pipes[1]);
$error = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$exitCode = proc_close($process);

if (file_exists($script)) {
    @unlink($script);
}

if ($exitCode !== 0 || ! file_exists($database)) {
    fwrite(STDERR, trim($output."\n".$error).PHP_EOL);
    exit($exitCode ?: 1);
}

echo "Database created: {$database}".PHP_EOL;
