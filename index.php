<?php
declare(strict_types=1);

/**
 * Rozpakowywarka online — rozpakowuje archiwa przesłane formularzem.
 *
 * Formaty:  ZIP (także z hasłem), TAR, TAR.GZ/TGZ, TAR.BZ2, GZ, BZ2, 7Z
 * Wymaga:   PHP 8.1+
 *           ext-zip   (ZIP)
 *           ext-zlib  (GZ, TAR.GZ)      — zwykle wbudowane
 *           ext-bz2   (BZ2, TAR.BZ2)    — opcjonalne
 *           program 7z / 7zz / 7za      — opcjonalny, tylko dla 7Z
 *
 * Ustawienia można zmieniać zmiennymi środowiskowymi:
 *   UNPACK_MAX_BYTES  maks. łączny rozmiar po rozpakowaniu (domyślnie 512 MB)
 *   UNPACK_MAX_FILES  maks. liczba plików w archiwum        (domyślnie 5000)
 *   UNPACK_TTL        czas przechowywania wyników w sekundach (domyślnie 3600)
 *   UNPACK_WORK_DIR   katalog roboczy (domyślnie <katalog tymczasowy>/rozpakowywarka)
 */

define('MAX_UNPACKED_BYTES', (int)(getenv('UNPACK_MAX_BYTES') ?: 512 * 1024 * 1024));
define('MAX_FILES', (int)(getenv('UNPACK_MAX_FILES') ?: 5000));
define('TTL_SECONDS', (int)(getenv('UNPACK_TTL') ?: 3600));
define('WORK_DIR', rtrim((string)(getenv('UNPACK_WORK_DIR') ?: sys_get_temp_dir() . '/rozpakowywarka'), '/'));

/* ------------------------------------------------------------------ */
/*  Narzędzia ogólne                                                   */
/* ------------------------------------------------------------------ */

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function humanSize(int $bytes): string
{
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $v = (float)$bytes;
    $i = 0;
    while ($v >= 1024 && $i < count($units) - 1) {
        $v /= 1024;
        $i++;
    }
    $num = $i === 0 ? (string)(int)$v : (string)preg_replace('/,0$/', '', number_format($v, 1, ',', ' '));
    return $num . ' ' . $units[$i];
}

function iniBytes(string|false $v): int
{
    if ($v === false) {
        return 0;
    }
    $v = trim($v);
    if ($v === '') {
        return 0;
    }
    $n = (float)$v;
    return (int)match (strtolower(substr($v, -1))) {
        'g' => $n * 1024 ** 3,
        'm' => $n * 1024 ** 2,
        'k' => $n * 1024,
        default => $n,
    };
}

function uploadLimit(): int
{
    $limits = array_filter([
        iniBytes(ini_get('upload_max_filesize')),
        iniBytes(ini_get('post_max_size')),
    ], static fn(int $x): bool => $x > 0);
    return $limits ? min($limits) : 0;
}

function plural(int $n, string $one, string $few, string $many): string
{
    if ($n === 1) {
        return $one;
    }
    $last = $n % 10;
    $lastTwo = $n % 100;
    return ($last >= 2 && $last <= 4 && ($lastTwo < 12 || $lastTwo > 14)) ? $few : $many;
}

function joinPl(array $items): string
{
    $items = array_values($items);
    if (count($items) <= 1) {
        return (string)($items[0] ?? '');
    }
    $last = array_pop($items);
    return implode(', ', $items) . ' i ' . $last;
}

function rrmdir(string $dir): void
{
    if (is_link($dir) || !is_dir($dir)) {
        @unlink($dir);
        return;
    }
    foreach (new DirectoryIterator($dir) as $f) {
        if ($f->isDot()) {
            continue;
        }
        $p = $f->getPathname();
        if ($f->isDir() && !$f->isLink()) {
            rrmdir($p);
        } else {
            @unlink($p);
        }
    }
    @rmdir($dir);
}

function cleanupOld(): void
{
    if (!is_dir(WORK_DIR) || random_int(1, 20) !== 1) {
        return;
    }
    $now = time();
    foreach (new DirectoryIterator(WORK_DIR) as $f) {
        if ($f->isDot() || $f->isLink() || !$f->isDir()) {
            continue;
        }
        if ($now - $f->getMTime() > TTL_SECONDS) {
            rrmdir($f->getPathname());
        }
    }
}

/** Nazwy w archiwach bywają w CP852/CP1250 (starsze programy na Windowsie) — zamień na UTF-8. */
function fixEncoding(string $s): string
{
    if (preg_match('//u', $s) === 1) {
        return $s;
    }
    if (function_exists('iconv')) {
        foreach (['CP852', 'CP1250', 'ISO-8859-1'] as $charset) {
            $c = @iconv($charset, 'UTF-8//IGNORE', $s);
            if ($c !== false && $c !== '') {
                return $c;
            }
        }
    }
    return (string)preg_replace('/[^\x20-\x7E]/', '_', $s);
}

/**
 * Zamienia nazwę z archiwum na bezpieczną ścieżkę względną albo zwraca null.
 * Odrzuca: "..", ścieżki bezwzględne, litery dysków, znaki sterujące (ochrona przed „zip slip”).
 */
function safeRelPath(string $name): ?string
{
    $name = str_replace('\\', '/', $name);
    if (str_contains($name, "\0") || preg_match('/^[A-Za-z]:/', $name) === 1) {
        return null;
    }
    $parts = [];
    foreach (explode('/', $name) as $seg) {
        if ($seg === '' || $seg === '.') {
            continue;
        }
        if ($seg === '..' || strlen($seg) > 255 || preg_match('/[\x00-\x1F]/', $seg) === 1) {
            return null;
        }
        $parts[] = $seg;
    }
    if (!$parts) {
        return null;
    }
    $rel = implode('/', $parts);
    return strlen($rel) > 1024 ? null : $rel;
}

/* ------------------------------------------------------------------ */
/*  Zapis rozpakowanych plików z limitami                              */
/* ------------------------------------------------------------------ */

final class Extractor
{
    public int $bytes = 0;
    public int $count = 0;
    /** @var array<string,int> */
    public array $files = [];
    /** @var string[] */
    public array $skipped = [];

