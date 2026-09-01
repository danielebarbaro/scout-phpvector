<?php

declare(strict_types=1);

/*
 * ezimuel/phpvector is wired in through a composer "path" repository whose
 * symlink resolves outside vendor/, so Pest treats it as first party code.
 * These rules are about this package, hence the PHPVector namespace is ignored.
 */

arch()->preset()->php()->ignoring('PHPVector');

arch()->preset()->security()->ignoring('PHPVector');

arch('it will not use dd(), ddd(), env(), or exit()')
    ->expect('DanieleBarbaro\ScoutPHPVector')
    ->not->toUse(['dd', 'ddd', 'env', 'exit']);

arch('the package source declares strict types')
    ->expect('DanieleBarbaro\ScoutPHPVector')
    ->toUseStrictTypes();
