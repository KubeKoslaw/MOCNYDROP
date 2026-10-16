<?php

declare(strict_types=1);

namespace MocnyDrop\Tests;

use MocnyDrop\Administrator;
use MocnyDrop\Ekwipunek;
use MocnyDrop\ILosujacy;
use MocnyDrop\LogAudytowy;
use MocnyDrop\Losowanie;
use MocnyDrop\Portfel;
use MocnyDrop\Przedmiot;
use MocnyDrop\Rola;
use MocnyDrop\Rzadkosc;
use MocnyDrop\Skrzynka;
use MocnyDrop\SkrzynkaPrzedmiot;
use MocnyDrop\Token;
use MocnyDrop\TypLosowania;
use MocnyDrop\Uzytkownik;
use MocnyDrop\Wedka;
use ReflectionClass;
use ReflectionNamedType;

/**
 * tests/DiagramKlasTest.php
 *
 * Test zgodności STRUKTURALNEJ kodu z MOCNYDROP_diagram_klas.drawio (wersja
 * z gałęzi main; zmiana v2: Skrzynka zwraca Wedka i nie realizuje starego
 * ILosujacy, który jest zachowany dla historycznych SkrzynkaPrzedmiot).
 *
 * Sprawdza przez refleksję, że:
 *   - każda klasa z diagramu istnieje w backend/,
 *   - każda metoda z diagramu istnieje i jest publiczna,
 *   - atrybuty z diagramu istnieją jako pola (nazwy jak w diagramie),
 *   - generalizacja Administrator —|> Uzytkownik jest dziedziczeniem,
 *   - interfejs «Losowanie» (ILosujacy) ma metodę losuj(): Przedmiot,
 *   - typy atrybutów z diagramu (rola: Rola, typ: TypLosowania,
 *     wynik: Przedmiot, rzadkosc: Rzadkosc) są prawdziwymi typami PHP.
 *
 * Gdy ktoś usunie albo przemianuje element diagramu w kodzie, ten test się
 * wysypie i powie wprost, której klasy/metody brakuje.
 *
 * Udokumentowany wyjątek: Ekwipunek. Diagram rysuje go z atrybutami wpisu
 * (id, data_nabycia, sprzedany), ale metody (dodaj/usun/sprzedaj/
 * wartoscRynkowa) przyjmują Przedmiot, czyli działają na zbiorze. W kodzie
 * Ekwipunek jest trzymany per gracz, a atrybuty wpisu wracają z lista() —
 * ich obecność sprawdza EkwipunekTest.
 *
 * Właściciel: @KubeKoslaw (Core Backend)
 */
final class DiagramKlasTest extends TestCase
{
    /**
     * Klasa z diagramu => metody publiczne z diagramu.
     *
     * @var array<class-string, list<string>>
     */
    private const KLASY = [
        Uzytkownik::class => ['zaloguj', 'zarejestruj', 'czyZablokowany'],
        Administrator::class => ['zbanuj', 'korygujSaldo', 'edytujSkrzynke', 'eksportujRaport'],
        Portfel::class => ['czyStac', 'pobierzOplate', 'dodajSrodki'],
        Token::class => ['weryfikuj', 'uniewaznij'],
        Przedmiot::class => ['obliczWartosc', 'opis'],
        Rzadkosc::class => [],
        Ekwipunek::class => ['dodaj', 'usun', 'sprzedaj', 'wartoscRynkowa'],
        Skrzynka::class => ['otworz', 'losuj', 'czyAktywna'],
        SkrzynkaPrzedmiot::class => ['losuj'],
        Wedka::class => ['zarzuc'],
        Losowanie::class => ['zapisz', 'wyliczRTP'],
        LogAudytowy::class => ['zapisz'],
    ];

    /**
     * Atrybuty z diagramu => pole w klasie.
     *
     * @var array<class-string, list<string>>
     */
    private const ATRYBUTY = [
        Uzytkownik::class => ['id', 'login', 'email', 'hasloHash', 'zablokowany', 'rola'],
        Portfel::class => ['id', 'saldo', 'waluta'],
        Token::class => ['token', 'expiresAt'],
        Przedmiot::class => ['id', 'nazwa', 'wartosc', 'rzadkosc'],
        Rzadkosc::class => ['nazwa', 'mnoznik'],
        Skrzynka::class => ['id', 'nazwa', 'cena', 'ikona', 'aktywna'],
        SkrzynkaPrzedmiot::class => ['id', 'waga'],
        Wedka::class => ['id', 'nazwa', 'kosztUzycia'],
        Losowanie::class => ['id', 'czas', 'typ', 'wynik'],
        LogAudytowy::class => ['id', 'akcja', 'czas'],
    ];

    public function testWszystkieKlasyZDiagramuIstnieja(): void
    {
        foreach (array_keys(self::KLASY) as $klasa) {
            $this->assertTrue(class_exists($klasa), 'Klasa z diagramu musi istnieć: ' . $klasa);
        }
    }

