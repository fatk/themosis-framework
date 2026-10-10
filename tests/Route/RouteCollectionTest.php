<?php

namespace Themosis\Tests\Route;

use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;
use Themosis\Route\Route;
use Themosis\Route\RouteCollection;

class RouteCollectionTest extends TestCase
{
    public function testDomainRoutesMatchBeforeRoutesWithoutDomain()
    {
        $routes = new RouteCollection();
        $routes->add((new Route(['GET', 'HEAD'], '{any}', ['uses' => 'Catch@all']))->where('any', '.*'));
        $routes->add((new Route(['GET', 'HEAD'], 'status', ['uses' => 'Api@status']))->domain('api.example.com'));

        $this->assertSame('Api@status', $routes->match(Request::create('http://api.example.com/status'))->getAction('uses'));
        $this->assertSame('Catch@all', $routes->match(Request::create('http://www.example.com/status'))->getAction('uses'));
        $this->assertSame('api.example.com', $routes->getRoutes()[0]->getDomain());
    }

    public function testRoutesWithTheSameUriAndLastMethodAreAllKept()
    {
        $routes = new RouteCollection();
        $routes->add(new Route(['PUT', 'POST'], 'form', ['uses' => 'Form@update']));
        $routes->add(new Route(['POST'], 'form', ['uses' => 'Form@store']));

        $this->assertCount(2, $routes->getRoutes());
    }

    public function testWordPressRoutesSharingAUriAreToldApartByTheirConditionParameters()
    {
        $routes = new RouteCollection();
        $conditions = ['is_page' => ['page']];
        $routes->add((new Route(['GET', 'HEAD'], 'page', ['uses' => 'Page@about', 'about']))->setConditions($conditions));
        $routes->add((new Route(['GET', 'HEAD'], 'page', ['uses' => 'Page@contact', 'contact']))->setConditions($conditions));

        $this->assertCount(2, $routes->getRoutes());
        $this->assertCount(2, $routes->get('GET'));
    }

    public function testSerializedCollectionKeepsDomainRoutes()
    {
        $routes = new RouteCollection();
        $routes->add(new Route(['GET', 'HEAD'], 'about', ['uses' => 'Page@about']));
        $routes->add((new Route(['GET', 'HEAD'], 'about', ['uses' => 'Api@about']))->domain('api.example.com'));

        foreach ($routes as $route) {
            $route->prepareForSerialization();
        }

        $cached = unserialize(serialize($routes));

        $this->assertCount(2, $cached->getRoutes());
        $this->assertSame('Api@about', $cached->match(Request::create('http://api.example.com/about'))->getAction('uses'));
        $this->assertSame('Page@about', $cached->match(Request::create('http://www.example.com/about'))->getAction('uses'));
    }
}
