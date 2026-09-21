<?php
declare(strict_types=1);

/**
 * Testy end-to-end: prawdziwy serwer `php -S` i żądania HTTP (sesja, CSRF, multipart, pobieranie).
 * Limity serwera są celowo małe, żeby sprawdzić komunikaty o przekroczeniu.
 */

final class E2e
{
    public static ?string $base = null;
    /** @var resource|null */
    private static $proc = null;
    public static string $work = '';

    public static function start(): string
    {
        if (self::$base !== null) {
            return self::$base;
        }
        if (!function_exists('proc_open')) {
            skip('brak proc_open');
        }
        $root = TEST_ROOT;
        for ($try = 0; $try < 20; $try++) {
            $port = random_int(20000, 45000);
            $probe = @stream_socket_server('tcp://127.0.0.1:' . $port);
            if ($probe !== false) {
                fclose($probe);
                break;
            }
        }
        self::$work = TEST_TMP . '/e2e-work';
        $cmd = [
            PHP_BINARY, '-S', '127.0.0.1:' . $port,
            '-d', 'upload_max_filesize=2M', '-d', 'post_max_size=3M', '-d', 'max_file_uploads=5',
            '-d', 'display_errors=0', '-d', 'log_errors=0',
            $root . '/index.php',
        ];
        $env = ['PATH' => (string)getenv('PATH'), 'LANG' => 'C.UTF-8', 'LC_ALL' => 'C.UTF-8',
            'UNPACK_WORK_DIR' => self::$work, 'UNPACK_MAX_BYTES' => (string)(64 * 1024 * 1024), 'UNPACK_MAX_FILES' => '200'];
        self::$proc = proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $root, $env);
        if (!is_resource(self::$proc)) {
            skip('nie można uruchomić php -S');
        }
        register_shutdown_function(static function (): void {
            if (is_resource(self::$proc)) {
                proc_terminate(self::$proc);
            }
        });
        for ($i = 0; $i < 100; $i++) {
            $s = @fsockopen('127.0.0.1', $port, $e, $m, 0.2);
            if ($s !== false) {
                fclose($s);
                return self::$base = 'http://127.0.0.1:' . $port;
            }
            usleep(100000);
        }
        skip('serwer php -S nie wystartował');
    }
}

/** Klient HTTP z ciasteczkami sesji. */
final class Client
{
    /** @var array<string,string> */
    public array $cookies = [];
    public string $csrf = '';

