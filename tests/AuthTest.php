<?php

declare(strict_types=1);

namespace MocnyDrop\Tests;

use MocnyDrop\ApiException;
use MocnyDrop\Auth;
use MocnyDrop\Wallet;

/**
 * tests/AuthTest.php
 *
 * Testy rejestracji, logowania i autoryzacji tokenem Bearer.
 *
 * Właściciel: @KubeKoslaw (Core Backend)
 */
final class AuthTest extends TestCase
{
    public function testRejestracjaTworzyKontoZSaldemStartowym(): void
    {
        $user = Auth::register('wedkarz', 'Wedkarz@Test.local', 'haslo1234', $this->db);

        $this->assertSame('wedkarz', $user['login'], 'Login bez zmian');
        $this->assertSame('wedkarz@test.local', $user['email'], 'E-mail musi być normalizowany do małych liter');
        $this->assertSame('user', $user['rola'], 'Nowe konto nie może być kontem administratora');
        $this->assertFloat(50.0, (float) $user['saldo'], 'Saldo startowe z ustawień bazy (saldo_startowe)');
        $this->assertFalse($user['czy_zablokowany'], 'Nowe konto nie jest zablokowane');
        $this->assertFloat(50.0, Wallet::balance($this->db, $user['id']), 'Saldo musi być faktycznie zapisane w bazie');
    }

    /** Odpowiedź API nigdy nie może zawierać hasha hasła. */
    public function testHashHaslaNieWychodziNaZewnatrz(): void
    {
        $user = Auth::register('wedkarz', 'w@test.local', 'haslo1234', $this->db);

        $publiczne = Auth::toPublic($user);

        $this->assertFalse(isset($publiczne['haslo_hash']), 'Wersja publiczna nie może zawierać hasha');
        $this->assertSame(['id', 'login', 'email', 'rola', 'saldo'], array_keys($publiczne), 'Dokładna lista pól publicznych');

        $hash = (string) $this->db->query('SELECT haslo_hash FROM uzytkownicy')->fetchColumn();

        $this->assertTrue($hash !== 'haslo1234', 'Hasło nie może leżeć w bazie w postaci jawnej');
        $this->assertTrue(strlen($hash) >= 60, 'Hash musi wyglądać na wynik password_hash()');
        $this->assertTrue(password_verify('haslo1234', $hash), 'Hash musi dać się zweryfikować poprawnym hasłem');
        $this->assertFalse(password_verify('zlehaslo99', $hash), 'Hash nie może przechodzić dla złego hasła');
    }

    public function testRejestracjaOdrzucaZajetyLogin(): void
    {
        Auth::register('wedkarz', 'a@test.local', 'haslo1234', $this->db);

        $this->assertThrows(
            ApiException::class,
            fn (): array => Auth::register('wedkarz', 'b@test.local', 'haslo1234', $this->db),
            'Zajęty login powinien być odrzucony',
            'login_taken'
        );

        $this->assertSame(1, (int) $this->db->query('SELECT COUNT(*) FROM uzytkownicy')->fetchColumn(), 'Nie może powstać drugie konto');
    }

    public function testRejestracjaOdrzucaZajetyEmail(): void
    {
        Auth::register('pierwszy', 'Ten.Sam@Test.local', 'haslo1234', $this->db);

        // Ten sam e-mail zapisany inaczej — normalizacja ma to złapać.
        $this->assertThrows(
            ApiException::class,
            fn (): array => Auth::register('drugi', 'ten.sam@test.local', 'haslo1234', $this->db),
            'Zajęty e-mail powinien być odrzucony niezależnie od wielkości liter',
            'email_taken'
        );
    }

    public function testRejestracjaWalidujeDaneWejsciowe(): void
    {
        $this->assertThrows(
            ApiException::class,
            fn (): array => Auth::register('ab', 'ok@test.local', 'haslo1234', $this->db),
            'Za krótki login powinien być odrzucony',
            'login_length'
        );

        $this->assertThrows(
            ApiException::class,
            fn (): array => Auth::register('login ze spacjami', 'ok@test.local', 'haslo1234', $this->db),
            'Login ze spacjami powinien być odrzucony',
            'login_charset'
        );

        $this->assertThrows(
            ApiException::class,
            fn (): array => Auth::register('ok_login', 'to-nie-email', 'haslo1234', $this->db),
            'Nieprawidłowy e-mail powinien być odrzucony',
            'email_invalid'
        );

        $this->assertThrows(
            ApiException::class,
            fn (): array => Auth::register('ok_login', 'ok@test.local', 'krotkie', $this->db),
            'Za krótkie hasło powinno być odrzucone',
            'password_too_short'
        );

        $this->assertSame(0, (int) $this->db->query('SELECT COUNT(*) FROM uzytkownicy')->fetchColumn(), 'Żadna z odrzuconych rejestracji nie może utworzyć konta');
    }

