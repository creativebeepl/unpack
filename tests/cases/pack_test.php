<?php
declare(strict_types=1);

const PL_FILES = [
    'zażółć gęślą jaźń.txt' => "Zażółć gęślą jaźń — ĄĆĘŁŃÓŚŹŻ ąćęłńóśźż\n",
    'raport/Łódź – wyniki.csv' => "a;b;c\n1;2;3\n",
    'pusty.txt' => '',
    'dane.bin' => "\0\1\2\xFF\xFE binary \0",
];

function requireFormat(string $slug): void
{
    if (!(formatAvailability()[$slug] ?? false)) {
        skip('format ' . $slug . ' niedostępny na tym komputerze');
    }
}

/** Pakuje PL_FILES i zwraca [wynik packEntries, katalog wyjściowy]. */
function packSample(string $format, string $password = '', int $part = 0, array $files = PL_FILES): array
{
    $in = tmpDir('in');
    $out = tmpDir('out');
    $abs = makeFiles($in, $files);
    $res = packEntries($format, $abs, $in, $out, 'archiwum', $password, $part);
    return [$res, $out];
}

/** Uruchamia polecenie zewnętrzne i zwraca [kod, stdout]. */
function sh(array $cmd): array
{
    [$code, $out, $err] = runCmd($cmd, 60, 4 * 1024 * 1024);
    return [$code, $out . $err];
}

foreach (['zip', 'tar', 'tar.gz', 'tar.bz2', '7z'] as $fmt) {
    test("pakowanie $fmt: wiele plików z polskimi nazwami wraca bez zmian", function () use ($fmt) {
        requireFormat($fmt);
        [$res, $out] = packSample($fmt);
        assertSame($fmt, $res['type']);
        assertSame('archiwum.' . $fmt, $res['name']);
        assertSame([$res['name']], array_keys($res['files']));
        $expected = PL_FILES;
        ksort($expected);
        assertSame($expected, unpackWithApp($out . '/' . $res['name']));
    });
}

foreach (['gz', 'bz2'] as $fmt) {
    test("pakowanie $fmt: jeden plik daje zwykły .$fmt", function () use ($fmt) {
        requireFormat($fmt);
        $one = ['zażółć.txt' => str_repeat("Zażółć gęślą jaźń\n", 500)];
        [$res, $out] = packSample($fmt, '', 0, $one);
        assertSame($fmt, $res['type']);
        assertSame('archiwum.' . $fmt, $res['name']);
        assertSame($one, unpackWithApp($out . '/' . $res['name']) === ['archiwum' => $one['zażółć.txt']] ? $one : []);
    });

    test("pakowanie $fmt: wiele plików trafia do TAR.$fmt", function () use ($fmt) {
        requireFormat($fmt);
        [$res, $out] = packSample($fmt);
        assertSame('tar.' . $fmt, $res['type']);
        $expected = PL_FILES;
        ksort($expected);
        assertSame($expected, unpackWithApp($out . '/' . $res['name']));
    });
}

test('pakowanie gz: wynik czyta zewnętrzny gzip', function () {
    $in = tmpDir('in');
    $out = tmpDir('out');
    $abs = makeFiles($in, ['a.txt' => "Zażółć gęślą jaźń\n"]);
    $res = packEntries('gz', $abs, $in, $out, 'a.txt', '', 0);
    assertSame('a.txt.gz', $res['name']);
    [$code, $text] = sh(['gzip', '-dc', $out . '/a.txt.gz']);
    assertSame(0, $code);
    assertSame("Zażółć gęślą jaźń\n", $text);
});

test('zip: nazwy mają flagę UTF-8, a unzip i ZipArchive widzą polskie znaki', function () {
    [$res, $out] = packSample('zip');
    $path = $out . '/' . $res['name'];
    $bytes = (string)file_get_contents($path);
    assertSame("PK\x03\x04", substr($bytes, 0, 4));
    $flags = unpack('v', substr($bytes, 6, 2))[1];
    assertTrue(($flags & 0x800) !== 0, 'brak flagi UTF-8 w nagłówku ZIP');
    $zip = new ZipArchive();
    assertTrue($zip->open($path, ZipArchive::RDONLY) === true);
    $names = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $names[] = (string)$zip->getNameIndex($i);
    }
    $zip->close();
    sort($names);
    assertTrue(in_array('zażółć gęślą jaźń.txt', $names, true), 'nazwy: ' . implode(', ', $names));
    assertTrue(in_array('raport/Łódź – wyniki.csv', $names, true));
    if (is_executable('/bin/unzip') || is_executable('/usr/bin/unzip')) {
        [$code, $text] = sh(['unzip', '-t', $path]);
        assertSame(0, $code, $text);
    }
});

