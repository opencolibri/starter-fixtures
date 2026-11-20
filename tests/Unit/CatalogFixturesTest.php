<?php

declare(strict_types=1);

namespace OpenColibri\Starter\Fixtures\Tests\Unit;

use OpenColibri\Starter\Core\Dataset\Dataset;
use OpenColibri\Starter\Fixtures\CatalogFixtures;
use OpenColibri\Starter\Fixtures\CatalogTarget;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Yaml;

final class CatalogFixturesTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/catalog-fixtures-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->directory);
    }

    public function testBuildsTheCatalogOfTheDefaultChannel(): void
    {
        $dataset = $this->dataset(
            [
                ['name' => 'Red wines', 'image' => 'media/red.webp'],
                ['name' => 'White wines'],
                ['name' => 'Empty'],
            ],
            [
                ['title' => 'Margaux', 'collection_name' => 'Red wines', 'price_eur' => 62, 'image' => 'media/margaux.webp', 'description' => 'Cassis'],
                ['title' => 'Chablis', 'collection_name' => 'white wines', 'price_eur' => 1, 'image' => 'https://example.com/chablis.webp'],
                ['title' => 'Mystery', 'collection_name' => 'Unknown'],
            ],
        );
        $catalog = CatalogFixtures::build($dataset, CatalogTarget::defaultChannel('Cellar'));

        static::assertSame(CatalogFixtures::FILES, array_keys($catalog->files));
        static::assertSame(3, $catalog->productCount);
        static::assertSame(1, $catalog->imageCount);
        static::assertSame(1, $catalog->missingImageCount);
        // Taxons without products are not shown in the menu.
        static::assertSame(2, $catalog->taxonCount);

        $root = $this->custom($catalog->files['taxons'], 'import_taxons')['catalog_category'];
        static::assertSame('catalog-category', $root['slug']);
        static::assertSame(['catalog_red_wines', 'catalog_white_wines'], array_column($root['children'], 'code'));

        $products = $this->custom($catalog->files['products'], 'import_products');
        static::assertSame('catalog_margaux', $products[0]['code']);
        static::assertSame('Cassis', $products[0]['description']);
        static::assertSame(['catalog_red_wines', 'catalog_category'], $products[0]['taxons']);
        static::assertSame([['path' => '%kernel.project_dir%/var/import/catalog/media/margaux.webp', 'type' => 'main']], $products[0]['images']);
        static::assertSame('catalog_white_wines', $products[1]['main_taxon']);
        static::assertArrayNotHasKey('images', $products[1]);
        static::assertSame('catalog_category', $products[2]['main_taxon']);
        static::assertSame(['WEB_STORE'], $products[2]['channels']);
        static::assertSame('Mystery', $products[2]['description']);

        $prices = $this->custom($catalog->files['product_prices'], 'import_product_prices');
        static::assertSame(6200, $prices[0]['price']);
        // Clamped to the minimum price.
        static::assertSame(300, $prices[1]['price']);
        static::assertSame(1999, $prices[2]['price']);

        static::assertSame(
            [['taxon' => 'catalog_red_wines', 'path' => '%kernel.project_dir%/var/import/catalog/media/red.webp', 'type' => 'main']],
            $this->custom($catalog->files['taxon_images'], 'import_taxon_images'),
        );
        // The default channel exists already: it only gets the menu taxon.
        static::assertSame([], $catalog->files['channel']);
        static::assertSame(
            [['channel' => 'WEB_STORE', 'menu_taxon' => 'catalog_category']],
            $this->custom($catalog->files['channel_access'], 'import_channel_access'),
        );
    }

    public function testBuildsADedicatedChannel(): void
    {
        $dataset = $this->dataset([['name' => 'Tea']], [['title' => 'Sencha', 'collection_name' => 'Tea', 'price_eur' => 9.5]]);
        $catalog = CatalogFixtures::build($dataset, new CatalogTarget('tea-house', 'Tea House', 'TEA_HOUSE', 'tea-house.app.test'));

        $channel = $this->custom($catalog->files['channel'], 'import_channel')['TEA_HOUSE'];
        static::assertSame('Tea House', $channel['name']);
        static::assertSame('tea-house.app.test', $channel['hostname']);
        static::assertSame('tea_house_category', $channel['menu_taxon']);
        static::assertSame([['channel' => 'TEA_HOUSE']], $this->custom($catalog->files['channel_access'], 'import_channel_access'));
        static::assertSame(['TEA_HOUSE'], $this->custom($catalog->files['products'], 'import_products')[0]['channels']);
    }

    public function testKeepsTheGroupsOfAGroupedMenu(): void
    {
        $dataset = $this->dataset(
            [
                ['name' => 'Men'],
                ['name' => 'Women'],
                ['name' => 'Men T-shirts', 'parent' => 'Men'],
                ['name' => 'Women T-shirts', 'parent' => 'Women'],
                ['name' => 'Men shoes', 'parent' => 'Men'],
            ],
            [
                ['title' => 'Tee', 'collection_name' => 'Men T-shirts', 'price_eur' => 20],
                ['title' => 'Blouse', 'collection_name' => 'Women T-shirts', 'price_eur' => 30],
            ],
        );
        $catalog = CatalogFixtures::build($dataset, CatalogTarget::defaultChannel('Clothes'));

        $root = $this->custom($catalog->files['taxons'], 'import_taxons')['catalog_category'];
        static::assertSame(['catalog_men', 'catalog_women'], array_column($root['children'], 'code'));
        static::assertSame(['catalog_men_t_shirts'], array_column($root['children'][0]['children'], 'code'));
        static::assertSame(
            ['catalog_men_t_shirts', 'catalog_men', 'catalog_category'],
            $this->custom($catalog->files['products'], 'import_products')[0]['taxons'],
        );
    }

    public function testLimitsTheFlatMenuAndTheProducts(): void
    {
        $collections = [];
        $products = [];

        foreach (range(1, 14) as $index) {
            $collections[] = ['name' => 'Collection ' . $index];
            $products[] = ['title' => 'Product ' . $index, 'collection_name' => 'Collection ' . $index, 'price_eur' => 10];
        }

        $products[] = ['title' => 'Product 15', 'collection_name' => 'Collection 14', 'price_eur' => 10];
        $catalog = CatalogFixtures::build($this->dataset($collections, $products), CatalogTarget::defaultChannel('Shop'));

        static::assertSame(CatalogFixtures::MENU_TAXON_LIMIT, $catalog->taxonCount);
        static::assertSame(5, $catalog->promoCount);
        static::assertSame(3, CatalogFixtures::build($this->dataset($collections, $products), CatalogTarget::defaultChannel('Shop'), 3)->productCount);
    }

    public function testBuildsEveryBuiltInDataset(): void
    {
        foreach (glob(__DIR__ . '/../../../Datasets/datasets/*', \GLOB_ONLYDIR) ?: [] as $path) {
            $dataset = new Dataset(basename($path), $path, true);
            $catalog = CatalogFixtures::build($dataset, CatalogTarget::defaultChannel($dataset->name()));

            static::assertSame(\count($dataset->products()), $catalog->productCount, $dataset->slug);
            static::assertSame($catalog->productCount, $catalog->imageCount, $dataset->slug . ' has an image for each product.');
            static::assertGreaterThan(0, $catalog->taxonCount, $dataset->slug);
        }
    }

    public function testRejectsAnInvalidKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new CatalogTarget('../shop', 'Shop');
    }

    /**
     * @param list<array<string, mixed>> $collections
     * @param list<array<string, mixed>> $products
     */
    private function dataset(array $collections, array $products): Dataset
    {
        $path = $this->directory . '/' . bin2hex(random_bytes(4));
        mkdir($path . '/media', 0o775, true);
        file_put_contents($path . '/collections.yaml', Yaml::dump(['collections' => $collections], 4));
        file_put_contents($path . '/products.yaml', Yaml::dump(['products' => $products], 4));
        file_put_contents($path . '/media/margaux.webp', 'webp');
        file_put_contents($path . '/media/red.webp', 'webp');

        return new Dataset(basename($path), $path);
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array<array-key, mixed>
     */
    private function custom(array $config, string $key): array
    {
        return $config['sylius_fixtures']['suites']['import']['fixtures'][$key]['options']['custom'];
    }
}