    public function testLogowanieZwracaToken(): void
    {
        Auth::register('wedkarz', 'w@test.local', 'haslo1234', $this->db);

        $wynik = Auth::login('wedkarz', 'haslo1234', $this->db);

        $this->assertSame('Bearer', $wynik['token_type'], 'Typ tokena');
        $this->assertSame(64, strlen($wynik['token']), 'Token to 32 bajty z random_bytes() zapisane jako hex');
        $this->assertTrue(ctype_xdigit($wynik['token']), 'Token musi być wyłącznie szesnastkowy');
        $this->assertTrue($wynik['expires_at'] !== '', 'Data ważności tokena musi być ustawiona');
        $this->assertSame('wedkarz', $wynik['user']['login'], 'Dane gracza w odpowiedzi logowania');
        $this->assertFalse(isset($wynik['user']['haslo_hash']), 'Hash nie może iść do frontendu');
        $this->assertSame(1, (int) $this->db->query('SELECT COUNT(*) FROM tokeny')->fetchColumn(), 'Token musi zostać zapisany w bazie');
    }

    public function testLogowanieDzialaTEmailem(): void
    {
        Auth::register('wedkarz', 'W@Test.local', 'haslo1234', $this->db);

        $wynik = Auth::login('w@test.local', 'haslo1234', $this->db);

        $this->assertSame('wedkarz', $wynik['user']['login'], 'Logowanie e-mailem musi trafić w to samo konto');
    }

    /**
     * Komunikat musi być identyczny dla złego loginu i złego hasła.
     * Inaczej atakujący mógłby enumerate'ować, które konta istnieją.
     */
    public function testKomunikatBleduNieZdradzaIstnieniaKonta(): void
    {
        Auth::register('istniejacy', 'i@test.local', 'haslo1234', $this->db);

        $zleHaslo = $this->zlapBlad(fn (): array => Auth::login('istniejacy', 'zlehaslo99', $this->db));
        $zlyLogin = $this->zlapBlad(fn (): array => Auth::login('nieistniejacy', 'zlehaslo99', $this->db));

        $this->assertTrue($zleHaslo !== 'BRAK_WYJATKU', 'Złe hasło musi zostać odrzucone');
        $this->assertSame($zleHaslo, $zlyLogin, 'Błąd logowania musi być nieodróżnialny dla istniejącego i nieistniejącego konta');
        $this->assertTrue(str_starts_with($zleHaslo, 'invalid_credentials|'), 'Oba przypadki muszą używać kodu invalid_credentials');
    }

    public function testKazdeLogowanieDajeInnyToken(): void
    {
        Auth::register('wedkarz', 'w@test.local', 'haslo1234', $this->db);

        $pierwszy = Auth::login('wedkarz', 'haslo1234', $this->db)['token'];
        $drugi = Auth::login('wedkarz', 'haslo1234', $this->db)['token'];

        $this->assertTrue($pierwszy !== $drugi, 'Tokeny z dwóch logowań muszą się różnić — inaczej dałoby się je odgadnąć');
        $this->assertSame(2, (int) $this->db->query('SELECT COUNT(*) FROM tokeny')->fetchColumn(), 'Obie sesje są ważne równolegle');
    }

    public function testTokenUwierzytelniaGracza(): void
    {
        Auth::register('wedkarz', 'w@test.local', 'haslo1234', $this->db);
        $token = Auth::login('wedkarz', 'haslo1234', $this->db)['token'];

        $user = Auth::authenticate($token, $this->db);

        $this->assertNotNull($user, 'Poprawny token musi uwierzytelnić gracza');
        $this->assertSame('wedkarz', $user['login'], 'Token musi wskazywać właściwe konto');
        $this->assertNull(Auth::authenticate('nieprawdziwy-token', $this->db), 'Nieistniejący token musi być odrzucony');
        $this->assertNull(Auth::authenticate(null, $this->db), 'Brak tokena musi być odrzucony');
        $this->assertNull(Auth::authenticate('', $this->db), 'Pusty token musi być odrzucony');
    }

