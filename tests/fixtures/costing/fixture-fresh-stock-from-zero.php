<?php
/**
 * Golden fixture: Fresh stock from zero (null prior average)
 *
 * Tests weighted-average costing when adding initial inventory to a product with no history.
 *
 * Expected behavior (Part I §3, D1–D4):
 * - Old stock: 0, old average: null → treated as inventory_value 0.0
 * - New average: must equal the incoming unit cost exactly
 * - New inventory value: new_stock * new_average
 *
 * Scenario name: 'fresh_stock_from_zero'
 */

return array(
	'scenario_name'      => 'fresh_stock_from_zero',
	'scenario_description' => 'Initial inventory with null prior average',
	'setup' => array(
		'old_stock'         => 0,
		'old_average_cost'  => null,  // No prior history.
		'old_inventory_value' => 0.0,
	),
	'operation' => array(
		'added_stock'       => 10,
		'unit_cost_entered' => 15.5,
	),
	'expected' => array(
		'new_stock'         => 10,
		'new_average_cost'  => 15.5,     // Must equal input exactly when old stock was 0.
		'new_inventory_value' => 155.0,  // 10 * 15.5.
		'precision_decimals' => 4,
	),
	'metadata' => array(
		'citation' => 'Part I §2 (Weighted-average costing, current implementation)',
		'math'      => 'new_avg = (old_stock * old_avg + added_stock * unit_cost) / new_stock = (0 * null + 10 * 15.5) / 10 = 15.5',
	),
);
