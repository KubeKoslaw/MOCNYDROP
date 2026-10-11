<?php

declare(strict_types=1);

namespace MocnyDrop\Tests;

use MocnyDrop\ApiException;
use MocnyDrop\Token;
use MocnyDrop\Uzytkownik;
use MocnyDrop\Portfel;

/**
 * tests/UzytkownikTest.php
 *
 * Testy rejestracji, logowania i autoryzacji tokenem Bearer.
 *
 * Właściciel: @KubeKoslaw (Core Backend)
 */
final class UzytkownikTest extends TestCase
{
    public function testRejestracjaTworzyKontoZSaldemStartowym(): void
    {
        $user = $this->zarejestruj('wedkarz', 'Wedkarz@Test.local', 'haslo1234');

        $this->assertSame('wedkarz', $user['login'], 'Login bez zmian');
        $this->assertSame('wedkarz@test.local', $user['email'], 'E-mail musi być normalizowany do małych liter');
        $this->assertSame('user', $user['rola'], 'Nowe konto nie może być kontem administratora');
        $this->assertFloat(50.0, (float) $user['saldo'], 'Saldo startowe z ustawień bazy (saldo_startowe)');
        $this->assertFalse($user['zablokowany'], 'Nowe konto nie jest zablokowane');
        $this->assertFloat(50.0, Portfel::dlaUzytkownika($user['id'], $this->db)->saldo, 'Saldo musi być faktycznie zapisane w bazie');
    }

    /** Odpowiedź API nigdy nie może zawierać hasha hasła. */
    public function testHashHaslaNieWychodziNaZewnatrz(): void
    {
        $user = $this->zarejestruj('wedkarz', 'w@test.local', 'haslo1234');

        $publiczne = Uzytkownik::znajdz($user['id'], $this->db)->doPublicznej($this->db);

        $this->assertFalse(isset($publiczne['haslo_hash']), 'Wersja publiczna nie może zawierać hasha');
        $this->assertSame(
            ['id', 'login', 'email', 'rola', 'saldo', 'waluta'],
            array_keys($publiczne),
            'Dokładna lista pól publicznych (waluta dochodzi z Portfela)'
        );

        $hash = (string) $this->db->query('SELECT haslo_hash FROM uzytkownicy')->fetchColumn();

        $this->assertTrue($hash !== 'haslo1234', 'Hasło nie może leżeć w bazie w postaci jawnej');
        $this->assertTrue(strlen($hash) >= 60, 'Hash musi wyglądać na wynik password_hash()');
        $this->assertTrue(password_verify('haslo1234', $hash), 'Hash musi dać się zweryfikować poprawnym hasłem');
        $this->assertFalse(password_verify('zlehaslo99', $hash), 'Hash nie może przechodzić dla złego hasła');
    }

    public function testRejestracjaOdrzucaZajetyLogin(): void
    {
        $this->zarejestruj('wedkarz', 'a@test.local', 'haslo1234');

        $this->assertThrows(
            ApiException::class,
            fn (): array => $this->zarejestruj('wedkarz', 'b@test.local', 'haslo1234'),
            'Zajęty login powinien być odrzucony',
            'login_taken'
        );

        $this->assertSame(1, (int) $this->db->query('SELECT COUNT(*) FROM uzytkownicy')->fetchColumn(), 'Nie może powstać drugie konto');
    }

    public function testRejestracjaOdrzucaZajetyEmail(): void
    {
        $this->zarejestruj('pierwszy', 'Ten.Sam@Test.local', 'haslo1234');

        // Ten sam e-mail zapisany inaczej — normalizacja ma to złapać.
        $this->assertThrows(
            ApiException::class,
            fn (): array => $this->zarejestruj('drugi', 'ten.sam@test.local', 'haslo1234'),
            'Zajęty e-mail powinien być odrzucony niezależnie od wielkości liter',
            'email_taken'
        );
    }

