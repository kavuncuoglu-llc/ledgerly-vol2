<?php

use App\Ai\Health\CircuitBreaker;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Yaml\Yaml;

describe('ops/compose/monitoring.yml', function () {
    function monitoring(): array
    {
        return Yaml::parseFile(base_path('ops/compose/monitoring.yml'));
    }

    it('publishes only Grafana, on loopback, behind a login with no default password', function () {
        $services = monitoring()['services'];

        expect($services['prometheus'])->not->toHaveKey('ports')
            ->and($services['grafana']['ports'])->toBe(['127.0.0.1:${GRAFANA_PORT:-3000}:3000'])
            ->and($services['grafana']['environment']['GF_SECURITY_ADMIN_PASSWORD'])->toStartWith('${GRAFANA_ADMIN_PASSWORD:?')
            ->and($services['grafana']['environment']['GF_AUTH_ANONYMOUS_ENABLED'])->toBe('false')
            ->and($services['grafana']['environment']['GF_USERS_ALLOW_SIGN_UP'])->toBe('false')
            ->and(envExampleValue('GRAFANA_ADMIN_PASSWORD'))->toBe('')
            ->and(file_get_contents(base_path('ops/compose/monitoring.yml')))->not->toMatch('/GRAFANA_ADMIN_PASSWORD:-/');
    });

    it('pins Prometheus and Grafana and never says latest', function () {
        $services = monitoring()['services'];

        expect($services['prometheus']['image'])->toBe('${PROMETHEUS_IMAGE:-prom/prometheus:v3.15.1}')
            ->and($services['grafana']['image'])->toBe('${GRAFANA_IMAGE:-grafana/grafana:13.2.4}')
            ->and(envExampleValue('PROMETHEUS_IMAGE'))->toMatch('#^prom/prometheus:v\d+\.\d+\.\d+$#')
            ->and(envExampleValue('GRAFANA_IMAGE'))->toMatch('#^grafana/grafana:\d+\.\d+\.\d+$#')
            ->and(file_get_contents(base_path('ops/compose/monitoring.yml')))->not->toContain(':latest');
    });

    it('gives Prometheus the two keys it needs as secrets, not as text', function () {
        $compose = monitoring();

        expect($compose['secrets'])->toBe(['llama_api_key' => ['environment' => 'LLAMA_API_KEY'], 'ops_metrics_token' => ['environment' => 'OPS_METRICS_TOKEN']])
            ->and($compose['services']['prometheus']['secrets'])->toBe(['llama_api_key', 'ops_metrics_token'])
            ->and(file_get_contents(base_path('ops/monitoring/prometheus.yml')))->not->toMatch('/credentials:\s/');
    });

    it('is valid Compose, alone and beside the vLLM file', function () {
        if (Process::run('docker compose version')->failed()) {
            $this->markTestSkipped('docker compose is not available');
        }

        $env = ['GRAFANA_ADMIN_PASSWORD' => 'x', 'VLLM_API_KEY' => 'k', 'VLLM_REVISION' => envExampleValue('VLLM_REVISION'), 'VLLM_MODEL' => envExampleValue('VLLM_MODEL'), 'VLLM_MAX_MODEL_LEN' => '8192', 'VLLM_MAX_NUM_SEQS' => '32', 'VLLM_TOOL_PARSER' => 'llama3_json', 'HF_TOKEN' => '', 'LLAMA_API_KEY' => '', 'OPS_METRICS_TOKEN' => ''];

        foreach ([['monitoring.yml'], ['vllm.yml', 'monitoring.yml']] as $files) {
            $command = ['docker', 'compose', '--env-file', base_path('.env.example'), '-f', base_path('ops/compose/base.yml')];

            foreach ($files as $file) {
                array_push($command, '-f', base_path('ops/compose/'.$file));
            }

            array_push($command, '--profile', 'vllm', '--profile', 'monitoring', 'config', '--quiet');

            $result = Process::env($env)->run($command);

            expect($result->successful())->toBeTrue($result->errorOutput());
        }
    });
});

