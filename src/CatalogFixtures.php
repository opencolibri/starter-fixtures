<?php

declare(strict_types=1);

namespace OpenColibri\Starter\Fixtures;

use OpenColibri\Starter\Core\Dataset\Dataset;
use OpenColibri\Starter\Core\Util\Fixtures as CoreFixtures;

/**
 * Turns a dataset into the configuration of the "import" fixture suite: taxons (the collections, under a root taxon
 * used as the channel menu), products with their image, prices (a few of them on promo) and the channel.
 *
 * Pure: it reads the dataset and computes the fixtures, without network, AI or database. The dataset already tells
 * each product's collection and price (see Dataset).
 */
final class CatalogFixtures
{
    /** Taxons shown in a flat menu: beyond this, theme menus wrap over many lines. */
    public const int MENU_TAXON_LIMIT = 12;

    public const int PROMO_PRODUCT_COUNT = 5;
    public const float PROMO_ORIGINAL_PRICE_RATIO = 1.33;
    public const float PRICE_EUR_MIN = 3.0;
    public const float PRICE_EUR_FALLBACK = 19.99;

    /** Fixture files, in the order the suite loads them. */
    public const array FILES = ['taxons', 'taxon_images', 'channel', 'channel_access', 'products', 'product_prices'];

    public static function build(Dataset $dataset, CatalogTarget $target, ?int $limit = null): GeneratedCatalog
    {
        $taxons = self::taxonIndex($dataset, $target);
        $products = array_values(array_filter(
            $dataset->products(),
            static fn(array $product): bool => '' !== trim((string) ($product['title'] ?? '')),
        ));

        if (null !== $limit) {
            $products = \array_slice($products, 0, max(0, $limit));
        }

        $assignments = [];

        foreach ($products as $index => $product) {
            // A product points at the code of its collection, or at its name. An unknown one goes to the root taxon.
            $collection = \is_scalar($product['collection'] ?? null) ? (string) $product['collection'] : (string) ($product['collection_name'] ?? '');

            if (null !== $taxon = self::find(Codes::normalizeLabel($collection), $taxons)) {
                $assignments[$index] = $taxon['code'];
            }
        }

        [$taxons, $assignments] = self::menuTaxons($taxons, $assignments);
        $parents = array_column(array_filter($taxons, static fn(array $taxon): bool => null !== $taxon['parent']), 'parent', 'code');

        $taxonImages = [];

        foreach ($taxons as $taxon) {
            if (null !== $path = self::imageFixturePath($dataset, $target, $taxon['image'])) {
                $taxonImages[] = ['taxon' => $taxon['code'], 'path' => $path, 'type' => 'main'];
            }
        }

        $promoIndexes = array_fill_keys(self::promoIndexes(\count($products)), true);
        $productEntries = [];
        $priceEntries = [];
        $usedCodes = [];
        $usedSlugs = [];
        $missingImages = 0;

        foreach ($products as $index => $product) {
            $title = trim((string) $product['title']);
            $explicitCode = \is_string($product['code'] ?? null) ? $product['code'] : '';
            $code = $target->prefixedCode(Codes::unique(Codes::fromLabel('' !== trim($explicitCode) ? $explicitCode : $title, 'product'), $usedCodes));
            $description = trim((string) ($product['description'] ?? ''));
            $description = '' !== $description ? $description : $title;
            $taxonCode = $assignments[$index] ?? $target->menuTaxonCode();

            $entry = [
                'name' => $title,
                'code' => $code,
                'slug' => Codes::unique(Codes::slug($code), $usedSlugs, '-'),
                'short_description' => $description,
                'description' => $description,
                'main_taxon' => $taxonCode,
                // Parent taxons list the product too, so a group page (e.g. "Men") shows its children's products.
                'taxons' => array_values(array_unique([$taxonCode, ...self::ancestors($taxonCode, $parents), $target->menuTaxonCode()])),
                'channels' => [$target->channelCode],
                'tax_category' => 'other',
            ];

            $image = \is_string($product['image'] ?? null) ? $product['image'] : null;

            if (null !== $path = self::imageFixturePath($dataset, $target, $image)) {
                $entry['images'] = [['path' => $path, 'type' => 'main']];
            } elseif (null !== $image && '' !== trim($image)) {
                ++$missingImages;
            }

            $productEntries[] = $entry;

            $price = self::priceCents($product['price_eur'] ?? null);
            $priceEntry = ['code' => $code, 'price' => $price, 'channel' => $target->channelCode];

            if (isset($promoIndexes[$index])) {
                $priceEntry['original_price'] = (int) round($price * self::PROMO_ORIGINAL_PRICE_RATIO);
            }

            $priceEntries[] = $priceEntry;
        }

        return new GeneratedCatalog(
            files: [
                'taxons' => self::suite('import_taxons', 'taxon', [
                    $target->menuTaxonCode() => [
                        'code' => $target->menuTaxonCode(),
                        'name' => 'Category',
                        'slug' => Codes::slug($target->menuTaxonCode()),
                        'children' => self::taxonChildren($taxons, null),
                    ],
                ]),
                'taxon_images' => self::suite('import_taxon_images', 'import_taxon_image', $taxonImages),
                // The default channel already exists (app suite).
                'channel' => $target->isDefaultChannel() ? [] : self::suite('import_channel', 'channel', [
                    $target->channelCode => self::channel($target),
                ]),
                'channel_access' => self::suite('import_channel_access', 'import_channel_access', [
                    // A dedicated channel gets its menu taxon when it is created.
                    $target->isDefaultChannel()
                        ? ['channel' => $target->channelCode, 'menu_taxon' => $target->menuTaxonCode()]
                        : ['channel' => $target->channelCode],
                ]),
                'products' => self::suite('import_products', 'import_product', $productEntries),
                'product_prices' => self::suite('import_product_prices', 'import_product_price', $priceEntries),
            ],
            taxonCount: \count($taxons),
            productCount: \count($productEntries),
            imageCount: \count(array_filter($productEntries, static fn(array $entry): bool => isset($entry['images']))),
            missingImageCount: $missingImages,
            promoCount: \count(array_intersect_key($promoIndexes, $products)),
        );
    }

