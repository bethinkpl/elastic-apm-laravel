<?php

namespace PhilKra\ElasticApmLaravel\Apm;


use Illuminate\Support\Collection;

/*
 * Creating an extension of the Collection class let's us establish
 * a named dependency which can be more easily modified in the future.
 */
class SpanCollection extends Collection
{
    public function push(...$values)
    {
        if ($this->count() >= config('elastic-apm.spans.maxTraceItems')) {
            return;
        }

        parent::push(...$values);
    }
}