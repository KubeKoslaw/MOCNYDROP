<?php

declare(strict_types=1);

namespace MocnyDrop\Tests;

use RuntimeException;

/**
 * tests/AssertionFailed.php
 *
 * Osobna klasa na nieudaną asercję, żeby runner odróżniał "test wykazał błąd"
 * od "test się wysypał" (np. PDOException z bazy). Bez tego każdy wyjątek
 * wyglądałby tak samo i trudno byłoby znaleźć prawdziwą przyczynę.
 *
 * Właściciel: @KubeKoslaw (Core Backend)
 */
final class AssertionFailed extends RuntimeException
{
}