test('zip z hasłem: bez hasła i ze złym hasłem błąd, z dobrym działa', function () {
    [$res, $out] = packSample('zip', 'Hasło-żółć-123');
    $path = $out . '/' . $res['name'];
    assertThrows(fn() => unpackWithApp($path), 'hasłem');
    assertThrows(fn() => unpackWithApp($path, 'zle'), 'hasło');
    $expected = PL_FILES;
    ksort($expected);
    assertSame($expected, unpackWithApp($path, 'Hasło-żółć-123'));
    // szyfrowanie AES-256 — każdy wpis ma metodę szyfrowania
    $zip = new ZipArchive();
    $zip->open($path, ZipArchive::RDONLY);
    $st = $zip->statIndex(0);
    assertSame(ZipArchive::EM_AES_256, $st['encryption_method']);
    $zip->close();
});

test('7z z hasłem: bez hasła nie da się nawet zobaczyć nazw, z dobrym hasłem działa', function () {
    requireFormat('7z');
    [$res, $out] = packSample('7z', 'sekret żółć');
    $path = $out . '/' . $res['name'];
    assertThrows(fn() => unpackWithApp($path), 'hasł');
    assertThrows(fn() => unpackWithApp($path, 'zle'), 'hasł');
    [$code, $text] = sh([(string)find7z(), 'l', $path]);
    assertTrue(!str_contains($text, 'wyniki'), 'nazwy plików powinny być zaszyfrowane (-mhe=on)');
    $expected = PL_FILES;
    ksort($expected);
    assertSame($expected, unpackWithApp($path, 'sekret żółć'));
});

test('hasło jest dozwolone tylko dla ZIP i 7Z', function () {
    foreach (['tar', 'tar.gz', 'tar.bz2', 'gz', 'bz2'] as $fmt) {
        $in = tmpDir('in');
        $abs = makeFiles($in, ['a.txt' => 'x']);
        assertThrows(fn() => packEntries($fmt, $abs, $in, tmpDir('out'), 'a', 'haslo'), 'Hasło można');
    }
});

test('podział na części jest dozwolony tylko dla 7Z', function () {
    $in = tmpDir('in');
    $abs = makeFiles($in, ['a.txt' => 'x']);
    assertThrows(fn() => packEntries('zip', $abs, $in, tmpDir('out'), 'a', '', 100000), 'tylko dla formatu 7Z');
});

test('7z: podział na części daje .001, .002… i da się złożyć z powrotem', function () {
    requireFormat('7z');
    mt_srand(7);
    $noise = '';
    for ($i = 0; $i < 60000; $i++) {
        $noise .= pack('N', mt_rand()); // szum słabo się kompresuje, więc części są pełne
    }
    $files = ['losowe.bin' => $noise, 'zażółć.txt' => "gęślą\n"];
    [$res, $out] = packSample('7z', '', 100 * 1024, $files);
    $names = array_keys($res['files']);
    assertTrue(count($names) >= 3, 'oczekiwano wielu części, jest: ' . implode(', ', $names));
    assertSame('archiwum.7z.001', $names[0]);
    assertSame('archiwum.7z.002', $names[1]);
    foreach (array_slice($names, 0, -1) as $n) {
        assertSame(100 * 1024, $res['files'][$n], "część $n");
    }
    assertTrue($res['files'][end($names)] <= 100 * 1024);
    // 7z otwiera wolumeny po pierwszej części — tak samo zrobi użytkownik
    $dir = tmpDir('joined');
    [$code, $text] = sh([(string)find7z(), 'x', '-y', '-o' . $dir, $out . '/archiwum.7z.001']);
    assertTrue($code <= 1, $text);
    ksort($files);
    assertSame($files, readTree($dir));
});

test('7z bez podziału: dokładnie jeden plik .7z', function () {
    requireFormat('7z');
    [$res] = packSample('7z');
    assertSame(['archiwum.7z'], array_keys($res['files']));
});

test('tar: wynik czyta zewnętrzny program tar (polskie znaki, foldery)', function () {
    [$res, $out] = packSample('tar');
    [$code, $text] = sh(['tar', '-tf', $out . '/' . $res['name']]);
    assertSame(0, $code, $text);
    assertContains('zażółć gęślą jaźń.txt', $text);
    assertContains('raport/Łódź – wyniki.csv', $text);
    $dir = tmpDir('gnutar');
    [$code, $text] = sh(['tar', '-xf', $out . '/' . $res['name'], '-C', $dir]);
    assertSame(0, $code, $text);
    $expected = PL_FILES;
    ksort($expected);
    assertSame($expected, readTree($dir));
});