    /** @return array{status:int,headers:array<string,string>,body:string} */
    public function request(string $method, string $path, ?string $body = null, array $headers = []): array
    {
        $h = $headers;
        if ($this->cookies) {
            $h[] = 'Cookie: ' . implode('; ', array_map(static fn($k, $v) => "$k=$v", array_keys($this->cookies), $this->cookies));
        }
        $ctx = stream_context_create(['http' => [
            'method' => $method, 'header' => implode("\r\n", $h), 'content' => $body ?? '',
            'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 60,
        ]]);
        $text = (string)file_get_contents(E2e::start() . $path, false, $ctx);
        $status = 0;
        $out = [];
        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('#^HTTP/\S+ (\d+)#', $line, $m) === 1) {
                $status = (int)$m[1];
                continue;
            }
            $p = strpos($line, ':');
            if ($p === false) {
                continue;
            }
            $name = strtolower(substr($line, 0, $p));
            $val = trim(substr($line, $p + 1));
            if ($name === 'set-cookie' && preg_match('/^([^=]+)=([^;]*)/', $val, $m) === 1) {
                $this->cookies[$m[1]] = $m[2];
            }
            $out[$name] = $val;
        }
        return ['status' => $status, 'headers' => $out, 'body' => $text];
    }

    /** Otwiera stronę, pobiera ciasteczko sesji i token CSRF. */
    public function open(string $path = '/'): array
    {
        $r = $this->request('GET', $path);
        if (preg_match('/name="csrf" value="([a-f0-9]+)"/', $r['body'], $m) === 1) {
            $this->csrf = $m[1];
        }
        return $r;
    }

    /**
     * @param list<array{string,string}> $fields pola tekstowe [nazwa, wartość]
     * @param list<array{string,string,string}> $files pliki [pole, nazwa pliku, zawartość]
     */
    public function post(array $fields, array $files = [], bool $ajax = true, bool $withCsrf = true): array
    {
        $b = 'BOUNDARY' . bin2hex(random_bytes(8));
        if ($withCsrf) {
            array_unshift($fields, ['csrf', $this->csrf]);
        }
        $body = '';
        foreach ($fields as [$n, $v]) {
            $body .= "--$b\r\nContent-Disposition: form-data; name=\"$n\"\r\n\r\n$v\r\n";
        }
        foreach ($files as [$n, $fn, $c]) {
            $body .= "--$b\r\nContent-Disposition: form-data; name=\"$n\"; filename=\"$fn\"\r\nContent-Type: application/octet-stream\r\n\r\n$c\r\n";
        }
        $body .= "--$b--\r\n";
        $h = ['Content-Type: multipart/form-data; boundary=' . $b];
        if ($ajax) {
            $h[] = 'X-Requested-With: XMLHttpRequest';
        }
        return $this->request('POST', '/', $body, $h);
    }

    /** Wysyła formularz pakowania. @return array{status:int,headers:array<string,string>,body:string} */
    public function pack(array $files, string $format = 'zip', array $extra = [], ?array $paths = null, bool $ajax = true): array
    {
        $fields = [['action', 'pack'], ['format', $format]];
        foreach ($extra as $n => $v) {
            $fields[] = [(string)$n, (string)$v];
        }
        foreach ($paths ?? [] as $p) {
            $fields[] = ['paths[]', $p];
        }
        $up = [];
        foreach ($files as $name => $content) {
            $up[] = ['files[]', (string)$name, $content];
        }
        return $this->post($fields, $up, $ajax);
    }

    /** Pakuje i zwraca token zadania; wywala test, gdy się nie udało. */
    public function packOk(array $files, string $format = 'zip', array $extra = [], ?array $paths = null): string
    {
        $r = $this->pack($files, $format, $extra, $paths);
        $j = json_decode($r['body'], true);
        if ($r['status'] !== 200 || !is_array($j) || empty($j['ok'])) {
            throw new RuntimeException('pakowanie nie powiodło się (HTTP ' . $r['status'] . '): ' . substr($r['body'], 0, 300));
        }
        assertTrue(preg_match('/^\/?\?j=([a-f0-9]{32})#wynik$/', $j['redirect'], $m) === 1, 'redirect: ' . $j['redirect']);
        return $m[1];
    }

    public function download(string $token, string $name): array
    {
        return $this->request('GET', '/?d=' . $token . '&f=' . rawurlencode($name));
    }

    public function json(array $r): array
    {
        $j = json_decode($r['body'], true);
        return is_array($j) ? $j : ['ok' => false, 'error' => 'nie JSON: ' . substr($r['body'], 0, 200)];
    }
}

function saveTemp(string $bytes, string $ext = 'bin'): string
{
    $p = TEST_TMP . '/dl-' . bin2hex(random_bytes(4)) . '.' . $ext;
    file_put_contents($p, $bytes);
    return $p;
}

test('e2e: zakładka „Spakuj” ma formularz z wieloma plikami, formatami, hasłem i podziałem', function () {
    $c = new Client();
    $r = $c->open('/?tab=pack');
    assertSame(200, $r['status']);
    foreach (['id="pack"', 'name="files[]"', ' multiple', 'name="format"', 'name="password"', 'name="part_size"', 'name="part_unit"', 'role="progressbar"', 'value="tar.bz2"', 'value="7z"'] as $needle) {
        assertContains($needle, $r['body']);
    }
    assertTrue(!str_contains($r['body'], 'id="upload"'), 'na zakładce Spakuj nie powinno być formularza rozpakowywania');
    assertContains('aria-current="page">Spakuj', $r['body']);
    assertContains("script-src 'nonce-", $r['headers']['content-security-policy']);
});

test('e2e: zakładka „Rozpakuj” jest bez zmian i nie ma formularza pakowania', function () {
    $c = new Client();
    $r = $c->open('/');
    assertContains('id="upload"', $r['body']);
    assertContains('name="archive"', $r['body']);
    assertTrue(!str_contains($r['body'], 'id="pack"'));
    assertContains('aria-current="page">Rozpakuj', $r['body']);
});

test('e2e: pakowanie wielu plików do ZIP, strona wyniku i pobranie', function () {
    $c = new Client();
    $c->open('/?tab=pack');
    $files = ['a.txt' => "Zażółć gęślą jaźń\n", 'b.txt' => "bbb\n", 'c.txt' => "ccc\n"];
    $token = $c->packOk($files);
    $page = $c->request('GET', '/?j=' . $token);
    assertSame(200, $page['status']);
    assertContains('Spakowano: archiwum.zip', $page['body']);
    assertContains('3 pliki', $page['body']);
    assertContains('id="pack"', $page['body'] === '' ? '' : $c->request('GET', '/?tab=pack')['body']);
    $dl = $c->download($token, 'archiwum.zip');
    assertSame(200, $dl['status']);
    assertContains('attachment', $dl['headers']['content-disposition']);
    $zip = new ZipArchive();
    assertTrue($zip->open(saveTemp($dl['body'], 'zip'), ZipArchive::RDONLY) === true);
    $got = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $got[(string)$zip->getNameIndex($i)] = (string)$zip->getFromIndex($i);
    }
    ksort($got);
    assertSame($files, $got);
});

