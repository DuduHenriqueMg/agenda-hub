<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

use App\Models\User;
use App\Models\Tenant;

it('filtra registros pelo tenant', function () {
    $tenant1 = Tenant::factory()->create();
    $tenant2 = Tenant::factory()->create();

    app()->instance('tenant', $tenant1);

    User::factory()->create(['tenant_id' => $tenant1->id]);
    User::factory()->create(['tenant_id' => $tenant2->id]);

    expect(User::count())->toBe(1);
});

it('seta tenant_id automaticamente ao criar', function () {
    $tenant = Tenant::factory()->create();
    app()->instance('tenant', $tenant);

    $user = User::factory()->create();

    expect($user->tenant_id)->toBe($tenant->id);
});

it('não filtra quando não há tenant no container', function () {
    $tenant1 = Tenant::factory()->create();
    $tenant2 = Tenant::factory()->create();

    User::factory()->create(['tenant_id' => $tenant1->id]);
    User::factory()->create(['tenant_id' => $tenant2->id]);

    expect(User::count())->toBe(2);
});
