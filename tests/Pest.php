<?php

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
| Todo teste em tests/Feature ganha o TestCase do Laravel (app inicializada,
| helpers HTTP, etc.). Testes de unidade puros ficam em tests/Unit.
*/

pest()->extend(Tests\TestCase::class)->in('Feature');
