<?php

declare(strict_types=1);

namespace OpenColibri\Starter\Fixtures;

/**
 * Where a dataset is loaded in Sylius: the channel holding its catalog, and the key naming its fixtures
 * (config/sylius/fixtures/import/<key>/), its images (var/import/<key>/media/) and prefixing its product and taxon
 * codes, so loading it again replaces it without touching the rest of the shop.
 *
 * The default channel (WEB_STORE, created by the app fixture suite) is kept on reset: only the catalog is removed.
 * Any other channel is dedicated to the catalog: it is created on load, and removed on reset.
 */
final readonly class CatalogTarget
{
    public const string DEFAULT_CHANNEL = 'WEB_STORE';

    /** Key of the catalog loaded into the default channel by sylius:dataset:load. */
    public const string DEFAULT_KEY = 'catalog';

    /**
     * @param string|null $hostname      hostname of a dedicated channel (ignored on the default channel)
     * @param string|null $customerEmail a customer created with the catalog, removed when it is reset
     */
    public function __construct(
        public string $key,
        public string $name,
        public string $channelCode = self::DEFAULT_CHANNEL,
        public ?string $hostname = null,
        public ?string $customerEmail = null,
    ) {
        if ('' === $this->codePrefix() || !preg_match('/^[a-z0-9][a-z0-9_-]*$/', $key)) {
            throw new \InvalidArgumentException(\sprintf('Invalid catalog key "%s": use lowercase letters, digits, "-" and "_".', $key));
        }
    }

    public static function defaultChannel(string $name): self
    {
        return new self(self::DEFAULT_KEY, $name);
    }

    public function isDefaultChannel(): bool
    {
        return self::DEFAULT_CHANNEL === $this->channelCode;
    }

    public function codePrefix(): string
    {
        return preg_replace('/[^a-z0-9_]/', '', str_replace('-', '_', strtolower($this->key))) ?? '';
    }

    public function prefixedCode(string $code): string
    {
        return str_starts_with($code, $this->codePrefix() . '_') ? $code : $this->codePrefix() . '_' . $code;
    }

    /**
     * Root of the catalog taxons, the menu taxon of its channel.
     */
    public function menuTaxonCode(): string
    {
        return $this->prefixedCode('category');
    }

    /**
     * Relative to config/sylius/fixtures/ of the application.
     */
    public function fixturesDir(): string
    {
        return 'import/' . $this->key;
    }

    /**
     * Relative to the application directory.
     */
    public function mediaDir(): string
    {
        return 'var/import/' . $this->key . '/media';
    }

    /**
     * Image path written into the fixtures: Symfony resolves it relative to the Sylius kernel when the suite is
     * compiled, and the containers loading the fixtures only mount the application directory.
     */
    public function mediaFixturePath(string $file): string
    {
        return '%kernel.project_dir%/' . $this->mediaDir() . '/' . $file;
    }

    /**
     * The console command removing the catalog (and the dedicated channel) from the database.
     */
    public function resetCommand(): string
    {
        return \sprintf(
            'php bin/console sylius:import:channel:reset %s --prefix=%s%s%s -n',
            $this->channelCode,
            $this->codePrefix(),
            null !== $this->customerEmail ? ' --shop-email=' . $this->customerEmail : '',
            $this->isDefaultChannel() ? ' --keep-channel' : '',
        );
    }
}
