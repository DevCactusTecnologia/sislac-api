<?php

use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
| Feature e Contract usam a aplicação Laravel. Testes unitários puros ficam
| em tests/Unit e não inicializam framework desnecessariamente.
*/

pest()->extend(TestCase::class)->in('Feature', 'Contract');

require_once __DIR__.'/Support/laboratories.php';
