<?php

namespace Base\Video\Tests\Kernel;

use Symfony\Component\Config\Loader\Loader;
use Symfony\Component\Routing\RouteCollection;

/** @internal WithoutSuggestedPackagesKernel's routing loader of the route types a left-out bundle gives: no route */
final class LeftOutRouteTypes extends Loader
{
    /** @param list<string> $types */
    public function __construct(private readonly array $types, ?string $env = null)
    {
        parent::__construct($env);
    }

    public function load(mixed $resource, ?string $type = null): RouteCollection
    {
        return new RouteCollection();
    }

    public function supports(mixed $resource, ?string $type = null): bool
    {
        return \in_array($type, $this->types, true);
    }
}
