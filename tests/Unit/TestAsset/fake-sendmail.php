<?php

/**
 * A stand-in for the sendmail program in the Sendmail tests, run with PHP_BINARY.
 *
 * Usage: php fake-sendmail.php RECORD MODE [sendmail arguments...]
 *
 * Writes the sendmail arguments and standard input as JSON to the file RECORD, then acts as
 * MODE says: "ok" exits 0, "warn" writes to standard error and exits 0, "fail" writes to
 * standard error and exits 75, "fail-stdout" writes only whitespace to standard error and
 * text to standard output and exits 1, "fail-silent" exits 1 without output, and "hang" sleeps
 * for 30 seconds, for the timeout tests.
 *
 * RECORD must be a .json file in the temporary directory, so that a mutant passing other
 * arguments in its place cannot leave files anywhere else.
 */

declare(strict_types=1);

$arguments = $_SERVER['argv'] ?? [];
$record    = (string) ($arguments[1] ?? '');
$mode      = (string) ($arguments[2] ?? '');

if (! str_starts_with($record, sys_get_temp_dir()) || ! str_ends_with($record, '.json')) {
    fwrite(STDERR, data: "fake-sendmail: the record must be a .json file in the temporary directory\n");
    exit(2);
}

file_put_contents($record, json_encode([
    'argv'  => array_slice($arguments, offset: 3),
    'stdin' => stream_get_contents(STDIN),
], JSON_THROW_ON_ERROR));

if ('warn' === $mode || 'fail' === $mode) {
    fwrite(STDERR, data: "sendmail: cannot write the queue file\n");
}

if ('hang' === $mode) {
    sleep(30);
}

if ('fail-stdout' === $mode) {
    fwrite(STDERR, data: " \n");
    fwrite(STDOUT, data: "sendmail: recipient refused\n");
}

exit(match ($mode) {
    'ok', 'warn' => 0,
    'fail'       => 75,
    default      => 1,
});
