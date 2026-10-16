<?php

declare(strict_types=1);
namespace MocnyDrop\Tests;

use MocnyDrop\{Database,SkrzynkaWedka};
use PDO;

final class WspolbieznoscTest extends TestCase
{
    public function testRownolegleOdbioryIPonowieniaSaRozliczaneRaz(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'md_concurrency_');
        $gate = $file.'.gate';
        try {
            $db = new PDO('sqlite:'.$file);
            $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            $db->exec('PRAGMA journal_mode=WAL');
            Database::applySchema($db);
            $u = Fixtures::user($db);
            $rod = Fixtures::wedkaZRyba($db);
            $case = Fixtures::skrzynka($db, 'Dzienna', 0);
            $db->exec("UPDATE skrzynki SET dzienna=1 WHERE id=$case");
            SkrzynkaWedka::ustawPule($case, [['wedka_id'=>$rod,'waga'=>1]], $db);
            $a = $this->uruchom($file,$u['id'],$case,'parallel-1',$gate);
            $b = $this->uruchom($file,$u['id'],$case,'parallel-2',$gate);
            touch($gate);
            $results = [$this->odbierz($a),$this->odbierz($b)];
            $this->assertSame(1, count(array_filter($results, fn ($r)=>$r['ok'])), 'Tylko jeden odbiór wygrywa wyścig');
            $this->assertSame(1, count(array_filter($results, fn ($r)=>($r['kod']??'')==='daily_case_cooldown')), 'Drugi odbiór dostaje kod limitu, nie błąd SQLite');
            $key = $results[0]['ok'] ? 'parallel-1' : 'parallel-2';
            $c = $this->uruchom($file,$u['id'],$case,$key,$gate);
            $d = $this->uruchom($file,$u['id'],$case,$key,$gate);
            $this->assertSame($this->odbierz($c), $this->odbierz($d), 'Równoległe ponowienia odtwarzają ten sam wynik');
            $this->assertSame(1, (int) $db->query('SELECT COUNT(*) FROM historia_losowan')->fetchColumn(), 'Dokładnie jeden wpis historii');
            $this->assertSame(1, (int) $db->query('SELECT COUNT(*) FROM uzytkownik_wedki')->fetchColumn(), 'Dokładnie jedna nagroda');
            $db = null;
        } finally {
            foreach ([$gate,$file.'-wal',$file.'-shm',$file] as $path) if (is_file($path)) unlink($path);
        }
    }

    private function uruchom(string $file, int $user, int $case, string $key, string $gate): array
    {
        $cmd = [PHP_BINARY];
        // Zachowaj moduły przy uruchamianiu zestawu przez przenośne PHP -n.
        if (php_ini_loaded_file() === false) {
            $cmd = [...$cmd, '-n', '-d', 'extension_dir='.ini_get('extension_dir')];
            foreach (['pdo','pdo_sqlite'] as $module) {
                if (is_file(ini_get('extension_dir').'/'.$module.'.'.PHP_SHLIB_SUFFIX)) {
                    $cmd = [...$cmd, '-d', 'extension='.$module.'.'.PHP_SHLIB_SUFFIX];
                }
            }
        }
        $pipes = [];
        $process = proc_open([...$cmd,__DIR__.'/concurrent-case.php',$file,(string)$user,(string)$case,$key,$gate], [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes);
        fclose($pipes[0]);
        return [$process,$pipes];
    }

    private function odbierz(array $worker): array
    {
        [$process,$pipes]=$worker;
        $out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);
        fclose($pipes[1]);fclose($pipes[2]);
        $this->assertSame(0,proc_close($process),'Proces zakończony bez awarii: '.$out.$err);
        return json_decode($out,true,512,JSON_THROW_ON_ERROR);
    }
}
