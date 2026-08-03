<?php
/**
 * Golden fixture: Blend into existing stock and average
 *
 * Tests weighted-average costing when receiving more inventory on top of existing stock.
 *
 * Expected behavior (Part I §2, current implementation):
 * - Incoming inventory with a different unit cost changes the product's average
 * - New average = value-weighted mean of old and new inventory
 *
 * Scenario setup:
 * - Old: 100 units at average cost 10.0 = value 1000.0
 * - New: 50 units at cost 12.0 = value 600.0
 * - Expected new total: 150 units at weighted average
 */

return array(
	'scenario_name'      => 'blend_existing_average',
	'scenario_description' => 'Blend incoming inventory with existing stock and average',
	'setup' => array(
		'old_stock'         => 100,
		'old_average_cost'  => 10.0,
		'old_inventory_value' => 1000.0,
	),
	'operation' => array(
		'added_stock'       => 50,
		'unit_cost_entered' => 12.0,
	),
	'expected' => array(
		'new_stock'         => 150,
		// new_avg = (1000 + 600) / 150 = 1600 / 150 = 10.6666...
		'new_average_cost'  => 10.6667,
		// new_value = 150 * 10.6667 = 1600.0050 ≈ 1600.0050 (but to 4dp = 1600.0050)
		// More precisely: (old_stock * old_avg + added_stock * unit_cost)
		'new_inventory_value' => 1600.0,
		'precision_decimals' => 4,
	),
	'metadata' => array(
		'citation' => 'Part I §2 (Weighted-average costing formula)',
		'math'      => 'new_avg = (old_value + added_value) / new_stock = (1000.0 + 600.0) / 150 = 10.6666...',
	),
);
