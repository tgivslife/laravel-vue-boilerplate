<?php

namespace Tests\Feature\Access;

use App\Models\Access\RequiredPermission;
use App\Models\User;
use App\Services\Access\AccessScope;
use Database\Seeders\PermissionSeeder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Contracts\Permission as PermissionContract;
use Tests\Support\Widget;
use Tests\TestCase;

abstract class AccessTestCase extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (!Schema::hasTable('widgets')) {
            Schema::create('widgets', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('region')->nullable();
            });
        }

        Relation::morphMap(['widget' => Widget::class]);

        config([
            'access.protectables' => [
                'widget' => ['model' => Widget::class, 'label' => 'name'],
            ]
        ]);

        // The real vocabulary ({resource}.{verb} + lockout permissions),
        // exactly as a deployed application would seed it.
        $this->seed(PermissionSeeder::class);
    }

    protected function permission(string $name): PermissionContract
    {
        return config('permission.models.permission')::findOrCreate($name, config('access.guard'));
    }

    /**
     * A user holding the given permissions directly.
     */
    protected function userWithPermissions(string ...$names): User
    {
        $user = $this->createUser();

        foreach ($names as $name) {
            $user->givePermissionTo($this->permission($name));
        }

        return $user;
    }

    /**
     * Attach a rule group to a widget (or class-level when null).
     *
     * @param  list<string>|string  $permissions
     */
    protected function requirePermissions(
        ?Widget $widget,
        string $type,
        array|string $permissions,
        string $mode = 'all'
    ): void {
        foreach ((array) $permissions as $name) {
            RequiredPermission::create([
                'permission_id' => $this->permission($name)->getKey(),
                'protectable_type' => 'widget',
                'protectable_id' => $widget?->getKey(),
                'type' => $type,
                'mode' => $mode,
            ]);
        }

        app(AccessScope::class)->flush();
    }

    /**
     * The names of the widgets the user can see, in insertion order.
     *
     * @return list<string>
     */
    protected function visibleWidgetNames(User $user, string $type = 'view'): array
    {
        return Widget::query()->visibleTo($user, $type)->orderBy('id')->pluck('name')->all();
    }
}
