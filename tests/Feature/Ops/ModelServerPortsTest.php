<?php

use Symfony\Component\Yaml\Yaml;

// Chapter 44, Topic 9 and Appendix I.7: "Compose files publish no model-server ports; CI checks for `ports:` on those services". This repository's files do publish one port per model server (so a laptop can reach it), bound to 127.0.0.1 only
// (BOOK-ISSUES 3), so what is checked is the binding: no published port anywhere in ops/compose is open to other machines, and the servers that take a key will not start without one.
function composeServices(): array
{
    $services = [];

    foreach (glob(base_path('ops/compose/*.yml')) as $file) {
        foreach (Yaml::parseFile($file)['services'] ?? [] as $name => $service) {
            $services[basename($file).':'.$name] = $service;
        }
    }

    return $services;
}

it('publishes no port on an interface other than 127.0.0.1', function () {
    $published = [];

    foreach (composeServices() as $name => $service) {
        foreach ($service['ports'] ?? [] as $port) {
            $published[$name][] = $port;

            expect(is_string($port) && str_starts_with($port, '127.0.0.1:'))->toBeTrue("{$name} publishes [{$port}] beyond localhost");
        }
    }

    expect($published)->not->toBeEmpty();
});

it('does not start a model server that takes an API key without one', function (string $file, string $service, string $variable) {
    $environment = Yaml::parseFile(base_path("ops/compose/{$file}"))['services'][$service];

    expect(json_encode($environment))->toContain('${'.$variable.':?');
})->with([
    ['llama.yml', 'llama', 'LLAMA_API_KEY'],
    ['vllm.yml', 'vllm', 'VLLM_API_KEY'],
]);
