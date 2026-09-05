<?php

namespace App\Http\Controllers\Ops;

use App\Http\Controllers\Controller;
use App\Services\Ops\HealthCheckService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * The /up readiness endpoint, app-owned instead of the framework's `health:` route.
 *
 * Same contract as the stock route - 200 while the critical probes pass, 500 when one fails, `{"status": ...}`
 * for JSON callers - rendered from an asset-free view: the framework page loads a font and Tailwind from
 * third-party CDNs, which the CSP refuses. A failure is reported as an exception so the log names the probe.
 *
 * The page names the failing probes but never their detail: the URL is unauthenticated and a detail string
 * can carry a host or port. The detail is in the reported exception and in `health:check`.
 */
class HealthController extends Controller
{
    /**
     * Run the critical probes and answer with the readiness status.
     *
     * @param  Request  $request  The probe or browser request.
     * @param  HealthCheckService  $health  Runs the critical probes.
     */
    public function show(Request $request, HealthCheckService $health): JsonResponse|Response
    {
        $failures = $health->criticalFailures();
        $status = $failures === [] ? Response::HTTP_OK : Response::HTTP_INTERNAL_SERVER_ERROR;

        if ($failures !== []) {
            report(new RuntimeException('Critical health probes failing - '.implode('; ', array_map(
                    static fn(array $probe): string => "{$probe['name']}: {$probe['detail']}",
                    $failures,
                ))));
        }

        // Informational only: a down-for-maintenance instance is still ready, and must stay in rotation to serve the maintenance page.
        $maintenance = app()->isDownForMaintenance();

        if ($request->expectsJson()) {
            return response()->json([
                'status' => $failures === [] ? 'up' : 'down',
                'maintenance' => $maintenance,
            ], $status);
        }

        return response($this->page($request, $failures, $maintenance), $status);
    }

    /**
     * @param  Request  $request  Supplies the arrival time when LARAVEL_START is undefined (bare phpunit runs).
     * @param  list<array{name: string, ok: bool, critical: bool, detail: string, duration_ms: float}>  $failures
     * @param  bool  $maintenance  Whether the application is down for maintenance.
     */
    private function page(Request $request, array $failures, bool $maintenance): View
    {
        $startedAt = defined('LARAVEL_START') ? LARAVEL_START : (float) $request->server('REQUEST_TIME_FLOAT');

        return view('ops.health', [
            'failing' => array_column($failures, 'name'),
            'maintenance' => $maintenance,
            'renderedMs' => (int) round((microtime(true) - $startedAt) * 1000),
        ]);
    }
}
