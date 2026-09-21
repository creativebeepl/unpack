<?php
declare(strict_types=1);

/**
 * Testy Rozpakowywarki — bez zależności, uruchom:  php tests/run.php [filtr-nazwy]
 *
 * Ładuje index.php bez obsługi żądania (UNPACK_NO_BOOT) i wykonuje pliki tests/cases/*_test.php.
 * Testy formatów zewnętrznych (7z, bz2) są pomijane, gdy serwer ich nie ma.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

$root = dirname(__DIR__);
$work = sys_get_temp_dir() . '/rozpakowywarka-tests-' . bin2hex(random_bytes(4));
mkdir($work, 0700, true);
putenv('UNPACK_WORK_DIR=' . $work . '/work');
putenv('UNPACK_MAX_BYTES=' . (64 * 1024 * 1024));
putenv('UNPACK_MAX_FILES=200');

define('UNPACK_NO_BOOT', true);
define('TEST_ROOT', $root);
define('TEST_TMP', $work);
require $root . '/index.php';

final class SkipTest extends Exception
{
}

final class Tests
{
    /** @var list<array{string,callable}> */
    public static array $all = [];
}

function test(string $name, callable $fn): void
{
    Tests::$all[] = [$name, $fn];
}

function skip(string $why): never
{
    throw new SkipTest($why);
}

function assertTrue(mixed $cond, string $msg = 'oczekiwano true'): void
{
    if ($cond !== true) {
        throw new RuntimeException($msg);
    }
}

function assertSame(mixed $expected, mixed $actual, string $msg = ''): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(($msg !== '' ? $msg . ': ' : '') . 'oczekiwano ' . var_export($expected, true) . ', jest ' . var_export($actual, true));
    }
}

function assertContains(string $needle, string $haystack, string $msg = ''): void
{
    if (!str_contains($haystack, $needle)) {
        throw new RuntimeException(($msg !== '' ? $msg . ': ' : '') . 'brak „' . $needle . '” w: ' . substr($haystack, 0, 300));
    }
}

function assertThrows(callable $fn, string $messagePart = ''): void
{
    try {
        $fn();
    } catch (RuntimeException $e) {
        assertContains($messagePart, $e->getMessage(), 'komunikat wyjątku');
        return;
    }
    throw new RuntimeException('oczekiwano wyjątku' . ($messagePart !== '' ? ' z „' . $messagePart . '”' : ''));
}

/** Tworzy pusty katalog testowy. */
function tmpDir(string $label = 't'): string
{
    $d = TEST_TMP . '/' . $label . '-' . bin2hex(random_bytes(4));
    mkdir($d, 0700, true);
    return $d;
}

/** Zapisuje pliki [ścieżka => zawartość] w katalogu; zwraca [ścieżka => ścieżka na dysku]. @return array<string,string> */
function makeFiles(string $dir, array $files): array
{
    $abs = [];
    foreach ($files as $rel => $content) {
        $p = $dir . '/' . $rel;
        if (!is_dir(dirname($p))) {
            mkdir(dirname($p), 0700, true);
        }
        file_put_contents($p, $content);
        $abs[(string)$rel] = $p;
    }
    return $abs;
}

/** @return array<string,string> ścieżka => zawartość (posortowane) */
function readTree(string $dir): array
{
    $out = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if ($f->isFile()) {
            $out[substr($f->getPathname(), strlen($dir) + 1)] = (string)file_get_contents($f->getPathname());
        }
    }
    ksort($out);
    return $out;
}

/** Wywołuje istniejącą część „Rozpakuj” na wygenerowanym archiwum i zwraca odczytane drzewo plików. @return array<string,string> */
function unpackWithApp(string $archive, string $password = ''): array
{
    $type = detectType($archive);
    if ($type === null) {
        throw new RuntimeException('detectType nie rozpoznał archiwum');
    }
    $dir = tmpDir('unpacked');
    $ex = new Extractor($dir);
    switch ($type) {
        case 'zip':
            extractZip($archive, $password, $ex);
            break;
        case 'tar':
            extractTar($archive, $ex);
            break;
        case 'tar.gz':
            extractTar('compress.zlib://' . $archive, $ex);
            break;
        case 'tar.bz2':
            extractTar('compress.bzip2://' . $archive, $ex);
            break;
        case 'gz':
            extractSingle('compress.zlib://' . $archive, (string)preg_replace('/\.gz$/', '', basename($archive)), $ex);
            break;
        case 'bz2':
            extractSingle('compress.bzip2://' . $archive, (string)preg_replace('/\.bz2$/', '', basename($archive)), $ex);
            break;
        case '7z':
            extract7z($archive, $password, $ex);
            break;
    }
    return readTree($dir);
}

foreach (glob(__DIR__ . '/cases/*_test.php') ?: [] as $file) {
    require $file;
}

$filter = $argv[1] ?? '';
$pass = $fail = $skipped = 0;
foreach (Tests::$all as [$name, $fn]) {
    if ($filter !== '' && !str_contains($name, $filter)) {
        continue;
    }
    try {
        $fn();
        $pass++;
        echo "  ok    $name\n";
    } catch (SkipTest $e) {
        $skipped++;
        echo "  skip  $name  ({$e->getMessage()})\n";
    } catch (Throwable $e) {
        $fail++;
        echo "  FAIL  $name\n        " . str_replace("\n", "\n        ", $e->getMessage()) . "\n        w " . basename($e->getFile()) . ':' . $e->getLine() . "\n";
    }
}

rrmdir($work);
echo "\n$pass ok, $fail błędów, $skipped pominiętych\n";
exit($fail > 0 ? 1 : 0);