    public function testWylogowanieUniewazniaToken(): void
    {
        Auth::register('wedkarz', 'w@test.local', 'haslo1234', $this->db);
        $token = Auth::login('wedkarz', 'haslo1234', $this->db)['token'];

        Auth::logout($token, $this->db);

        $this->assertNull(Auth::authenticate($token, $this->db), 'Po wylogowaniu token nie może już działać');
        $this->assertSame(0, (int) $this->db->query('SELECT COUNT(*) FROM tokeny')->fetchColumn(), 'Token musi zostać skasowany z bazy');
    }

    public function testWygaslyTokenNieDziala(): void
    {
        $user = Fixtures::user($this->db, 'wedkarz', 100.0);
        $staryToken = str_repeat('a', 64);

        $this->db->prepare('INSERT INTO tokeny (user_id, token, expires_at) VALUES (?, ?, ?)')
            ->execute([$user['id'], $staryToken, '2000-01-01 00:00:00']);

        $this->assertNull(Auth::authenticate($staryToken, $this->db), 'Token z przeszłą datą ważności musi być odrzucony');
    }

    public function testZablokowaneKontoNieMozeSieZalogowac(): void
    {
        Auth::register('wedkarz', 'w@test.local', 'haslo1234', $this->db);

        $this->db->exec('UPDATE uzytkownicy SET czy_zablokowany = 1');

        $this->assertThrows(
            ApiException::class,
            fn (): array => Auth::login('wedkarz', 'haslo1234', $this->db),
            'Zablokowane konto nie może się zalogować',
            'account_blocked'
        );
    }

    /** Blokada musi działać natychmiast, także na już wydane tokeny. */
    public function testBlokadaUniewazniaWczesniejWydanyToken(): void
    {
        Auth::register('wedkarz', 'w@test.local', 'haslo1234', $this->db);
        $token = Auth::login('wedkarz', 'haslo1234', $this->db)['token'];

        $this->assertNotNull(Auth::authenticate($token, $this->db), 'Przed blokadą token działa');

        $this->db->exec('UPDATE uzytkownicy SET czy_zablokowany = 1');

        $this->assertNull(Auth::authenticate($token, $this->db), 'Po blokadzie wcześniej wydany token musi przestać działać');
    }

    /**
     * Apache w konfiguracji CGI/FastCGI potrafi wyciąć nagłówek Authorization.
     * Bez tych wariantów logowanie "działa lokalnie, nie działa na hostingu".
     */
    public function testBearerTokenJestWyciaganyZNaglowka(): void
    {
        $oryginal = $_SERVER['HTTP_AUTHORIZATION'] ?? null;

        try {
            $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer abc123';
            $this->assertSame('abc123', Auth::bearerToken(), 'Standardowy nagłówek Bearer');

            $_SERVER['HTTP_AUTHORIZATION'] = '  bearer   abc123  ';
            $this->assertSame('abc123', Auth::bearerToken(), 'Wielkość liter i odstępy nie mogą mieć znaczenia');

            $_SERVER['HTTP_AUTHORIZATION'] = 'Basic abc123';
            $this->assertNull(Auth::bearerToken(), 'Inny schemat autoryzacji musi być odrzucony');

            $_SERVER['HTTP_AUTHORIZATION'] = 'abc123';
            $this->assertNull(Auth::bearerToken(), 'Token bez prefiksu Bearer musi być odrzucony');

            $_SERVER['HTTP_AUTHORIZATION'] = '';
            $this->assertNull(Auth::bearerToken(), 'Pusty nagłówek musi być odrzucony');
        } finally {
            // $_SERVER jest globalny — po teście musi wrócić do stanu sprzed niego,
            // inaczej kolejne testy widziałyby cudzy nagłówek.
            if ($oryginal === null) {
                unset($_SERVER['HTTP_AUTHORIZATION']);
            } else {
                $_SERVER['HTTP_AUTHORIZATION'] = $oryginal;
            }
        }
    }

    /** Zwraca "kod|komunikat" albo znacznik braku wyjątku. */
    private function zlapBlad(callable $callback): string
    {
        try {
            $callback();
        } catch (ApiException $e) {
            return $e->errorCode() . '|' . $e->getMessage();
        }

        return 'BRAK_WYJATKU';
    }
}
