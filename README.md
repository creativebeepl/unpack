#Aplikację,  która by pozwala po przez formularz rozpakować pliki np.: zip, tar, 7z

##Co robi aplikacja

- Formularz z przeciąganiem pliku i paskiem postępu. Rozpakowuje ZIP (także z hasłem), TAR, TAR.GZ, TAR.BZ2, GZ, BZ2 i 7Z.
- Po rozpakowaniu pokazuje listę plików z filtrem. Pliki pobierzesz pojedynczo albo wszystkie razem jako ZIP.
- Format rozpoznaje po zawartości pliku, nie po rozszerzeniu.
- Poprawnie odczytuje polskie znaki w nazwach ze starszych archiwów.

##Zabezpieczenia

- Ścieżki z .. i ścieżki bezwzględne są odrzucane (atak „zip slip”).
- Limity: 512 MB po rozpakowaniu i 5000 plików, żeby „bomba” zip nie zapełniła dysku. Można je zmienić zmiennymi środowiskowymi opisanymi na początku index.php.
- Wyniki są usuwane po 60 minut. Widzi je tylko przeglądarka, która przesłała plik, a przycisk „Usuń pliki z serwera” kasuje je od razu.
- Archiwa 7Z z dowiązaniami symbolicznymi są odrzucane.
  
##Uruchomienie

- Lokalnie, do testu (PHP 8.1 lub nowszy, z rozszerzeniem zip):
- php -S localhost:8000 -d upload_max_filesize=256M -d post_max_size=260M index.php
- Na serwerze przez Docker (obraz zawiera też program 7z):
- docker build -t rozpakowywarka . i docker run -p 8080:80 rozpakowywarka. Zadziała na VPS-ie albo w Render, Fly.io lub Railway.
- Na zwykłym hostingu PHP wgraj index.php. ZIP, TAR, GZ i BZ2 zadziałają, a 7Z zwykle nie, bo wymaga programu 7z. Aplikacja sama pokaże, których formatów brakuje.

##Dwie uwagi

- Publiczna instancja bez logowania może zostać wykorzystana do zapełniania dysku i obciążania procesora. Postaw ją za HTTPS i dodaj hasło (np. basic auth w nginx/Apache) albo ogranicz dostęp po adresie IP.
- Jeśli używasz nginx jako proxy, ustaw client_max_body_size na co najmniej 260M, inaczej większe pliki zostaną odrzucone.
