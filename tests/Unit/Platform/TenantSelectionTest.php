<?php

use App\Platform\Tenancy\Exceptions\NoActiveMembership;
use App\Platform\Tenancy\Exceptions\TenantNotAuthorized;
use App\Platform\Tenancy\Exceptions\TenantSelectionRequired;
use App\Platform\Tenancy\TenantSelection;

it('nega acesso quando o usuário não possui vínculo ativo', function () {
    $selection = new TenantSelection;

    expect(fn () => $selection->select([], null))
        ->toThrow(NoActiveMembership::class);
});

it('seleciona automaticamente o único laboratório ativo', function () {
    $selection = new TenantSelection;

    expect($selection->select(['tenant-a'], null))->toBe('tenant-a');
});

it('aceita a seleção explícita quando ela corresponde ao único vínculo', function () {
    $selection = new TenantSelection;

    expect($selection->select(['tenant-a'], 'tenant-a'))->toBe('tenant-a');
});

it('nega um tenant solicitado que não pertence ao usuário', function () {
    $selection = new TenantSelection;

    expect(fn () => $selection->select(['tenant-a'], 'tenant-b'))
        ->toThrow(TenantNotAuthorized::class);
});

it('exige seleção explícita quando existem vários vínculos ativos', function () {
    $selection = new TenantSelection;

    expect(fn () => $selection->select(['tenant-a', 'tenant-b'], null))
        ->toThrow(TenantSelectionRequired::class);
});

it('seleciona entre vários vínculos somente o tenant autorizado solicitado', function () {
    $selection = new TenantSelection;

    expect($selection->select(['tenant-a', 'tenant-b'], 'tenant-b'))->toBe('tenant-b');
});

it('nega header forjado quando existem vários vínculos ativos', function () {
    $selection = new TenantSelection;

    expect(fn () => $selection->select(['tenant-a', 'tenant-b'], 'tenant-c'))
        ->toThrow(TenantNotAuthorized::class);
});

it('deduplica vínculos antes de decidir se a seleção é obrigatória', function () {
    $selection = new TenantSelection;

    expect($selection->select(['tenant-a', 'tenant-a'], null))->toBe('tenant-a');
});
