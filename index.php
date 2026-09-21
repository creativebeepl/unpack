<?php
declare(strict_types=1);

/**
 * Rozpakowywarka online — rozpakowuje archiwa przesłane formularzem.
 *
 * Formaty:  ZIP (także z hasłem), TAR, TAR.GZ/TGZ, TAR.BZ2, GZ, BZ2, 7Z
 *           — rozpakowywanie oraz pakowanie (zakładka „Spakuj”; hasło: ZIP i 7Z,
 *           podział na części: 7Z)
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
    $field = trim(substr($h, 148, 8), " \0");
    if (preg_match('/^[0-7]+$/', $field) !== 1) {
        return false;
    }
    $stored = octdec($field);
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
                // AES w wariancie AE-2 (małe pliki) nie zapisuje CRC (=0) — integralność zapewnia wtedy HMAC
                $aes = in_array((int)($st['encryption_method'] ?? 0), [ZipArchive::EM_AES_128, ZipArchive::EM_AES_192, ZipArchive::EM_AES_256], true);
                $crcOk = ($aes && (int)$st['crc'] === 0) || hexdec(hash_final($crc)) === (int)$st['crc'];
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
function runCmd(array $cmd, int $timeout, int $capBytes, ?string $cwd = null): array
{
    $env = [
        'PATH' => (string)(getenv('PATH') ?: '/usr/bin:/bin'),
        'LANG' => 'C.UTF-8',
        'LC_ALL' => 'C.UTF-8',
    ];
    $proc = @proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd, $env);
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
/*  Pakowanie: formaty i ustawienia                                    */
/* ------------------------------------------------------------------ */

const MIN_PART_BYTES = 65536;
const MAX_PARTS = 500;

/** Formaty (małymi literami) i informacja, czy ten serwer je obsługuje. @return array<string,bool> */
function formatAvailability(): array
{
    $gz = in_array('compress.zlib', stream_get_wrappers(), true);
    $bz = in_array('compress.bzip2', stream_get_wrappers(), true);
    return [
        'zip' => class_exists('ZipArchive'),
        'tar' => true,
        'tar.gz' => $gz,
        'gz' => $gz,
        'tar.bz2' => $bz,
        'bz2' => $bz,
        '7z' => find7z() !== null && function_exists('proc_open'),
    ];
}

function formatLabel(string $slug): string
{
    return strtoupper($slug);
}

/** Zakładka: „pack” tylko dla zapytań o pakowanie, w pozostałych przypadkach „unpack”. */
function currentTab(): string
{
    return ($_POST['action'] ?? '') === 'pack' || ($_GET['tab'] ?? '') === 'pack' ? 'pack' : 'unpack';
}

/** Ile plików naraz przyjmie PHP (max_file_uploads) i aplikacja (MAX_FILES). */
function maxUploadFiles(): int
{
    $php = (int)ini_get('max_file_uploads');
    return $php > 0 ? min(MAX_FILES, $php) : MAX_FILES;
}

/** Zamienia rozmiar części z formularza na bajty; 0 oznacza „bez podziału”. */
function parsePartSize(string $size, string $unit): int
{
    $size = trim(str_replace(',', '.', $size));
    if ($size === '') {
        return 0;
    }
    if (!is_numeric($size) || (float)$size <= 0) {
        throw new RuntimeException('Rozmiar części musi być liczbą większą od zera.');
    }
    $mult = match (strtoupper($unit)) {
        'KB' => 1024,
        'MB' => 1024 ** 2,
        'GB' => 1024 ** 3,
        default => throw new RuntimeException('Nieznana jednostka rozmiaru części.'),
    };
    $bytes = (int)min(round((float)$size * $mult), 1024 ** 4);
    if ($bytes < MIN_PART_BYTES) {
        throw new RuntimeException('Minimalny rozmiar części to ' . humanSize(MIN_PART_BYTES) . '.');
    }
    return $bytes;
}

/** Ucina napis do $max bajtów, nie rozcinając znaku UTF-8. */
function truncateUtf8(string $s, int $max): string
{
    if (strlen($s) <= $max) {
        return $s;
    }
    $s = substr($s, 0, $max);
    while ($s !== '' && preg_match('//u', $s) !== 1) {
        $s = substr($s, 0, -1);
    }
    return $s;
}

/**
 * Nadaje plikowi unikalną ścieżkę w archiwum: przy kolizji (także bez rozróżniania wielkości liter
 * i między plikiem a folderem) dopisuje „ (2)”, „ (3)”… przed rozszerzeniem.
 *
 * @param array{files?:array<string,true>,dirs?:array<string,true>} $taken
 */
function uniqueRel(string $rel, array &$taken): string
{
    $key = static fn(string $p): string => function_exists('mb_strtolower') ? mb_strtolower($p) : strtolower($p);
    $taken += ['files' => [], 'dirs' => []];
    $segs = explode('/', $rel);
    $last = count($segs) - 1;

    // folder o nazwie zajętej przez zwykły plik: „x/y.txt” po pliku „x” trafia do „x (2)/y.txt”
    for ($i = 0; $i < $last; $i++) {
        $orig = $segs[$i];
        for ($n = 2; isset($taken['files'][$key(implode('/', array_slice($segs, 0, $i + 1)))]); $n++) {
            $segs[$i] = $orig . ' (' . $n . ')';
        }
    }

    // sam plik: zajęta nazwa pliku albo folderu → numer przed rozszerzeniem
    $dir = $last > 0 ? implode('/', array_slice($segs, 0, $last)) . '/' : '';
    $base = $segs[$last];
    $dot = strrpos($base, '.');
    [$stem, $ext] = ($dot === false || $dot === 0) ? [$base, ''] : [substr($base, 0, $dot), substr($base, $dot)];
    $cand = $dir . $base;
    for ($n = 2; isset($taken['files'][$key($cand)]) || isset($taken['dirs'][$key($cand)]); $n++) {
        $cand = $dir . $stem . ' (' . $n . ')' . $ext;
    }

    $taken['files'][$key($cand)] = true;
    for ($p = dirname($key($cand)); $p !== '.' && $p !== '/' && $p !== ''; $p = dirname($p)) {
        $taken['dirs'][$p] = true;
    }
    return $cand;
}

