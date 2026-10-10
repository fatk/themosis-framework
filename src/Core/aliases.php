<?php

/*
|--------------------------------------------------------------------------
| Laravel Foundation aliases
|--------------------------------------------------------------------------
|
| Some illuminate/* components reference classes that only ship with the
| full laravel/framework package (e.g. Illuminate\Queue\CallQueuedClosure
| uses Illuminate\Foundation\Bus\Dispatchable, Bus::chain() returns an
| Illuminate\Foundation\Bus\PendingChain and AnonymousEvent uses
| Illuminate\Foundation\Events\Dispatchable). Without them, queued
| closures, scheduled closures, job chains and anonymous broadcast events
| fail with a fatal error.
| Themosis provides equivalent implementations; alias them lazily so a
| real laravel/framework install (resolved first by Composer) still wins.
|
*/

spl_autoload_register(static function (string $class): void {
    $aliases = [
        'Illuminate\Foundation\Bus\Dispatchable' => \Themosis\Core\Bus\Dispatchable::class,
        'Illuminate\Foundation\Bus\PendingChain' => \Themosis\Core\Bus\PendingChain::class,
        'Illuminate\Foundation\Events\Dispatchable' => \Themosis\Core\Events\Dispatchable::class,
    ];

    if (isset($aliases[$class])) {
        class_alias($aliases[$class], $class);
    }
});
