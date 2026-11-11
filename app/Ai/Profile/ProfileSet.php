<?php

namespace App\Ai\Profile;

use App\Help\HelpArticle;
use InvalidArgumentException;

// storage/samples/profile-set.json: {"prompts": [{"name": "...", "prompt": "..."}, {"name": "...", "article": "billing/late-fees", "instruction": "..."}]}
// An article entry sends the help article followed by the instruction, so the prompts get longer and the KV cache has something to grow into.
final class ProfileSet
{
    /** @return list<ProfilePrompt> */
    public static function load(string $path): array
    {
        if (! is_file($path)) {
            throw new InvalidArgumentException("There is no prompt set at {$path}.");
        }

        $prompts = json_decode((string) file_get_contents($path), true)['prompts'] ?? [];

        if ($prompts === []) {
            throw new InvalidArgumentException("{$path} lists no prompts.");
        }

        return array_values(array_map(fn (array $entry) => self::prompt($entry), $prompts));
    }

    private static function prompt(array $entry): ProfilePrompt
    {
        $name = (string) ($entry['name'] ?? 'prompt');

        if (isset($entry['prompt'])) {
            return new ProfilePrompt($name, (string) $entry['prompt']);
        }

        [$category, $slug] = array_pad(explode('/', (string) ($entry['article'] ?? '')), 2, '');

        $article = HelpArticle::find($category, $slug);

        if ($article === null) {
            throw new InvalidArgumentException("The prompt '{$name}' names the article '{$entry['article']}', which does not exist.");
        }

        return new ProfilePrompt($name, $article->body."\n\n".($entry['instruction'] ?? 'Summarize this in three sentences.'));
    }
}
