<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Guards the two failure modes that let EnhancedReportController rot.
 *
 * Sixteen of its nineteen public methods were routed nowhere, and each carried a
 * "GET /api/v1/admin/reports/..." docblock naming a URL that either 404'd or was
 * served by ReportController's own implementation. Nothing failed, because
 * nothing checked: an unrouted controller method is invisible to a test suite
 * that only exercises HTTP, and a comment cannot 404.
 *
 * So these assertions read the route table rather than the docblocks:
 *  1. every public method on a report controller is mounted somewhere, and
 *  2. every URL a method's docblock advertises is a route that reaches THAT
 *     method.
 *
 * Adding an unreachable report, or moving a route without correcting the comment
 * above the action, fails here.
 */
class ReportRoutingTest extends TestCase
{
    /** Controllers whose every public method must be reachable over HTTP. */
    private const REPORT_CONTROLLERS = [
        \App\Http\Controllers\Api\EnhancedReportController::class,
        \App\Http\Controllers\Api\ReportController::class,
        \App\Http\Controllers\Api\ExecutiveReportController::class,
    ];

    /**
     * Map of "Controller@method" => list of mounted URIs, built from the real
     * route table rather than from a hand-maintained fixture.
     */
    private function routedActions(): array
    {
        $map = [];

        foreach (Route::getRoutes() as $route) {
            $action = $route->getActionName();

            if (! str_contains($action, '@')) {
                continue;
            }

            $map[$action][] = $route->uri();
        }

        return $map;
    }

    /** Public, non-inherited, non-magic methods declared on the controller. */
    private function publicActions(string $controller): array
    {
        $methods = (new ReflectionClass($controller))->getMethods(ReflectionMethod::IS_PUBLIC);

        return array_values(array_filter(
            $methods,
            fn (ReflectionMethod $m) => $m->getDeclaringClass()->getName() === $controller
                && ! str_starts_with($m->getName(), '__')
        ));
    }

    public function test_every_public_report_action_is_reachable_from_the_route_table(): void
    {
        $routed = $this->routedActions();
        $orphans = [];

        foreach (self::REPORT_CONTROLLERS as $controller) {
            foreach ($this->publicActions($controller) as $method) {
                if (empty($routed[$controller.'@'.$method->getName()])) {
                    $orphans[] = class_basename($controller).'::'.$method->getName().'()';
                }
            }
        }

        $this->assertSame([], $orphans, sprintf(
            "These report actions are routed nowhere, so no request can reach them:\n  %s\n"
            .'Mount them in routes/api.php or delete them — an unreachable report is dead code '
            .'that still has to be read, reviewed and kept compiling.',
            implode("\n  ", $orphans)
        ));
    }

    public function test_docblock_urls_resolve_to_the_action_they_document(): void
    {
        $routed = $this->routedActions();
        $wrong = [];

        foreach (self::REPORT_CONTROLLERS as $controller) {
            foreach ($this->publicActions($controller) as $method) {
                $doc = $method->getDocComment();

                if ($doc === false) {
                    continue;
                }

                // "GET /api/v1/admin/reports/x" and "GET /admin/reports/x" are both
                // used in these files; normalise to the route table's own form,
                // which has no leading slash and always carries the api/v1 prefix.
                if (! preg_match_all('#^\s*\*\s*(?:GET|POST|PUT|PATCH|DELETE)\s+(/\S+)#mi', $doc, $matches)) {
                    continue;
                }

                $actual = $routed[$controller.'@'.$method->getName()] ?? [];

                foreach ($matches[1] as $claimed) {
                    $normalised = ltrim($claimed, '/');
                    $normalised = preg_replace('#^api/v1/#', '', $normalised);
                    $normalised = preg_replace('#^admin/#', '', $normalised);

                    $reached = false;
                    foreach ($actual as $uri) {
                        $uriNorm = preg_replace('#^api/v1/admin/#', '', $uri);
                        // {id} vs {metric} etc: compare the static prefix only.
                        if ($uriNorm === $normalised
                            || str_starts_with($uriNorm, rtrim($normalised, '/').'/')
                            || str_starts_with($normalised, rtrim($uriNorm, '/').'/')
                            || preg_replace('#/\{[^}]+\}#', '', $uriNorm) === preg_replace('#/\{[^}]+\}#', '', $normalised)
                        ) {
                            $reached = true;
                            break;
                        }
                    }

                    if (! $reached) {
                        $wrong[] = sprintf(
                            '%s::%s() documents "%s" but is mounted at [%s]',
                            class_basename($controller),
                            $method->getName(),
                            $claimed,
                            $actual ? implode(', ', $actual) : 'no route at all'
                        );
                    }
                }
            }
        }

        $this->assertSame([], $wrong, sprintf(
            "These docblocks advertise a URL that does not reach the method they sit above:\n  %s\n"
            .'A wrong URL in a comment is worse than no comment — it sends the next reader, '
            ."and any client built from it, to a 404 or to another controller's implementation.",
            implode("\n  ", $wrong)
        ));
    }
}