/** Przekształca $_FILES['files'] (pola files[]) na listę wpisów. @return list<array{name:string,tmp_name:string,error:int}> */
function normalizeUploads(mixed $f): array
{
    if (!is_array($f) || !is_array($f['name'] ?? null) || !is_array($f['tmp_name'] ?? null) || !is_array($f['error'] ?? null)) {
        return [];
    }
    $out = [];
    foreach (array_keys($f['name']) as $i) {
        if (is_array($f['name'][$i]) || is_array($f['tmp_name'][$i] ?? null) || is_array($f['error'][$i] ?? null)) {
            continue; // zagnieżdżone pola files[a][b] — nieobsługiwane
        }
        $out[] = ['name' => (string)$f['name'][$i], 'tmp_name' => (string)($f['tmp_name'][$i] ?? ''), 'error' => (int)($f['error'][$i] ?? UPLOAD_ERR_NO_FILE)];
    }
    return $out;
}

/* ------------------------------------------------------------------ */
/*  TAR — zapis (czysty PHP, także przez gzip/bzip2)                   */
/* ------------------------------------------------------------------ */

final class TarWriter
{
    /** @param resource $fh */
    public function __construct(private $fh)
    {
    }

    private function write(string $data): void
    {
        if ($data !== '' && fwrite($this->fh, $data) !== strlen($data)) {
            throw new RuntimeException('Nie udało się zapisać archiwum (brak miejsca na dysku?).');
        }
    }

    private static function octal(int $v, int $width): string
    {
        return sprintf('%0' . ($width - 1) . 'o', $v) . "\0";
    }

    /** Rekord PAX: „<długość> klucz=wartość\n”, gdzie długość obejmuje także własne cyfry. */
    public static function paxRecord(string $key, string $value): string
    {
        $len = strlen($key) + strlen($value) + 3;
        $n = $len;
        do {
            $prev = $n;
            $n = $len + strlen((string)$prev);
        } while ($n !== $prev);
        return $n . ' ' . $key . '=' . $value . "\n";
    }

    private function header(string $name, int $size, int $mtime, string $type): string
    {
        $sizeField = $size < 8 ** 11 ? self::octal($size, 12) : "\x80\0\0\0" . pack('J', $size); // ponad 8 GB: format binarny GNU
        $h = pack(
            'a100a8a8a8a12a12a8a1a100a6a2a32a32a8a8a155a12',
            substr($name, 0, 100),
            self::octal($type === '5' ? 0755 : 0644, 8),
            self::octal(0, 8),
            self::octal(0, 8),
            $sizeField,
            self::octal(max(0, $mtime), 12),
            '        ',
            $type,
            '',
            "ustar\0",
            '00',
            '',
            '',
            '',
            '',
            '',
            ''
        );
        $sum = array_sum(unpack('C*', $h));
        return substr($h, 0, 148) . sprintf('%06o', $sum) . "\0 " . substr($h, 156);
    }

    public function addFile(string $name, string $abs, ?int $mtime = null): void
    {
        $in = @fopen($abs, 'rb');
        if ($in === false) {
            throw new RuntimeException('Nie można odczytać jednego z plików.');
        }
        try {
            $size = (int)filesize($abs);
            $mtime ??= (int)filemtime($abs);
            if (strlen($name) > 100) { // długa (lub wielobajtowa) nazwa: rozszerzenie PAX, które czyta też extractTar()
                $pax = self::paxRecord('path', $name);
                $this->write($this->header('PaxHeader/' . truncateUtf8(basename($name), 80), strlen($pax), $mtime, 'x'));
                $this->write($pax . str_repeat("\0", tarPad(strlen($pax))));
            }
            $this->write($this->header($name, $size, $mtime, '0'));
            $left = $size;
            while ($left > 0) {
                $chunk = fread($in, min(65536, $left));
                if ($chunk === false || $chunk === '') {
                    throw new RuntimeException('Plik zmienił się w trakcie pakowania.');
                }
                $this->write($chunk);
                $left -= strlen($chunk);
            }
            $this->write(str_repeat("\0", tarPad($size)));
        } finally {
            fclose($in);
        }
    }

    public function finish(): void
    {
        $this->write(str_repeat("\0", 1024));
    }
}

/** Otwiera plik do zapisu, opcjonalnie od razu kompresując: '' (bez), 'gz' albo 'bz2'. @return resource */
function openCompressedWrite(string $path, string $compression)
{
    $fh = match ($compression) {
        '' => @fopen($path, 'wb'),
        'gz' => function_exists('gzopen') ? @gzopen($path, 'wb6') : false,
        'bz2' => function_exists('bzopen') ? @bzopen($path, 'w') : false,
        default => false,
    };
    if ($fh === false) {
        throw new RuntimeException('Nie można utworzyć archiwum (brak obsługi kompresji na tym serwerze?).');
    }
    return $fh;
}