    public function testKazdaMetodaZDiagramuJestPubliczna(): void
    {
        foreach (self::KLASY as $klasa => $metody) {
            $ref = new ReflectionClass($klasa);

            foreach ($metody as $metoda) {
                $this->assertTrue(
                    $ref->hasMethod($metoda),
                    sprintf('%s musi mieć metodę %s() z diagramu', $ref->getShortName(), $metoda)
                );

                $this->assertTrue(
                    $ref->getMethod($metoda)->isPublic(),
                    sprintf('%s::%s() musi być publiczna (na diagramie znak +)', $ref->getShortName(), $metoda)
                );
            }
        }
    }

    public function testAtrybutyZDiagramuIstnieja(): void
    {
        foreach (self::ATRYBUTY as $klasa => $pola) {
            $ref = new ReflectionClass($klasa);

            foreach ($pola as $pole) {
                $this->assertTrue(
                    $ref->hasProperty($pole),
                    sprintf('%s musi mieć atrybut %s z diagramu', $ref->getShortName(), $pole)
                );
            }
        }
    }

    /** Generalizacja z diagramu (krawędź e0): Administrator —|> Uzytkownik. */
    public function testAdministratorDziedziczyPoUzytkowniku(): void
    {
        $this->assertTrue(
            is_subclass_of(Administrator::class, Uzytkownik::class),
            'Administrator musi dziedziczyć po Uzytkownik (generalizacja z diagramu)'
        );

        $this->assertFalse(
            (new ReflectionClass(Uzytkownik::class))->isFinal(),
            'Uzytkownik nie może być final, bo Administrator po nim dziedziczy'
        );
    }

    /** Interfejs «Losowanie» z diagramu: + losuj() : Przedmiot. */
    public function testILosujacyJestInterfejsemZMetodaLosuj(): void
    {
        $this->assertTrue(interface_exists(ILosujacy::class), 'ILosujacy musi istnieć');

        $ref = new ReflectionClass(ILosujacy::class);

        $this->assertTrue($ref->isInterface(), 'ILosujacy musi być interfejsem, nie klasą');
        $this->assertTrue($ref->hasMethod('losuj'), 'ILosujacy musi deklarować losuj()');

        $zwrot = $ref->getMethod('losuj')->getReturnType();

        $this->assertTrue($zwrot instanceof ReflectionNamedType, 'losuj() musi mieć typ zwracany');
        $this->assertSame(Przedmiot::class, (string) $zwrot, 'losuj() musi zwracać Przedmiot (jak na diagramie)');
    }

    /** Typy atrybutów z diagramu muszą być prawdziwymi typami, a nie stringami. */
    public function testTypyAtrybutowZDiagramu(): void
    {
        $oczekiwane = [
            [Uzytkownik::class, 'rola', Rola::class],
            [Losowanie::class, 'typ', TypLosowania::class],
            [Losowanie::class, 'wynik', Przedmiot::class],
            [Przedmiot::class, 'rzadkosc', Rzadkosc::class],
        ];

        foreach ($oczekiwane as [$klasa, $pole, $typ]) {
            $ref = new ReflectionClass($klasa);

            $this->assertTrue($ref->hasProperty($pole), $klasa . ' musi mieć pole ' . $pole);

            $typPola = $ref->getProperty($pole)->getType();

            $this->assertTrue($typPola instanceof ReflectionNamedType, $klasa . '::' . $pole . ' musi być typowany');
            $this->assertSame($typ, $typPola->getName(), sprintf('%s::$%s musi być typu %s', $klasa, $pole, $typ));
        }
    }

    /** Typy wyliczeniowe z diagramu (typ atrybutu, nie osobna klasa). */
    public function testEnumyZDiagramuIstnieja(): void
    {
        $this->assertTrue(enum_exists(Rola::class), 'Rola musi być enumem (typ atrybutu `rola`)');
        $this->assertTrue(enum_exists(TypLosowania::class), 'TypLosowania musi być enumem (typ atrybutu `typ`)');

        // TypLosowania pokrywa cztery tryby gry ze strony 2 diagramu PROJEKT.
        $this->assertSame(
            ['skrzynka', 'wedka', 'upgrader', 'trader'],
            array_map(static fn (TypLosowania $t): string => $t->value, TypLosowania::cases()),
            'TypLosowania musi pokrywać tryby gry z diagramu PROJEKT'
        );
    }

