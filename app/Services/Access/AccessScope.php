<?php

namespace App\Services\Access;

use App\Contracts\Access\ScopeDimension;
use App\Models\Access\RequiredPermission;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * Per-request access memo and record-level decision point.
 *
 * Registered as a scoped singleton, so every cached answer lives exactly one request (one job, one command),
 * revoking access is effective on the next request by construction, with no TTL window to invalidate.
 * Nothing here is ever written to config() or a persistent store.
 */
final class AccessScope
{
    /**
     * Held permission ids per user key.
     *
     * @var array<int|string, list<int>>
     */
    private array $permissionIds = [];

    /**
     * Super-admin verdict per user key.
     *
     * @var array<int|string, bool>
     */
    private array $superAdmin = [];

    /**
     * Class-level rules grouped by "alias|type", loaded in one query.
     *
     * @var array<string, list<array{permission_id: int, mode: string}>>|null
     */
    private ?array $classRules = null;

    /**
     * Whether instance rules exist at all, per "alias|type".
     *
     * @var array<string, bool>|null
     */
    private ?array $instanceRulePresence = null;

    /**
     * Record-level verdicts per "user|alias|id|type".
     *
     * @var array<string, bool>
     */
    private array $recordVerdicts = [];

    /**
     * Resolved dimension instances (config `access.dimensions`).
     *
     * @var list<ScopeDimension>|null
     */
    private ?array $dimensions = null;

    /**
     * Whether the user holds the configured super-admin role.
     */
    public function isSuperAdmin(Authenticatable $user): bool
    {
        $key = $user->getAuthIdentifier();

        return $this->superAdmin[$key] ??= method_exists($user, 'hasRole')
            && $user->hasRole(config('access.super_admin_role'));
    }

    /**
     * The ids of every permission the user holds (direct and via roles).
     *
     * @return list<int>
     */
    public function permissionIds(Authenticatable $user): array
    {
        $key = $user->getAuthIdentifier();

        return $this->permissionIds[$key] ??= method_exists($user, 'getAllPermissions')
            ? $user->getAllPermissions()->pluck('id')->all()
            : [];
    }

    /**
     * The registered scope dimensions, resolved once per request.
     *
     * @return list<ScopeDimension>
     */
    public function dimensions(): array
    {
        return $this->dimensions ??= array_map(
            static fn(string $class): ScopeDimension => app($class),
            config('access.dimensions', [])
        );
    }

    /**
     * The class-level rule group for a protectable alias and rule type.
     *
     * @return list<array{permission_id: int, mode: string}>
     */
    public function classRules(string $alias, string $type): array
    {
        if ($this->classRules === null) {
            $this->classRules = RequiredPermission::query()
                ->classLevel()
                ->get(['protectable_type', 'type', 'permission_id', 'mode'])
                ->groupBy(static fn(RequiredPermission $rule): string => $rule->protectable_type.'|'.$rule->type)
                ->map(static fn($rules) => $rules
                    ->map(static fn(RequiredPermission $rule): array => [
                        'permission_id' => $rule->permission_id,
                        'mode' => $rule->mode,
                    ])
                    ->values()
                    ->all())
                ->all();
        }

        return $this->classRules[$alias.'|'.$type] ?? [];
    }

    /**
     * Whether any record-specific rules exist for the alias and type.
     *
     * Loaded as one grouped query so the common case - a class with no instance rules - lets visibleTo() skip its subqueries entirely.
     */
    public function hasInstanceRules(string $alias, string $type): bool
    {
        if ($this->instanceRulePresence === null) {
            $this->instanceRulePresence = RequiredPermission::query()
                ->whereNotNull('protectable_id')
                ->groupBy('protectable_type', 'type')
                ->get(['protectable_type', 'type'])
                ->mapWithKeys(static fn(RequiredPermission $rule): array => [
                    $rule->protectable_type.'|'.$rule->type => true,
                ])
                ->all();
        }

        return $this->instanceRulePresence[$alias.'|'.$type] ?? false;
    }

    /**
     * Whether a rule group is satisfied by the held permission ids.
     *
     * `all` rows are each required;
     * `any` rows form a pool of which at least one must be held. An empty group always passes.
     *
     * @param  list<array{permission_id: int, mode: string}>  $rules
     * @param  list<int>  $held
     */
    public function passesGroup(array $rules, array $held): bool
    {
        $anyPool = [];

        foreach ($rules as $rule) {
            if ($rule['mode'] === RequiredPermission::MODE_ANY) {
                $anyPool[] = $rule['permission_id'];

                continue;
            }

            if (!in_array($rule['permission_id'], $held, true)) {
                return false;
            }
        }

        return $anyPool === [] || array_intersect($anyPool, $held) !== [];
    }

    /**
     * Whether the user may perform `type` on this specific record.
     *
     * Composes every layer below the capability check (which stays in the policy):
     * super-admin bypass, registered scope dimensions, class-level rules, then the record's own rules.
     */
    public function allowsRecord(Authenticatable $user, Model $model, string $type): bool
    {
        if ($this->isSuperAdmin($user)) {
            return true;
        }

        $key = $user->getAuthIdentifier().'|'.$model->getMorphClass().'|'.$model->getKey().'|'.$type;

        return $this->recordVerdicts[$key] ??= $this->resolveRecordVerdict($user, $model, $type);
    }

    /**
     * Drop the rule memos (called when rules change mid-request).
     */
    public function flushRules(): void
    {
        $this->classRules = null;
        $this->instanceRulePresence = null;
        $this->recordVerdicts = [];
    }

    /**
     * Drop everything (called after access mutations mid-request).
     */
    public function flush(): void
    {
        $this->permissionIds = [];
        $this->superAdmin = [];
        $this->dimensions = null;
        $this->flushRules();
    }

    private function resolveRecordVerdict(Authenticatable $user, Model $model, string $type): bool
    {
        foreach ($this->dimensions() as $dimension) {
            if ($dimension->appliesTo($model) && !$dimension->allows($user, $model)) {
                return false;
            }
        }

        $alias = $model->getMorphClass();
        $held = $this->permissionIds($user);

        if (!$this->passesGroup($this->classRules($alias, $type), $held)) {
            return false;
        }

        if (!$this->hasInstanceRules($alias, $type)) {
            return true;
        }

        $instanceRules = RequiredPermission::query()
            ->forRecord($alias, (int) $model->getKey())
            ->where('type', $type)
            ->get(['permission_id', 'mode'])
            ->map(static fn(RequiredPermission $rule): array => [
                'permission_id' => $rule->permission_id,
                'mode' => $rule->mode,
            ])
            ->all();

        return $this->passesGroup($instanceRules, $held);
    }
}
