<?php

declare(strict_types=1);

namespace Workflow;

use BadMethodCallException;
use Illuminate\Bus\Queueable;
use Illuminate\Container\Container;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Routing\RouteDependencyResolverTrait;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use LimitIterator;
use SplFileObject;
use Throwable;
use Workflow\Exceptions\NonRetryableExceptionContract;
use Workflow\Middleware\ActivityMiddleware;
use Workflow\Middleware\WithoutOverlappingMiddleware;
use Workflow\Models\StoredWorkflow;
use Workflow\Serializers\Serializer;

class Activity implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use RouteDependencyResolverTrait;
    use SerializesModels;

    public $tries = PHP_INT_MAX;

    public $maxExceptions = PHP_INT_MAX;

    public $timeout = 0;

    public $arguments;

    public $key = '';

    private Container $container;

    public function __construct(
        public int $index,
        public string $now,
        public StoredWorkflow $storedWorkflow,
        ...$arguments
    ) {
        $this->arguments = $arguments;

        if (property_exists($this, 'connection')) {
            $this->onConnection($this->connection);
        }

        if (property_exists($this, 'queue')) {
            $this->onQueue($this->queue);
        }

        $this->afterCommit = true;
    }

    public function backoff()
    {
        return [1, 2, 5, 10, 15, 30, 60, 120];
    }

    public function workflowId()
    {
        return $this->storedWorkflow->id;
    }

    public function webhookUrl(string $signalMethod = ''): string
    {
        $basePath = config('workflows.webhooks_route', '/webhooks');
        if ($signalMethod === '') {
            $workflow = Str::kebab(class_basename($this->storedWorkflow->class));
            return url("{$basePath}/{$workflow}");
        }
        $signal = Str::kebab($signalMethod);
        return url("{$basePath}/signal/{$this->storedWorkflow->id}/{$signal}");
    }

    public function handle()
    {
        if (! method_exists($this, 'execute')) {
            throw new BadMethodCallException('Execute method not implemented.');
        }

        $this->container = App::make(Container::class);

        if ($this->storedWorkflow->logs()->whereIndex($this->index)->exists()) {
            return;
        }

        try {
            return $this->{'execute'}(...$this->resolveClassMethodDependencies($this->arguments, $this, 'execute'));
        } catch (\Throwable $throwable) {
            $this->storedWorkflow->exceptions()
                ->create([
                    'class' => $this::class,
                    'exception' => Serializer::serialize($throwable),
                ]);

            if ($throwable instanceof NonRetryableExceptionContract) {
                $this->fail($throwable);
            }

            throw $throwable;
        }
    }

    public function middleware()
    {
        return [
            new WithoutOverlappingMiddleware(
                $this->storedWorkflow->id,
                WithoutOverlappingMiddleware::ACTIVITY,
                0,
                $this->timeout
            ),
            new ActivityMiddleware(),
        ];
    }

    public function failed(Throwable $throwable): void
    {
        $workflow = $this->storedWorkflow->toWorkflow();

        $file = new SplFileObject($throwable->getFile());
        $iterator = new LimitIterator($file, max(0, $throwable->getLine() - 4), 7);

        $throwable = [
            'class' => get_class($throwable),
            'message' => $throwable->getMessage(),
            'code' => $throwable->getCode(),
            'line' => $throwable->getLine(),
            'file' => $throwable->getFile(),
            'trace' => collect($throwable->getTrace())
                ->filter(static fn ($trace) => Serializer::serializable($trace))
                ->toArray(),
            'snippet' => array_slice(iterator_to_array($iterator), 0, 7),
        ];

        Exception::dispatch(
            $this->index,
            $this->now,
            $this->storedWorkflow,
            $throwable,
            $workflow->connection(),
            $workflow->queue()
        );
    }

    public function heartbeat(): void
    {
        pcntl_alarm(max($this->timeout, 0));
        if ($this->timeout) {
            Cache::put($this->key, 1, $this->timeout);
        }
    }
}
