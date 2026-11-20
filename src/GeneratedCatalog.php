<?php

declare(strict_types=1);

namespace OpenColibri\Starter\Fixtures;

final readonly class GeneratedCatalog
{
    /**
     * @param array<string, array<string, mixed>> $files fixture file name (without extension) => configuration
     */
    public function __construct(
        public array $files,
        public int $taxonCount,
        public int $productCount,
        public int $imageCount,
        public int $missingImageCount,
        public int $promoCount,
    ) {}
}