/** @param array<string,string> $entries ścieżka w archiwum => ścieżka na dysku */
function packTar(array $entries, string $out, string $compression): void
{
    $fh = openCompressedWrite($out, $compression);
    try {
        $tar = new TarWriter($fh);
        foreach ($entries as $rel => $abs) {
            $tar->addFile((string)$rel, $abs);
        }
        $tar->finish();
    } finally {
        fclose($fh);
    }
}

/** Jeden plik do .gz / .bz2. */
function packSingle(string $abs, string $out, string $compression): void
{
    $in = @fopen($abs, 'rb');
    if ($in === false) {
        throw new RuntimeException('Nie można odczytać pliku.');
    }
    try {
        $fh = openCompressedWrite($out, $compression);
        try {
            while (($chunk = fread($in, 65536)) !== false && $chunk !== '') {
                if (fwrite($fh, $chunk) !== strlen($chunk)) {
                    throw new RuntimeException('Nie udało się zapisać archiwum (brak miejsca na dysku?).');
                }
            }
        } finally {
            fclose($fh);
        }
    } finally {
        fclose($in);
    }
}

/** @param array<string,string> $entries ścieżka w archiwum => ścieżka na dysku */
function packZip(array $entries, string $out, string $password): void
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('Ten serwer nie ma rozszerzenia PHP „zip”, więc nie utworzy plików ZIP.');
    }
    $zip = new ZipArchive();
    if ($zip->open($out, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Nie można utworzyć archiwum ZIP.');
    }
    foreach ($entries as $rel => $abs) {
        $rel = (string)$rel;
        // nazwy zawsze jako UTF-8 (z flagą UTF-8 w archiwum), dzięki czemu polskie znaki są czytelne
        if (!$zip->addFile($abs, $rel, 0, 0, ZipArchive::FL_ENC_UTF_8)) {
            throw new RuntimeException('Nie można dodać pliku do archiwum ZIP.');
        }
        if ($password !== '' && !$zip->setEncryptionName($rel, ZipArchive::EM_AES_256, $password)) {
            throw new RuntimeException('Nie można zaszyfrować archiwum ZIP.');
        }
    }
    if (!$zip->close()) {
        throw new RuntimeException('Nie udało się zapisać archiwum ZIP.');
    }
}

/** 7Z przez program 7z. Pliki muszą leżeć w $inDir pod swoimi ścieżkami z archiwum. */
function pack7z(string $inDir, string $out, string $password, int $partBytes): void
{
    $bin = find7z();
    if ($bin === null || !function_exists('proc_open')) {
        throw new RuntimeException('Ten serwer nie obsługuje 7Z (brak programu 7z).');
    }
    $cmd = [$bin, 'a', '-t7z', '-y', '-bd', '-mx=5'];
    if ($password !== '') {
        $cmd[] = '-p' . $password;
        $cmd[] = '-mhe=on'; // szyfruj także nazwy plików
    }
    if ($partBytes > 0) {
        $cmd[] = '-v' . $partBytes . 'b';
    }
    // katalog roboczy = $inDir, więc w archiwum nie ma ścieżek bezwzględnych
    [$code, $stdout, $stderr] = runCmd(array_merge($cmd, ['--', $out, '*']), 300, 65536, $inDir);
    if ($code < 0 || $code > 1) {
        error_log('7z: ' . substr($stderr . "\n" . $stdout, 0, 500));
        throw new RuntimeException('Nie udało się utworzyć archiwum 7Z.');
    }
}

/**
 * Pakuje wpisy do jednego archiwum (albo do części, dla 7Z z $partBytes) w $outDir.
 *
 * @param array<string,string> $entries ścieżka w archiwum => ścieżka pliku na dysku
 * @param string $inDir katalog, w którym leżą pliki (potrzebny tylko dla 7Z)
 * @return array{type:string,name:string,files:array<string,int>} faktyczny format, nazwa i rozmiary utworzonych plików
 */
