<?php

declare(strict_types=1);

namespace OpenColibri\Starter\Fixtures\Tasks;

use Castor\Api\Attribute\AsApi;
use Castor\Attribute\AsArgument;
use Castor\Attribute\AsOption;
use Castor\Attribute\AsTask;
use Castor\Exception\ProblemException;
use OpenColibri\Starter\Core\App;
use OpenColibri\Starter\Core\Dataset\Dataset;
use OpenColibri\Starter\Core\Dataset\Datasets;
use OpenColibri\Starter\Fixtures\CatalogLoader;
use OpenColibri\Starter\Fixtures\CatalogTarget;

use function Castor\io;

/**
 * Lists the datasets, and loads one into the default channel of the shop. See docs/datasets/index.md.
 */
final readonly class DatasetTasks
{
    public function __construct(
        private App $app,
    ) {}

    /**
     * @return iterable<int, array{task: AsTask, function: callable}>
     */
    public function __invoke(): iterable
    {
        yield [
            'task' => new AsTask('list', 'sylius:dataset', 'List the datasets that can be loaded into the shop'),
            /** @phpstan-ignore-next-line  */
            'function' => #[AsApi] function (): void {
                self::writeList(array_values(array_map(static fn(Dataset $dataset): array => $dataset->describe(), Datasets::all())));
            },
        ];

        yield [
            'task' => new AsTask('load', 'sylius:dataset', 'Load a dataset into the default channel, replacing the catalog loaded before'),
            /** @phpstan-ignore-next-line  */
            'function' => #[AsApi(async: true, lock: 'sylius:database')] function (
                #[AsArgument(description: 'Dataset slug (see sylius:dataset:list)')]
                ?string $dataset = null,
                #[AsOption(description: 'Maximum number of products')]
                ?int $limit = null,
            ): void {
                $this->run(function () use ($dataset, $limit): void {
                    $dataset = Datasets::get($dataset ?? io()->choice('Which dataset would you like to load?', array_keys(Datasets::all())));
                    $target = CatalogTarget::defaultChannel($dataset->name());
                    $loader = new CatalogLoader($this->app);

                    io()->title(\sprintf('Loading dataset %s into the default channel', $dataset->slug));
                    $loader->generate($dataset, $target, $limit);
                    $loader->load($target);
                    io()->success(\sprintf('Dataset %s loaded.', $dataset->slug));
                });
            },
        ];

        yield [
            'task' => new AsTask('unload', 'sylius:dataset', 'Remove the catalog loaded by sylius:dataset:load from the default channel'),
            /** @phpstan-ignore-next-line  */
            'function' => #[AsApi(async: true, lock: 'sylius:database')] function (): void {
                $this->run(function (): void {
                    (new CatalogLoader($this->app))->unload(CatalogTarget::defaultChannel('Catalog'));
                    io()->success('Catalog removed from the default channel.');
                });
            },
        ];
    }

    /**
     * JSON on non-TTY (castor-api, pipes), a table when a human is at a terminal.
     *
     * @param list<array<string, mixed>> $datasets
     */
    public static function writeList(array $datasets, ?bool $tty = null): void
    {
        if (!($tty ?? stream_isatty(\STDOUT))) {
            io()->writeln(json_encode($datasets, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE));

            return;
        }

        if ([] === $datasets) {
            io()->writeln('No datasets found.');

            return;
        }

        io()->table(['Name', 'Slug', 'Source', 'Products', 'Images', 'Collections'], array_map(static fn(array $dataset): array => [
            $dataset['name'],
            $dataset['slug'],
            $dataset['builtIn'] ? 'built-in' : (string) ($dataset['source'] ?? '—'),
            (string) $dataset['productCount'],
            \sprintf('%d/%d', $dataset['imageCount'], $dataset['productCount']),
            (string) $dataset['collectionCount'],
        ], $datasets));
    }

    /**
     * Errors end the task with a non-zero exit code, so castor-api reports the run as failed.
     */
    private function run(callable $callback): void
    {
        try {
            $callback();
        } catch (\InvalidArgumentException|\RuntimeException $exception) {
            throw new ProblemException($exception->getMessage(), previous: $exception);
        }
    }
}