    public function testRejestracjaWalidujeDaneWejsciowe(): void
    {
        $this->assertThrows(
            ApiException::class,
            fn (): array => $this->zarejestruj('ab', 'ok@test.local', 'haslo1234'),
            'Za krótki login powinien być odrzucony',
            'login_length'
        );

        $this->assertThrows(
            ApiException::class,
            fn (): array => $this->zarejestruj('login ze spacjami', 'ok@test.local', 'haslo1234'),
            'Login ze spacjami powinien być odrzucony',
            'login_charset'
        );

        $this->assertThrows(
            ApiException::class,
            fn (): array => $this->zarejestruj('ok_login', 'to-nie-email', 'haslo1234'),
            'Nieprawidłowy e-mail powinien być odrzucony',
            'email_invalid'
        );

        $this->assertThrows(
            ApiException::class,
            fn (): array => $this->zarejestruj('ok_login', 'ok@test.local', 'krotkie'),
            'Za krótkie hasło powinno być odrzucone',
            'password_too_short'
        );

        $this->assertSame(0, (int) $this->db->query('SELECT COUNT(*) FROM uzytkownicy')->fetchColumn(), 'Żadna z odrzuconych rejestracji nie może utworzyć konta');
    }

    public function testLogowanieZwracaToken(): void
    {
        $this->zarejestruj('wedkarz', 'w@test.local', 'haslo1234');

        $wynik = Token::zaloguj('wedkarz', 'haslo1234', $this->db);

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
        $this->zarejestruj('wedkarz', 'W@Test.local', 'haslo1234');

        $wynik = Token::zaloguj('w@test.local', 'haslo1234', $this->db);

        $this->assertSame('wedkarz', $wynik['user']['login'], 'Logowanie e-mailem musi trafić w to samo konto');
    }

    /**
     * Komunikat musi być identyczny dla złego loginu i złego hasła.
     * Inaczej atakujący mógłby enumerate'ować, które konta istnieją.
     */
    public function testKomunikatBleduNieZdradzaIstnieniaKonta(): void
    {
        $this->zarejestruj('istniejacy', 'i@test.local', 'haslo1234');

        $zleHaslo = $this->zlapBlad(fn (): array => Token::zaloguj('istniejacy', 'zlehaslo99', $this->db));
        $zlyLogin = $this->zlapBlad(fn (): array => Token::zaloguj('nieistniejacy', 'zlehaslo99', $this->db));

        $this->assertTrue($zleHaslo !== 'BRAK_WYJATKU', 'Złe hasło musi zostać odrzucone');
        $this->assertSame($zleHaslo, $zlyLogin, 'Błąd logowania musi być nieodróżnialny dla istniejącego i nieistniejącego konta');
        $this->assertTrue(str_starts_with($zleHaslo, 'invalid_credentials|'), 'Oba przypadki muszą używać kodu invalid_credentials');
    }

    public function testKazdeLogowanieDajeInnyToken(): void
    {
        $this->zarejestruj('wedkarz', 'w@test.local', 'haslo1234');

        $pierwszy = Token::zaloguj('wedkarz', 'haslo1234', $this->db)['token'];
        $drugi = Token::zaloguj('wedkarz', 'haslo1234', $this->db)['token'];

        $this->assertTrue($pierwszy !== $drugi, 'Tokeny z dwóch logowań muszą się różnić — inaczej dałoby się je odgadnąć');
        $this->assertSame(2, (int) $this->db->query('SELECT COUNT(*) FROM tokeny')->fetchColumn(), 'Obie sesje są ważne równolegle');
    }

    public function testTokenUwierzytelniaGracza(): void
    {
        $this->zarejestruj('wedkarz', 'w@test.local', 'haslo1234');
        $token = Token::zaloguj('wedkarz', 'haslo1234', $this->db)['token'];

        $user = Token::uwierzytelnij($token, $this->db);

        $this->assertNotNull($user, 'Poprawny token musi uwierzytelnić gracza');
        $this->assertSame('wedkarz', $user->login, 'Token musi wskazywać właściwe konto');
        $this->assertNull(Token::uwierzytelnij('nieprawdziwy-token', $this->db), 'Nieistniejący token musi być odrzucony');
        $this->assertNull(Token::uwierzytelnij(null, $this->db), 'Brak tokena musi być odrzucony');
        $this->assertNull(Token::uwierzytelnij('', $this->db), 'Pusty token musi być odrzucony');
    }

