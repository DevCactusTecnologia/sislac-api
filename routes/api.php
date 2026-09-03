<?php

use App\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Rotas da API (prefixo /api, grupo de middleware "api")
|--------------------------------------------------------------------------
| Fase 0: só o health check. As rotas de plataforma (super-admin) e de
| laboratório entram na Fase 1 em arquivos separados (routes/central.php e
| routes/tenant.php), conforme docs/ARCHITECTURE.md.
*/

Route::get('/health', HealthController::class)->name('api.health');
