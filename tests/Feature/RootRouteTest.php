<?php

namespace Tests\Feature;

use Tests\TestCase;

class RootRouteTest extends TestCase
{
    public function test_a_raiz_identifica_a_api_sem_redirecionar_para_admin(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertJson([
                'service' => 'SISLAC API',
            ]);
    }
}