    public function testWylogowanieUniewazniaToken(): void
    {
        $this->zarejestruj('wedkarz', 'w@test.local', 'haslo1234');
        $token = Token::zaloguj('wedkarz', 'haslo1234', $this->db)['token'];

        Token::zBazy($token, $this->db)?->uniewaznij();

        $this->assertNull(Token::uwierzytelnij($token, $this->db), 'Po wylogowaniu token nie może już działać');
        $this->assertSame(0, (int) $this->db->query('SELECT COUNT(*) FROM tokeny')->fetchColumn(), 'Token musi zostać skasowany z bazy');
    }

    public function testWygaslyTokenNieDziala(): void
    {
        $user = Fixtures::user($this->db, 'wedkarz', 100.0);
        $staryToken = str_repeat('a', 64);

        $this->db->prepare('INSERT INTO tokeny (user_id, token, expires_at) VALUES (?, ?, ?)')
            ->execute([$user['id'], $staryToken, '2000-01-01 00:00:00']);

        $this->assertNull(Token::uwierzytelnij($staryToken, $this->db), 'Token z przeszłą datą ważności musi być odrzucony');
    }

    public function testZablokowaneKontoNieMozeSieZalogowac(): void
    {
        $this->zarejestruj('wedkarz', 'w@test.local', 'haslo1234');

        $this->db->exec('UPDATE uzytkownicy SET zablokowany = 1');

        $this->assertThrows(
            ApiException::class,
            fn (): array => Token::zaloguj('wedkarz', 'haslo1234', $this->db),
            'Zablokowane konto nie może się zalogować',
            'account_blocked'
        );
    }

    /** Blokada musi działać natychmiast, także na już wydane tokeny. */
    public function testBlokadaUniewazniaWczesniejWydanyToken(): void
    {
        $this->zarejestruj('wedkarz', 'w@test.local', 'haslo1234');
        $token = Token::zaloguj('wedkarz', 'haslo1234', $this->db)['token'];

        $this->assertNotNull(Token::uwierzytelnij($token, $this->db), 'Przed blokadą token działa');

        $this->db->exec('UPDATE uzytkownicy SET zablokowany = 1');

        $this->assertNull(Token::uwierzytelnij($token, $this->db), 'Po blokadzie wcześniej wydany token musi przestać działać');
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
            $this->assertSame('abc123', Token::bearerToken(), 'Standardowy nagłówek Bearer');

            $_SERVER['HTTP_AUTHORIZATION'] = '  bearer   abc123  ';
            $this->assertSame('abc123', Token::bearerToken(), 'Wielkość liter i odstępy nie mogą mieć znaczenia');

            $_SERVER['HTTP_AUTHORIZATION'] = 'Basic abc123';
            $this->assertNull(Token::bearerToken(), 'Inny schemat autoryzacji musi być odrzucony');

            $_SERVER['HTTP_AUTHORIZATION'] = 'abc123';
            $this->assertNull(Token::bearerToken(), 'Token bez prefiksu Bearer musi być odrzucony');

            $_SERVER['HTTP_AUTHORIZATION'] = '';
            $this->assertNull(Token::bearerToken(), 'Pusty nagłówek musi być odrzucony');
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

    /**
     * Rejestracja przez klasy z diagramu: Uzytkownik::utworz() + zarejestruj().
     *
     * Zwraca kształt, jakim posługują się testy: pola publiczne gracza
     * (Uzytkownik::doPublicznej) poszerzone o `zablokowany`, którego API
     * celowo nie wystawia.
     *
     * @return array<string, mixed>
     */
    private function zarejestruj(string $login, string $email, string $haslo): array
    {
        $uzytkownik = Uzytkownik::utworz($login, $email, $haslo, $this->db);
        $uzytkownik->zarejestruj();

        return $uzytkownik->doPublicznej($this->db) + [
            'zablokowany' => $uzytkownik->czyZablokowany(),
        ];
    }
}
