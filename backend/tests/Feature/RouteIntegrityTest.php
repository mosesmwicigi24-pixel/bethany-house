<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Every route answers with code that exists. Twelve routes once pointed at
 * controller methods nobody wrote (a POS receipt, the till summary, a
 * customer's default address…): each returned a server error to whoever
 * called it. This keeps a route from being added ahead of its method again.
 */
class RouteIntegrityTest extends TestCase
{
    public function test_every_route_points_at_a_method_that_exists(): void
    {
        $missing = [];
        foreach (Route::getRoutes() as $route) {
            $action = $route->getActionName();
            if (!str_contains($action, '@')) {
                continue;   // closures and invokables
            }
            [$class, $method] = explode('@', $action);
            if (!class_exists($class) || !method_exists($class, $method)) {
                $missing[] = implode('|', $route->methods()) . ' ' . $route->uri() . ' → ' . $action;
            }
        }

        $this->assertSame([], $missing, "Routes without a controller method:\n" . implode("\n", $missing));
    }
}
