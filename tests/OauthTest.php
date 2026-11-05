<?php

declare(strict_types=1);

namespace MocnyDrop\Tests;

use MocnyDrop\Administrator;
use MocnyDrop\ApiException;
use MocnyDrop\OauthKod;
use MocnyDrop\Settings;
use MocnyDrop\Token;
use MocnyDrop\Uzytkownik;

final class OauthTest extends TestCase
{
    private function konto(string $login = 'gracz'): array
    {
        return Fixtures::user($this->db, $login, 100.0);
    }

    public function testKodMaTtlZWartosciZUstawien(): void
    {
        $konto = $this->konto();

        $kod = OauthKod::wystaw($konto['id'], $this->db);

        $this->assertFalse($kod->czyWygasl(), 'Świeży kod nie może być wygasły');
        $this->assertTrue($kod->expiresAt !== '', 'Kod ma datę ważności');
        $this->assertBetween(295.0, 300.0, (float) (strtotime($kod->expiresAt) - time()), 'TTL kodu wynosi 300 s (schema.sql)');
        $this->assertSame($konto['id'], $kod->userId, 'Kod wskazuje właściciela');
        $this->assertFalse($kod->czyZuzyty, 'Świeży kod nie jest zużyty');
        $this->assertSame(1, (int) $this->db->query('SELECT COUNT(*) FROM oauth_kody')->fetchColumn(), 'Kod zapisany w oauth_kody');
    }

    public function testTtlKoduPochodziZUstawien(): void
    {
        $konto = $this->konto();
        Fixtures::setting($this->db, Settings::KEY_OAUTH_CODE_SECONDS, '60');

        $kod = OauthKod::wystaw($konto['id'], $this->db);

        $this->assertBetween(55.0, 60.0, (float) (strtotime($kod->expiresAt) - time()), 'TTL kodu czytany z ustawień, nie z kodu');
    }

    public function testWymianaKoduTworzyAccessIRefresh(): void
    {
        $konto = $this->konto();
        $kod = OauthKod::wystaw($konto['id'], $this->db);

        $uzytkownikId = $kod->wymien();

        $this->assertSame($konto['id'], $uzytkownikId, 'wymien() zwraca id właściciela kodu');
        $this->assertSame(1, (int) $this->db->query('SELECT czy_zuzyty FROM oauth_kody WHERE id = ' . $kod->id)->fetchColumn(), 'Kod został oznaczony jako zużyty');

        $token = Token::wystaw(Uzytkownik::znajdz($uzytkownikId, $this->db), $this->db);
        $this->assertSame(64, strlen($token->token), 'Access token to 32 bajty random_bytes() w hex');
        $this->assertSame(64, strlen($token->refreshToken), 'Refresh token to 32 bajty random_bytes() w hex');
        $this->assertTrue(ctype_xdigit($token->token), 'Access token musi być szesnastkowy');
        $this->assertTrue($token->token !== $token->refreshToken, 'Access i refresh muszą się różnić');
        $this->assertSame(1, (int) $this->db->query('SELECT COUNT(*) FROM oauth_tokeny')->fetchColumn(), 'Para tokenów zapisana w oauth_tokeny');
    }

    public function testDoApiZwracaKontraktTokena(): void
    {
        $konto = $this->konto();
        $token = Token::wystaw(Uzytkownik::znajdz($konto['id'], $this->db), $this->db);

        $api = $token->doApi();

        $this->assertSame(
            ['access_token', 'token_type', 'expires_in', 'refresh_token', 'refresh_expires_in', 'expires_at'],
            array_keys($api),
            'Dokładny kontrakt odpowiedzi api/token.php'
        );
        $this->assertSame('Bearer', $api['token_type'], 'Typ tokena');
        $this->assertSame(3600, $api['expires_in'], 'Access TTL 3600 s z ustawień');
        $this->assertSame(30 * 86400, $api['refresh_expires_in'], 'Refresh TTL 30 dni z ustawień');
        $this->assertSame($token->token, $api['access_token'], 'Access token w odpowiedzi');
        $this->assertSame($token->expiresAt, $api['expires_at'], 'Data wygaśnięcia access tokena w odpowiedzi');
    }

    public function testKodJednorazowy(): void
    {
        $konto = $this->konto();
        $kod = OauthKod::wystaw($konto['id'], $this->db);

        OauthKod::znajdz($kod->kod, $this->db)->wymien();

        $this->assertThrows(
            ApiException::class,
            fn (): int => OauthKod::znajdz($kod->kod, $this->db)->wymien(),
            'Ten sam kod nie może być wymieniony drugi raz',
            'invalid_grant'
        );
    }

