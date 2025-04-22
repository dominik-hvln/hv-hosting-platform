# System Hostingowy z Autoskalowaniem

Nowoczesny system hostingowy z funkcją dynamicznego skalowania zasobów kont użytkowników, zintegrowany z WHMCS, CloudLinux i DirectAdmin.

## Wymagania

- PHP 8.3+
- Laravel 12
- MySQL 5.7/8+
- Kompatybilność z DirectAdmin + CloudLinux 9
- WHMCS 8.12+
- Redis (dla obsługi kolejek)

## Instalacja

1. Sklonuj repozytorium:
```bash
git clone https://github.com/twoja-organizacja/hosting-system.git
cd hosting-system
```

2. Zainstaluj zależności:
```bash
composer install
```

3. Skopiuj plik .env.example do .env i skonfiguruj:
```bash
cp .env.example .env
php artisan key:generate
```

4. Skonfiguruj połączenie z bazą danych w pliku .env

5. Przeprowadź migracje bazy danych:
```bash
php artisan migrate
```

6. Uruchom serwer deweloperski:
```bash
php artisan serve
```

## Funkcjonalności

### Użytkownik
- Rejestracja i logowanie (z tokenem Sanctum)
- Potwierdzenie e-maila
- Obsługa 2FA (opcjonalnie)
- Wylogowanie (token revoke)

### Portfel Klienta
- Logi transakcji (wpłaty, zakupy, kody rabatowe)
- Saldo w czasie rzeczywistym
- Możliwość doładowania przez bramkę płatniczą lub ręcznie (API)

### System Planów Hostingowych
- Lista aktywnych planów (ram, cpu, cena miesięczna i roczna, okres trwania)
- Zakup planu przez portfel lub bramkę płatniczą
- Automatyczne przypisanie planu do konta

### Obsługa Usług
- Model usług przypisanych do użytkownika
- Czas trwania usługi, data rozpoczęcia i zakończenia
- Status: aktywna, wygasła, zawieszona
- Informacja o źródle płatności (portfel/bramka)

### Autoskalowanie
- Komenda Artisan (`php artisan autoscale:run`)
- Pobieranie zużycia CPU i RAM z CloudLinux (mock lub API)
- Warunki zwiększania zasobów: +50% CPU, +256MB RAM jeśli przekroczone
- Maksymalne limity ustalone per konto
- Obciążanie konta klienta przez WHMCS API (mock lub prawdziwe)

### System Kodów Promocyjnych
- Dodawanie i użycie kodów (np. -10%)
- Kody jednorazowe i wielorazowe
- Powiązanie z portfelem (dodanie środków)

### System Poleceń (Referral)
- Generowanie kodów poleceń
- Historia poleceń i przypisanie do konta
- Dodanie bonusów za polecenie

### Zarządzanie Kontem Hostingowym
- Dane konta: login, username, zasoby
- Przełącznik aktywności autoskalowania
- Informacje o zasobach, historia skalowania

## API Endpoints

Dokumentacja API jest dostępna pod adresem `/api/documentation` po uruchomieniu projektu.

## Komendy Artisan

- `php artisan autoscale:run` - Uruchamia proces autoskalowania
- `php artisan backup:create` - Tworzy kopię zapasową systemu
- `php artisan backup:restore {id}` - Przywraca kopię zapasową
- `php artisan whmcs:sync` - Synchronizuje dane z WHMCS

## Testy

Uruchomienie testów:

```bash
php artisan test
```

## Integracje

System integruje się z następującymi zewnętrznymi usługami:

- WHMCS (billing, ID konta, synchronizacja usług)
- CloudLinux LVE API (limit CPU/RAM)
- DirectAdmin lub inny panel (w przyszłości)
- System bramek płatniczych (PayNow, Stripe, P24)

## Dodatkowe Moduły

- Statystyki wykorzystania zasobów
- API dla frontend React
- System kopii zapasowych
- Newsletter i zgoda marketingowa (zgodne z RODO)
- Tryb EKO (statystyki ciemnego motywu, oszczędzania zasobów)
- Zewnętrzny serwer backupowy
- Failover i HA

## Licencja

Ten projekt jest licencjonowany na warunkach licencji MIT.
