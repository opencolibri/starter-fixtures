<?php

declare(strict_types=1);

namespace OpenColibri\Starter\Fixtures;

use OpenColibri\Starter\Core\App;
use OpenColibri\Starter\Core\Dataset\Dataset;
use OpenColibri\Starter\Core\Util\Filesystem;
use OpenColibri\Starter\Core\Util\Log;
use OpenColibri\Starter\Core\Util\Runner;
use OpenColibri\Starter\Fixtures\Extension\FixturesExtensions;
use Symfony\Component\Filesystem\Filesystem as SymfonyFilesystem;

/**
 * Generates the fixtures of a catalog into the application (config/sylius/fixtures/import/<key>/ and its images in
 * var/import/<key>/media/), loads them, or removes them.
 *
 * The "import" fixture suite (config/sylius/fixtures/import.php) holds one catalog: the last one loaded.
 */
final readonly class CatalogLoader
{
    public const string SUITE = 'import';

    /** Sub-directory of a catalog's fixtures holding the ones of the fixtures extensions. */
    public const string EXTENSIONS_DIR = 'extensions';

    private SymfonyFilesystem $filesystem;

    public function __construct(
        private App $app,
    ) {
        $this->filesystem = new SymfonyFilesystem();
    }

    /**
     * Writes the fixtures of the dataset (no database change), and copies its images into the application.
     */
    public function generate(Dataset $dataset, CatalogTarget $target, ?int $limit = null): GeneratedCatalog
    {
        $catalog = CatalogFixtures::build($dataset, $target, $limit);

        if (0 === $catalog->productCount) {
            throw new \RuntimeException(\sprintf('Dataset "%s" has no product.', $dataset->slug));
        }

        $this->filesystem->remove($this->fixturesDir($target));

        foreach ($catalog->files as $name => $config) {
            $this->writeFixture($target->fixturesDir() . '/' . $name . '.php', $config);
        }

        $this->writeExtensionFixtures($target);

        $mediaDir = $this->app->directory() . '/' . $target->mediaDir();

        if (is_dir($dataset->mediaDir())) {
            $this->filesystem->mirror($dataset->mediaDir(), $mediaDir, options: ['delete' => true, 'override' => true]);
        } else {
            $this->filesystem->remove($mediaDir);
        }

        Log::comment(\sprintf(
            'Fixtures of %s: %d taxon(s), %d product(s), %d with an image, %d on promo.',
            $target->key,
            $catalog->taxonCount,
            $catalog->productCount,
            $catalog->imageCount,
            $catalog->promoCount,
        ));

        if ($catalog->missingImageCount > 0) {
            Log::warning(\sprintf(
                '%d product image(s) are not stored in dataset "%s": run "castor sylius:dataset:images %1$s" to store them.',
                $catalog->missingImageCount,
                $dataset->slug,
            ));
        }

        return $catalog;
    }

    public function isGenerated(CatalogTarget $target): bool
    {
        return is_file($this->fixturesDir($target) . '/products.php');
    }

    /**
     * Rewrites the fixtures contributed by the extensions, dropping those of extensions no longer registered.
     */
    public function writeExtensionFixtures(CatalogTarget $target): void
    {
        $this->filesystem->remove($this->fixturesDir($target) . '/' . self::EXTENSIONS_DIR);

        foreach (FixturesExtensions::all() as $extension) {
            foreach ($extension->buildFixtures($target) as $name => $config) {
                $this->writeFixture(\sprintf('%s/%s/%s.php', $target->fixturesDir(), self::EXTENSIONS_DIR, $name), $config);
            }
        }
    }

    /**
     * Replaces the catalog in the database: removes what a previous load created (the dedicated channel, or the
     * prefixed catalog of the default channel), then loads the suite.
     */
    public function load(CatalogTarget $target): void
    {
        if (!$this->isGenerated($target)) {
            throw new \RuntimeException(\sprintf('No fixtures generated for catalog "%s".', $target->key));
        }

        ApplicationScaffold::ensure($this->app);
        $this->writeSuite($target);
        // The suite changed: rebuild the container so neither the CLI nor the storefront uses a stale one.
        $this->clearCache();
        Runner::run($this->app, $target->resetCommand());
        Runner::run($this->app, 'php bin/console sylius:fixtures:load ' . self::SUITE . ' -n');
    }

    /**
     * Removes the catalog from the database, then its fixtures and images.
     */
    public function unload(CatalogTarget $target): void
    {
        try {
            Runner::run($this->app, $target->resetCommand());
        } catch (\Throwable $exception) {
            if (!self::isMissingSchema($exception)) {
                throw $exception;
            }

            Log::comment('The Sylius database schema is not installed: nothing to remove from the database.');
        }

        $this->removeFiles($target);
        $this->clearCache();
    }

    public function removeFiles(CatalogTarget $target): void
    {
        $suiteTargetsCatalog = str_contains((string) @file_get_contents($this->suitePath()), "'" . $target->fixturesDir() . '/');

        $this->filesystem->remove([$this->fixturesDir($target), \dirname($this->app->directory() . '/' . $target->mediaDir())]);

        if ($suiteTargetsCatalog) {
            $this->writeSuite(null);
        }
    }

    /**
     * Points the suite at the fixtures of a catalog, or at none.
     */
    public function writeSuite(?CatalogTarget $target): void
    {
        $resources = [];

        if (null !== $target) {
            foreach (CatalogFixtures::FILES as $name) {
                $resources[] = ['resource' => $target->fixturesDir() . '/' . $name . '.php'];
            }

            foreach (glob($this->fixturesDir($target) . '/' . self::EXTENSIONS_DIR . '/*.php') ?: [] as $file) {
                $resources[] = ['resource' => $target->fixturesDir() . '/' . self::EXTENSIONS_DIR . '/' . basename($file)];
            }
        }

        $this->writeFixture(self::SUITE . '.php', [
            'imports' => $resources,
            'sylius_fixtures' => ['suites' => [self::SUITE => ['listeners' => ['logger' => null]]]],
        ]);
    }

    public function clearCache(): void
    {
        Runner::run($this->app, 'php bin/console cache:clear -n');
    }

    private function fixturesDir(CatalogTarget $target): string
    {
        return $this->app->directory() . '/config/sylius/fixtures/' . $target->fixturesDir();
    }

    private function suitePath(): string
    {
        return $this->app->directory() . '/config/sylius/fixtures/' . self::SUITE . '.php';
    }

    /**
     * @param array<string, mixed> $config
     */
    private function writeFixture(string $relativePath, array $config): void
    {
        $exported = Filesystem::exportArray($config);
        $this->filesystem->dumpFile(
            $this->app->directory() . '/config/sylius/fixtures/' . $relativePath,
            <<<PHP
                <?php

                declare(strict_types=1);

                namespace Symfony\\Component\\DependencyInjection\\Loader\\Configurator;

                return App::config({$exported});

                PHP,
        );
    }

    private static function isMissingSchema(\Throwable $exception): bool
    {
        $message = '';

        for ($current = $exception; null !== $current; $current = $current->getPrevious()) {
            $message .= $current->getMessage();
        }

        return str_contains($message, 'sylius_channel') && (str_contains($message, 'does not exist') || str_contains($message, '42P01'));
    }
}