    public function __construct(public readonly string $root)
    {
    }

    public function skip(string $raw): void
    {
        if (count($this->skipped) < 100) {
            $this->skipped[] = fixEncoding($raw);
        }
    }

    public function addDir(string $rawName): void
    {
        $rel = safeRelPath(fixEncoding($rawName));
        if ($rel === null) {
            $this->skip($rawName);
            return;
        }
        $p = $this->root . '/' . $rel;
        if (!is_dir($p) && !@mkdir($p, 0755, true)) {
            $this->skip($rawName);
        }
    }

    /**
     * Zapisuje jeden plik. $next zwraca kolejne porcje danych, a pusty ciąg na końcu.
     * Zwraca liczbę zapisanych bajtów albo null, gdy wpis pominięto.
     */
    public function store(string $rawName, callable $next): ?int
    {
        $rel = safeRelPath(fixEncoding($rawName));
        if ($rel === null) {
            $this->skip($rawName);
            return null;
        }
        if ($this->count >= MAX_FILES) {
            throw new RuntimeException('Archiwum zawiera więcej niż ' . MAX_FILES . ' plików.');
        }
        $target = $this->root . '/' . $rel;
        $dir = dirname($target);
        if ((!is_dir($dir) && !@mkdir($dir, 0755, true)) || is_dir($target)) {
            $this->skip($rawName);
            return null;
        }
        $out = @fopen($target, 'wb');
        if ($out === false) {
            $this->skip($rawName);
            return null;
        }
        $size = 0;
        try {
            while (true) {
                $chunk = $next();
                if ($chunk === '' || $chunk === false || $chunk === null) {
                    break;
                }
                $len = strlen($chunk);
                $this->bytes += $len;
                if ($this->bytes > MAX_UNPACKED_BYTES) {
                    throw new RuntimeException('Po rozpakowaniu dane przekraczają limit ' . humanSize(MAX_UNPACKED_BYTES) . '.');
                }
                fwrite($out, $chunk);
                $size += $len;
            }
        } finally {
            fclose($out);
        }
        if (!isset($this->files[$rel])) {
            $this->count++;
        }
        $this->files[$rel] = $size;
        return $size;
    }

    /** Dla rozpakowań wykonanych zewnętrznym programem: przejrzyj katalog, usuń dowiązania, policz pliki. */
    public function scan(): void
    {
        $prefix = strlen($this->root) + 1;
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $info) {
            $path = $info->getPathname();
            $rel = substr($path, $prefix);
            if ($info->isLink()) {
                @unlink($path);
                $this->skip($rel . ' (dowiązanie symboliczne)');
                continue;
            }
            if ($info->isDir()) {
                continue;
            }
            if (!$info->isFile()) {
                @unlink($path);
                continue;
            }
            if (preg_match('//u', $rel) !== 1) {
                @unlink($path);
                $this->skip($rel . ' (nieobsługiwane kodowanie nazwy)');
                continue;
            }
            if ($this->count >= MAX_FILES) {
                throw new RuntimeException('Archiwum zawiera więcej niż ' . MAX_FILES . ' plików.');
            }
            $size = (int)$info->getSize();
            $this->bytes += $size;
            if ($this->bytes > MAX_UNPACKED_BYTES) {
                throw new RuntimeException('Po rozpakowaniu dane przekraczają limit ' . humanSize(MAX_UNPACKED_BYTES) . '.');
            }
            $this->files[$rel] = $size;
            $this->count++;
        }
    }
}

/* ------------------------------------------------------------------ */
/*  Wykrywanie formatu                                                 */
/* ------------------------------------------------------------------ */

function tarHeaderValid(string $h): bool
{
    if (strlen($h) !== 512 || $h === str_repeat("\0", 512)) {
        return false;
    }
    $stored = octdec(trim(substr($h, 148, 8), " \0"));
    $head = substr($h, 0, 148);
    $tail = substr($h, 156);
    $unsigned = array_sum(unpack('C*', $head)) + 256 + array_sum(unpack('C*', $tail));
    $signed = array_sum(unpack('c*', $head)) + 256 + array_sum(unpack('c*', $tail));
    return $stored == $unsigned || $stored == $signed;
}

function isTarStream(string $uri): bool
{
    $fh = @fopen($uri, 'rb');
    if ($fh === false) {
        return false;
    }
    $buf = '';
    while (strlen($buf) < 512) {
        $c = fread($fh, 512 - strlen($buf));
        if ($c === false || $c === '') {
            break;
        }
        $buf .= $c;
    }
    fclose($fh);
    return tarHeaderValid($buf);
}

/** Format rozpoznajemy po zawartości pliku, a nie po rozszerzeniu. */
function detectType(string $path): ?string
{
    $fh = @fopen($path, 'rb');
    if ($fh === false) {
        return null;
    }
    $head = (string)fread($fh, 8);
    fclose($fh);

    if (str_starts_with($head, "PK\x03\x04") || str_starts_with($head, "PK\x05\x06")) {
        return 'zip';
    }
    if (str_starts_with($head, "7z\xBC\xAF\x27\x1C")) {
        return '7z';
    }
    if (str_starts_with($head, "\x1F\x8B")) {
        return isTarStream('compress.zlib://' . $path) ? 'tar.gz' : 'gz';
    }
    if (str_starts_with($head, 'BZh')) {
        return isTarStream('compress.bzip2://' . $path) ? 'tar.bz2' : 'bz2';
    }
    return isTarStream($path) ? 'tar' : null;
}

/* ------------------------------------------------------------------ */
/*  ZIP                                                                */
/* ------------------------------------------------------------------ */

