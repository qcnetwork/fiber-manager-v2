<?php

namespace App\Support;

use App\Models\Area;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Finds a network location (POP, ODC, ODP, joint) by its morph alias, as the current user may see it.
 *
 * ODC/ODP/joints are limited by their area scope; a POP is an Area itself and has no such scope,
 * so it is checked against the user's area here.
 */
final class NetworkLocation
{
    public static function find(?string $type, mixed $id): ?Model
    {
        $class = $type ? Relation::getMorphedModel($type) : null;
        if (! $class || ! is_numeric($id)) {
            return null;
        }

        $location = $class::find($id);
        if ($location instanceof Area && ! auth()->user()?->canAccessArea($location->id)) {
            return null;
        }

        return $location;
    }

    public static function findOrFail(?string $type, mixed $id): Model
    {
        return self::find($type, $id)
            ?? throw (new ModelNotFoundException)->setModel(Relation::getMorphedModel((string) $type) ?? Model::class, [$id]);
    }
}