describe('ops/monitoring', function () {
    it('scrapes the three servers and the app, each by the address the Compose network gives it', function () {
        $prometheus = Yaml::parseFile(base_path('ops/monitoring/prometheus.yml'));
        $jobs = collect($prometheus['scrape_configs'])->keyBy('job_name');

        expect($jobs->keys()->all())->toBe(['vllm', 'triton', 'llama', 'ledgerly-app'])
            ->and($jobs['vllm']['static_configs'][0]['targets'])->toBe(['vllm:8000'])
            ->and($jobs['triton']['static_configs'][0]['targets'])->toBe(['triton:8002'])          // the metrics port, never published
            ->and($jobs['llama']['authorization']['credentials_file'])->toBe('/run/secrets/llama_api_key')
            ->and($jobs['ledgerly-app']['metrics_path'])->toBe('/ops/metrics')
            ->and($jobs['ledgerly-app']['authorization']['credentials_file'])->toBe('/run/secrets/ops_metrics_token');
    });

    it('provisions a Prometheus data source and a dashboard whose queries all use that data source', function () {
        $source = Yaml::parseFile(base_path('ops/monitoring/grafana/provisioning/datasources/prometheus.yml'));
        $dashboard = json_decode(file_get_contents(base_path('ops/monitoring/grafana/dashboards/ledgerly-inference.json')), true, flags: JSON_THROW_ON_ERROR);

        expect($source['datasources'][0]['uid'])->toBe('ledgerly-prometheus')
            ->and($dashboard['uid'])->toBe('ledgerly-inference')
            ->and($dashboard['panels'])->not->toBeEmpty();

        $series = [];

        foreach ($dashboard['panels'] as $panel) {
            expect($panel['datasource']['uid'])->toBe('ledgerly-prometheus');

            foreach ($panel['targets'] as $target) {
                expect($target['datasource']['uid'])->toBe('ledgerly-prometheus')->and($target['expr'])->not->toBe('');

                $series[] = $target['expr'];
            }
        }

        $all = implode("\n", $series);

        foreach (['vllm:time_to_first_token_seconds_bucket', 'vllm:generation_tokens_total', 'vllm:num_requests_waiting', 'nv_inference_exec_count', 'nv_inference_queue_duration_us', 'ledgerly_ai_breaker_open', 'llamacpp:kv_cache_usage_ratio'] as $expected) {
            expect($all)->toContain($expected);          // TTFT, throughput, cache usage, the breaker: what Figure 25-9's table lists, for each of the three servers
        }
    });
});

describe('GET /ops/metrics', function () {
    it('is a 404 until OPS_METRICS_TOKEN is set, so the route does not admit it exists', function () {
        config(['ai.metrics_token' => '']);

        $this->get('ops/metrics')->assertNotFound();
        $this->get('ops/metrics', ['Authorization' => 'Bearer '])->assertNotFound();
    });

    it('refuses a wrong or missing token and answers the right one with the breaker per provider', function () {
        config(['ai.metrics_token' => 'metrics-secret']);

        $this->get('ops/metrics')->assertUnauthorized();
        $this->get('ops/metrics', ['Authorization' => 'Bearer nope'])->assertUnauthorized();

        foreach (range(1, 3) as $ignored) {
            app(CircuitBreaker::class)->recordFailure('vllm');
        }

        $response = $this->get('ops/metrics', ['Authorization' => 'Bearer metrics-secret'])->assertOk();

        expect($response->headers->get('Content-Type'))->toStartWith('text/plain')
            ->and($response->getContent())->toContain('ledgerly_ai_breaker_open{provider="vllm"} 1')
            ->and($response->getContent())->toContain('ledgerly_ai_breaker_open{provider="local"} 0')
            ->and($response->getContent())->toContain('ledgerly_ai_breaker_failures{provider="vllm"} 3')
            ->and($response->getContent())->toContain('ledgerly_ai_failed_jobs_total 0')
            ->and($response->getContent())->not->toContain('frontier');                              // a provider that is not set up is not listed
    });
});

