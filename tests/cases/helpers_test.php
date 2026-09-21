<?php
declare(strict_types=1);

test('parsePartSize: puste pole oznacza brak podziału', function () {
    assertSame(0, parsePartSize('', 'MB'));
    assertSame(0, parsePartSize('  ', 'MB'));
});

test('parsePartSize: jednostki i przecinek dziesiętny', function () {
    assertSame(100 * 1024, parsePartSize('100', 'KB'));
    assertSame(1024 * 1024, parsePartSize('1', 'MB'));
    assertSame((int)(1.5 * 1024 ** 2), parsePartSize('1,5', 'mb'));
    assertSame(2 * 1024 ** 3, parsePartSize('2', 'GB'));
});

test('parsePartSize: odrzuca błędne wartości', function () {
    assertThrows(fn() => parsePartSize('abc', 'MB'), 'liczbą');
    assertThrows(fn() => parsePartSize('-5', 'MB'), 'liczbą');
    assertThrows(fn() => parsePartSize('0', 'MB'), 'liczbą');
    assertThrows(fn() => parsePartSize('10', 'TB'), 'jednostka');
    assertThrows(fn() => parsePartSize('1', 'KB'), 'Minimalny');
});

test('truncateUtf8: nie rozcina polskich znaków', function () {
    $s = 'zażółć gęślą jaźń';
    for ($max = 1; $max <= strlen($s); $max++) {
        $cut = truncateUtf8($s, $max);
        assertTrue(preg_match('//u', $cut) === 1, "niepoprawny UTF-8 przy $max");
        assertTrue(strlen($cut) <= $max);
        assertTrue(str_starts_with($s, $cut));
    }
    assertSame($s, truncateUtf8($s, 1000));
});

test('uniqueRel: kolizje nazw dostają numer przed rozszerzeniem', function () {
    $taken = [];
    assertSame('a.txt', uniqueRel('a.txt', $taken));
    assertSame('a (2).txt', uniqueRel('a.txt', $taken));
    assertSame('a (3).txt', uniqueRel('a.txt', $taken));
    assertSame('kat/a.txt', uniqueRel('kat/a.txt', $taken));
    assertSame('kat/a (2).txt', uniqueRel('kat/a.txt', $taken));
});

test('uniqueRel: wielkość liter nie ma znaczenia (Windows)', function () {
    $taken = [];
    assertSame('Żółw.txt', uniqueRel('Żółw.txt', $taken));
    assertSame('żółw (2).txt', uniqueRel('żółw.txt', $taken));
});

test('uniqueRel: plik nie może zajmować miejsca folderu', function () {
    $taken = [];
    assertSame('a/b.txt', uniqueRel('a/b.txt', $taken));
    assertSame('a (2)', uniqueRel('a', $taken));
});

test('uniqueRel: folder nie może zajmować miejsca pliku', function () {
    $taken = [];
    assertSame('x', uniqueRel('x', $taken));
    assertSame('x (2)/y.txt', uniqueRel('x/y.txt', $taken));
    assertSame('x (2)/z.txt', uniqueRel('x/z.txt', $taken)); // kolejne pliki z tego folderu trafiają w to samo miejsce
    assertSame('x (3)', uniqueRel('x', $taken));
});

test('uniqueRel: plik bez rozszerzenia i z kropką na początku', function () {
    $taken = [];
    assertSame('.env', uniqueRel('.env', $taken));
    assertSame('.env (2)', uniqueRel('.env', $taken));
    assertSame('README', uniqueRel('README', $taken));
    assertSame('README (2)', uniqueRel('README', $taken));
});

test('TarWriter::paxRecord: długość obejmuje własne cyfry', function () {
    foreach ([1, 5, 90, 95, 100, 300] as $n) {
        $rec = TarWriter::paxRecord('path', str_repeat('x', $n));
        [$len] = explode(' ', $rec, 2);
        assertSame(strlen($rec), (int)$len, "n=$n");
        assertTrue(str_ends_with($rec, "\n"));
    }
});

test('normalizeUploads: składa pola files[] w listę', function () {
    $f = ['name' => ['a.txt', 'b.txt'], 'tmp_name' => ['/tmp/1', '/tmp/2'], 'error' => [0, 4], 'size' => [1, 0]];
    $n = normalizeUploads($f);
    assertSame(2, count($n));
    assertSame('a.txt', $n[0]['name']);
    assertSame(UPLOAD_ERR_NO_FILE, $n[1]['error']);
});

test('normalizeUploads: ignoruje pole pojedyncze i zagnieżdżone', function () {
    assertSame([], normalizeUploads(null));
    assertSame([], normalizeUploads(['name' => 'a', 'tmp_name' => 'b', 'error' => 0]));
    $nested = ['name' => [['x']], 'tmp_name' => [['y']], 'error' => [[0]]];
    assertSame([], normalizeUploads($nested));
});

test('formatAvailability: ZIP i TAR zawsze są, klucze to małe litery', function () {
    $a = formatAvailability();
    assertSame(['zip', 'tar', 'tar.gz', 'gz', 'tar.bz2', 'bz2', '7z'], array_keys($a));
    assertTrue($a['tar']);
    assertSame('TAR.GZ', formatLabel('tar.gz'));
});

test('currentTab: pakowanie tylko po action=pack lub ?tab=pack', function () {
    $post = $_POST;
    $get = $_GET;
    $_POST = [];
    $_GET = [];
    assertSame('unpack', currentTab());
    $_GET = ['tab' => 'pack'];
    assertSame('pack', currentTab());
    $_GET = [];
    $_POST = ['action' => 'pack'];
    assertSame('pack', currentTab());
    $_POST = ['action' => 'upload'];
    assertSame('unpack', currentTab());
    $_POST = $post;
    $_GET = $get;
});

test('tarHeaderValid: śmieci w polu sumy kontrolnej to nie TAR i bez ostrzeżeń PHP', function () {
    set_error_handler(static function (int $no, string $msg): never {
        throw new RuntimeException("ostrzeżenie PHP: $msg");
    });
    try {
        $junk = str_repeat("\x8f\x02Zażółć", 80);
        assertSame(false, tarHeaderValid(substr($junk, 0, 512)));
        assertSame(false, tarHeaderValid(str_repeat('z', 512)));
    } finally {
        restore_error_handler();
    }
});