function extractZip(string $path, string $password, Extractor $ex): void
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('Ten serwer nie ma rozszerzenia PHP „zip”, więc nie rozpakuje plików ZIP.');
    }
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::RDONLY) !== true) {
        throw new RuntimeException('Nie można otworzyć pliku ZIP — plik jest uszkodzony albo to nie jest archiwum ZIP.');
    }
    try {
        if ($password !== '') {
            $zip->setPassword($password);
        }
        $num = $zip->numFiles;
        if ($num > MAX_FILES * 4) {
            throw new RuntimeException('Archiwum zawiera zbyt wiele wpisów.');
        }
        $declared = 0;
        for ($i = 0; $i < $num; $i++) {
            $st = $zip->statIndex($i);
            if ($st !== false) {
                $declared += (int)$st['size'];
            }
        }
        if ($declared > MAX_UNPACKED_BYTES) {
            throw new RuntimeException('Po rozpakowaniu dane przekraczają limit ' . humanSize(MAX_UNPACKED_BYTES) . '.');
        }

        for ($i = 0; $i < $num; $i++) {
            $st = $zip->statIndex($i);
            if ($st === false) {
                continue;
            }
            $raw = (string)$zip->getNameIndex($i, ZipArchive::FL_ENC_RAW);
            if (str_ends_with($raw, '/')) {
                $ex->addDir($raw);
                continue;
            }
            $encrypted = (int)($st['encryption_method'] ?? 0) !== 0;
            if ($encrypted && $password === '') {
                throw new RuntimeException('Archiwum jest chronione hasłem — wpisz hasło i spróbuj ponownie.');
            }
            $stream = $zip->getStreamIndex($i);
            if ($stream === false) {
                throw new RuntimeException($encrypted
                    ? 'Nieprawidłowe hasło albo nieobsługiwany typ szyfrowania.'
                    : 'Nie można odczytać jednego z wpisów w archiwum ZIP.');
            }
            $crc = hash_init('crc32b');
            try {
                $written = $ex->store($raw, static function () use ($stream, $crc) {
                    $d = fread($stream, 65536);
                    if ($d === false || $d === '') {
                        return '';
                    }
                    hash_update($crc, $d);
                    return $d;
                });
            } finally {
                fclose($stream);
            }
            if ($written !== null) {
                $crcOk = hexdec(hash_final($crc)) === (int)$st['crc'];
                if ($written !== (int)$st['size'] || !$crcOk) {
                    throw new RuntimeException($encrypted
                        ? 'Nieprawidłowe hasło albo uszkodzone archiwum.'
                        : 'Jeden z plików w archiwum jest uszkodzony (błędna suma kontrolna).');
                }
            }
        }
    } finally {
        $zip->close();
    }
}

/* ------------------------------------------------------------------ */
/*  TAR (czysty PHP, także przez strumienie gzip/bzip2)                */
/* ------------------------------------------------------------------ */

function readExact($fh, int $n): string
{
    $buf = '';
    while (strlen($buf) < $n) {
        $c = fread($fh, $n - strlen($buf));
        if ($c === false || $c === '') {
            throw new RuntimeException('Archiwum jest ucięte lub uszkodzone.');
        }
        $buf .= $c;
    }
    return $buf;
}

function skipBytes($fh, int $n): void
{
    while ($n > 0) {
        $step = min($n, 65536);
        readExact($fh, $step);
        $n -= $step;
    }
}

function readBlock($fh): ?string
{
    $buf = '';
    while (strlen($buf) < 512) {
        $c = fread($fh, 512 - strlen($buf));
        if ($c === false || $c === '') {
            break;
        }
        $buf .= $c;
    }
    if ($buf === '') {
        return null;
    }
    if (strlen($buf) < 512) {
        throw new RuntimeException('Archiwum TAR jest ucięte.');
    }
    return $buf;
}

function tarNumber(string $field): int
{
    if ((ord($field[0]) & 0x80) !== 0) { // format binarny GNU dla dużych plików
        $v = ord($field[0]) & 0x7F;
        for ($i = 1, $n = strlen($field); $i < $n; $i++) {
            $v = $v * 256 + ord($field[$i]);
        }
        return $v > PHP_INT_MAX / 2 ? intdiv(PHP_INT_MAX, 2) : (int)$v;
    }
    return (int)octdec(trim($field, " \0"));
}

function tarPad(int $size): int
{
    return (512 - ($size % 512)) % 512;
}

/** @return array<string,string> */
function parsePax(string $data): array
{
    $out = [];
    $pos = 0;
    $len = strlen($data);
    while ($pos < $len) {
        $sp = strpos($data, ' ', $pos);
        if ($sp === false) {
            break;
        }
        $recLen = (int)substr($data, $pos, $sp - $pos);
        if ($recLen <= 0) {
            break;
        }
        $rec = substr($data, $sp + 1, $recLen - ($sp - $pos) - 2);
        $eq = strpos($rec, '=');
        if ($eq !== false) {
            $out[substr($rec, 0, $eq)] = substr($rec, $eq + 1);
        }
        $pos += $recLen;
    }
    return $out;
}

function extractTar(string $uri, Extractor $ex): void
{
    $fh = @fopen($uri, 'rb');
    if ($fh === false) {
        throw new RuntimeException('Nie można otworzyć archiwum TAR (brak obsługi kompresji na tym serwerze?).');
    }
    try {
        $longName = null;
        $pax = [];
        while (true) {
            $hdr = readBlock($fh);
            if ($hdr === null || $hdr === str_repeat("\0", 512)) {
                break;
            }
            if (!tarHeaderValid($hdr)) {
                throw new RuntimeException('Nieprawidłowy nagłówek TAR — archiwum jest uszkodzone.');
            }
            $type = $hdr[156];
            $size = tarNumber(substr($hdr, 124, 12));
            $name = rtrim(substr($hdr, 0, 100), "\0");
            if (substr($hdr, 257, 5) === 'ustar') {
                $prefix = rtrim(substr($hdr, 345, 155), "\0");
                if ($prefix !== '') {
                    $name = $prefix . '/' . $name;
                }
            }

            // nagłówki pomocnicze: długie nazwy GNU, rozszerzenia PAX
            if ($type === 'L' || $type === 'x' || $type === 'g' || $type === 'K') {
                if ($size > 1048576) {
                    throw new RuntimeException('Nieprawidłowy nagłówek TAR — archiwum jest uszkodzone.');
                }
                $data = readExact($fh, $size);
                skipBytes($fh, tarPad($size));
                if ($type === 'L') {
                    $longName = rtrim($data, "\0");
                } elseif ($type === 'x') {
                    $pax = parsePax($data);
                }
                continue;
            }

            if (isset($pax['path'])) {
                $name = $pax['path'];
            } elseif ($longName !== null) {
                $name = $longName;
            }
            if (isset($pax['size'])) {
                $size = (int)$pax['size'];
            }
            $longName = null;
            $pax = [];

            $toSkip = $size;
            $isFile = $type === '0' || $type === "\0" || $type === '7';
            if ($type === '5' || ($isFile && str_ends_with($name, '/'))) {
                $ex->addDir($name);
            } elseif ($isFile) {
                if ($size > MAX_UNPACKED_BYTES) {
                    throw new RuntimeException('Po rozpakowaniu dane przekraczają limit ' . humanSize(MAX_UNPACKED_BYTES) . '.');
                }
                $remaining = $size;
                $ex->store($name, static function () use ($fh, &$remaining) {
                    if ($remaining <= 0) {
                        return '';
                    }
                    $n = min(65536, $remaining);
                    $d = readExact($fh, $n);
                    $remaining -= $n;
                    return $d;
                });
                $toSkip = $remaining; // niezerowe, jeśli wpis pominięto
            } else {
                $ex->skip($name . ' (dowiązanie lub plik specjalny)');
            }
            skipBytes($fh, $toSkip + tarPad($size));
        }
    } finally {
        fclose($fh);
    }
}