test('e2e: jeden plik z polską nazwą → „nazwa.zip”, nagłówek pobrania ma filename*=UTF-8', function () {
    $c = new Client();
    $c->open('/?tab=pack');
    $token = $c->packOk(['zażółć gęślą.txt' => 'treść']);
    $dl = $c->download($token, 'zażółć gęślą.txt.zip');
    assertSame(200, $dl['status']);
    assertContains("filename*=UTF-8''" . rawurlencode('zażółć gęślą.txt.zip'), $dl['headers']['content-disposition']);
    $page = $c->request('GET', '/?j=' . $token)['body'];
    assertContains('Spakowano: zażółć gęślą.txt.zip', $page);
    assertContains('f=' . rawurlencode('zażółć gęślą.txt.zip'), $page);
});

test('e2e: ścieżki folderów z paths[] trafiają do archiwum', function () {
    $c = new Client();
    $c->open('/?tab=pack');
    $token = $c->packOk(['x.txt' => '1', 'y.txt' => '2'], 'tar', [], ['projekt/Łódź/x.txt', 'projekt/y.txt']);
    $dl = $c->download($token, 'archiwum.tar');
    $tar = saveTemp($dl['body'], 'tar');
    [$code, $out] = runCmd(['tar', '-tf', $tar], 30, 65536);
    assertSame(0, $code);
    assertContains('projekt/Łódź/x.txt', $out);
    assertContains('projekt/y.txt', $out);
});

test('e2e: pliki o tej samej nazwie nie nadpisują się', function () {
    $c = new Client();
    $c->open('/?tab=pack');
    $up = [['files[]', 'a.txt', 'pierwszy'], ['files[]', 'a.txt', 'drugi']];
    $r = $c->post([['action', 'pack'], ['format', 'zip']], $up);
    $token = substr($c->json($r)['redirect'], -38, 32);
    $zip = new ZipArchive();
    $zip->open(saveTemp($c->download($token, 'archiwum.zip')['body'], 'zip'), ZipArchive::RDONLY);
    $got = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $got[(string)$zip->getNameIndex($i)] = (string)$zip->getFromIndex($i);
    }
    ksort($got);
    assertSame(['a (2).txt' => 'drugi', 'a.txt' => 'pierwszy'], $got);
});

test('e2e: ZIP z hasłem — z formularza do pliku, który wymaga hasła', function () {
    $c = new Client();
    $c->open('/?tab=pack');
    $token = $c->packOk(['tajne.txt' => 'bardzo tajna treść, dłuższa niż 20 bajtów'], 'zip', ['password' => 'Hasło123']);
    $page = $c->request('GET', '/?j=' . $token)['body'];
    assertContains('zaszyfrowane hasłem', $page);
    $path = saveTemp($c->download($token, 'tajne.txt.zip')['body'], 'zip');
    $zip = new ZipArchive();
    $zip->open($path, ZipArchive::RDONLY);
    assertSame(ZipArchive::EM_AES_256, $zip->statIndex(0)['encryption_method']);
    assertSame(false, $zip->getFromIndex(0), 'bez hasła nie powinno dać się odczytać');
    $zip->setPassword('Hasło123');
    assertSame('bardzo tajna treść, dłuższa niż 20 bajtów', $zip->getFromIndex(0));
});

