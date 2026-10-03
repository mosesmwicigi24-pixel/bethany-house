<?php

/*
 * Third-party providers are listed BY HAND, and have to be.
 *
 * Laravel discovers them at `composer dump-autoload` and writes the result to
 * bootstrap/cache/packages.php — which the image builds correctly and then the
 * `laravel_bootstrap` named volume mounts straight over at runtime. Whatever
 * that volume held on the day it was created is what boots, so a package added
 * later is installed in vendor/, absent from the manifest, and missing from the
 * container: "Target class [purifier] does not exist", thrown from a migration,
 * with the deploy stopping there. DomPDF is on this list for the same reason.
 *
 * The entrypoint now re-runs package:discover into the volume on every boot, so
 * discovery does work again — but a provider listed here does not depend on it,
 * and after a migration was stopped mid-deploy by exactly that dependency, that
 * is the belt worth keeping alongside the braces.
 */
return [
    App\Providers\AppServiceProvider::class,
    App\Providers\RouteServiceProvider::class,
    App\Providers\AuthServiceProvider::class,
    Barryvdh\DomPDF\ServiceProvider::class,
    Mews\Purifier\PurifierServiceProvider::class,
];