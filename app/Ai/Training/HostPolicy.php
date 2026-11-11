<?php

namespace App\Ai\Training;

use Illuminate\Support\Facades\Config;
use InvalidArgumentException;

// Chapter 41, Topic 2 and Chapter 43, Topics 1 and 6: may this dataset's rows go to this provider (and region)? Rows with no customer data may go anywhere. Customer-derived rows (production conversations, agent edits) go only where the dataset's manifest records the provider and region as allowed, and only to a provider whose own privacy block says it does not train on API data and has signed a DPA: the decision is written down in the manifest ("hosts"), so the export enforces it and nobody has to remember it.
final class HostPolicy
{
    /** @param list<string> $sources the parts of the dataset the run would use
     * @return list<string> why the answer is no; empty means yes */
    public function refusals(Dataset $dataset, string $provider, array $sources, ?string $region = null): array
    {
        $customerDerived = array_values(array_diff($sources, DatasetPublisher::NO_CUSTOMER_DATA));

        if ($customerDerived === []) {
            return [];
        }

        $reasons = [];

        $listed = implode(' and ', $customerDerived);

        $host = $this->host($dataset, $provider, $region);

        if ($host === null) {
            $reasons[] = "{$listed} hold customer data and the manifest of {$dataset->name()} does not allow ".($region === null ? $provider : "{$provider} in {$region}").' (php artisan dataset:allow records a decision).';
        }

        $privacy = (array) Config::get("ai.providers.{$provider}.privacy", []);

        if (($privacy['trains_on_api_data'] ?? true) !== false) {
            $reasons[] = "{$provider}'s privacy block does not say it leaves API data out of training.";
        }

        if (($privacy['dpa_signed'] ?? false) !== true) {
            $reasons[] = "{$provider} has no signed data processing agreement in its privacy block.";
        }

        return $reasons;
    }

    public function allow(Dataset $dataset, string $provider, ?string $region, string $reason): array
    {
        $manifest = $dataset->readJson('manifest.json');

        if ($manifest === []) {
            throw new InvalidArgumentException("Dataset {$dataset->name()} has no manifest.json: run dataset:publish.");
        }

        $hosts = array_values(array_filter($manifest['hosts'] ?? [], fn (array $host) => ! ($host['provider'] === $provider && ($host['region'] ?? null) === $region)));

        $hosts[] = ['provider' => $provider, 'region' => $region, 'reason' => $reason, 'recorded' => now()->toDateString()];

        $manifest['hosts'] = $hosts;

        $dataset->writeJson('manifest.json', $manifest);

        return $manifest['hosts'];
    }

    // Chapter 43, Topic 6: the question the rented-GPU session asks before it creates anything. `billing-v1` is a dataset version (every source it holds counts); `billing-synthetic@a41c09e` is the private Hub copy that dataset:publish made of the parts with no customer data, named by repository and revision.
    /** @return list<string> why the answer is no; empty means yes */
    public function sessionRefusals(string $reference, string $provider, ?string $region): array
    {
        if (str_contains($reference, '@')) {
            [$repository, $revision] = explode('@', $reference, 2);

            $found = $this->byHub($repository, $revision);

            return $found === null
                ? ["No published dataset has the Hub copy {$reference}: dataset:publish --hub-private records it in a manifest, and the session checks that manifest."]
                : [];
        }

        $dataset = Dataset::named($reference);

        $manifest = $dataset->readJson('manifest.json');

        if ($manifest === []) {
            return ["Dataset {$reference} has no manifest.json: run dataset:publish."];
        }

        return $this->refusals($dataset, $provider, array_keys($manifest['composition'] ?? []), $region);
    }

    private function byHub(string $repository, string $revision): ?array
    {
        $root = rtrim((string) Config::get('ai.training.datasets_path'), '/');

        foreach (glob("{$root}/*/manifest.json") ?: [] as $path) {
            $manifest = json_decode((string) file_get_contents($path), true);

            $hub = $manifest['hub'] ?? null;

            if (! is_array($hub)) {
                continue;
            }

            $same = $hub['repository'] === $repository || basename($hub['repository']) === $repository;

            if ($same && str_starts_with((string) $hub['revision'], $revision)) {
                return $manifest;
            }
        }

        return null;
    }

    private function host(Dataset $dataset, string $provider, ?string $region): ?array
    {
        foreach ($dataset->readJson('manifest.json')['hosts'] ?? [] as $host) {
            if ($host['provider'] !== $provider) {
                continue;
            }

            if ($region !== null && ($host['region'] ?? null) !== $region) {
                continue;
            }

            return $host;
        }

        return null;
    }
}
