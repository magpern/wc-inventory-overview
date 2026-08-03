<?php
/**
 * Golden fixture: EUR to EUR rate is always 1.0
 *
 * Tests FX rate resolution short-circuit for EUR→EUR.
 *
 * Expected behavior (Part I §2, Exchange_Rates service):
 * - EUR→EUR always returns 1.0 regardless of any stored rates
 * - No fallback logic is needed; short-circuit happens before database lookup
 *
 * Scenario:
 * - Request: EUR→EUR rate
 * - Expected: 1.0 (always)
 */

return array(
	'scenario_name'      => 'eur_passthrough',
	'scenario_description' => 'EUR to EUR short-circuit returns 1.0',
	'operation' => array(
		'from_currency' => 'EUR',
		'to_currency'   => 'EUR',
		'rate_date'     => '2026-08-03',
	),
	'expected' => array(
		'rate'              => 1.0,
		'source'            => 'short_circuit', // Not from database.
	),
	'metadata' => array(
		'citation' => 'Part I §2 (Exchange_Rates::get_exchange_rate_to_eur)',
		'note'      => 'This short-circuit must not query the database.',
	),
);