    public function testDrugiRazTenSamKod(): void
    {
        $konto = $this->konto();
        $kod = OauthKod::wystaw($konto['id'], $this->db);

        $kod->wymien();

        $this->assertThrows(
            ApiException::class,
            fn (): int => $kod->wymien(),
            'Ten sam obiekt kodu nie może być wymieniony ponownie',
            'invalid_grant'
        );
    }

    public function testWygaslyKodJestOdrzucany(): void
    {
        $konto = $this->konto();
        $kod = OauthKod::wystaw($konto['id'], $this->db);

        $this->db->prepare("UPDATE oauth_kody SET expires_at = '2000-01-01 00:00:00' WHERE id = ?")
            ->execute([$kod->id]);

        $zBazy = OauthKod::znajdz($kod->kod, $this->db);

        $this->assertNotNull($zBazy, 'Wygasły kod nadal da się odczytać z bazy');
        $this->assertTrue($zBazy->czyWygasl(), 'czyWygasl() musi wykryć przeszłą datę');
        $this->assertThrows(
            ApiException::class,
            fn (): int => $zBazy->wymien(),
            'Wygasły kod nie może być wymieniony',
            'invalid_grant'
        );
    }

    public function testNieistniejacyKod(): void
    {
        $this->assertNull(OauthKod::znajdz('nie-ma-takiego-kodu', $this->db), 'Nieistniejący kod zwraca null');
        $this->assertNull(OauthKod::znajdz('', $this->db), 'Pusty kod zwraca null');
    }

    public function testRefreshDajeNowyAccessAStaryTokenPrzestajeDzialac(): void
    {
        $konto = $this->konto();
        $user = Uzytkownik::znajdz($konto['id'], $this->db);
        $token = Token::wystaw($user, $this->db);

        $this->assertNotNull(Token::uwierzytelnij($token->token, $this->db), 'Przed odświeżeniem access token działa');

        $nowy = Token::odswiez($token->refreshToken, $this->db);

        $this->assertTrue($nowy->token !== $token->token, 'Refresh musi wydać NOWY access token');
        $this->assertSame(1, (int) $this->db->query('SELECT COUNT(*) FROM oauth_tokeny')->fetchColumn(), 'Odświeżenie aktualizuje wiersz, nie tworzy nowego');
        $this->assertNull(Token::uwierzytelnij($token->token, $this->db), 'Stary access token przestaje działać');
        $this->assertNotNull(Token::uwierzytelnij($nowy->token, $this->db), 'Nowy access token działa');
    }

    public function testRefreshNieZmieniaRefreshTokena(): void
    {
        $konto = $this->konto();
        $token = Token::wystaw(Uzytkownik::znajdz($konto['id'], $this->db), $this->db);

        $nowy = Token::odswiez($token->refreshToken, $this->db);

        $this->assertSame($token->refreshToken, $nowy->refreshToken, 'Refresh token pozostaje ten sam (bez rotacji)');
        $this->assertSame($token->refreshExpiresAt, $nowy->refreshExpiresAt, 'Ważność refresh tokena bez zmian');
        $this->assertSame($konto['id'], $nowy->uzytkownikId, 'Nowy token wskazuje to samo konto');
    }

    public function testWygaslyRefreshTokenJestOdrzucany(): void
    {
        $konto = $this->konto();
        $token = Token::wystaw(Uzytkownik::znajdz($konto['id'], $this->db), $this->db);

        $this->db->prepare("UPDATE oauth_tokeny SET refresh_expires_at = '2000-01-01 00:00:00' WHERE id = ?")
            ->execute([$token->id]);

        $this->assertThrows(
            ApiException::class,
            fn (): Token => Token::odswiez($token->refreshToken, $this->db),
            'Wygasły refresh token nie może odświeżyć sesji',
            'invalid_grant'
        );
    }

    public function testNieistniejacyRefreshTokenJestOdrzucany(): void
    {
        $this->assertThrows(
            ApiException::class,
            fn (): Token => Token::odswiez('nieprawdziwy-refresh', $this->db),
            'Nieistniejący refresh token musi być odrzucony',
            'invalid_grant'
        );

        $this->assertThrows(
            ApiException::class,
            fn (): Token => Token::odswiez('', $this->db),
            'Pusty refresh token musi być odrzucony',
            'invalid_grant'
        );
    }