/** Pojedynczy plik skompresowany (.gz / .bz2). */
function extractSingle(string $uri, string $outName, Extractor $ex): void
{
    $fh = @fopen($uri, 'rb');
    if ($fh === false) {
        throw new RuntimeException('Nie można otworzyć pliku (brak obsługi kompresji na tym serwerze?).');
    }
    try {
        $ex->store($outName, static function () use ($fh) {
            $d = fread($fh, 65536);
            return $d === false ? '' : $d;
        });
    } finally {
        fclose($fh);
    }
}

/* ------------------------------------------------------------------ */
/*  7Z (przez program 7z / 7zz / 7za)                                  */
/* ------------------------------------------------------------------ */

function find7z(): ?string
{
    $dirs = array_filter(array_merge(
        explode(PATH_SEPARATOR, (string)getenv('PATH')),
        ['/usr/bin', '/usr/local/bin', '/bin', '/opt/homebrew/bin']
    ));
    foreach (['7zz', '7z', '7za', '7zr'] as $bin) {
        foreach ($dirs as $d) {
            $p = rtrim($d, '/') . '/' . $bin;
            if (@is_file($p) && @is_executable($p)) {
                return $p;
            }
        }
    }
    return null;
}

/** @return array{0:int,1:string,2:string} kod wyjścia, stdout, stderr */
function runCmd(array $cmd, int $timeout, int $capBytes): array
{
    $env = [
        'PATH' => (string)(getenv('PATH') ?: '/usr/bin:/bin'),
        'LANG' => 'C.UTF-8',
        'LC_ALL' => 'C.UTF-8',
    ];
    $proc = @proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
    if (!is_resource($proc)) {
        return [-1, '', 'proc_open failed'];
    }
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $out = '';
    $err = '';
    $exit = -1;
    $start = time();
    while (true) {
        $chunk = stream_get_contents($pipes[1]);
        if (is_string($chunk) && strlen($out) < $capBytes) {
            $out .= $chunk;
        }
        $chunk = stream_get_contents($pipes[2]);
        if (is_string($chunk) && strlen($err) < $capBytes) {
            $err .= $chunk;
        }
        $st = proc_get_status($proc);
        if (!$st['running']) {
            $exit = (int)$st['exitcode'];
            $rest = stream_get_contents($pipes[1]);
            if (is_string($rest) && strlen($out) < $capBytes) {
                $out .= $rest;
            }
            $rest = stream_get_contents($pipes[2]);
            if (is_string($rest) && strlen($err) < $capBytes) {
                $err .= $rest;
            }
            break;
        }
        if (time() - $start > $timeout) {
            proc_terminate($proc, 9);
            $err .= "\nTimeout";
            break;
        }
        usleep(40000);
    }
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);
    return [$exit, $out, $err];
}

function sevenZipError(string $text): string
{
    if (preg_match('/wrong password|encrypted|enter password|password/i', $text) === 1) {
        return 'Nieprawidłowe hasło albo archiwum wymaga hasła.';
    }
    error_log('7z: ' . substr($text, 0, 500));
    return 'Nie udało się rozpakować archiwum 7Z — plik może być uszkodzony.';
}

function extract7z(string $archive, string $password, Extractor $ex): void
{
    $bin = find7z();
    if ($bin === null || !function_exists('proc_open')) {
        throw new RuntimeException('Ten serwer nie obsługuje 7Z (brak programu 7z).');
    }
    $pw = $password !== '' ? ['-p' . $password] : [];

    // 1) lista wpisów — sprawdzamy limity zanim cokolwiek trafi na dysk
    [$code, $out, $err] = runCmd(array_merge([$bin, 'l', '-slt'], $pw, ['--', $archive]), 120, 16 * 1024 * 1024);
    if ($code < 0 || $code > 1) {
        throw new RuntimeException(sevenZipError($err . "\n" . $out));
    }
    $parts = preg_split('/^-{10}[ \t\r]*$/m', $out, 2);
    $blocks = preg_split('/\R{2,}/', trim($parts[1] ?? ''));
    $total = 0;
    $files = 0;
    $encrypted = false;
    foreach ($blocks ?: [] as $block) {
        if (preg_match('/^Path = /m', $block) !== 1) {
            continue;
        }
        if (preg_match('/^Symbolic Link = /m', $block) === 1 || preg_match('/^Attributes = .* l[r-][w-][x-]/m', $block) === 1) {
            throw new RuntimeException('Archiwum zawiera dowiązania symboliczne — ze względów bezpieczeństwa nie można go rozpakować.');
        }
        if (preg_match('/^Folder = \+/m', $block) === 1) {
            continue;
        }
        $files++;
        if (preg_match('/^Size = (\d+)/m', $block, $m) === 1) {
            $total += (int)$m[1];
        }
        if (preg_match('/^Encrypted = \+/m', $block) === 1) {
            $encrypted = true;
        }
    }
    if ($encrypted && $password === '') {
        throw new RuntimeException('Archiwum jest chronione hasłem — wpisz hasło i spróbuj ponownie.');
    }
    if ($files > MAX_FILES) {
        throw new RuntimeException('Archiwum zawiera więcej niż ' . MAX_FILES . ' plików.');
    }
    if ($total > MAX_UNPACKED_BYTES) {
        throw new RuntimeException('Po rozpakowaniu dane przekraczają limit ' . humanSize(MAX_UNPACKED_BYTES) . '.');
    }

    // 2) rozpakowanie do katalogu roboczego
    [$code, $out, $err] = runCmd(
        array_merge([$bin, 'x', '-y', '-aoa', '-bd', '-o' . $ex->root], $pw, ['--', $archive]),
        300,
        65536
    );
    if ($code < 0 || $code > 1) {
        throw new RuntimeException(sevenZipError($err . "\n" . $out));
    }
    $ex->scan();
}