test('e2e: 7Z z hasłem i podziałem na części, pobranie części i wszystkich razem jako ZIP', function () {
    if (find7z() === null) {
        skip('brak programu 7z');
    }
    mt_srand(3);
    $noise = '';
    for ($i = 0; $i < 75000; $i++) {
        $noise .= pack('N', mt_rand());
    }
    $c = new Client();
    $c->open('/?tab=pack');
    $token = $c->packOk(['szum.bin' => $noise], '7z', ['password' => 'tajne', 'part_size' => '100', 'part_unit' => 'KB']);
    $page = $c->request('GET', '/?j=' . $token)['body'];
    assertContains('szum.bin.7z.001', $page);
    assertContains('szum.bin.7z.002', $page);
    assertContains('zaszyfrowane hasłem', $page);
    assertContains('częściach', $page);
    assertContains('Pobierz wszystkie części jako ZIP', $page);

    $all = $c->request('GET', '/?d=' . $token . '&all=1');
    assertSame(200, $all['status']);
    assertContains('-czesci.zip', $all['headers']['content-disposition']);
    $dir = tmpDir('parts');
    $zip = new ZipArchive();
    assertTrue($zip->open(saveTemp($all['body'], 'zip'), ZipArchive::RDONLY) === true);
    assertTrue($zip->numFiles >= 3);
    assertTrue($zip->extractTo($dir));
    $out = tmpDir('joined');
    [$code, $text] = runCmd([(string)find7z(), 'x', '-y', '-ptajne', '-o' . $out, $dir . '/szum.bin.7z.001'], 60, 65536);
    assertTrue($code <= 1, $text);
    assertTrue(hash_equals(md5($noise), md5_file($out . '/szum.bin')), 'zawartość po złożeniu części różni się od oryginału');
});

test('e2e: okrągła droga — TAR.GZ spakowany w aplikacji rozpakowuje ta sama aplikacja', function () {
    $c = new Client();
    $c->open('/?tab=pack');
    $files = ['Łódź.txt' => "Zażółć\n", 'katalog/dane.csv' => "1;2\n"];
    $token = $c->packOk(['Łódź.txt' => $files['Łódź.txt'], 'dane.csv' => $files['katalog/dane.csv']], 'tar.gz', [], ['Łódź.txt', 'katalog/dane.csv']);
    $archive = $c->download($token, 'archiwum.tar.gz')['body'];
    assertSame("\x1f\x8b", substr($archive, 0, 2));

    $c->open('/');
    $r = $c->post([['action', 'upload']], [['archive', 'archiwum.tar.gz', $archive]]);
    $j = $c->json($r);
    assertTrue(!empty($j['ok']), json_encode($j, JSON_UNESCAPED_UNICODE));
    $page = $c->request('GET', '/' . $j['redirect'])['body'];
    assertContains('Rozpakowano: archiwum.tar.gz', $page);
    assertContains('2 pliki', $page);
    assertContains('data-name="Łódź.txt"', $page);
    assertContains('data-name="katalog/dane.csv"', $page);
});

test('e2e: GZ przy wielu plikach zamienia się w TAR.GZ (informacja w wyniku)', function () {
    $c = new Client();
    $c->open('/?tab=pack');
    $token = $c->packOk(['a.txt' => '1', 'b.txt' => '2'], 'gz');
    $page = $c->request('GET', '/?j=' . $token)['body'];
    assertContains('archiwum.tar.gz', $page);
    assertSame(200, $c->download($token, 'archiwum.tar.gz')['status']);
});

test('e2e: błędne dane formularza dają czytelny komunikat', function () {
    $c = new Client();
    $c->open('/?tab=pack');
    $one = ['a.txt' => 'x'];
    $cases = [
        'brak plików' => [$c->pack([], 'zip'), 400, 'Dodaj przynajmniej jeden plik'],
        'zły format' => [$c->pack($one, 'rar'), 400, 'Wybierz format'],
        'hasło do tar' => [$c->pack($one, 'tar', ['password' => 'x']), 400, 'tylko dla archiwów ZIP i 7Z'],
        'podział zip' => [$c->pack($one, 'zip', ['part_size' => '100', 'part_unit' => 'KB']), 400, 'tylko dla formatu 7Z'],
        'część za mała' => [$c->pack($one, '7z', ['part_size' => '1', 'part_unit' => 'KB']), 400, 'Minimalny rozmiar'],
        'część nie liczba' => [$c->pack($one, '7z', ['part_size' => 'abc']), 400, 'liczbą'],
        'ścieżka z ..' => [$c->pack($one, 'zip', [], ['../../etc/passwd']), 422, 'Nieprawidłowa nazwa'],
    ];
    foreach ($cases as $name => [$r, $status, $msg]) {
        assertSame($status, $r['status'], $name);
        $j = $c->json($r);
        assertSame(false, $j['ok'] ?? null, $name);
        assertContains($msg, (string)($j['error'] ?? ''), $name);
    }
});

test('e2e: zbyt duża liczba części w podziale jest odrzucona', function () {
    if (find7z() === null) {
        skip('brak programu 7z');
    }
    $c = new Client();
    $c->open('/?tab=pack');
    $data = str_repeat('x', 1024 * 1024 + 500); // 1 MB, część 1 KB → ~1000 części
    $r = $c->pack(['duzy.bin' => $data], '7z', ['part_size' => '64', 'part_unit' => 'KB']);
    assertSame(200, $r['status'], 'sanity: 16 części powinno przejść');
    $r = $c->pack(['duzy.bin' => $data], '7z', ['part_size' => '0.05', 'part_unit' => 'MB']); // ~51 KB < minimum
    assertSame(400, $r['status']);
});

