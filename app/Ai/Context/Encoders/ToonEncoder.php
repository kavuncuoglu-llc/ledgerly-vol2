<?php

namespace App\Ai\Context\Encoders;

use HelgeSverre\Toon\DecodeOptions;
use HelgeSverre\Toon\EncodeOptions;
use HelgeSverre\Toon\Exceptions\DecodeException;
use HelgeSverre\Toon\SpecVersion;
use HelgeSverre\Toon\Toon;
use InvalidArgumentException;

// Chapter 48, Topic 4: a TOON library behind the encoder interface, with the spec version pinned beside the package version. The package (helgesverre/toon) carries several wire formats and never picks one for you (its default is the 3.3 profile of an older release), so the version is named on every call and read from config('ai.context.toon.spec_version'): an upgrade that changes the encoding fails ToonConformanceTest instead of silently changing every prompt.
final class ToonEncoder
{
    public function __construct(private SpecVersion $version, private string $delimiter = ',', private int $indent = 2) {}

    public static function pinned(string $delimiter = ',', int $indent = 2): self
    {
        return self::forVersion((string) config('ai.context.toon.spec_version'), $delimiter, $indent);
    }

    public static function forVersion(string $version, string $delimiter = ',', int $indent = 2): self
    {
        $known = SpecVersion::tryFrom($version);

        if ($known === null) {
            throw new InvalidArgumentException("[{$version}] is not a TOON spec version the installed library implements: ".implode(', ', array_map(fn (SpecVersion $case) => $case->value, SpecVersion::cases())).'.');
        }

        return new self($known, $delimiter, $indent);
    }

    public function specVersion(): string
    {
        return $this->version->value;
    }

    public function encode(mixed $data): string
    {
        return Toon::encode($data, new EncodeOptions(indent: $this->indent, delimiter: $this->delimiter, version: $this->version));
    }

    // Strict unless a caller says otherwise: the declared row counts and field lists are checked, and a model's malformed attempt is an error to repair, not a truncated table to trust. Nothing in the app asks for the lenient mode; the parameter is there so the spec's lenient fixtures can be run.
    public function decode(string $toon, bool $strict = true): mixed
    {
        try {
            return Toon::decode($toon, new DecodeOptions(indent: $this->indent, strict: $strict, version: $this->version));
        } catch (DecodeException $e) {
            throw new ToonDecodeError($e->getMessage(), previous: $e);
        }
    }
}
