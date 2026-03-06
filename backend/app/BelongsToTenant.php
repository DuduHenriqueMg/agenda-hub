<?php

namespace App;
use Illuminate\Database\Eloquent\Model;
use App\Models\Scopes\TenantScope;

trait BelongsToTenant
{
    protected static function bootBelongsToTenant(): void{
        static::addGlobalScope(new TenantScope);

        static::creating(function (Model $model) {
            $model->tenant_id ??= app('tenant')?->id;
        });
    }   
}
