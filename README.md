Aplikację,  która by pozwala po przez formularz rozpakować pliki np.: zip, tar, 7z

Co robi aplikacja

Formularz z przeciąganiem pliku i paskiem postępu. Rozpakowuje ZIP (także z hasłem), TAR, TAR.GZ, TAR.BZ2, GZ, BZ2 i 7Z.
Po rozpakowaniu pokazuje listę plików z filtrem. Pliki pobierzesz pojedynczo albo wszystkie razem jako ZIP.
Format rozpoznaje po zawartości pliku, nie po rozszerzeniu.
Poprawnie odczytuje polskie znaki w nazwach ze starszych archiwów.

Zabezpieczenia

Ścieżki z .. i ścieżki bezwzględne są odrzucane (atak „zip slip”).
Limity: 512 MB po rozpakowaniu i 5000 plików, żeby „bomba” zip nie zapełniła dysku. Można je zmienić zmiennymi środowiskowymi opisanymi na początku index.php.
Wyniki są usuwane po 60 minut. Widzi je tylko przeglądarka, która przesłała plik, a przycisk „Usuń pliki z serwera” kasuje je od razu.
Archiwa 7Z z dowiązaniami symbolicznymi są odrzucane.