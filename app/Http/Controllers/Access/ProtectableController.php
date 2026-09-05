<?php

namespace App\Http\Controllers\Access;

use App\Http\Controllers\Controller;
use App\Http\Requests\Access\RuleSyncRequest;
use App\Http\Resources\Access\RuleGroupResource;
use App\Http\Responses\JsonSuccessResponse;
use App\Models\Access\RequiredPermission;
use App\Services\Access\AccessControlService;
use App\Support\Access\EscapedLikeFilter;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;
use Symfony\Component\HttpFoundation\Response;

/**
 * Required-permission rules administration: the protectable whitelist (from config/access.php), a record browser per protectable,
 * and the class-level and per-record rule groups.
 */
class ProtectableController extends Controller
{
    public function __construct(
        private readonly AccessControlService $accessControl
    ) {
    }

    /**
     * The protectable whitelist with each alias's class-rule summary.
     */
    public function index(Request $request): JsonResponse
    {
        $classRuleCounts = RequiredPermission::query()
            ->classLevel()
            ->selectRaw('protectable_type, type, count(*) as rules')
            ->groupBy('protectable_type', 'type')
            ->get()
            ->groupBy('protectable_type');

        $protectables = collect(config('access.protectables', []))
            ->map(static fn(array $protectable, string $alias): array => [
                'alias' => $alias,
                'label' => $protectable['label'],
                'rule_types' => config('access.rule_types'),
                'class_rules' => ($classRuleCounts[$alias] ?? collect())
                    ->mapWithKeys(static fn($row): array => [$row->type => (int) $row->rules])
                    ->all(),
            ])
            ->values()
            ->all();

        return new JsonSuccessResponse(
            status: Response::HTTP_OK,
            message: 'Protectables retrieved successfully',
            data: ['protectables' => $protectables],
        )->toResponse($request);
    }

    /**
     * The class-level rule groups for one protectable, keyed by type.
     */
    public function classRules(Request $request, string $alias): JsonResponse
    {
        $this->assertKnownAlias($alias);

        return new JsonSuccessResponse(
            status: Response::HTTP_OK,
            message: 'Rules retrieved successfully',
            data: ['rules' => $this->ruleGroups($alias, null)],
        )->toResponse($request);
    }

    /**
     * Replace one class-level rule group (one type).
     */
    public function syncClassRules(RuleSyncRequest $request, string $alias): JsonResponse
    {
        $this->assertKnownAlias($alias);

        $this->accessControl->syncClassRules(
            $request->user(),
            $alias,
            $request->validated('type'),
            $request->validated('permission_ids'),
            $request->validated('mode'),
        );

        return new JsonSuccessResponse(
            status: Response::HTTP_OK,
            message: __('api.access.rules_updated'),
            data: ['rules' => $this->ruleGroups($alias, null)],
        )->toResponse($request);
    }

    /**
     * Browse a protectable's records: id, display label, and whether the
     * record carries rules of its own. `filter[search]` matches the
     * configured label column (bound LIKE).
     */
    public function records(Request $request, string $alias): JsonResponse
    {
        $this->assertKnownAlias($alias);

        $request->validate([
            'filter.search' => ['sometimes', 'string', 'max:255'],
        ]);

        $model = $this->modelFor($alias);
        $label = config("access.protectables.{$alias}.label");

        $records = QueryBuilder::for($model->newQuery())
            ->allowedFilters(
                AllowedFilter::custom('search', new EscapedLikeFilter([$label])),
            )
            ->allowedSorts($model->getKeyName())
            ->defaultSort($model->getKeyName())
            ->simplePaginate((int) config('access.user_browser.per_page', 25));

        $ruledIds = RequiredPermission::query()
            ->where('protectable_type', $alias)
            ->whereIn('protectable_id', collect($records->items())->map(static fn(Model $record) => $record->getKey()))
            ->pluck('protectable_id')
            ->unique();

        return new JsonSuccessResponse(
            status: Response::HTTP_OK,
            message: 'Records retrieved successfully',
            data: [
                'records' => collect($records->items())->map(static fn(Model $record): array => [
                    'id' => $record->getKey(),
                    'label' => (string) $record->getAttribute($label),
                    'has_rules' => $ruledIds->contains($record->getKey()),
                ])->all(),
                'has_more' => $records->hasMorePages(),
            ],
        )->toResponse($request);
    }

    /**
     * The rule groups for one record, keyed by type.
     */
    public function recordRules(Request $request, string $alias, int $recordId): JsonResponse
    {
        $this->assertKnownAlias($alias);
        $record = $this->modelFor($alias)->newQuery()->findOrFail($recordId);

        return new JsonSuccessResponse(
            status: Response::HTTP_OK,
            message: 'Rules retrieved successfully',
            data: ['rules' => $this->ruleGroups($alias, (int) $record->getKey())],
        )->toResponse($request);
    }

    /**
     * Replace one record-level rule group (one type).
     */
    public function syncRecordRules(RuleSyncRequest $request, string $alias, int $recordId): JsonResponse
    {
        $this->assertKnownAlias($alias);
        $record = $this->modelFor($alias)->newQuery()->findOrFail($recordId);

        $this->accessControl->syncRecordRules(
            $request->user(),
            $record,
            $request->validated('type'),
            $request->validated('permission_ids'),
            $request->validated('mode'),
        );

        return new JsonSuccessResponse(
            status: Response::HTTP_OK,
            message: __('api.access.rules_updated'),
            data: ['rules' => $this->ruleGroups($alias, (int) $record->getKey())],
        )->toResponse($request);
    }

    private function assertKnownAlias(string $alias): void
    {
        abort_unless(array_key_exists($alias, config('access.protectables', [])), Response::HTTP_NOT_FOUND);
    }

    private function modelFor(string $alias): Model
    {
        $class = config("access.protectables.{$alias}.model");

        return new $class;
    }

    private function rulesFor(string $alias, ?int $recordId): Collection
    {
        return RequiredPermission::query()
            ->where('protectable_type', $alias)
            ->when(
                $recordId === null,
                static fn($query) => $query->whereNull('protectable_id'),
                static fn($query) => $query->where('protectable_id', $recordId),
            )
            ->with('permission:id,name')
            ->get();
    }

    /**
     * The {type, mode, permissions[]} rule groups for a protectable class (null record id) or one record.
     */
    private function ruleGroups(string $alias, ?int $recordId): AnonymousResourceCollection
    {
        return RuleGroupResource::collection(
            $this->rulesFor($alias, $recordId)->groupBy('type')->values()
        );
    }
}