function packEntries(string $format, array $entries, string $inDir, string $outDir, string $baseName, string $password = '', int $partBytes = 0): array
{
    if (!$entries) {
        throw new RuntimeException('Dodaj przynajmniej jeden plik.');
    }
    if ($password !== '' && !in_array($format, ['zip', '7z'], true)) {
        throw new RuntimeException('Hasło można ustawić tylko dla archiwów ZIP i 7Z.');
    }
    if ($partBytes > 0 && $format !== '7z') {
        throw new RuntimeException('Podział na części jest dostępny tylko dla formatu 7Z.');
    }
    $type = $format;
    if (count($entries) > 1 && ($format === 'gz' || $format === 'bz2')) {
        $type = 'tar.' . $format; // GZ i BZ2 pakują jeden strumień — wiele plików najpierw do TAR
    }
    $name = truncateUtf8($baseName, 150) . '.' . $type;
    $out = $outDir . '/' . $name;

    switch ($type) {
        case 'zip':
            packZip($entries, $out, $password);
            break;
        case 'tar':
            packTar($entries, $out, '');
            break;
        case 'tar.gz':
            packTar($entries, $out, 'gz');
            break;
        case 'tar.bz2':
            packTar($entries, $out, 'bz2');
            break;
        case 'gz':
        case 'bz2':
            packSingle((string)reset($entries), $out, $type);
            break;
        case '7z':
            pack7z($inDir, $out, $password, $partBytes);
            break;
        default:
            throw new RuntimeException('Nieznany format archiwum.');
    }

    $files = [];
    foreach (new DirectoryIterator($outDir) as $f) {
        $fn = $f->getFilename();
        if ($f->isFile() && ($fn === $name || preg_match('/^' . preg_quote($name, '/') . '\.\d{3,}$/', $fn) === 1)) {
            $files[$fn] = (int)$f->getSize();
        }
    }
    uksort($files, 'strnatcasecmp');
    if (!$files || max($files) === 0) {
        throw new RuntimeException('Nie udało się utworzyć archiwum.');
    }
    return ['type' => $type, 'name' => $name, 'files' => $files];
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

function checkUploadError(int $err, string $noFileMsg): void
{
    switch ($err) {
        case UPLOAD_ERR_OK:
            return;
        case UPLOAD_ERR_NO_FILE:
            fail($noFileMsg);
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            fail('Plik jest za duży. Limit przesyłania na tym serwerze to ' . humanSize(uploadLimit()) . '.', 413);
        default:
            fail('Przesyłanie pliku nie powiodło się (kod ' . $err . '). Spróbuj ponownie.');
    }
}

function handleUpload(): void
{
    $file = $_FILES['archive'] ?? null;
    if (!is_array($file) || is_array($file['error'] ?? null)) {
        fail('Wybierz plik archiwum.');
    }
    checkUploadError((int)$file['error'], 'Wybierz plik archiwum.');
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

function handlePack(): never
{
    $avail = formatAvailability();
    $format = (string)($_POST['format'] ?? '');
    if (!isset($avail[$format])) {
        fail('Wybierz format archiwum.');
    }
    if (!$avail[$format]) {
        fail('Ten serwer nie obsługuje formatu ' . formatLabel($format) . '.', 501);
    }
    $password = (string)($_POST['password'] ?? '');
    if ($password !== '' && !in_array($format, ['zip', '7z'], true)) {
        fail('Hasło można ustawić tylko dla archiwów ZIP i 7Z.');
    }
    try {
        $partBytes = parsePartSize((string)($_POST['part_size'] ?? ''), (string)($_POST['part_unit'] ?? 'MB'));
    } catch (RuntimeException $e) {
        fail($e->getMessage());
    }
    if ($partBytes > 0 && $format !== '7z') {
        fail('Podział na części jest dostępny tylko dla formatu 7Z.');
    }

    $uploads = normalizeUploads($_FILES['files'] ?? null);
    if (!$uploads) {
        fail('Dodaj przynajmniej jeden plik.');
    }
    $paths = is_array($_POST['paths'] ?? null) ? array_values($_POST['paths']) : [];
    if ($paths && count($paths) !== count($uploads)) { // PHP po cichu odrzuca nadmiar (max_file_uploads, max_input_vars)
        fail('Nie wszystkie pliki dotarły na serwer. Dodaj mniej plików naraz (limit: ' . maxUploadFiles() . ').', 413);
    }
    if (count($uploads) > maxUploadFiles()) {
        fail('Za dużo plików naraz. Limit to ' . maxUploadFiles() . '.', 413);
    }

    $taken = [];
    $entries = [];
    $total = 0;
    foreach ($uploads as $i => $u) {
        checkUploadError($u['error'], 'Dodaj przynajmniej jeden plik.');
        if (!is_uploaded_file($u['tmp_name'])) {
            fail('Nieprawidłowy plik.');
        }
        $raw = is_string($paths[$i] ?? null) && $paths[$i] !== '' ? $paths[$i] : basename(str_replace('\\', '/', $u['name']));
        $rel = safeRelPath(fixEncoding($raw));
        if ($rel === null) {
            fail('Nieprawidłowa nazwa pliku: „' . fixEncoding($raw) . '”.', 422);
        }
        $total += (int)filesize($u['tmp_name']);
        if ($total > MAX_UNPACKED_BYTES) {
            fail('Pliki przekraczają limit ' . humanSize(MAX_UNPACKED_BYTES) . '.', 413);
        }
        $entries[uniqueRel($rel, $taken)] = $u['tmp_name'];
    }
    if ($partBytes > 0 && intdiv($total, $partBytes) + 1 > MAX_PARTS) {
        fail('Archiwum miałoby ponad ' . MAX_PARTS . ' części. Zwiększ rozmiar części.', 422);
    }

    if (!is_dir(WORK_DIR) && !@mkdir(WORK_DIR, 0700, true) && !is_dir(WORK_DIR)) {
        error_log('Rozpakowywarka: nie można utworzyć ' . WORK_DIR);
        fail('Serwer nie ma miejsca na pliki tymczasowe.', 500);
    }
    @set_time_limit(300);

    $token = bin2hex(random_bytes(16));
    $jobDir = WORK_DIR . '/' . $token;
    $inDir = $jobDir . '/in';
    $outDir = $jobDir . '/files';
    if (!@mkdir($inDir, 0700, true) || !@mkdir($outDir, 0700, true)) {
        rrmdir($jobDir);
        fail('Serwer nie ma miejsca na pliki tymczasowe.', 500);
    }

    try {
        $abs = [];
        foreach ($entries as $rel => $tmp) {
            $dst = $inDir . '/' . $rel;
            if ((!is_dir(dirname($dst)) && !@mkdir(dirname($dst), 0700, true)) || !move_uploaded_file($tmp, $dst)) {
                throw new RuntimeException('Nie udało się zapisać przesłanego pliku.');
            }
            $abs[$rel] = $dst;
        }
        uksort($abs, 'strnatcasecmp');
        $baseName = count($abs) === 1 ? basename((string)array_key_first($abs)) : 'archiwum';
        $result = packEntries($format, $abs, $inDir, $outDir, $baseName, $password, $partBytes);
    } catch (RuntimeException $e) {
        rrmdir($jobDir);
        fail($e->getMessage(), 422);
    } catch (Throwable $e) {
        rrmdir($jobDir);
        error_log('Rozpakowywarka: ' . $e);
        fail('Nie udało się spakować plików.', 500);
    }
    rrmdir($inDir); // zostaje tylko gotowe archiwum

    file_put_contents($jobDir . '/meta.json', json_encode([
        'mode' => 'pack',
        'name' => $result['name'],
        'type' => $result['type'],
        'created' => time(),
        'bytes' => array_sum($result['files']),
        'files' => $result['files'],
        'inputCount' => count($abs),
        'inputBytes' => $total,
        'encrypted' => $password !== '',
        'skipped' => [],
    ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));

    $_SESSION['jobs'] = array_slice(($_SESSION['jobs'] ?? []) + [$token => time()], -30, null, true);
    $redirect = selfUrl() . '?j=' . $token . '#wynik'; // od razu do wyniku, pod formularzem
    if (isAjax()) {
        jsonOut(['ok' => true, 'redirect' => $redirect]);
    }
    header('Location: ' . $redirect, true, 303);
    exit;
}

function handlePost(): void
{
    if (empty($_POST) && empty($_FILES) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        fail('Przesyłane pliki są za duże. Limit przesyłania na tym serwerze to ' . humanSize(uploadLimit()) . '.', 413);
    }
    if (!hash_equals((string)$_SESSION['csrf'], (string)($_POST['csrf'] ?? ''))) {
        fail('Sesja wygasła. Odśwież stronę i spróbuj ponownie.', 403);
    }
    $action = (string)($_POST['action'] ?? 'upload');
    if ($action === 'delete') {
        $token = (string)($_POST['token'] ?? '');
        $job = loadJob($token);
        if ($job !== null) {
            rrmdir($job['dir']);
        }
        unset($_SESSION['jobs'][$token]);
        header('Location: ' . selfUrl(), true, 303);
        exit;
    }
    if ($action === 'pack') {
        handlePack();
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
        $isPack = ($job['meta']['mode'] ?? 'unpack') === 'pack';
        foreach (array_keys($job['meta']['files']) as $rel) {
            $rel = (string)$rel;
            $abs = $job['files'] . '/' . $rel;
            if (is_file($abs) && !is_link($abs)) {
                $zip->addFile($abs, $rel);
                if ($isPack) { // części są już spakowane — bez ponownej kompresji
                    $zip->setCompressionName($rel, ZipArchive::CM_STORE);
                }
            }
        }
        if (!$zip->close() || !is_file($tmp)) {
            http_response_code(500);
            echo 'Nie można utworzyć archiwum.';
            return;
        }
        $base = preg_replace('/\.[A-Za-z0-9]+$/', '', (string)$job['meta']['name']);
        $dl = ($base !== '' && $base !== null ? $base : 'pliki') . ($isPack ? '-czesci.zip' : '-rozpakowane.zip');
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
    $formats = [];
    foreach (formatAvailability() as $slug => $ok) {
        $formats[formatLabel($slug)] = $ok;
    }
    $supported = array_keys(array_filter($formats));
    $missing = array_keys(array_filter($formats, static fn(bool $v): bool => !$v));
    $meta = $job['meta'] ?? null;
    $files = $meta['files'] ?? [];
    $tab = $meta !== null ? (string)($meta['mode'] ?? 'unpack') : currentTab();
    $isPack = $tab === 'pack';
    $maxFileBytes = iniBytes(ini_get('upload_max_filesize'));
    $maxTotalBytes = iniBytes(ini_get('post_max_size'));
    $maxFiles = maxUploadFiles();
    ?>
<!doctype html>
<html lang="pl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $isPack ? 'Spakuj pliki online' : 'Rozpakowywarka online' ?></title>
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
input[type=password],input[type=search],input[type=number],select{font:inherit;width:100%;max-width:22rem;margin-top:.4rem;padding:.6rem .75rem;color:var(--ink);background:var(--paper);border:2px solid var(--line);border-radius:4px}
input[type=password]:focus,input[type=search]:focus,input[type=number]:focus,select:focus{outline:3px solid var(--focus);outline-offset:1px;border-color:var(--ink)}
input:disabled,select:disabled{opacity:.5;cursor:not-allowed}
.tabs{display:flex;gap:.5rem;margin:0 0 1.6rem;padding:0;list-style:none}
.tabs a{display:block;padding:.5rem 1.1rem;border:2px solid var(--line);border-radius:4px;text-decoration:none;font-weight:700;color:var(--slate)}
.tabs a[aria-current=page]{background:var(--tape);border-color:var(--on-tape);color:var(--on-tape)}
.pair{display:flex;flex-wrap:wrap;gap:.75rem}
.pair>*{flex:1 1 8rem}
.pair input[type=number]{max-width:none}
.pair select{max-width:none}
.btn.small{padding:.25rem .8rem;font-size:.875rem}
#folder[hidden]{display:none}
#picklist{margin-top:1.4rem}
#picklist[hidden]{display:none}
#picksum{margin:.6rem 0 0;color:var(--slate)}
#fmtnote{margin:.4rem 0 0;color:var(--slate);font-size:.925rem}
td.rm{white-space:nowrap;text-align:right;padding-left:1rem}
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
  <nav aria-label="Tryb">
    <ul class="tabs">
      <li><a href="<?= h($self) ?>"<?= !$isPack ? ' aria-current="page"' : '' ?>>Rozpakuj</a></li>
      <li><a href="<?= h($self) ?>?tab=pack"<?= $isPack ? ' aria-current="page"' : '' ?>>Spakuj</a></li>
    </ul>
  </nav>

<?php if ($isPack): ?>
  <h1>Spakuj pliki online</h1>
  <p class="lead">Dodaj jeden lub wiele plików, wybierz format i — jeśli chcesz — ustaw hasło. Gotowe archiwum pobierzesz w wybranym formacie.</p>
<?php else: ?>
  <h1>Rozpakuj archiwum online</h1>
  <p class="lead">Wybierz plik ZIP, TAR, 7Z lub inne archiwum. Pliki pobierzesz pojedynczo albo razem jako jeden ZIP.</p>
<?php endif; ?>

  <p id="error" class="error" role="alert"<?= $error === null ? ' hidden' : '' ?>><?= $error !== null ? h($error) : '' ?></p>

<?php if ($isPack): ?>
  <form id="pack" method="post" enctype="multipart/form-data" action="<?= h($self) ?>" data-max-file="<?= (int)$maxFileBytes ?>" data-max-total="<?= (int)$maxTotalBytes ?>" data-max-files="<?= (int)$maxFiles ?>" data-max-input="<?= (int)MAX_UNPACKED_BYTES ?>">
    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
    <input type="hidden" name="action" value="pack">

    <label class="drop" id="drop">
      <input type="file" name="files[]" id="picker" multiple>
      <strong>Upuść pliki lub foldery tutaj albo wybierz je z dysku</strong>
      <span>Możesz dodać wiele plików naraz. Limit jednego pliku: <?= $maxFileBytes > 0 ? h(humanSize($maxFileBytes)) : 'brak' ?>, wszystkich razem: <?= $maxTotalBytes > 0 ? h(humanSize($maxTotalBytes)) : 'brak' ?>.</span>
    </label>
    <div class="row">
      <button class="btn quiet small" type="button" id="folder" hidden>Dodaj cały folder</button>
      <button class="btn quiet small" type="button" id="clear" hidden>Wyczyść listę</button>
    </div>

    <table id="picklist" hidden>
      <caption class="visually-hidden">Wybrane pliki</caption>
      <tbody></tbody>
    </table>
    <p id="picksum" aria-live="polite"></p>

    <label class="field" for="format">Format archiwum</label>
    <select id="format" name="format">
<?php foreach (formatAvailability() as $slug => $ok): ?>
      <option value="<?= h($slug) ?>"<?= $ok ? '' : ' disabled' ?><?= $slug === 'zip' ? ' selected' : '' ?>><?= h(formatLabel($slug)) ?><?= $ok ? '' : ' (niedostępny na tym serwerze)' ?></option>
<?php endforeach; ?>
    </select>
    <p id="fmtnote"></p>

    <label class="field" for="password">Hasło do archiwum <small>Opcjonalne — tylko dla ZIP (AES-256) i 7Z (szyfruje też nazwy plików).</small></label>
    <input type="password" id="password" name="password" autocomplete="new-password">

    <label class="field" for="part_size">Podział na części <small>Opcjonalne — tylko dla 7Z. Zostaw puste, by nie dzielić archiwum.</small></label>
    <div class="pair">
      <input type="number" id="part_size" name="part_size" min="1" step="any" inputmode="decimal" placeholder="np. 100">
      <select id="part_unit" name="part_unit" aria-label="Jednostka rozmiaru części">
        <option value="KB">KB</option>
        <option value="MB" selected>MB</option>
        <option value="GB">GB</option>
      </select>
    </div>

    <div class="row">
      <button class="btn" type="submit" id="go">Spakuj</button>
    </div>
    <div class="bar" id="bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" hidden><i></i></div>
    <p id="status" aria-live="polite"></p>
  </form>

  <p class="note">
    Dostępne formaty: <?= h(joinPl($supported)) ?>.<?= $missing ? ' Niedostępne na tym serwerze: ' . h(joinPl($missing)) . '.' : '' ?>
    GZ i BZ2 pakują jeden plik — przy wielu plikach powstanie TAR.GZ lub TAR.BZ2.
    Wynik jest przechowywany <?= (int)max(1, round(TTL_SECONDS / 60)) ?> min i widzisz go tylko w tej przeglądarce.
    Limity: <?= h(humanSize(MAX_UNPACKED_BYTES)) ?> i <?= (int)$maxFiles ?> plików.
  </p>
<?php else: ?>
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
<?php endif; ?>

<?php if ($meta !== null): ?>
  <section class="result" id="wynik" aria-labelledby="result-title">
<?php if ($isPack): ?>
    <h2 id="result-title">Spakowano: <?= h((string)$meta['name']) ?></h2>
    <p class="summary">
      <?= (int)$meta['inputCount'] ?> <?= plural((int)$meta['inputCount'], 'plik', 'pliki', 'plików') ?> (<?= h(humanSize((int)$meta['inputBytes'])) ?>)
      → <?= h(strtoupper((string)$meta['type'])) ?>, <?= h(humanSize((int)$meta['bytes'])) ?><?= count($files) > 1 ? ' w ' . count($files) . ' częściach' : '' ?><?= !empty($meta['encrypted']) ? ', zaszyfrowane hasłem' : '' ?>.
    </p>
<?php else: ?>
    <h2 id="result-title">Rozpakowano: <?= h((string)$meta['name']) ?></h2>
    <p class="summary"><?= count($files) ?> <?= plural(count($files), 'plik', 'pliki', 'plików') ?>, razem <?= h(humanSize((int)$meta['bytes'])) ?>.</p>
<?php endif; ?>
    <div class="row">
      <?php if (class_exists('ZipArchive') && (!$isPack || count($files) > 1)): ?>
      <a class="btn" href="?d=<?= h($token) ?>&amp;all=1"><?= $isPack ? 'Pobierz wszystkie części jako ZIP' : 'Pobierz wszystko jako ZIP' ?></a>
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
        <caption class="visually-hidden"><?= $isPack ? 'Pliki archiwum' : 'Rozpakowane pliki' ?></caption>
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
  var bar = document.getElementById('bar');
  var fill = bar.firstElementChild;
  var status = document.getElementById('status');
  var errBox = document.getElementById('error');
  var go = document.getElementById('go');
  var drop = document.getElementById('drop');

  function fmt(n) {
    var u = ['B', 'KB', 'MB', 'GB'], i = 0;
    while (n >= 1024 && i < u.length - 1) { n /= 1024; i++; }
    return (i === 0 ? n : n.toFixed(1).replace('.', ',')) + ' ' + u[i];
  }
  function showError(msg) { errBox.textContent = msg; errBox.hidden = false; }
  function clearError() { errBox.hidden = true; }
  function reset() {
    go.disabled = false; bar.hidden = true; bar.classList.remove('busy');
    fill.style.width = '0'; status.textContent = '';
  }
  function plPlural(n, one, few, many) {
    var l = n % 10, l2 = n % 100;
    if (n === 1) { return one; }
    return (l >= 2 && l <= 4 && (l2 < 12 || l2 > 14)) ? few : many;
  }

  // wysyła formularz przez XHR z paskiem postępu; zwraca false, gdy przeglądarka nie potrafi
  function send(form, data, busyText, failText) {
    if (!window.FormData || !window.XMLHttpRequest) { return false; }
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
      status.textContent = busyText;
    };
    xhr.onload = function () {
      var r = null;
      try { r = JSON.parse(xhr.responseText); } catch (_) {}
      if (r && r.ok) { window.location.href = r.redirect; return; }
      reset();
      showError((r && r.error) || failText);
    };
    xhr.onerror = function () { reset(); showError('Błąd połączenia z serwerem. Spróbuj ponownie.'); };
    xhr.send(data);
    return true;
  }

  function dragUi(onDrop) {
    ['dragenter', 'dragover'].forEach(function (ev) {
      drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.add('is-over'); });
    });
    ['dragleave', 'drop'].forEach(function (ev) {
      drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.remove('is-over'); });
    });
    drop.addEventListener('drop', onDrop);
  }

  /* ---------- Rozpakuj ---------- */
  var upload = document.getElementById('upload');
  if (upload) {
    var input = upload.querySelector('input[type=file]');
    var picked = document.getElementById('picked');
    var maxBytes = parseInt(upload.getAttribute('data-max'), 10) || 0;

    var showFile = function () {
      var f = input.files[0];
      picked.textContent = f ? f.name + ' (' + fmt(f.size) + ')' : '';
      clearError();
    };
    input.addEventListener('change', showFile);
    dragUi(function (e) {
      if (e.dataTransfer && e.dataTransfer.files.length) { input.files = e.dataTransfer.files; showFile(); }
    });
    upload.addEventListener('submit', function (e) {
      if (!window.FormData || !window.XMLHttpRequest) { return; }   // zwykły formularz jako zapas
      e.preventDefault();
      var f = input.files[0];
      if (!f) { showError('Wybierz plik archiwum.'); return; }
      if (maxBytes && f.size > maxBytes) {
        showError('Plik jest za duży. Limit przesyłania na tym serwerze to ' + fmt(maxBytes) + '.');
        return;
      }
      send(upload, new FormData(upload), 'Rozpakowywanie…', 'Nie udało się rozpakować archiwum.');
    });
  }

  /* ---------- Spakuj ---------- */
  var pack = document.getElementById('pack');
  if (pack) {
    var picker = document.getElementById('picker');
    var list = document.getElementById('picklist');
    var listBody = list.querySelector('tbody');
    var sum = document.getElementById('picksum');
    var format = document.getElementById('format');
    var note = document.getElementById('fmtnote');
    var password = document.getElementById('password');
    var partSize = document.getElementById('part_size');
    var partUnit = document.getElementById('part_unit');
    var folderBtn = document.getElementById('folder');
    var clearBtn = document.getElementById('clear');
    var maxFile = parseInt(pack.getAttribute('data-max-file'), 10) || 0;
    var maxTotal = parseInt(pack.getAttribute('data-max-total'), 10) || 0;
    var maxFiles = parseInt(pack.getAttribute('data-max-files'), 10) || 0;
    var maxInput = parseInt(pack.getAttribute('data-max-input'), 10) || 0;
    var items = [];   // { file, path }

    var total = function () { return items.reduce(function (s, it) { return s + it.file.size; }, 0); };

    var render = function () {
      listBody.textContent = '';
      items.forEach(function (it, i) {
        var tr = document.createElement('tr');
        var name = document.createElement('td'); name.className = 'path'; name.textContent = it.path;
        var size = document.createElement('td'); size.className = 'size'; size.textContent = fmt(it.file.size);
        var rm = document.createElement('td'); rm.className = 'rm';
        var b = document.createElement('button');
        b.type = 'button'; b.className = 'btn quiet small'; b.textContent = 'Usuń';
        b.setAttribute('aria-label', 'Usuń ' + it.path);
        b.addEventListener('click', function () { items.splice(i, 1); render(); });
        rm.appendChild(b);
        tr.appendChild(name); tr.appendChild(size); tr.appendChild(rm);
        listBody.appendChild(tr);
      });
      list.hidden = items.length === 0;
      clearBtn.hidden = items.length === 0;
      sum.textContent = items.length
        ? items.length + ' ' + plPlural(items.length, 'plik', 'pliki', 'plików') + ', razem ' + fmt(total()) + '.'
        : 'Nie wybrano jeszcze żadnych plików.';
    };

    var add = function (file, path) {
      path = path || file.webkitRelativePath || file.name;
      for (var i = 0; i < items.length; i++) {
        if (items[i].path === path && items[i].file.size === file.size && items[i].file.lastModified === file.lastModified) { return; }
      }
      items.push({ file: file, path: path });
    };

    var validate = function () {
      if (maxFiles && items.length > maxFiles) { return 'Za dużo plików naraz. Limit to ' + maxFiles + '.'; }
      for (var i = 0; i < items.length; i++) {
        if (maxFile && items[i].file.size > maxFile) {
          return 'Plik „' + items[i].path + '” jest za duży. Limit jednego pliku to ' + fmt(maxFile) + '.';
        }
      }
      var t = total();
      // zapas na nagłówki multipart (ok. 300 B na plik)
      if (maxTotal && t + items.length * 300 + 2048 > maxTotal) {
        return 'Pliki są za duże. Limit przesyłania na tym serwerze to ' + fmt(maxTotal) + '.';
      }
      if (maxInput && t > maxInput) { return 'Pliki przekraczają limit ' + fmt(maxInput) + '.'; }
      return null;
    };

    var addList = function (files) {
      Array.prototype.forEach.call(files, function (f) { add(f); });
      var msg = validate();
      if (msg) { showError(msg); } else { clearError(); }
      render();
    };

    picker.addEventListener('change', function () { addList(picker.files); picker.value = ''; });

    // zapis folderu (webkitdirectory) i upuszczanie folderów
    if ('webkitdirectory' in picker) {
      var dirPicker = document.createElement('input');
      dirPicker.type = 'file'; dirPicker.multiple = true; dirPicker.hidden = true;
      dirPicker.setAttribute('webkitdirectory', '');
      dirPicker.addEventListener('change', function () { addList(dirPicker.files); dirPicker.value = ''; });
      pack.appendChild(dirPicker);
      folderBtn.hidden = false;
      folderBtn.addEventListener('click', function () { dirPicker.click(); });
    }
    clearBtn.addEventListener('click', function () { items = []; clearError(); render(); });

    var walk = function (entry, prefix, done) {
      if (entry.isFile) {
        entry.file(function (f) { add(f, prefix + entry.name); done(); }, done);
      } else if (entry.isDirectory) {
        var reader = entry.createReader(), all = [];
        (function more() {
          reader.readEntries(function (batch) {
            if (batch.length) { all = all.concat(Array.prototype.slice.call(batch)); more(); return; }
            var left = all.length;
            if (!left) { done(); return; }
            all.forEach(function (en) {
              walk(en, prefix + entry.name + '/', function () { if (--left === 0) { done(); } });
            });
          }, done);
        })();
      } else { done(); }
    };

    dragUi(function (e) {
      var dt = e.dataTransfer;
      if (!dt) { return; }
      var entries = [];
      if (dt.items && dt.items.length && dt.items[0].webkitGetAsEntry) {
        for (var i = 0; i < dt.items.length; i++) {
          var en = dt.items[i].webkitGetAsEntry();
          if (en) { entries.push(en); }
        }
      }
      if (!entries.length) { addList(dt.files); return; }
      var left = entries.length;
      entries.forEach(function (en) {
        walk(en, '', function () {
          if (--left === 0) {
            var msg = validate();
            if (msg) { showError(msg); } else { clearError(); }
            render();
          }
        });
      });
    });

    // reguły zależne od formatu
    var updateFormat = function () {
      var f = format.value;
      var canPass = f === 'zip' || f === '7z';
      password.disabled = !canPass;
      if (!canPass) { password.value = ''; }
      partSize.disabled = partUnit.disabled = f !== '7z';
      if (f !== '7z') { partSize.value = ''; }
      note.textContent = f === 'gz' || f === 'bz2'
        ? 'Format ' + f.toUpperCase() + ' pakuje jeden plik. Przy wielu plikach powstanie TAR.' + f.toUpperCase() + '.'
        : (canPass ? '' : 'Ten format nie obsługuje haseł.');
    };
    format.addEventListener('change', updateFormat);
    updateFormat();
    render();

    pack.addEventListener('submit', function (e) {
      if (!window.FormData || !window.XMLHttpRequest) { return; }   // bez JS działa zwykły formularz
      e.preventDefault();
      if (!items.length) { showError('Dodaj przynajmniej jeden plik.'); return; }
      var msg = validate();
      if (msg) { showError(msg); return; }
      var data = new FormData(pack);
      data.delete('files[]');
      items.forEach(function (it) {
        data.append('files[]', it.file, it.file.name);
        data.append('paths[]', it.path);
      });
      send(pack, data, 'Pakowanie…', 'Nie udało się spakować plików.');
    });
  }

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

if (defined('UNPACK_NO_BOOT')) { // testy ładują same funkcje, bez obsługi żądania
    return;
}

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
        $error = 'Ten wynik już nie istnieje — wygasł albo został usunięty. Zacznij jeszcze raz.';
    }
}
renderPage($error, $job, $token);
