<?php

namespace App\Providers;

use App\Models\AppSetting;
use App\Models\User;
use App\Support\Redis\PhpRedisSentinelConnector;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Redis\RedisManager;
use Illuminate\Support\ServiceProvider;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * The dev-only tooling (Telescope, Clockwork) lives in require-dev and is excluded from composer's auto-discovery,
     * so it is registered here - guarded by class_exists so a --no-dev production build boots without the packages,
     * and gated to the local environment.
     * These must never appear in bootstrap/providers.php, which registers unconditionally.
     */
    public function register(): void
    {
        $this->registerSentinelRedisDriver();

        if (class_exists(\Laravel\Telescope\TelescopeServiceProvider::class) && $this->app->environment('local')) {
            $this->app->register(\Laravel\Telescope\TelescopeServiceProvider::class);
            $this->app->register(TelescopeServiceProvider::class);
        }

        if (class_exists(\Clockwork\Support\Laravel\ClockworkServiceProvider::class) && $this->app->environment('local')) {
            $this->app->register(\Clockwork\Support\Laravel\ClockworkServiceProvider::class);
        }
    }

    /**
     * Register the phpredis-sentinel driver (App\Support\Redis) with the Redis manager.
     *
     * The sentinel branch of config/database.php selects it via `client => phpredis-sentinel`;
     * RedisManager consults custom creators before the built-in phpredis/predis match.
     * `redis` is a deferred singleton, so the creator is attached on resolution - with a resolved() guard in case an
     * earlier provider already touched Redis during registration.
     * The binding is checked rather than type-hinted: a test that swaps `redis` for a mock should get its mock,
     * not a TypeError from a provider that only wanted to register a driver it will never use.
     */
    private function registerSentinelRedisDriver(): void
    {
        $register = static function (mixed $manager): void {
            if (!$manager instanceof RedisManager) {
                return;
            }

            // Deliberately not a static closure: RedisManager::extend() rebinds it to the manager.
            $manager->extend('phpredis-sentinel', function () {
                return new PhpRedisSentinelConnector();
            });
        };

        $this->app->afterResolving('redis', $register);

        if ($this->app->resolved('redis')) {
            $register($this->app->make('redis'));
        }
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->enforceMorphMap();
    }

    /**
     * Aliases for every model the application morphs to, so *_type columns never couple the database to class names.
     * The map is enforced: a model missing from it (or from the access protectables, merged in by AccessServiceProvider)
     * cannot be stored in any *_type column.
     */
    private function enforceMorphMap(): void
    {
        Relation::enforceMorphMap([
            'user' => User::class,
            'role' => Role::class,
            'permission' => Permission::class,
            'app_setting' => AppSetting::class,
        ]);
    }
}