    /**
     * Słownik klas z diagramu, których jeszcze nie implementujemy — test
     * pilnuje, żeby nikt ich nie dodał po cichu w innej postaci (np. jako
     * `OauthCodeService`), tylko zbudował zgodnie ze specyfikacją.
     *
     * Skrzynka i SkrzynkaPrzedmiot są już zaimplementowane (tryb «Otwarcie
     * skrzynki», UC_CASE) — sprawdzają je testy wyżej i SkrzynkaTest.
     */
    public function testNieZaimplementowaneKlasyZDiagramuPozostajaNieobecne(): void
    {
        foreach (['OauthKod'] as $nazwa) {
            $this->assertFalse(
                class_exists('MocnyDrop\\' . $nazwa),
                $nazwa . ' nie jest jeszcze zaimplementowana — gdy się pojawi, dodaj ją do DiagramKlasTest::KLASY'
            );
        }
    }

    /**
     * Realizacja interfejsu «Losowanie» z diagramu: przerywane strzałki
     * Skrzynka ..|> ILosujacy (krawędź e13) i SkrzynkaPrzedmiot ..|> ILosujacy
     * (krawędź e14).
     */
    public function testKlasyLosujaceRealizujaInterfejsILosujacy(): void
    {
        foreach ([SkrzynkaPrzedmiot::class] as $klasa) {
            $this->assertTrue(
                is_subclass_of($klasa, ILosujacy::class),
                $klasa . ' musi realizować interfejs ILosujacy (krawędzie e13/e14 diagramu)'
            );

            $zwrot = (new ReflectionClass($klasa))->getMethod('losuj')->getReturnType();

            $this->assertTrue($zwrot instanceof ReflectionNamedType, $klasa . '::losuj() musi mieć typ zwracany');
            $this->assertSame(Przedmiot::class, (string) $zwrot, $klasa . '::losuj() musi zwracać Przedmiot');
        }
    }

    /**
     * Sygnatury z diagramu, których nie da się sprawdzić samą listą metod:
     *   Skrzynka::otworz(u : Uzytkownik) : Przedmiot
     *   Administrator::edytujSkrzynke(s : Skrzynka)
     *   Administrator::korygujSaldo(u : Uzytkownik, kwota : float)
     */
    public function testSygnaturyMetodZDiagramu(): void
    {
        $otworz = (new ReflectionClass(Skrzynka::class))->getMethod('otworz');
        $parametry = $otworz->getParameters();

        $this->assertSame(1, count($parametry), 'Skrzynka::otworz() przyjmuje dokładnie jeden parametr (u)');
        $this->assertSame(Uzytkownik::class, (string) $parametry[0]->getType(), 'Parametr otworz() musi być typu Uzytkownik');
        $this->assertSame(Wedka::class, (string) $otworz->getReturnType(), 'Nowa skrzynka odblokowuje Wedka; stary interfejs zachowano dla historycznych przedmiotów');

        $edytuj = (new ReflectionClass(Administrator::class))->getMethod('edytujSkrzynke');
        $parametryEdycji = $edytuj->getParameters();

        $this->assertSame(Skrzynka::class, (string) $parametryEdycji[0]->getType(), 'edytujSkrzynke(s) musi przyjmować Skrzynka');

        $koryguj = (new ReflectionClass(Administrator::class))->getMethod('korygujSaldo');
        $parametryKorekty = $koryguj->getParameters();

        $this->assertSame(2, count($parametryKorekty), 'korygujSaldo(u, kwota) ma dwa parametry');
        $this->assertSame(Uzytkownik::class, (string) $parametryKorekty[0]->getType(), 'Pierwszy parametr korygujSaldo to Uzytkownik');
        $this->assertSame('float', (string) $parametryKorekty[1]->getType(), 'Drugi parametr korygujSaldo to float');

        $zbanuj = (new ReflectionClass(Administrator::class))->getMethod('zbanuj');
        $this->assertSame(Uzytkownik::class, (string) $zbanuj->getParameters()[0]->getType(), 'zbanuj(u) przyjmuje Uzytkownik');

        $eksport = (new ReflectionClass(Administrator::class))->getMethod('eksportujRaport');
        $this->assertSame('string', (string) $eksport->getReturnType(), 'eksportujRaport() musi zwracać string');
    }

    /**
     * TypLosowania pokrywa cztery tryby gry, a każdy z nich ma swój endpoint
     * z diagramu PROJEKT (strona 2): open_case.php, fish.php, upgrade.php,
     * trade_up.php.
     */
    public function testKazdyTrybGryMaEndpointZDiagramuProjekt(): void
    {
        $endpointy = [
            TypLosowania::Skrzynka->value => 'api/open_case.php',
            TypLosowania::Wedka->value => 'api/fish.php',
            TypLosowania::Upgrader->value => 'api/upgrade.php',
            TypLosowania::Trader->value => 'api/trade_up.php',
        ];

        foreach ($endpointy as $typ => $sciezka) {
            $this->assertTrue(
                is_file(dirname(__DIR__) . '/' . $sciezka),
                'Tryb ' . $typ . ' musi mieć endpoint ' . $sciezka . ' (nazwa z diagramu PROJEKT)'
            );
        }
    }
}
