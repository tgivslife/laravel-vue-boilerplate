{{--
    The /up readiness page (HealthController). Self-contained on purpose: no scripts, fonts or
    external requests, so it renders under the strict CSP. Probe names only - details stay in the log.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ config('app.name') }} - {{ $failing === [] ? 'up' : 'down' }}</title>
    <style>
        body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; background: #f5f5f5; color: #171717; font-family: system-ui, sans-serif; }
        .card { display: flex; align-items: center; gap: 1.25rem; padding: 1.25rem 1.5rem; background: #fff; border-radius: .5rem; box-shadow: 0 10px 30px rgba(0, 0, 0, .08); }
        .dot { width: .75rem; height: .75rem; border-radius: 50%; background: #22c55e; flex: none; }
        .down .dot { background: #dc2626; }
        h1 { margin: 0; font-size: 1.25rem; font-weight: 600; }
        p { margin: .35rem 0 0; font-size: .875rem; color: #525252; }
        code { font-family: ui-monospace, monospace; }
        @media (prefers-color-scheme: dark) {
            body { background: #171717; color: #f5f5f5; }
            .card { background: #262626; }
            p { color: #a3a3a3; }
        }
    </style>
</head>
<body>
<div class="card {{ $failing === [] ? '' : 'down' }}">
    <div class="dot"></div>
    <div>
        <h1>Application {{ $failing === [] ? 'up' : 'experiencing problems' }}</h1>
        @if ($failing !== [])
            <p>Failing: {!! implode(', ', array_map(static fn(string $name): string => '<code>'.e($name).'</code>', $failing)) !!}</p>
        @else
            <p>All critical probes passed.</p>
        @endif
        @if ($maintenance)
            <p>Down for maintenance: visitors see the maintenance page.</p>
        @endif
        <p>HTTP request received. Response rendered in {{ $renderedMs }}ms.</p>
    </div>
</div>
</body>
</html>
