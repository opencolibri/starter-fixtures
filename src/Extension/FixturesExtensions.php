<?php

declare(strict_types=1);

namespace OpenColibri\Starter\Fixtures\Extension;

/**
 * Packages register their extension here, usually from a file autoloaded by Composer.
 */
final class FixturesExtensions
{
    /** @var array<string, FixturesExtensionInterface> */
    private static array $extensions = [];

    public static function register(string $name, FixturesExtensionInterface $extension): void
    {
        self::$extensions[$name] = $extension;
    }

    public static function unregister(string $name): void
    {
        unset(self::$extensions[$name]);
    }

    /**
     * @return array<string, FixturesExtensionInterface>
     */
    public static function all(): array
    {
        return self::$extensions;
    }
}