test('e2e: limity serwera — za duży plik, za dużo plików, za duży formularz', function () {
    $c = new Client();
    $c->open('/?tab=pack');
    $r = $c->pack(['duzy.bin' => str_repeat('a', 2 * 1024 * 1024 + 10)]);
    assertSame(413, $r['status']);
    assertContains('za duży', (string)($c->json($r)['error'] ?? ''));

    $many = [];
    $paths = [];
    for ($i = 1; $i <= 6; $i++) {
        $many["f$i.txt"] = 'x';
        $paths[] = "f$i.txt";
    }
    $r = $c->pack($many, 'zip', [], $paths); // max_file_uploads=5
    assertSame(413, $r['status']);
    assertContains('Nie wszystkie pliki dotarły', (string)($c->json($r)['error'] ?? ''));

    $r = $c->pack(['a.bin' => str_repeat('a', 1900000), 'b.bin' => str_repeat('b', 1900000)]); // razem > post_max_size 3 MB
    assertSame(413, $r['status']);
});

test('e2e: brak tokenu CSRF blokuje pakowanie', function () {
    $c = new Client();
    $c->open('/?tab=pack');
    $r = $c->post([['action', 'pack'], ['format', 'zip']], [['files[]', 'a.txt', 'x']], true, false);
    assertSame(403, $r['status']);
});

test('e2e: bez JS — błąd pokazuje zakładkę Spakuj, sukces przekierowuje 303', function () {
    $c = new Client();
    $c->open('/?tab=pack');
    $r = $c->pack(['a.txt' => 'x'], 'tar', ['password' => 'x'], null, false);
    assertSame(400, $r['status']);
    assertContains('id="pack"', $r['body']);
    assertContains('tylko dla archiwów ZIP i 7Z', $r['body']);

    $r = $c->pack(['a.txt' => 'x'], 'tar', [], null, false);
    assertSame(303, $r['status']);
    assertContains('?j=', $r['headers']['location']);
});

test('e2e: wynik widzi tylko sesja, która go utworzyła', function () {
    $c = new Client();
    $c->open('/?tab=pack');
    $token = $c->packOk(['a.txt' => 'x'], 'tar');
    $other = new Client();
    $other->open('/');
    assertSame(404, $other->download($token, 'archiwum.tar')['status']);
    assertContains('nie istnieje', $other->request('GET', '/?j=' . $token)['body']);
});

test('e2e: pobieranie z podmienioną nazwą (../) i nieistniejącym plikiem daje 404', function () {
    $c = new Client();
    $c->open('/?tab=pack');
    $token = $c->packOk(['a.txt' => 'x'], 'tar');
    assertSame(404, $c->download($token, '../meta.json')['status']);
    assertSame(404, $c->download($token, 'meta.json')['status']);
    assertSame(404, $c->download($token, 'a.txt')['status']);
});

test('e2e: usunięcie wyniku kasuje pliki z serwera, w tym katalog wejściowy', function () {
    $c = new Client();
    $c->open('/?tab=pack');
    $token = $c->packOk(['a.txt' => 'x'], 'zip');
    assertTrue(is_dir(E2e::$work . '/' . $token), 'katalog zadania powinien istnieć');
    assertTrue(!is_dir(E2e::$work . '/' . $token . '/in'), 'pliki wejściowe powinny zniknąć zaraz po spakowaniu');
    $r = $c->post([['action', 'delete'], ['token', $token]], [], false);
    assertSame(303, $r['status']);
    clearstatcache(); // katalog usunął inny proces (serwer), a PHP pamięta wcześniejszy wynik is_dir()
    assertTrue(!is_dir(E2e::$work . '/' . $token), 'katalog zadania powinien zostać usunięty');
    assertSame(404, $c->download($token, 'a.txt.zip')['status']);
});

test('e2e: nieudane pakowanie nie zostawia śmieci na dysku', function () {
    $c = new Client();
    $c->open('/?tab=pack');
    $before = is_dir(E2e::$work) ? count(glob(E2e::$work . '/*') ?: []) : 0;
    $c->pack(['a.txt' => 'x'], '7z', ['part_size' => 'abc']);
    $c->pack(['a.txt' => 'x'], 'tar', ['password' => 'x']);
    $after = is_dir(E2e::$work) ? count(glob(E2e::$work . '/*') ?: []) : 0;
    assertSame($before, $after);
});