    public function testLogoutUniewazniaAccessIRefresh(): void
    {
        $konto = $this->konto();
        $token = Token::wystaw(Uzytkownik::znajdz($konto['id'], $this->db), $this->db);

        Token::zBazy($token->token, $this->db)?->uniewaznij();

        $this->assertNull(Token::uwierzytelnij($token->token, $this->db), 'Po logout access token nie działa');
        $this->assertSame(0, (int) $this->db->query('SELECT COUNT(*) FROM oauth_tokeny')->fetchColumn(), 'Wiersz access+refresh skasowany');
        $this->assertThrows(
            ApiException::class,
            fn (): Token => Token::odswiez($token->refreshToken, $this->db),
            'Po logout refresh token też nie działa',
            'invalid_grant'
        );
    }

    public function testBanUsuwaKodyITokeny(): void
    {
        $adminKonto = Fixtures::user($this->db, 'szef', 0.0, 'admin');
        $konto = $this->konto();
        $admin = Administrator::zUzytkownika(Uzytkownik::znajdz($adminKonto['id'], $this->db), $this->db);
        $gracz = Uzytkownik::znajdz($konto['id'], $this->db);

        Token::wystaw($gracz, $this->db);
        OauthKod::wystaw($gracz->id, $this->db);

        $admin->zbanuj($gracz);

        $this->assertSame(0, (int) $this->db->query('SELECT COUNT(*) FROM oauth_tokeny')->fetchColumn(), 'Ban usuwa sesje OAuth (access + refresh)');
        $this->assertSame(0, (int) $this->db->query('SELECT COUNT(*) FROM oauth_kody')->fetchColumn(), 'Ban usuwa kody autoryzacyjne');
    }

    public function testWymagajZalogowaniaDzialaPoWymianie(): void
    {
        $konto = $this->konto();
        $kod = OauthKod::wystaw($konto['id'], $this->db);
        $token = Token::wystaw(Uzytkownik::znajdz($kod->wymien(), $this->db), $this->db);

        $oryginal = $_SERVER['HTTP_AUTHORIZATION'] ?? null;

        try {
            $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token->token;
            $zalogowany = Token::wymagajZalogowania($this->db);

            $this->assertSame($konto['id'], $zalogowany->id, 'Bearer z tokena OAuth uwierzytelnia gracza');

            unset($_SERVER['HTTP_AUTHORIZATION']);

            $this->assertThrows(
                ApiException::class,
                fn (): Uzytkownik => Token::wymagajZalogowania($this->db),
                'Brak Bearer musi dać 401',
                'unauthorized'
            );
        } finally {
            if ($oryginal === null) {
                unset($_SERVER['HTTP_AUTHORIZATION']);
            } else {
                $_SERVER['HTTP_AUTHORIZATION'] = $oryginal;
            }
        }
    }

    public function testTokenPoWygasnieciuJestOdrzucany(): void
    {
        $konto = $this->konto();
        $token = Token::wystaw(Uzytkownik::znajdz($konto['id'], $this->db), $this->db);

        $this->db->prepare("UPDATE oauth_tokeny SET expires_at = '2000-01-01 00:00:00' WHERE id = ?")
            ->execute([$token->id]);

        $this->assertNull(Token::uwierzytelnij($token->token, $this->db), 'Access token po wygaśnięciu jest odrzucany');
        $this->assertTrue(Token::odswiez($token->refreshToken, $this->db)->token !== $token->token, 'Refresh nadal odświeża wygasły access token');
    }

    public function testBrakGrantTypeDajeUnsupportedGrantType(): void
    {
        $wynik = $this->uruchomApiTokenZPustymCialem();

        $this->assertTrue(
            str_contains($wynik, 'unsupported_grant_type'),
            'api/token.php bez grant_type musi odpowiedzieć unsupported_grant_type (jest: ' . trim($wynik) . ')'
        );
    }

    private function uruchomApiTokenZPustymCialem(): string
    {
        $sciezka = tempnam(sys_get_temp_dir(), 'mocnydrop-oauth-');

        if ($sciezka === false) {
            return '';
        }

        $skrypt = "<?php\n"
            . '$_SERVER["REQUEST_METHOD"] = "POST";' . "\n"
            . '$_SERVER["CONTENT_TYPE"] = "application/json";' . "\n"
            . '$_SERVER["HTTP_ACCEPT"] = "application/json";' . "\n"
            . 'require ' . var_export(dirname(__DIR__) . '/api/token.php', true) . ";\n";

        file_put_contents($sciezka, $skrypt);

        try {
            $deskryptory = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
            $proces = proc_open([PHP_BINARY, $sciezka], $deskryptory, $potoki);

            if (!is_resource($proces)) {
                return '';
            }

            $stdout = (string) stream_get_contents($potoki[1]);
            stream_get_contents($potoki[2]);

            fclose($potoki[1]);
            fclose($potoki[2]);
            proc_close($proces);

            return $stdout;
        } finally {
            @unlink($sciezka);
        }
    }
}
