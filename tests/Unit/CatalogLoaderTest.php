<?php

declare(strict_types=1);

namespace OpenColibri\Starter\Fixtures\Tests\Unit;

use OpenColibri\Starter\Core\App;
use OpenColibri\Starter\Core\Dataset\Dataset;
use OpenColibri\Starter\Fixtures\CatalogLoader;
use OpenColibri\Starter\Fixtures\CatalogTarget;
use OpenColibri\Starter\Fixtures\Extension\FixturesExtensionInterface;
use OpenColibri\Starter\Fixtures\Extension\FixturesExtensions;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class CatalogLoaderTest extends TestCase
{
    private string $directory;
    private CatalogLoader $loader;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/catalog-loader-' . bin2hex(random_bytes(4));
        mkdir($this->directory . '/app', 0o775, true);
        $this->loader = new CatalogLoader(new App('app', $this->directory . '/app'));
        FixturesExtensions::register('test', new class implements FixturesExtensionInterface {
            public function scaffold(App $app): void {}

            public function buildFixtures(CatalogTarget $target): array
            {
                return ['shop_user' => ['target' => $target->key]];
            }
        });
    }

    protected function tearDown(): void
    {
        FixturesExtensions::unregister('test');
        (new Filesystem())->remove($this->directory);
    }

    public function testGeneratesTheFixturesAndCopiesTheImages(): void
    {
        $target = new CatalogTarget('tea', 'Tea', 'TEA', 'tea.app.test');
        $this->loader->generate($this->dataset(), $target);

        static::assertTrue($this->loader->isGenerated($target));
        static::assertFileExists($this->directory . '/app/config/sylius/fixtures/import/tea/products.php');
        static::assertFileExists($this->directory . '/app/config/sylius/fixtures/import/tea/extensions/shop_user.php');
        static::assertFileExists($this->directory . '/app/var/import/tea/media/sencha.webp');
        static::assertStringContainsString("'name' => 'Sencha'", (string) file_get_contents($this->directory . '/app/config/sylius/fixtures/import/tea/products.php'));
    }

    public function testPointsTheSuiteAtTheCatalogUntilItIsRemoved(): void
    {
        $target = CatalogTarget::defaultChannel('Tea');
        $this->loader->generate($this->dataset(), $target);
        $this->loader->writeSuite($target);
        $suite = $this->directory . '/app/config/sylius/fixtures/import.php';

        static::assertStringContainsString("'resource' => 'import/catalog/products.php'", (string) file_get_contents($suite));
        static::assertStringContainsString("'resource' => 'import/catalog/extensions/shop_user.php'", (string) file_get_contents($suite));

        $this->loader->removeFiles($target);

        static::assertFalse($this->loader->isGenerated($target));
        static::assertDirectoryDoesNotExist($this->directory . '/app/var/import/catalog');
        static::assertStringNotContainsString('import/catalog/', (string) file_get_contents($suite));
    }

    public function testRefusesADatasetWithoutProducts(): void
    {
        $path = $this->directory . '/empty';
        mkdir($path, 0o775, true);
        file_put_contents($path . '/products.yaml', "products: []\n");

        $this->expectExceptionMessage('Dataset "empty" has no product.');

        $this->loader->generate(new Dataset('empty', $path), CatalogTarget::defaultChannel('Empty'));
    }

    private function dataset(): Dataset
    {
        $path = $this->directory . '/tea';
        mkdir($path . '/media', 0o775, true);
        file_put_contents($path . '/collections.yaml', "collections:\n  - name: Green\n");
        file_put_contents($path . '/products.yaml', "products:\n  - title: Sencha\n    collection_name: Green\n    price_eur: 9\n    image: media/sencha.webp\n");
        file_put_contents($path . '/media/sencha.webp', 'webp');

        return new Dataset('tea', $path);
    }
}
