<?php

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Context;

/**
 * Constrains every query to the current organization when one is resolved.
 *
 * When no organization context is set (console commands, unauthenticated
 * requests), queries run unscoped — callers must constrain explicitly.
 *
 * @implements Scope<Model>
 */
class OrganizationScope implements Scope
{
    /**
     * Apply the scope to a given Eloquent query builder.
     */
    public function apply(Builder $builder, Model $model): void
    {
        $organizationId = Context::get('organization_id');

        if (is_string($organizationId)) {
            $builder->where($model->qualifyColumn('organization_id'), $organizationId);
        }
    }
}