    /**
     * One taxon per collection, with its parent code. A collection is identified by its code when it has one (a
     * grouped menu may repeat a name across groups), otherwise by its name: collections of the same name are merged.
     *
     * @return list<array{code: string, name: string, slug: string, image: ?string, parent: ?string, id: string}>
     */
    public static function taxonIndex(Dataset $dataset, CatalogTarget $target): array
    {
        /** @var array<string, array{code: string, name: string, slug: string, image: ?string, parent: ?string, id: string, parentId: string}> $indexed */
        $indexed = [];
        $usedCodes = [];

        foreach ($dataset->collections() as $collection) {
            $name = trim((string) ($collection['name'] ?? ''));

            if ('' === $name) {
                continue;
            }

            $image = \is_string($collection['image'] ?? null) && !\in_array(strtolower(trim($collection['image'])), ['', 'null'], true)
                ? trim($collection['image'])
                : null;
            $parentId = Codes::normalizeLabel((string) ($collection['parent'] ?? ''));
            $id = Codes::normalizeLabel(\is_scalar($collection['code'] ?? null) && '' !== trim((string) $collection['code']) ? (string) $collection['code'] : $name);

            if (isset($indexed[$id])) {
                $indexed[$id]['image'] ??= $image;
                $indexed[$id]['parentId'] = '' !== $indexed[$id]['parentId'] ? $indexed[$id]['parentId'] : $parentId;

                continue;
            }

            $code = Codes::unique($target->prefixedCode(Codes::fromLabel($id)), $usedCodes);
            $indexed[$id] = ['code' => $code, 'name' => $name, 'slug' => $code, 'image' => $image, 'parent' => null, 'id' => $id, 'parentId' => $parentId];
        }

        $taxons = [];

        foreach ($indexed as $taxon) {
            $parent = '' !== $taxon['parentId'] ? self::find($taxon['parentId'], $indexed)['code'] ?? null : null;
            unset($taxon['parentId']);
            $taxon['parent'] = $parent !== $taxon['code'] ? $parent : null;
            $taxons[] = $taxon;
        }

        return $taxons;
    }

    /**
     * Keeps the taxons the menu shows. In a flat menu, the taxons holding the most products, in their dataset order:
     * products of a dropped taxon fall back to the root taxon. In a grouped menu (collections with a parent, grouped
     * when the dataset was built), the taxons holding products and their ancestors.
     *
     * @param list<array{code: string, name: string, slug: string, image: ?string, parent: ?string, id: string}> $taxons
     * @param array<int, string>                                                                    $assignments
     *
     * @return array{0: list<array{code: string, name: string, slug: string, image: ?string, parent: ?string, id: string}>, 1: array<int, string>}
     */
    public static function menuTaxons(array $taxons, array $assignments, int $limit = self::MENU_TAXON_LIMIT): array
    {
        $counts = array_count_values($assignments);

        if ([] !== array_filter(array_column($taxons, 'parent'))) {
            $parents = array_column($taxons, 'parent', 'code');
            $kept = [];

            foreach (array_keys($counts) as $code) {
                if (\array_key_exists($code, $parents)) {
                    $kept[$code] = true;

                    foreach (self::ancestors($code, array_filter($parents)) as $ancestor) {
                        $kept[$ancestor] = true;
                    }
                }
            }

            return [array_values(array_filter($taxons, static fn(array $taxon): bool => isset($kept[$taxon['code']]))), $assignments];
        }

        $positions = [];

        foreach ($taxons as $position => $taxon) {
            if (($counts[$taxon['code']] ?? 0) > 0) {
                $positions[$taxon['code']] = $position;
            }
        }

        uksort(
            $positions,
            static fn(int|string $a, int|string $b): int => [$counts[$b], $positions[$a]] <=> [$counts[$a], $positions[$b]],
        );
        $kept = array_flip(\array_slice(array_keys($positions), 0, $limit));
        $dropped = array_diff_key($positions, $kept);

        return [
            array_values(array_filter($taxons, static fn(array $taxon): bool => isset($kept[$taxon['code']]))),
            array_filter($assignments, static fn(string $code): bool => !isset($dropped[$code])),
        ];
    }

