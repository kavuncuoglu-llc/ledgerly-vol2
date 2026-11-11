<?php

namespace App\Ai\Training;

use App\Ai\Privacy\Span;
use Faker\Generator;

// Chapter 40, Topic 3: training data gets realistic invented values, not Chapter 34's placeholders. A model trained on <EMAIL_1> learns to write <EMAIL_1>, and the local model sees real values in production. The same real value maps to the same invented one within an example (`$map` is the example's), and the faker is seeded per example, so an export run twice writes the same file.
final class SurrogateRedactor
{
    public function __construct(private Generator $faker) {}

    public function startExample(string $seed): void
    {
        $this->faker->seed(crc32($seed));

        $this->faker->unique(true);
    }

    /**
     * @param  Span[]  $spans  in text order
     * @param  array<string, string>  $map
     */
    public function redact(string $text, array $spans, array &$map): string
    {
        foreach (array_reverse($spans) as $span) {
            $map[$span->text] ??= match ($span->type) {
                'PERSON' => $this->faker->name(),
                'EMAIL' => $this->faker->unique()->safeEmail(),
                'PHONE' => $this->faker->phoneNumber(),
                'CARD' => $this->faker->creditCardNumber(),
                'IBAN' => $this->faker->iban('DE'),
                'SSN' => $this->faker->numerify('9##-##-####'),
                default => "[{$span->type}]",
            };

            $text = substr_replace($text, $map[$span->text], $span->offset, $span->length);
        }

        return $text;
    }
}
