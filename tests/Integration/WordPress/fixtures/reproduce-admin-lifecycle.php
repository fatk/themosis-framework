<?php

/** Explicit failing acceptance probe for the design gate; never silently skipped by PHPUnit. */
require dirname(__DIR__, 3) . '/bootstrap.php';
require dirname(__DIR__, 3) . '/Support/AdminLifecycleHarness.php';

use Illuminate\Session\ArraySessionHandler;
use Themosis\Tests\Support\AdminLifecycleHarness;

$handler = new ArraySessionHandler(120);
$value = bin2hex(random_bytes(8));
$request = new AdminLifecycleHarness($handler, null, function ($session) use ($value) {
    $session->flash('early_flash', $value);
});
$request->dispatchAdmin();
$request->request->session()->put('late', $value);
$request->request->session()->flash('late_flash', $value);
$next = new AdminLifecycleHarness($handler, $request->cookieId());
$next->dispatchAdmin();
$checks = [
    'early_flash_control' => ($next->probe->before['early_flash'] ?? null) === $value,
    'late_normal_persisted' => ($next->probe->before['late'] ?? null) === $value,
    'late_flash_persisted' => ($next->probe->before['late_flash'] ?? null) === $value,
    'application_terminated_once' => $request->terminations === 1,
    'middleware_terminated_once' => $request->probe->terminations === 1,
];
$regenerated = new AdminLifecycleHarness($handler, null);
$regenerated->dispatchAdmin();
$issued = $regenerated->cookieId();
$regenerated->request->session()->regenerate(true);
$regenerated->request->session()->put('late', $value);
$checks['late_regenerated_cookie_matches_store'] = $issued === $regenerated->request->session()->getId();

// Counterexample only: a second save is intentionally not a proposed repair.
$double = new AdminLifecycleHarness($handler, null, function ($session) use ($value) {
    $session->flash('early_flash', $value);
});
$double->dispatchAdmin();
$double->request->session()->flash('late_flash', $value);
$double->request->session()->save();
$saved = AdminLifecycleHarness::stored($handler, $double->cookieId());
echo json_encode(['acceptance' => $checks, 'second_save_counterexample' => [
    'early_flash_lost' => ! array_key_exists('early_flash', $saved),
    'late_flash_present' => ($saved['late_flash'] ?? null) === $value,
]], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
exit(in_array(false, $checks, true) ? 1 : 0);
