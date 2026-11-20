<?php

declare(strict_types=1);

namespace OpenColibri\Starter\Fixtures;

use OpenColibri\Starter\Core\App;
use OpenColibri\Starter\Core\Storefront\Brand;
use OpenColibri\Starter\Core\Storefront\Hero;
use OpenColibri\Starter\Core\Util\Database;
use OpenColibri\Starter\Core\Util\Log;
use OpenColibri\Starter\Core\Util\Runner;
use OpenColibri\Starter\Core\Util\Yaml;
use OpenColibri\Starter\Fixtures\Extension\FixturesExtensions;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml as YamlParser;

/**
 * What the catalog fixtures need in the Sylius application (resources/application/): the "app" and "import" fixture
 * suites, the fixtures and the reset command of a catalog, and the homepage collections. Deployed once, without
 * overriding a file the project changed; the files listed in OWNED_FILES are refreshed on every run.
 */
final class ApplicationScaffold
{
    /** Files owned by this package (not meant to be customized), kept up to date in deployed applications. */
    public const array OWNED_FILES = [
        'src/Command/ResetImportChannelCommand.php',
        'src/Fixture/ImportChannelAccessFixture.php',
        'config/sylius/taxon_images.yaml',
    ];

    public static function resourcesDir(): string
    {
        return \dirname(__DIR__) . '/resources/application';
    }

    public static function isDeployed(App $app): bool
    {
        return is_file($app->directory() . '/config/sylius/fixtures/app.php');
    }

    public static function ensure(App $app): void
    {
        if (!is_dir($app->directory())) {
            throw new \RuntimeException(\sprintf(
                'Sylius application not found at "%s". Run "castor sylius:install" first.',
                $app->directory(),
            ));
        }

        if (self::isDeployed($app)) {
            self::upgrade($app);
            self::scaffoldExtensions($app);

            return;
        }

        Log::comment('Deploying the catalog fixtures into the Sylius application...');
        (new Filesystem())->mirror(self::resourcesDir(), $app->directory(), options: ['override' => false]);

        Hero::install($app);
        Brand::install($app);

        foreach (['../sylius/fixtures/app.php', '../sylius/fixtures/import.php', '../sylius/twig_hooks/**/**', '../sylius/taxon_images.yaml'] as $resource) {
            Yaml::addImport($app, 'config/packages/_sylius.yaml', $resource);
        }

        self::scaffoldExtensions($app);

        try {
            Runner::run($app, 'composer dump-autoload');
        } catch (\Throwable) {
            Log::comment('composer dump-autoload skipped (the stack may not be running yet).');
        }

        Database::migrate($app);
    }

    private static function scaffoldExtensions(App $app): void
    {
        foreach (FixturesExtensions::all() as $extension) {
            $extension->scaffold($app);
        }
    }

    /**
     * Brings an application deployed by an earlier version up to date.
     */
    private static function upgrade(App $app): void
    {
        $filesystem = new Filesystem();

        foreach (self::OWNED_FILES as $file) {
            $source = self::resourcesDir() . '/' . $file;
            $target = $app->directory() . '/' . $file;

            if (!is_file($target) || file_get_contents($source) !== file_get_contents($target)) {
                $filesystem->copy($source, $target, true);
                Log::comment(\sprintf('Updated %s.', $file));
            }
        }

        // Applications deployed before the taxon images got their own file.
        if (is_file($app->directory() . '/config/packages/_sylius.yaml')) {
            Yaml::addImport($app, 'config/packages/_sylius.yaml', '../sylius/taxon_images.yaml');
        }

        self::removeLegacyHeaderOverrides($app);
    }

    /**
     * Applications deployed before the header layout belonged to Sylius and the themes got
     * config/sylius/twig_hooks/shop/header.yaml, which disabled the navbar menu and the mobile category toggle of
     * every theme. Remove it if it is the generated one.
     */
    private static function removeLegacyHeaderOverrides(App $app): void
    {
        $path = $app->directory() . '/config/sylius/twig_hooks/shop/header.yaml';

        if (!is_file($path)) {
            return;
        }

        try {
            $config = YamlParser::parseFile($path);
        } catch (ParseException) {
            return;
        }

        $hooks = [
            'sylius_shop.base.header.navbar' => ['menu' => ['enabled' => false]],
            'sylius_shop.base.header.content' => ['taxon_hamburger' => ['enabled' => false]],
        ];
        $legacyLogoHook = [
            'sylius_shop.base.header.content.logo' => ['content' => ['template' => 'shop/shared/layout/header/logo.html.twig']],
        ];

        if (\in_array($config, [['sylius_twig_hooks' => ['hooks' => $hooks]], ['sylius_twig_hooks' => ['hooks' => $legacyLogoHook + $hooks]]], true)) {
            (new Filesystem())->remove($path);
            Log::comment('Removed the generated header overrides: the header layout belongs to Sylius and the themes.');
        }
    }
}