/* ------------------------------------------------------------------ */
/*  Zadania (wyniki rozpakowania) i sesja                              */
/* ------------------------------------------------------------------ */

function selfUrl(): string
{
    $u = strtok((string)($_SERVER['REQUEST_URI'] ?? '/'), '?');
    return $u === false || $u === '' ? '/' : $u;
}

function isAjax(): bool
{
    return strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
}

function jsonOut(array $data, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function fail(string $msg, int $code = 400): never
{
    if (isAjax()) {
        jsonOut(['ok' => false, 'error' => $msg], $code);
    }
    http_response_code($code);
    renderPage($msg);
    exit;
}

/** @return array{meta:array,dir:string,files:string}|null */
function loadJob(string $token): ?array
{
    if (preg_match('/^[a-f0-9]{32}$/', $token) !== 1 || empty($_SESSION['jobs'][$token])) {
        return null;
    }
    $dir = WORK_DIR . '/' . $token;
    $metaFile = $dir . '/meta.json';
    if (!is_file($metaFile)) {
        return null;
    }
    $meta = json_decode((string)file_get_contents($metaFile), true);
    if (!is_array($meta) || !isset($meta['created'], $meta['files'])) {
        return null;
    }
    if (time() - (int)$meta['created'] > TTL_SECONDS) {
        rrmdir($dir);
        unset($_SESSION['jobs'][$token]);
        return null;
    }
    return ['meta' => $meta, 'dir' => $dir, 'files' => $dir . '/files'];
}

function handleUpload(): void
{
    $file = $_FILES['archive'] ?? null;
    if (!is_array($file) || is_array($file['error'] ?? null)) {
        fail('Wybierz plik archiwum.');
    }
    switch ((int)$file['error']) {
        case UPLOAD_ERR_OK:
            break;
        case UPLOAD_ERR_NO_FILE:
            fail('Wybierz plik archiwum.');
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            fail('Plik jest za duży. Limit przesyłania na tym serwerze to ' . humanSize(uploadLimit()) . '.', 413);
        default:
            fail('Przesyłanie pliku nie powiodło się (kod ' . (int)$file['error'] . '). Spróbuj ponownie.');
    }
    $tmp = (string)$file['tmp_name'];
    if (!is_uploaded_file($tmp)) {
        fail('Nieprawidłowy plik.');
    }
    $origName = basename(str_replace('\\', '/', (string)$file['name']));
    $password = (string)($_POST['password'] ?? '');

    $type = detectType($tmp);
    if ($type === null) {
        fail('Nie rozpoznaję tego formatu. Obsługiwane: ZIP, TAR, TAR.GZ, TAR.BZ2, GZ, BZ2 i 7Z.');
    }

    if (!is_dir(WORK_DIR) && !@mkdir(WORK_DIR, 0700, true) && !is_dir(WORK_DIR)) {
        error_log('Rozpakowywarka: nie można utworzyć ' . WORK_DIR);
        fail('Serwer nie ma miejsca na pliki tymczasowe.', 500);
    }
    @set_time_limit(300);

    $token = bin2hex(random_bytes(16));
    $jobDir = WORK_DIR . '/' . $token;
    $filesDir = $jobDir . '/files';
    if (!@mkdir($filesDir, 0700, true)) {
        fail('Serwer nie ma miejsca na pliki tymczasowe.', 500);
    }

    $ex = new Extractor($filesDir);
    try {
        switch ($type) {
            case 'zip':
                extractZip($tmp, $password, $ex);
                break;
            case 'tar':
                extractTar($tmp, $ex);
                break;
            case 'tar.gz':
                extractTar('compress.zlib://' . $tmp, $ex);
                break;
            case 'tar.bz2':
                extractTar('compress.bzip2://' . $tmp, $ex);
                break;
            case 'gz':
                extractSingle('compress.zlib://' . $tmp, safeRelPath(preg_replace('/\.gz$/i', '', $origName) ?? '') ?? 'plik', $ex);
                break;
            case 'bz2':
                extractSingle('compress.bzip2://' . $tmp, safeRelPath(preg_replace('/\.bz2$/i', '', $origName) ?? '') ?? 'plik', $ex);
                break;
            case '7z':
                extract7z($tmp, $password, $ex);
                break;
        }
    } catch (RuntimeException $e) {
        rrmdir($jobDir);
        fail($e->getMessage(), 422);
    } catch (Throwable $e) {
        rrmdir($jobDir);
        error_log('Rozpakowywarka: ' . $e);
        fail('Nie udało się rozpakować archiwum.', 500);
    }

    if ($ex->count === 0) {
        rrmdir($jobDir);
        fail('Archiwum jest puste albo nie zawiera plików, które można rozpakować.', 422);
    }

    uksort($ex->files, 'strnatcasecmp');
    file_put_contents($jobDir . '/meta.json', json_encode([
        'name' => fixEncoding($origName),
        'type' => $type,
        'created' => time(),
        'bytes' => $ex->bytes,
        'files' => $ex->files,
        'skipped' => $ex->skipped,
    ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));

    $_SESSION['jobs'] = array_slice(($_SESSION['jobs'] ?? []) + [$token => time()], -30, null, true);
    $redirect = selfUrl() . '?j=' . $token;
    if (isAjax()) {
        jsonOut(['ok' => true, 'redirect' => $redirect]);
    }
    header('Location: ' . $redirect, true, 303);
    exit;
}

function handlePost(): void
{
    if (empty($_POST) && empty($_FILES) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        fail('Plik jest za duży. Limit przesyłania na tym serwerze to ' . humanSize(uploadLimit()) . '.', 413);
    }
    if (!hash_equals((string)$_SESSION['csrf'], (string)($_POST['csrf'] ?? ''))) {
        fail('Sesja wygasła. Odśwież stronę i spróbuj ponownie.', 403);
    }
    if (($_POST['action'] ?? 'upload') === 'delete') {
        $token = (string)($_POST['token'] ?? '');
        $job = loadJob($token);
        if ($job !== null) {
            rrmdir($job['dir']);
        }
        unset($_SESSION['jobs'][$token]);
        header('Location: ' . selfUrl(), true, 303);
        exit;
    }
    handleUpload();
}

function handleDownload(): void
{
    $job = loadJob((string)($_GET['d'] ?? ''));
    if ($job === null) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Nie znaleziono pliku — wynik mógł wygasnąć.';
        return;
    }
    session_write_close();
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');

    if (isset($_GET['all'])) {
        if (!class_exists('ZipArchive')) {
            http_response_code(501);
            echo 'Brak rozszerzenia zip.';
            return;
        }
        $tmp = tempnam(sys_get_temp_dir(), 'unp');
        @unlink($tmp);
        $zip = new ZipArchive();
        if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            http_response_code(500);
            echo 'Nie można utworzyć archiwum.';
            return;
        }
        foreach (array_keys($job['meta']['files']) as $rel) {
            $rel = (string)$rel;
            $abs = $job['files'] . '/' . $rel;
            if (is_file($abs) && !is_link($abs)) {
                $zip->addFile($abs, $rel);
            }
        }
        if (!$zip->close() || !is_file($tmp)) {
            http_response_code(500);
            echo 'Nie można utworzyć archiwum.';
            return;
        }
        $base = preg_replace('/\.[A-Za-z0-9]+$/', '', (string)$job['meta']['name']);
        $dl = ($base !== '' && $base !== null ? $base : 'pliki') . '-rozpakowane.zip';
        sendFile($tmp, $dl, 'application/zip');
        @unlink($tmp);
        return;
    }

    $rel = safeRelPath((string)($_GET['f'] ?? ''));
    if ($rel === null || !isset($job['meta']['files'][$rel])) {
        http_response_code(404);
        echo 'Nie znaleziono pliku.';
        return;
    }
    $abs = $job['files'] . '/' . $rel;
    if (!is_file($abs) || is_link($abs)) {
        http_response_code(404);
        echo 'Nie znaleziono pliku.';
        return;
    }
    sendFile($abs, basename($rel), 'application/octet-stream');
}

