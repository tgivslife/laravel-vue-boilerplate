<?php

use Illuminate\Support\Facades\Route;

/*
 * Bigint-id params, bounded to 18 digits (the longest always in signed-bigint range).
 * A non-numeric or over-long segment then 404s at routing instead of overflowing the binding query.
 * Global so a new {user}/{role}/... route cannot forget it.
 */
Route::pattern('user', '[0-9]{1,18}');
Route::pattern('role', '[0-9]{1,18}');
Route::pattern('recordId', '[0-9]{1,18}');
Route::pattern('tokenId', '[0-9]{1,18}');

/*
 * One file per console domain, all inheriting this file's /api prefix and `api` middleware group.
 * The shared session stack is the `protected` middleware group (bootstrap/app.php); the auth file
 * additionally carries the public surface and the deliberate escape hatches out of that stack.
 * Require order is registration order - literal-before-wildcard concerns stay within each file.
 */
require __DIR__.'/api/auth.php';
require __DIR__.'/api/settings.php';
require __DIR__.'/api/access.php';
