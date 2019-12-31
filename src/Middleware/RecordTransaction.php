<?php

namespace PhilKra\ElasticApmLaravel\Middleware;

use Closure;
use Illuminate\Support\Facades\Log;
use PhilKra\Agent;
use PhilKra\ElasticApmLaravel\Events\Span;
use PhilKra\Helper\Timer;

class RecordTransaction
{
    /**
     * @var \PhilKra\Agent
     */
    protected $agent;
    /**
     * @var Timer
     */
    private $timer;

    /**
     * RecordTransaction constructor.
     * @param Agent $agent
     */
    public function __construct(Agent $agent, Timer $timer)
    {
        $this->agent = $agent;
        $this->timer = $timer;
    }

    /**
     * [handle description]
     * @param  \Illuminate\Http\Request  $request [description]
     * @param  Closure $next [description]
     * @return [type]           [description]
     */
    public function handle($request, Closure $next)
    {
        $transaction = $this->agent->startTransaction(
            $this->getTransactionName($request)
        );

        // await the outcome
        $response = $next($request);

        $transaction->setResponse([
            'finished' => true,
            'headers_sent' => true,
            'status_code' => $response->getStatusCode(),
            'headers' => $this->formatHeaders($response->headers->all()),
        ]);

        $user = $request->user();
        $transaction->setUserContext([
            'id' => optional($user)->id,
            'email' => optional($user)->email,
            'username' => optional($user)->user_name,
            'ip' => $request->ip(),
            'user-agent' => $request->userAgent(),
        ]);

        $transaction->setMeta([
            'result' => $response->getStatusCode(),
            'type' => 'HTTP'
        ]);

        foreach (app('apm-spans-log')->toArray() as $spanContext) {
            // @see https://www.elastic.co/guide/en/apm/server/master/exported-fields-apm-span.html
            $spanDb = new Span($spanContext['name'], $transaction);
            $spanDb->setType($spanContext['type']);
            $spanDb->setSubtype($spanContext['subtype']);
            $spanDb->setContext($spanContext['context']);

            // optiponal fields
            if (isset($spanContext['action'])) {
                $spanDb->setAction($spanContext['action']);
            }
            if (isset($spanContext['stacktrace'])) {
                $spanDb->setStacktrace($spanContext['stacktrace']->toArray());
            }

            $spanDb->start();
            $spanDb->stop($spanContext['duration']); // in [ms]

            $spanDb->setStart($spanContext['start']); // in [us]

            $this->agent->putEvent($spanDb);
        }

        if (config('elastic-apm.transactions.use_route_uri')) {
            if (config('elastic-apm.transactions.normalize_uri')) {
                $transaction->setTransactionName($this->getMormalizedTransactionName($request));
            }
            else {
                $transaction->setTransactionName($this->getRouteUriTransactionName($request));
            }
        }

        // handle X-Requested-By header
        $requestedBy = $request->headers->get('X-Requested-By', 'end-user');

        // X-Requested-With: XMLHttpRequest (AJAX requests)
        if ($request->headers->get('X-Requested-With') === 'XMLHttpRequest') {
            $requestedBy = 'end-user-ajax';
        }

        $transaction->setTags(['requested_by' => $requestedBy]);

        $transaction->stop($this->timer->getElapsedInMilliseconds());

        return $response;
    }

    /**
     * Perform any final actions for the request lifecycle.
     *
     * @param  \Illuminate\Http\Request $request
     * @param  \Symfony\Component\HttpFoundation\Response $response
     *
     * @return void
     */
    public function terminate($request, $response)
    {
        try {
            $this->agent->send();
        }
        catch(\Throwable $t) {
            Log::error($t);
        }
    }

    /**
     * @param  \Illuminate\Http\Request $request
     *
     * @return string
     */
    protected function getTransactionName(\Illuminate\Http\Request $request): string
    {
        // fix leading /
        $path = ($request->server->get('REQUEST_URI') == '') ? '/' : $request->server->get('REQUEST_URI');

        return sprintf(
            "%s %s",
            $request->server->get('REQUEST_METHOD'),
            $path
        );
    }

    /**
     * @param  \Illuminate\Http\Request $request
     *
     * @return string
     */
    protected function getRouteUriTransactionName(\Illuminate\Http\Request $request): string
    {
        $path = ($request->path() === '/') ? '' : $request->path();

        return sprintf(
            "%s /%s",
            $request->server->get('REQUEST_METHOD'),
            $path
        );
    }

    /**
     * @param  \Illuminate\Http\Request $request
     *
     * @return string
     */
    protected function getMormalizedTransactionName(\Illuminate\Http\Request $request): string
    {
        $path = $this->getRouteUriTransactionName($request);

        // "PUT /api/v2/product/6404" becomes "PUT /api/v2/product/N"
        $parts = [];

        $tok = strtok($path, '/');
        while ($tok !== false) {
            $parts[] = is_numeric($tok) ? 'N' : $tok;
            $tok = strtok("/");
        }

        return join('/', $parts);
    }

    /**
     * @param array $headers
     *
     * @return array
     */
    protected function formatHeaders(array $headers): array
    {
        return collect($headers)->map(function ($values, $header) {
            return head($values);
        })->toArray();
    }
}
