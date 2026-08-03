<?php
/**
 * Golden fixture: Latest rate before requested date is used
 *
 * Tests FX rate fallback when no exact match exists for the requested date.
 *
 * Expected behavior (Part I §2, Exchange_Rates service):
 * - When no rate exists for the exact requested date, use the latest rate with date <= requested date
 * - No interpolation or forward-looking fallback
 * - If multiple rates exist on the latest matching date, use the most recently inserted (ORDER BY date DESC, id DESC)
 *
 * Scenario:
 * - Rates in DB: USD→EUR on 2026-08-01 at 0.95
 * - Request: USD→EUR on 2026-08-03 (after DB rate, no exact match)
 * - Expected: Return 0.95 (latest before 2026-08-03)
 */

return array(
	'scenario_name'      => 'latest_before_date',
	'scenario_description' => 'Use latest rate with date <= requested date',
	'setup' => array(
		'seeded_rates' => array(
			array(
				'from_currency' => 'USD',
				'to_currency'   => 'EUR',
				'rate_date'     => '2026-08-01',
				'rate_value'    => 0.95,
			),
		),
	),
	'operation' => array(
		'from_currency' => 'USD',
		'to_currency'   => 'EUR',
		'rate_date'     => '2026-08-03',  // After the seeded rate.
	),
	'expected' => array(
		'rate'              => 0.95,
		'source'            => 'fallback_to_latest_before',
		'fallback_date'     => '2026-08-01',
	),
	'metadata' => array(
		'citation' => 'Part I §2 (Exchange_Rates::get_exchange_rate_to_eur)',
		'note'      => 'No interpolation occurs; must use exact date or latest-before.',
	),
);
