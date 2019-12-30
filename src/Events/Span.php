<?php

namespace PhilKra\ElasticApmLaravel\Events;

use PhilKra\Events;

/**
 *
 * Spans
 *
 * @link https://www.elastic.co/guide/en/apm/server/master/span-api.html
 *
 */
class Span extends Events\Span {

	/**
	 * @var int
	 */
	private $startTimestamp = false;

	/**
	 * Set the timestamp of span start (in seconds)
	 *
	 * @return void
	 */
	public function setStart(float $timestamp) {
		$this->startTimestamp = $timestamp * 1000000; // seconds to microseconds
	}

	/**
	 * Get the Event's Timestamp (microseconds)
	 *
	 * @return int
	 */
	public function getTimestamp() : int {
		return $this->startTimestamp ?: parent::getTimestamp();
	}
}
