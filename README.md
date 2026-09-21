#Aplikację,  która by pozwala po przez formularz rozpakować pliki np.: zip, tar, 7z

##Co robi aplikacja

- Formularz z przeciąganiem pliku i paskiem postępu. Rozpakowuje ZIP (także z hasłem), TAR, TAR.GZ, TAR.BZ2, GZ, BZ2 i 7Z.
- Po rozpakowaniu pokazuje listę plików z filtrem. Pliki pobierzesz pojedynczo albo wszystkie razem jako ZIP.
- Format rozpoznaje po zawartości pliku, nie po rozszerzeniu.
- Poprawnie odczytuje polskie znaki w nazwach ze starszych archiwów.

##Pakowanie (zakładka „Spakuj”)

- Formularz z przeciąganiem plików (także całych folderów), listą wybranych plików z przyciskiem „Usuń” i paskiem postępu. Można dodać wiele plików naraz.
- Format wyniku do wyboru: ZIP, TAR, TAR.GZ, TAR.BZ2, GZ, BZ2 i 7Z. Gotowe archiwum pobierasz w wybranym formacie.
- Hasło (opcjonalne): ZIP (szyfrowanie AES-256) i 7Z (szyfrowane są też nazwy plików). Pozostałe formaty haseł nie obsługują.
- Podział na części (opcjonalny): tylko 7Z. Rozmiar części podajesz w KB, MB lub GB (minimum 64 KB, najwyżej 500 części). Powstają pliki `.7z.001`, `.7z.002`… — pobierzesz je pojedynczo albo wszystkie razem jako ZIP. Do rozpakowania potrzebna jest pierwsza część i pozostałe w tym samym katalogu (np. 7-Zip).
- GZ i BZ2 pakują jeden plik. Przy wielu plikach powstanie TAR.GZ lub TAR.BZ2.
- Polskie znaki w nazwach zapisywane są w UTF-8 (ZIP z flagą UTF-8), więc czytają je współczesne programy. Pliki o tej samej nazwie dostają numer, np. `raport (2).txt`.
- Pliki wejściowe są kasowane zaraz po spakowaniu, a wynik po 60 minutach, tak jak przy rozpakowywaniu.
- Hasło do 7Z jest przekazywane programowi 7z w argumencie (`-p`), więc na wielodostępnym serwerze bywa widoczne dla innych użytkowników systemu w liście procesów.

##Zabezpieczenia

- Ścieżki z .. i ścieżki bezwzględne są odrzucane (atak „zip slip”).
- Limity: 512 MB po rozpakowaniu i 5000 plików, żeby „bomba” zip nie zapełniła dysku. Można je zmienić zmiennymi środowiskowymi opisanymi na początku index.php.
- Wyniki są usuwane po 60 minut. Widzi je tylko przeglądarka, która przesłała plik, a przycisk „Usuń pliki z serwera” kasuje je od razu.
- Archiwa 7Z z dowiązaniami symbolicznymi są odrzucane.
  
##Uruchomienie

- Lokalnie, do testu (PHP 8.1 lub nowszy, z rozszerzeniem zip):
- php -S localhost:8000 -d upload_max_filesize=256M -d post_max_size=260M -d max_file_uploads=1000 -d max_input_vars=10000 index.php
- (max_file_uploads domyślnie wynosi w PHP tylko 20, a ponad limit pliki są po cichu pomijane, więc przy pakowaniu wielu plików warto go zwiększyć. Aplikacja wykryje obcięcie i pokaże błąd, a formularz nie pozwoli dodać więcej plików niż limit.)
- Na serwerze przez Docker (obraz zawiera też program 7z):
- docker build -t rozpakowywarka . i docker run -p 8080:80 rozpakowywarka. Zadziała na VPS-ie albo w Render, Fly.io lub Railway.
- Na zwykłym hostingu PHP wgraj index.php. ZIP, TAR, GZ i BZ2 zadziałają, a 7Z zwykle nie, bo wymaga programu 7z. Aplikacja sama pokaże, których formatów brakuje.

##Uwagi

- Publiczna instancja bez logowania może zostać wykorzystana do zapełniania dysku i obciążania procesora. Postaw ją za HTTPS i dodaj hasło (np. basic auth w nginx/Apache) albo ogranicz dostęp po adresie IP.
- Uruchamianie testów: php tests/run.php (opcjonalnie z fragmentem nazwy testu, np. php tests/run.php 7z). Testy nie wymagają Composera; formaty niedostępne na komputerze (np. bz2 albo 7z) są pomijane. Część „e2e” uruchamia serwer php -S na losowym porcie.
- Jeśli używasz nginx jako proxy, ustaw client_max_body_size na co najmniej 260M, inaczej większe pliki zostaną odrzucone.
