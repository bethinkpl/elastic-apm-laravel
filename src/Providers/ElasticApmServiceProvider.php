<?php

namespace PhilKra\ElasticApmLaravel\Providers;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Collection;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Support\Arr;
use PhilKra\Agent;
use PhilKra\ElasticApmLaravel\Apm\SpanCollection;
use PhilKra\ElasticApmLaravel\Apm\Transaction;
use PhilKra\ElasticApmLaravel\Contracts\VersionResolver;
use PhilKra\Helper\Timer;
use GuzzleHttp\Middleware;
use GuzzleHttp\HandlerStack;
use Psr\Http\Message\RequestInterface;
use GuzzleHttp\Promise\FulfilledPromise;

class ElasticApmServiceProvider extends ServiceProvider
{
    /** @var float */
    private $startTime;
    /** @var string  */
    private $sourceConfigPath = __DIR__ . '/../../config/elastic-apm.php';

    /** @var float */
    private static $lastHttpRequestStart;

    /**
     * Bootstrap the application services.
     *
     * @return void
     */
    public function boot()
    {
        if (class_exists('Illuminate\Foundation\Application', false)) {
            $this->publishes([
                realpath($this->sourceConfigPath) => config_path('elastic-apm.php'),
            ], 'config');
        }

        if (config('elastic-apm.active') === true && config('elastic-apm.spans.querylog.enabled') !== false) {
            $this->listenForQueries();
        }
    }

    /**
     * Register the application services.
     *
     * @return void
     */
    public function register()
    {

        $this->mergeConfigFrom(
            realpath($this->sourceConfigPath),
            'elastic-apm'
        );

        $this->app->singleton(Agent::class, function ($app) {
            return new Agent(
                array_merge(
                    [
                        'framework' => 'Laravel',
                        'frameworkVersion' => app()->version(),
                    ],
                    [
                        'active' => config('elastic-apm.active'),
                        'httpClient' => config('elastic-apm.httpClient'),
                    ],
                    $this->getAppConfig(),
                    config('elastic-apm.env'),
                    config('elastic-apm.server')
                )
            );
        });

        $this->startTime = $this->app['request']->server('REQUEST_TIME_FLOAT') ?? microtime(true);
        $timer = new Timer($this->startTime);

        $collection = new SpanCollection();

        $this->app->instance(Transaction::class, new Transaction($collection, $timer));

        $this->app->instance(Timer::class, $timer);

        $this->app->alias(Agent::class, 'elastic-apm');
        $this->app->instance('apm-spans-log', $collection);

    }

    /**
     * @return array
     */
    protected function getAppConfig(): array
    {
        $config = config('elastic-apm.app');

        if ($this->app->bound(VersionResolver::class)) {
            $config['appVersion'] = $this->app->make(VersionResolver::class)->getVersion();
        }

        return $config;
    }

    /**
     * @param Collection $stackTrace
     * @return Collection
     */
    protected function stripVendorTraces(Collection $stackTrace): Collection
    {
        return collect($stackTrace)->filter(function ($trace) {
            return !Str::startsWith((Arr::get($trace, 'file')), [
                base_path() . '/vendor',
            ]);
        });
    }

    /**
     * @param array $stackTrace
     * @return Collection
     */
    protected function getSourceCode(array $stackTrace): Collection
    {
        if (config('elastic-apm.spans.renderSource', false) === false) {
            return collect([]);
        }

        if (empty(Arr::get($stackTrace, 'file'))) {
            return collect([]);
        }

        $fileLines = file(Arr::get($stackTrace, 'file'));
        return collect($fileLines)->filter(function ($code, $line) use ($stackTrace) {
            //file starts counting from 0, debug_stacktrace from 1
            $stackTraceLine = Arr::get($stackTrace, 'line') - 1;

            $lineStart = $stackTraceLine - 5;
            $lineStop = $stackTraceLine + 5;

            return $line >= $lineStart && $line <= $lineStop;
        })->groupBy(function ($code, $line) use ($stackTrace) {
            if ($line < Arr::get($stackTrace, 'line')) {
                return 'pre_context';
            }

            if ($line == Arr::get($stackTrace, 'line')) {
                return 'context_line';
            }

            if ($line > Arr::get($stackTrace, 'line')) {
                return 'post_context';
            }

            return 'trash';
        });
    }

    protected function listenForQueries()
    {
        $this->app->events->listen(QueryExecuted::class, function (QueryExecuted $query) {
            if (config('elastic-apm.spans.querylog.enabled') === 'auto') {
                if ($query->time < config('elastic-apm.spans.querylog.threshold')) {
                    return;
                }
            }

            $stackTrace = $this->stripVendorTraces(
                collect(
                    debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT, config('elastic-apm.spans.backtraceDepth', 50))
                )
            );

            $stackTrace = $stackTrace->map(function ($trace) {
                $sourceCode = $this->getSourceCode($trace);

                return [
                    'function' => Arr::get($trace, 'function') . Arr::get($trace, 'type') . Arr::get($trace,
                            'function'),
                    'abs_path' => Arr::get($trace, 'file'),
                    'filename' => basename(Arr::get($trace, 'file')),
                    'lineno' => Arr::get($trace, 'line', 0),
                    'library_frame' => false,
                    'vars' => $vars ?? null,
                    'pre_context' => optional($sourceCode->get('pre_context'))->toArray(),
                    'context_line' => optional($sourceCode->get('context_line'))->first(),
                    'post_context' => optional($sourceCode->get('post_context'))->toArray(),
                ];
            })->values();


            // SQL type, e.g. SELECT, INSERT, DELETE, UPDATE, SET, ...
            $queryType = strtoupper(strtok(trim($query->sql), ' '));

            // @see https://www.elastic.co/guide/en/apm/server/master/span-api.html
            $query = [
                'name' => $queryType,
                'action' => 'query',
                'type' => 'db',
                'subtype' => 'mysql',

                // calculate start time from duration
                // $query->time is in milliseconds
                'start' => round(microtime(true) - $query->time / 1000, 3),
                'duration' => round($query->time, 3),
                'stacktrace' => $stackTrace,
                'context' => [
                    'db' => [
                        'instance' => $query->connection->getDatabaseName(),
                        'statement' => $query->sql,
                        'type' => 'sql',
                        'user' => $query->connection->getConfig('username'),
                    ],
                ],
            ];

            app('apm-spans-log')->push($query);
        });
    }

		public static function getGuzzleMiddleware() : callable
		{
			return Middleware::tap(
				function(RequestInterface $request, array $options) {
					self::$lastHttpRequestStart = microtime(true);
				},
				function (RequestInterface $request, array $options, FulfilledPromise $response) {
					// leave early if monitoring is disabled
					if (config('elastic-apm.active') !== true || config('elastic-apm.spans.httplog.enabled') !== true) {
						return;
					}

					$requestTime = (microtime(true) - self::$lastHttpRequestStart) * 1000; // in miliseconds

					$method = $request->getMethod();
					$host = $request->getUri()->getHost();

					$requestEntry = [
						// e.g. GET foo.example.net
						'name' => "{$method} {$host}",
						'type' => 'external',
						'subtype' => 'http',

						'start' => round(microtime(true) - $requestTime / 1000, 3),
						'duration' => round($requestTime, 3),

						'context' => [
							"http" => [
								// https://www.elastic.co/guide/en/apm/server/current/span-api.html
								"method" => $request->getMethod(),
								"url" => $request->getUri()->__toString(),
							]
						]
					];

					app('apm-spans-log')->push($requestEntry);
				}
			);
		}
}
