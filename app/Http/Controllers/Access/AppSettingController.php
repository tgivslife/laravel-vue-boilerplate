<?php

namespace App\Http\Controllers\Access;

use App\Http\Controllers\Controller;
use App\Http\Requests\Access\AppSettingUpdateRequest;
use App\Http\Responses\JsonSuccessResponse;
use App\Services\Settings\AppSettings;
use App\Services\Settings\ConfigInspector;
use App\Services\Settings\EnvironmentInspector;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The app-level settings surface: the admin editor (settings.manage) and the unauthenticated bootstrap read of the public-flagged subset.
 * Values resolve through the AppSettings service (closed registry, overrides-only storage);
 * writes land in the attribute-level audit trail via the AppSetting model.
 */
class AppSettingController extends Controller
{
    public function __construct(
        private readonly AppSettings $settings
    ) {
    }

    /**
     * Every registered setting with its resolved value plus the registry metadata (default, rules, public flag) the editor renders from.
     */
    public function index(Request $request): JsonResponse
    {
        $registry = (array) config('settings.app');

        return new JsonSuccessResponse(
            status: Response::HTTP_OK,
            message: 'Settings retrieved successfully',
            data: [
                'settings' => array_map(fn(string $key): array => [
                    'key' => $key,
                    'type' => (string) ($registry[$key]['type'] ?? 'text'),
                    'value' => $this->settings->get($key),
                    'default' => $registry[$key]['default'] ?? null,
                    'rules' => (array) ($registry[$key]['rules'] ?? []),
                    'nested' => (object) ($registry[$key]['nested'] ?? []),
                    'public' => (bool) ($registry[$key]['public'] ?? false),
                ], array_keys($registry)),
            ],
        )->toResponse($request);
    }

    /**
     * Store a value for one registered setting.
     * Unregistered keys 404: deployments differ only by registry, so a miss is a stale client, not a validation nuance.
     */
    public function update(AppSettingUpdateRequest $request, string $key): JsonResponse
    {
        abort_unless($this->settings->has($key), Response::HTTP_NOT_FOUND);

        $this->settings->set($key, $request->validated('value'));

        return new JsonSuccessResponse(
            status: Response::HTTP_OK,
            message: __('api.settings.app.updated'),
            data: ['key' => $key, 'value' => $this->settings->get($key)],
        )->toResponse($request);
    }

    /**
     * The read-only environment report: allowlisted runtime variables grouped by category, with
     * secrets masked to a set/not-set flag (see EnvironmentInspector). A deployment diagnostic
     * for verifying what the running container actually carries.
     */
    public function environment(Request $request, EnvironmentInspector $inspector): JsonResponse
    {
        return new JsonSuccessResponse(
            status: Response::HTTP_OK,
            message: 'Environment retrieved successfully',
            data: ['categories' => $inspector->report()],
        )->toResponse($request);
    }

    /**
     * The read-only config report: allowlisted dot-notation paths with their effective runtime
     * values, secrets masked (see ConfigInspector). Complements the environment report - a
     * variable may be unset while the app still runs with a default.
     */
    public function configReport(Request $request, ConfigInspector $inspector): JsonResponse
    {
        return new JsonSuccessResponse(
            status: Response::HTTP_OK,
            message: 'Config retrieved successfully',
            data: ['categories' => $inspector->report()],
        )->toResponse($request);
    }

    /**
     * The public-flagged settings, for SPA bootstrap. Unauthenticated by design: the registry's
     * public flag is the only gate, so nothing secret may ever carry it.
     */
    public function publicIndex(Request $request): JsonResponse
    {
        return new JsonSuccessResponse(
            status: Response::HTTP_OK,
            message: 'Settings retrieved successfully',
            data: ['settings' => $this->settings->publicSettings()],
        )->toResponse($request);
    }
}
