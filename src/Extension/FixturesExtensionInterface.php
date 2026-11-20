<?php

declare(strict_types=1);

namespace OpenColibri\Starter\Fixtures\Extension;

use OpenColibri\Starter\Core\App;
use OpenColibri\Starter\Fixtures\CatalogTarget;

/**
 * Lets a package hook into the catalog fixtures without the starter knowing it: e.g. the sales kit adding a customer
 * account, an admin or a theme to each shop it loads.
 */
interface FixturesExtensionInterface
{
    /**
     * Called each time the application scaffold is ensured (first deploy and every later run).
     */
    public function scaffold(App $app): void;

    /**
     * Fixture files added to the suite of the catalog, loaded after it.
     *
     * @return array<string, array<string, mixed>> file name (without extension) => fixture configuration
     */
    public function buildFixtures(CatalogTarget $target): array;
}