    /**
     * Spread over the catalog, so the promos are not all in the first collection.
     *
     * @return list<int>
     */
    public static function promoIndexes(int $productCount, int $count = self::PROMO_PRODUCT_COUNT): array
    {
        $count = min($count, $productCount);
        $indexes = [];

        for ($i = 0; $i < $count; ++$i) {
            $indexes[] = (int) floor($i * $productCount / $count);
        }

        return array_values(array_unique($indexes));
    }

    public static function priceCents(mixed $priceEur): int
    {
        $price = is_numeric($priceEur) ? round((float) $priceEur, 2) : 0.0;

        if ($price <= 0) {
            $price = self::PRICE_EUR_FALLBACK;
        }

        return (int) round(max($price, self::PRICE_EUR_MIN) * 100);
    }

    /**
     * The collection of this code, or else of this name.
     *
     * @template T of array{name: string, id: string}
     *
     * @param array<array-key, T> $taxons
     *
     * @return T|null
     */
    private static function find(string $identifier, array $taxons): ?array
    {
        if ('' === $identifier) {
            return null;
        }

        foreach ($taxons as $taxon) {
            if ($taxon['id'] === $identifier) {
                return $taxon;
            }
        }

        foreach ($taxons as $taxon) {
            if (Codes::normalizeLabel($taxon['name']) === $identifier) {
                return $taxon;
            }
        }

        return null;
    }

    /**
     * @param array<string, string> $parents taxon code => parent code
     *
     * @return list<string>
     */
    private static function ancestors(string $code, array $parents): array
    {
        $ancestors = [];

        while (isset($parents[$code]) && !\in_array($parents[$code], $ancestors, true)) {
            $code = $parents[$code];
            $ancestors[] = $code;
        }

        return $ancestors;
    }

    /**
     * @param list<array{code: string, name: string, slug: string, image: ?string, parent: ?string, id: string}> $taxons
     *
     * @return list<array<string, mixed>>
     */
    private static function taxonChildren(array $taxons, ?string $parent): array
    {
        $children = [];

        foreach ($taxons as $taxon) {
            if ($taxon['parent'] !== $parent) {
                continue;
            }

            $child = [
                'code' => $taxon['code'],
                'name' => $taxon['name'],
                'slug' => $taxon['slug'],
                'translations' => array_fill_keys(
                    CoreFixtures::defaultChannelLocaleCodes(),
                    ['name' => $taxon['name'], 'slug' => $taxon['slug']],
                ),
            ];

            if ([] !== $grandChildren = self::taxonChildren($taxons, $taxon['code'])) {
                $child['children'] = $grandChildren;
            }

            $children[] = $child;
        }

        return $children;
    }

    /**
     * Only the images stored in the media/ directory of the dataset: they are mirrored into the application.
     */
    private static function imageFixturePath(Dataset $dataset, CatalogTarget $target, ?string $image): ?string
    {
        $file = $dataset->imageFile($image);
        $mediaDir = $dataset->mediaDir() . '/';

        if (null === $file || !str_starts_with($file, $mediaDir)) {
            return null;
        }

        return $target->mediaFixturePath(substr($file, \strlen($mediaDir)));
    }

    /**
     * @return array<string, mixed>
     */
    private static function channel(CatalogTarget $target): array
    {
        return [
            'name' => $target->name,
            'code' => $target->channelCode,
            'locales' => CoreFixtures::defaultChannelLocaleCodes(),
            'currencies' => ['EUR'],
            'hostname' => $target->hostname,
            'enabled' => true,
            'menu_taxon' => $target->menuTaxonCode(),
            'shop_billing_data' => [
                'company' => $target->name,
                'country_code' => 'FR',
                'street' => '1 rue Example',
                'city' => 'Paris',
                'postcode' => '75001',
            ],
        ];
    }

    /**
     * @param array<array-key, mixed> $custom
     *
     * @return array<string, mixed>
     */
    public static function suite(string $key, string $fixture, array $custom): array
    {
        return [
            'sylius_fixtures' => [
                'suites' => [
                    'import' => [
                        'fixtures' => [
                            $key => ['name' => $fixture, 'options' => ['custom' => $custom]],
                        ],
                    ],
                ],
            ],
        ];
    }
}
