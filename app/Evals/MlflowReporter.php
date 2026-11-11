<?php

namespace App\Evals;

use Illuminate\Support\Facades\Http;
use RuntimeException;

// Chapter 29, the nightly job: quality over time belongs beside the training runs, so a night's numbers become one MLflow run (Chapter 28's server) in the experiment "ledgerly-evals".
final class MlflowReporter
{
    public function __construct(private string $baseUrl) {}

    public static function fromConfig(): self
    {
        return new self(rtrim((string) config('services.mlflow.tracking_uri', 'http://localhost:5050'), '/'));
    }

    public function log(string $experiment, string $runName, array $metrics, array $params, array $tags = []): string
    {
        $experimentId = $this->experimentId($experiment);

        $now = (int) (microtime(true) * 1000);

        $run = $this->post('runs/create', [
            'experiment_id' => $experimentId,
            'start_time' => $now,
            'run_name' => $runName,
            'tags' => $this->pairs($tags),
        ])['run']['info']['run_id'];

        $this->post('runs/log-batch', [
            'run_id' => $run,
            'metrics' => array_map(fn (string $key, float|int $value) => ['key' => $key, 'value' => (float) $value, 'timestamp' => $now, 'step' => 0], array_keys($metrics), $metrics),
            'params' => $this->pairs($params),
        ]);

        $this->post('runs/update', ['run_id' => $run, 'status' => 'FINISHED', 'end_time' => $now]);

        return $run;
    }

    private function experimentId(string $name): string
    {
        $found = Http::acceptJson()->get($this->baseUrl.'/api/2.0/mlflow/experiments/get-by-name', ['experiment_name' => $name]);

        if ($found->successful()) {
            return (string) $found->json('experiment.experiment_id');
        }

        return (string) $this->post('experiments/create', ['name' => $name])['experiment_id'];
    }

    private function post(string $path, array $body): array
    {
        $response = Http::acceptJson()->timeout(15)->post($this->baseUrl.'/api/2.0/mlflow/'.$path, $body);

        if ($response->failed()) {
            throw new RuntimeException("MLflow answered {$response->status()} for {$path}. Is it running at {$this->baseUrl}?");
        }

        return $response->json() ?? [];
    }

    private function pairs(array $values): array
    {
        $pairs = [];

        foreach ($values as $key => $value) {
            $pairs[] = ['key' => (string) $key, 'value' => (string) $value];
        }

        return $pairs;
    }
}
