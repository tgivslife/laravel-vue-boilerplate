<?php

namespace Tests\Support;

use App\Models\Concerns\HasRequiredPermissions;
use Illuminate\Database\Eloquent\Model;

/**
 * Test-only protectable.
 * The access layer's semantics tests run against this dummy so the suite stays independent of any real domain model.
 */
class Widget extends Model
{
    use HasRequiredPermissions;

    public $timestamps = false;

    protected $guarded = [];
}
