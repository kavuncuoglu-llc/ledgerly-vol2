<?php

namespace App\Ai\Packages;

use Carbon\CarbonImmutable;

// Chapter 48, Topic 7: what Composer knows about one package version, wherever it came from: composer.lock's installed copy (offline) or Packagist's metadata for a package the app does not install.
final readonly class PackageFacts
{
    /**
     * @param  list<string>  $licenses
     * @param  array<string, string>  $require
     * @param  list<string>  $dev
     */
    public function __construct(
        public string $name,
        public string $version,
        public bool $installed,
        public array $licenses,
        public array $require,
        public ?CarbonImmutable $released,
        public ?string $directory,
        public bool|string|null $abandoned = null,
    ) {}
}
