<?php

namespace Tests\Feature;

use Tests\TestCase;

class RootRouteTest extends TestCase
{
    public function test_a_raiz_redireciona_para_o_super_admin(): void
    {
        $this->get('/')->assertRedirect('/admin');
    }
}
