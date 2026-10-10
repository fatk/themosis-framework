<?php

namespace Themosis\Route;

use Illuminate\Routing\RouteCollection as IlluminateRouteCollection;

class RouteCollection extends IlluminateRouteCollection
{
    /**
     * Add the given route to the arrays of routes.
     *
     * WordPress routes sharing a URI are told apart by their condition parameters.
     * Domain routes are kept apart so they match first (Laravel 13).
     *
     * @param \Illuminate\Routing\Route $route
     */
    protected function addToCollections($route)
    {
        $methods = $route->methods();
        $domainAndUri = $route->getDomain() . $route->uri();

        if ($route->hasCondition() && ! empty($route->getConditionParameters())) {
            $domainAndUri .= serialize($route->getConditionParameters());
        }

        $allRoutesKey = implode('|', $methods) . $domainAndUri;

        if ($route->getDomain()) {
            foreach ($methods as $method) {
                $this->domainRoutes[$method][$domainAndUri] = $route;
            }

            $this->allDomainRoutes[$allRoutesKey] = $route;
        } else {
            foreach ($methods as $method) {
                $this->routes[$method][$domainAndUri] = $route;
            }

            $this->allRoutes[$allRoutesKey] = $route;
        }
    }
}