test('tar.gz: wynik czyta zewnętrzny tar -z', function () {
    [$res, $out] = packSample('tar.gz');
    [$code, $text] = sh(['tar', '-tzf', $out . '/' . $res['name']]);
    assertSame(0, $code, $text);
    assertContains('Łódź – wyniki.csv', $text);
});

test('tar: długie i wielobajtowe nazwy (>100 B) przez rozszerzenie PAX', function () {
    $long = 'katalog-ąęś/' . str_repeat('bardzo-długa-nazwa-żółć-', 8) . 'plik.txt';
    assertTrue(strlen($long) > 200);
    $files = [$long => "treść\n", 'krótki.txt' => "x\n"];
    [$res, $out] = packSample('tar', '', 0, $files);
    [$code, $text] = sh(['tar', '-tf', $out . '/' . $res['name']]);
    assertSame(0, $code, $text);
    assertContains($long, $text);
    ksort($files);
    assertSame($files, unpackWithApp($out . '/' . $res['name']));
});

test('tar: rozmiar >8 GB zapisywany w formacie binarnym GNU', function () {
    $tw = new TarWriter(fopen('php://memory', 'wb'));
    $m = new ReflectionMethod($tw, 'header');
    foreach ([0, 511, 8 ** 11 - 1, 8 ** 11, 20 * 1024 ** 3] as $size) {
        $h = $m->invoke($tw, 'duzy.bin', $size, 1700000000, '0');
        assertSame(512, strlen($h));
        assertTrue(tarHeaderValid($h), "suma kontrolna dla $size");
        assertSame($size, tarNumber(substr($h, 124, 12)), "rozmiar $size");
    }
});

test('tar: puste archiwum kończy się dwoma blokami zer i jest poprawne dla tar', function () {
    $out = tmpDir('out');
    $tw = null;
    $fh = fopen($out . '/e.tar', 'wb');
    $tw = new TarWriter($fh);
    $tw->finish();
    fclose($fh);
    assertSame(1024, filesize($out . '/e.tar'));
    [$code] = sh(['tar', '-tf', $out . '/e.tar']);
    assertSame(0, $code);
});

test('nazwa wyniku: jeden plik → nazwa pliku + rozszerzenie formatu, wiele → archiwum', function () {
    $in = tmpDir('in');
    $out = tmpDir('out');
    $abs = makeFiles($in, ['Żółć.docx' => 'x']);
    $res = packEntries('zip', $abs, $in, $out, 'Żółć.docx', '', 0);
    assertSame('Żółć.docx.zip', $res['name']);
});

test('nazwa wyniku: bardzo długa nazwa jest skracana bez psucia UTF-8', function () {
    $in = tmpDir('in');
    $out = tmpDir('out');
    $abs = makeFiles($in, ['a.txt' => 'x']);
    $res = packEntries('tar', $abs, $in, $out, str_repeat('ż', 200), '', 0);
    assertTrue(preg_match('//u', $res['name']) === 1);
    assertTrue(strlen($res['name']) <= 160);
    assertTrue(is_file($out . '/' . $res['name']));
});

test('packEntries: brak plików to błąd', function () {
    assertThrows(fn() => packEntries('zip', [], tmpDir(), tmpDir(), 'a'), 'przynajmniej jeden');
});

test('packEntries: nieznany format to błąd', function () {
    $in = tmpDir('in');
    $abs = makeFiles($in, ['a.txt' => 'x']);
    assertThrows(fn() => packEntries('rar', $abs, $in, tmpDir('out'), 'a'), 'Nieznany format');
});

test('zip: plik zapisany jako nie-UTF-8 (stare kodowanie) po fixEncoding jest poprawnie zapisany', function () {
    // przeglądarka wysyła UTF-8, ale nazwy przechodzą przez fixEncoding — starsze kodowanie CP852 „Zażółć”
    $cp852 = iconv('UTF-8', 'CP852', 'zażółć.txt');
    assertTrue(preg_match('//u', (string)$cp852) !== 1);
    assertSame('zażółć.txt', fixEncoding((string)$cp852));
    $in = tmpDir('in');
    $out = tmpDir('out');
    $abs = makeFiles($in, [fixEncoding((string)$cp852) => 'x']);
    $res = packEntries('zip', $abs, $in, $out, 'a', '', 0);
    assertSame(['zażółć.txt' => 'x'], unpackWithApp($out . '/' . $res['name']));
});
