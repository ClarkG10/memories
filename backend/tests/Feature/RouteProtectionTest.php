<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;
use Tests\TestCase;

/**
 * Structural guarantees about the API surface.
 *
 * Every one of these was true when it was written, and each is the kind of
 * thing a new route silently opts out of. Asserting the shape of the route
 * table catches that at the moment it happens rather than in production.
 */
class RouteProtectionTest extends TestCase
{
    /**
     * @return array<int, Route>
     */
    private function apiRoutes(): array
    {
        return array_values(array_filter(
            Router::getRoutes()->getRoutes(),
            fn (Route $route): bool => str_starts_with($route->uri(), 'api/'),
        ));
    }

    private function hasThrottle(Route $route): bool
    {
        foreach ($route->gatherMiddleware() as $middleware) {
            if (is_string($middleware) && str_contains($middleware, 'throttle')) {
                return true;
            }
        }

        return false;
    }

    public function test_every_api_route_is_rate_limited(): void
    {
        $unlimited = [];

        foreach ($this->apiRoutes() as $route) {
            if (! $this->hasThrottle($route)) {
                $unlimited[] = implode('|', $route->methods()).' '.$route->uri();
            }
        }

        /*
         | The media routes matter most here. Each one either reads from disk
         | or, on a cold cache, pulls an original out of Drive — so an
         | unlimited media route on a public archive is an open bandwidth
         | amplifier and a way to burn the owner's Drive quota.
         */
        $this->assertSame([], $unlimited, 'These API routes have no rate limit.');
    }

    public function test_every_route_that_changes_anything_requires_the_owner(): void
    {
        $unprotected = [];

        foreach ($this->apiRoutes() as $route) {
            $changesSomething = (bool) array_intersect($route->methods(), ['POST', 'PUT', 'PATCH', 'DELETE']);

            // Signing in is the one write anyone may attempt.
            if (! $changesSomething || $route->uri() === 'api/auth/login') {
                continue;
            }

            if (! in_array('auth:sanctum', $route->gatherMiddleware(), true)) {
                $unprotected[] = implode('|', $route->methods()).' '.$route->uri();
            }
        }

        $this->assertSame([], $unprotected, 'These write routes are reachable without signing in.');
    }

    public function test_every_read_route_respects_the_private_archive_setting(): void
    {
        $exempt = ['api/archive', 'api/auth/login', 'api/auth/me', 'api/auth/logout'];
        $leaky = [];

        foreach ($this->apiRoutes() as $route) {
            if (! in_array('GET', $route->methods(), true) || in_array($route->uri(), $exempt, true)) {
                continue;
            }

            $middleware = $route->gatherMiddleware();

            $gated = in_array('archive.viewable', $middleware, true)
                || in_array('media.viewable', $middleware, true)
                || in_array('auth:sanctum', $middleware, true);

            if (! $gated) {
                $leaky[] = $route->uri();
            }
        }

        $this->assertSame([], $leaky, 'These read routes ignore whether the archive is private.');
    }

    public function test_the_archive_endpoint_stays_reachable_so_a_visitor_can_be_offered_a_sign_in(): void
    {
        config(['memories.public' => false]);

        // Deliberately not gated: without it a private archive shows an error
        // instead of a way in.
        $this->getJson('/api/archive')->assertOk();
    }
}
