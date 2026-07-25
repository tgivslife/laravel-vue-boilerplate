<?php

namespace Tests\Support;

use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Auditable as RecordsAudits;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * Test-only auditable.
 * The attribute-level audit trail's semantics tests run against this dummy so the suite stays
 * independent of any real domain model.
 */
class AuditedItem extends Model implements Auditable
{
    use RecordsAudits;

    public $timestamps = false;

    protected $guarded = [];

    /**
     * @var list<string>
     */
    protected $auditExclude = ['secret'];
}
