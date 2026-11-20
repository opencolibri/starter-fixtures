<?php

declare(strict_types=1);

namespace OpenColibri\Starter\Fixtures;

use OpenColibri\Starter\Core\App;
use OpenColibri\Starter\Core\Service\SyliusService;
use OpenColibri\Starter\Core\Task\TaskProviderRegistry;
use OpenColibri\Starter\Core\Util\Fixtures;
use OpenColibri\Starter\Core\Util\Docker;
use OpenColibri\Starter\Fixtures\Tasks\DatasetTasks;

TaskProviderRegistry::register(
    'fixtures',
    static fn(SyliusService $service): iterable => (new DatasetTasks(new App($service->getName(), $service->getDirectory(), $service->getDomains()[0] ?? null)))(),
);

// The "app" suite (locales, channel, admin…) ships with the application scaffold.
Fixtures::beforeLoad(static function (App $app, array $suites): void {
    if (!\in_array('app', $suites, true)) {
        return;
    }

    $wasDeployed = ApplicationScaffold::isDeployed($app);
    ApplicationScaffold::ensure($app);

    if (!$wasDeployed) {
        Docker::run($app, 'php bin/console cache:clear --no-warmup -n');
    }
});