function sendFile(string $abs, string $downloadName, string $mime): void
{
    $ascii = (string)preg_replace('/[^\x20-\x7E]|["\\\\%]/', '_', $downloadName);
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($abs));
    header('Content-Disposition: attachment; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($downloadName));
    readfile($abs);
}

/* ------------------------------------------------------------------ */
/*  Widok                                                              */
/* ------------------------------------------------------------------ */

function renderPage(?string $error = null, ?array $job = null, string $token = ''): void
{
    $nonce = base64_encode(random_bytes(12));
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    header('Referrer-Policy: no-referrer');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('X-Robots-Tag: noindex');
    header("Content-Security-Policy: default-src 'none'; style-src 'nonce-$nonce'; script-src 'nonce-$nonce'; connect-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");

    $self = selfUrl();
    $csrf = (string)$_SESSION['csrf'];
    $limit = uploadLimit();
    $gz = in_array('compress.zlib', stream_get_wrappers(), true);
    $bz = in_array('compress.bzip2', stream_get_wrappers(), true);
    $formats = [
        'ZIP' => class_exists('ZipArchive'),
        'TAR' => true,
        'TAR.GZ' => $gz,
        'GZ' => $gz,
        'TAR.BZ2' => $bz,
        'BZ2' => $bz,
        '7Z' => find7z() !== null && function_exists('proc_open'),
    ];
    $supported = array_keys(array_filter($formats));
    $missing = array_keys(array_filter($formats, static fn(bool $v): bool => !$v));
    $meta = $job['meta'] ?? null;
    $files = $meta['files'] ?? [];
    ?>
<!doctype html>
<html lang="pl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Rozpakowywarka online</title>
<style nonce="<?= h($nonce) ?>">
:root{
  --fog:#E8EDF2; --paper:#FBFCFD; --ink:#14213D; --slate:#4F5E75; --line:#C4CEDB;
  --tape:#FFC629; --on-tape:#14213D; --stripe:#14213D; --signal:#B3261E; --focus:#2F5BEA;
}
@media (prefers-color-scheme:dark){
  :root{--fog:#0D1424; --paper:#151E34; --ink:#E9EEF6; --slate:#9DABC1; --line:#2B3852; --stripe:#0D1424; --signal:#FF8F86; --focus:#8FB0FF;}
}
*{box-sizing:border-box}
html{background:var(--fog);color:var(--ink)}
body{margin:0;font:1rem/1.55 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;-webkit-font-smoothing:antialiased}
main{max-width:46rem;margin:0 auto;padding:clamp(1.5rem,5vw,3.5rem) 1.25rem 4rem}
h1{font:700 clamp(2rem,6vw,3rem)/1.05 "Iowan Old Style","Palatino Linotype",Palatino,"Book Antiqua",Georgia,serif;letter-spacing:-.01em;margin:0 0 .6rem}
h2{font:700 1.35rem/1.2 "Iowan Old Style","Palatino Linotype",Palatino,Georgia,serif;margin:0 0 .35rem;overflow-wrap:anywhere}
.lead{margin:0 0 2rem;color:var(--slate);max-width:38rem}
.drop{display:block;position:relative;background:var(--paper);border:2px dashed var(--line);border-radius:6px;padding:2.6rem 1.5rem 1.8rem;cursor:pointer;transition:border-color .15s,background-color .15s}
.drop::before{content:"";position:absolute;left:-2px;right:-2px;top:-2px;height:12px;border-radius:6px 6px 0 0;background:repeating-linear-gradient(-45deg,var(--tape) 0 12px,var(--stripe) 12px 24px)}
.drop input{position:absolute;inset:0;width:100%;height:100%;opacity:0;cursor:pointer}
.drop:focus-within{outline:3px solid var(--focus);outline-offset:3px}
.drop.is-over{border-color:var(--ink);border-style:solid}
.drop strong{display:block;font-size:1.15rem}
.drop span{color:var(--slate)}
#picked{display:block;margin-top:.6rem;font-weight:600;color:var(--ink);overflow-wrap:anywhere}
label.field{display:block;margin:1.4rem 0 0;font-weight:600}
label.field small{display:block;font-weight:400;color:var(--slate)}
input[type=password],input[type=search]{font:inherit;width:100%;max-width:22rem;margin-top:.4rem;padding:.6rem .75rem;color:var(--ink);background:var(--paper);border:2px solid var(--line);border-radius:4px}
input[type=password]:focus,input[type=search]:focus{outline:3px solid var(--focus);outline-offset:1px;border-color:var(--ink)}
.btn{font:inherit;font-weight:700;display:inline-block;text-decoration:none;cursor:pointer;padding:.7rem 1.4rem;border-radius:4px;border:2px solid var(--on-tape);background:var(--tape);color:var(--on-tape)}
.btn:hover{filter:brightness(1.06)}
.btn:focus-visible{outline:3px solid var(--focus);outline-offset:3px}
.btn[disabled]{opacity:.55;cursor:progress}
.btn.quiet{background:transparent;color:var(--ink);border-color:var(--line)}
.btn.quiet:hover{border-color:var(--ink);filter:none}
.row{display:flex;flex-wrap:wrap;gap:.75rem;align-items:center;margin-top:1.6rem}
.note{margin:1.6rem 0 0;color:var(--slate);font-size:.925rem}
.error{margin:0 0 1.25rem;padding:.8rem 1rem;border:2px solid var(--signal);border-radius:4px;color:var(--signal);background:var(--paper);font-weight:600}
.error[hidden]{display:none}
.bar{height:16px;margin-top:1.4rem;border:2px solid var(--ink);border-radius:3px;overflow:hidden;background:var(--paper)}
.bar[hidden]{display:none}
.bar i{display:block;height:100%;width:0;background:repeating-linear-gradient(-45deg,var(--tape) 0 10px,var(--stripe) 10px 20px);background-size:28px 28px;transition:width .2s}
.bar.busy i{animation:march .8s linear infinite}
@keyframes march{to{background-position:28px 0}}
@media (prefers-reduced-motion:reduce){.bar.busy i{animation:none}.drop,.bar i{transition:none}}
#status{margin:.5rem 0 0;color:var(--slate);min-height:1.55em}
.summary{margin:0;color:var(--slate)}
.result{margin-top:2.6rem;padding-top:1.6rem;border-top:2px solid var(--ink)}
.table-wrap{margin-top:1.4rem}
table{width:100%;border-collapse:collapse}
td{padding:.55rem .4rem;border-bottom:1px solid var(--line);vertical-align:baseline}
td.path{overflow-wrap:anywhere}
td.path small{color:var(--slate)}
td.size,td.dl{white-space:nowrap;text-align:right;font-variant-numeric:tabular-nums}
td.size{color:var(--slate)}
td.dl{padding-left:1rem}
a{color:var(--ink);text-underline-offset:.2em}
a:focus-visible{outline:3px solid var(--focus);outline-offset:2px}
details{margin-top:1.2rem;color:var(--slate)}
details ul{margin:.5rem 0 0;padding-left:1.2rem;overflow-wrap:anywhere}
.visually-hidden{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap}
</style>
</head>
<body>
<main>
  <h1>Rozpakuj archiwum online</h1>
  <p class="lead">Wybierz plik ZIP, TAR, 7Z lub inne archiwum. Pliki pobierzesz pojedynczo albo razem jako jeden ZIP.</p>

  <p id="error" class="error" role="alert"<?= $error === null ? ' hidden' : '' ?>><?= $error !== null ? h($error) : '' ?></p>

  <form id="upload" method="post" enctype="multipart/form-data" action="<?= h($self) ?>" data-max="<?= (int)$limit ?>">
    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
    <input type="hidden" name="action" value="upload">

    <label class="drop" id="drop">
      <input type="file" name="archive" required accept=".zip,.tar,.gz,.tgz,.bz2,.tbz2,.7z">
      <strong>Upuść archiwum tutaj albo wybierz plik z dysku</strong>
      <span>Maksymalny rozmiar pliku: <?= $limit > 0 ? h(humanSize($limit)) : 'bez limitu' ?>.</span>
      <output id="picked"></output>
    </label>

    <label class="field" for="password">Hasło do archiwum
      <small>Wpisz tylko wtedy, gdy archiwum jest zaszyfrowane (ZIP, 7Z).</small>
    </label>
    <input type="password" id="password" name="password" autocomplete="off">

    <div class="row">
      <button class="btn" type="submit" id="go">Rozpakuj</button>
    </div>
    <div class="bar" id="bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" hidden><i></i></div>
    <p id="status" aria-live="polite"></p>
  </form>

  <p class="note">
    Obsługiwane formaty: <?= h(joinPl($supported)) ?>.<?= $missing ? ' Niedostępne na tym serwerze: ' . h(joinPl($missing)) . '.' : '' ?>
    Rozpakowane pliki są przechowywane <?= (int)max(1, round(TTL_SECONDS / 60)) ?> min i widzisz je tylko w tej przeglądarce.
    Limity po rozpakowaniu: <?= h(humanSize(MAX_UNPACKED_BYTES)) ?> i <?= (int)MAX_FILES ?> plików.
  </p>

<?php if ($meta !== null): ?>
  <section class="result" aria-labelledby="result-title">
    <h2 id="result-title">Rozpakowano: <?= h((string)$meta['name']) ?></h2>
    <p class="summary"><?= count($files) ?> <?= plural(count($files), 'plik', 'pliki', 'plików') ?>, razem <?= h(humanSize((int)$meta['bytes'])) ?>.</p>
    <div class="row">
      <?php if (class_exists('ZipArchive')): ?>
      <a class="btn" href="?d=<?= h($token) ?>&amp;all=1">Pobierz wszystko jako ZIP</a>
      <?php endif; ?>
      <form method="post" action="<?= h($self) ?>">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="token" value="<?= h($token) ?>">
        <button class="btn quiet" type="submit">Usuń pliki z serwera</button>
      </form>
    </div>

    <?php if (count($files) > 12): ?>
    <label class="field" for="filter">Filtruj listę plików</label>
    <input type="search" id="filter" autocomplete="off">
    <?php endif; ?>

    <div class="table-wrap">
      <table id="files">
        <caption class="visually-hidden">Rozpakowane pliki</caption>
        <tbody>
<?php foreach ($files as $rel => $size):
    $rel = (string)$rel;
    $dir = dirname($rel);
    $dirPart = $dir === '.' ? '' : $dir . '/';
?>
          <tr data-name="<?= h($rel) ?>">
            <td class="path"><small><?= h($dirPart) ?></small><?= h(basename($rel)) ?></td>
            <td class="size"><?= h(humanSize((int)$size)) ?></td>
            <td class="dl"><a href="?d=<?= h($token) ?>&amp;f=<?= h(rawurlencode($rel)) ?>" download>Pobierz<span class="visually-hidden"> <?= h(basename($rel)) ?></span></a></td>
          </tr>
<?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <?php if (!empty($meta['skipped'])): ?>
    <details>
      <summary>Pominięto <?= count($meta['skipped']) ?> wpisów ze względów bezpieczeństwa</summary>
      <ul>
        <?php foreach ($meta['skipped'] as $s): ?><li><?= h((string)$s) ?></li><?php endforeach; ?>
      </ul>
    </details>
    <?php endif; ?>
  </section>
<?php endif; ?>
</main>

<script nonce="<?= h($nonce) ?>">
(function () {
  var form = document.getElementById('upload');
  var input = form.querySelector('input[type=file]');
  var drop = document.getElementById('drop');
  var picked = document.getElementById('picked');
  var go = document.getElementById('go');
  var bar = document.getElementById('bar');
  var fill = bar.firstElementChild;
  var status = document.getElementById('status');
  var errBox = document.getElementById('error');
  var maxBytes = parseInt(form.getAttribute('data-max'), 10) || 0;

  function fmt(n) {
    var u = ['B', 'KB', 'MB', 'GB'], i = 0;
    while (n >= 1024 && i < u.length - 1) { n /= 1024; i++; }
    return (i === 0 ? n : n.toFixed(1).replace('.', ',')) + ' ' + u[i];
  }
  function showError(msg) { errBox.textContent = msg; errBox.hidden = false; }
  function clearError() { errBox.hidden = true; }
  function showFile() {
    var f = input.files[0];
    picked.textContent = f ? f.name + ' (' + fmt(f.size) + ')' : '';
    clearError();
  }
  function reset() {
    go.disabled = false; bar.hidden = true; bar.classList.remove('busy');
    fill.style.width = '0'; status.textContent = '';
  }

  input.addEventListener('change', showFile);
  ['dragenter', 'dragover'].forEach(function (ev) {
    drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.add('is-over'); });
  });
  ['dragleave', 'drop'].forEach(function (ev) {
    drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.remove('is-over'); });
  });
  drop.addEventListener('drop', function (e) {
    if (e.dataTransfer && e.dataTransfer.files.length) { input.files = e.dataTransfer.files; showFile(); }
  });

  form.addEventListener('submit', function (e) {
    if (!window.FormData || !window.XMLHttpRequest) { return; }   // zwykły formularz jako zapas
    e.preventDefault();
    var f = input.files[0];
    if (!f) { showError('Wybierz plik archiwum.'); return; }
    if (maxBytes && f.size > maxBytes) {
      showError('Plik jest za duży. Limit przesyłania na tym serwerze to ' + fmt(maxBytes) + '.');
      return;
    }
    clearError();
    go.disabled = true; bar.hidden = false; status.textContent = 'Wysyłanie: 0%';

    var xhr = new XMLHttpRequest();
    // getAttribute, bo pole <input name="action"> przesłania właściwość form.action
    xhr.open('POST', form.getAttribute('action'));
    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
    xhr.upload.onprogress = function (ev) {
      if (!ev.lengthComputable) { return; }
      var p = Math.round(ev.loaded / ev.total * 100);
      fill.style.width = p + '%';
      bar.setAttribute('aria-valuenow', p);
      status.textContent = 'Wysyłanie: ' + p + '%';
    };
    xhr.upload.onload = function () {
      fill.style.width = '100%'; bar.classList.add('busy');
      status.textContent = 'Rozpakowywanie…';
    };
    xhr.onload = function () {
      var r = null;
      try { r = JSON.parse(xhr.responseText); } catch (_) {}
      if (r && r.ok) { window.location.href = r.redirect; return; }
      reset();
      showError((r && r.error) || 'Nie udało się rozpakować archiwum.');
    };
    xhr.onerror = function () { reset(); showError('Błąd połączenia z serwerem. Spróbuj ponownie.'); };
    xhr.send(new FormData(form));
  });

  var filter = document.getElementById('filter');
  if (filter) {
    var rows = Array.prototype.slice.call(document.querySelectorAll('#files tr'));
    var names = rows.map(function (tr) { return tr.getAttribute('data-name').toLowerCase(); });
    filter.addEventListener('input', function () {
      var q = filter.value.trim().toLowerCase();
      rows.forEach(function (tr, i) { tr.hidden = q !== '' && names[i].indexOf(q) === -1; });
    });
  }
})();
</script>
</body>
</html>
<?php
}

/* ------------------------------------------------------------------ */
/*  Start                                                              */
/* ------------------------------------------------------------------ */

ini_set('session.use_strict_mode', '1');
session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'secure' => !empty($_SERVER['HTTPS'])]);
session_start();
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}
cleanupOld();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'POST') {
    handlePost();
    exit;
}
if (isset($_GET['d'])) {
    handleDownload();
    exit;
}

$job = null;
$token = '';
$error = null;
if (isset($_GET['j'])) {
    $token = (string)$_GET['j'];
    $job = loadJob($token);
    if ($job === null) {
        $error = 'Ten wynik już nie istnieje — wygasł albo został usunięty. Rozpakuj archiwum jeszcze raz.';
    }
}
renderPage($error, $job, $token);