describe('ops/compose/app.yml and the app image', function () {
    function appCompose(): array
    {
        return Yaml::parseFile(base_path('ops/compose/app.yml'));
    }

    it('runs the migrations, then warms the model, and only then starts Horizon', function () {
        $services = appCompose()['services'];

        expect($services['ai-warm']['depends_on']['migrate']['condition'])->toBe('service_completed_successfully')
            ->and($services['horizon']['depends_on']['ai-warm']['condition'])->toBe('service_completed_successfully')   // Figure 25-7
            ->and($services['ai-warm']['command'][2])->toContain('php artisan ai:warm --provider=local')
            ->and($services['ai-warm']['restart'])->toBe('no')
            ->and($services['horizon']['command'])->toBe(['php', 'artisan', 'horizon']);

        foreach (['app', 'reverb', 'ai-warm', 'horizon', 'migrate'] as $name) {
            expect($services[$name]['profiles'])->toBe(['app']);
        }
    });

    it('publishes the app and Reverb on loopback only, and carries no secret', function () {
        $services = appCompose()['services'];
        $text = file_get_contents(base_path('ops/compose/app.yml'));

        expect($services['app']['ports'])->toBe(['127.0.0.1:${APP_PORT:-8000}:8000'])
            ->and($services['reverb']['ports'])->toBe(['127.0.0.1:${REVERB_PORT:-8080}:8080'])
            ->and($services)->not->toHaveKey('ports')
            ->and($text)->not->toMatch('/(API_KEY|PASSWORD|SECRET|TOKEN):\s+[A-Za-z0-9]{12,}/')
            ->and($text)->toContain('env_file:');                                                 // the keys come from .env
    });

    it('is not in the root compose.yaml, and the image pins PHP and Composer', function () {
        $dockerfile = file_get_contents(base_path('ops/app/Dockerfile'));

        expect(Yaml::parseFile(base_path('compose.yaml'))['include'][0]['path'])->not->toContain('ops/compose/app.yml')
            ->and($dockerfile)->toMatch('/^FROM php:8\.3\.\d+-cli-bookworm$/m')
            ->and($dockerfile)->toMatch('/^FROM composer:2\.\d+\.\d+ AS composer$/m')
            ->and($dockerfile)->not->toContain(':latest')
            ->and($dockerfile)->toContain('pdo_pgsql pcntl gd zip')
            ->and(file_get_contents(base_path('.dockerignore')))->toContain('.env')->toContain('vendor');
    });

    it('is valid Compose', function () {
        if (Process::run('docker compose version')->failed()) {
            $this->markTestSkipped('docker compose is not available');
        }

        $result = Process::env(['HF_TOKEN' => ''])->run(['docker', 'compose', '--env-file', base_path('.env.example'), '-f', base_path('ops/compose/base.yml'), '-f', base_path('ops/compose/app.yml'), '--profile', 'app', 'config', '--quiet']);

        expect($result->successful())->toBeTrue($result->errorOutput());
    });
});

describe('ops/k8s', function () {
    it('asks for one GPU per replica, probes the health endpoints, and reads every key from a secret', function (string $file, string $readiness, string $liveness) {
        $values = Yaml::parseFile(base_path("ops/k8s/{$file}"));

        expect($values['resources']['limits']['nvidia.com/gpu'])->toBe(1)
            ->and($values['replicas'])->toBe(2)
            ->and($values['readinessProbe']['httpGet']['path'])->toBe($readiness)
            ->and($values['livenessProbe']['httpGet']['path'])->toBe($liveness)
            ->and($values['readinessProbe']['initialDelaySeconds'])->toBeLessThan($values['livenessProbe']['initialDelaySeconds'])   // never kill a server that is still loading
            ->and($values['image']['tag'])->not->toBe('latest')
            ->and(collect($values['volumes'])->pluck('name')->all())->toContain('shm')
            ->and(collect($values['persistentVolumeClaims'])->pluck('name')->all())->toContain('hf-cache');

        foreach ($values['env'] as $variable) {
            expect($variable)->toHaveKey('valueFrom');                                           // no key is written into the file
        }
    })->with([
        'vllm' => ['vllm-values.yaml', '/health', '/health'],
        'triton' => ['triton-values.yaml', '/v2/health/ready', '/v2/health/live'],
    ]);

    it('uses the same image tag and model pin as the Compose profile', function () {
        $vllm = Yaml::parseFile(base_path('ops/k8s/vllm-values.yaml'));
        $triton = Yaml::parseFile(base_path('ops/k8s/triton-values.yaml'));

        expect($vllm['image']['repository'].':'.$vllm['image']['tag'])->toBe(envExampleValue('VLLM_IMAGE'))
            ->and($vllm['args'])->toContain(envExampleValue('VLLM_MODEL'))->toContain(envExampleValue('VLLM_REVISION'))
            ->and($triton['image']['repository'].':'.$triton['image']['tag'])->toBe(envExampleValue('TRITON_IMAGE'));
    });

    it('has a chart that renders both files, when a container runtime can run helm', function () {
        if (Process::run('docker version')->failed()) {
            $this->markTestSkipped('docker is not available');
        }

        if (Process::run('docker image inspect alpine/helm:3.19.0')->failed()) {
            $this->markTestSkipped('alpine/helm:3.19.0 is not pulled');
        }

        foreach (['vllm', 'triton'] as $name) {
            $result = Process::run(['docker', 'run', '--rm', '-v', base_path('ops/k8s').':/k8s:ro', 'alpine/helm:3.19.0', 'template', $name, '/k8s/inference', '-f', "/k8s/{$name}-values.yaml"]);

            expect($result->successful())->toBeTrue($result->errorOutput())
                ->and($result->output())->toContain('nvidia.com/gpu: 1')
                ->and($result->output())->toContain('kind: Deployment');
        }
    });
});
