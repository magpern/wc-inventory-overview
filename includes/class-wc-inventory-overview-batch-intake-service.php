<?php
/**
 * Batch Intake: landed-cost-type vocabulary compatibility shim.
 *
 * @package WC_Inventory_Overview
 */

defined( 'ABSPATH' ) || exit;

/**
 * The create/apply/preview surface this class formerly held
 * (build_preview_from_post()/apply_batch_from_post()/rollback_batch_apply()/
 * build_movement_note_for_line()/render_preview_markup(), plus the private
 * parsing/formatting helpers that existed only to serve them) was M6's
 * "disabled, not deleted" surface, kept unreachable so
 * tests/includes/test-case.php's create_legacy_batch() fixture builder could
 * still exercise the real historical-batch code path. Physically removed in
 * M8 per the governance rule reserving that deletion for this milestone (see
 * docs/architecture-audit.md and docs/ARCHITECTURE_BASELINE_v1.24.0.md §12)
 * — create_legacy_batch() was rewritten first to build equivalent fixture
 * rows directly, without this class.
 *
 * The two methods below are the only ones that survive: they are live
 * delegation shims to WC_Inventory_Overview_Landed_Cost_Types (the M6
 * extraction target for this vocabulary), still exercised by
 * tests/integration/batch-migration/test-landed-cost-types-characterization.php
 * as a characterization proof that the pre-extraction API surface still
 * matches — not dead code.
 */
class WC_Inventory_Overview_Batch_Intake_Service {

	/**
	 * Allowed landed cost type slugs => admin label.
	 *
	 * @deprecated M6 — delegates to WC_Inventory_Overview_Landed_Cost_Types,
	 * the extraction target for this vocabulary (M6 §Retirement strategy).
	 * @return array<string, string>
	 */
	public static function landed_cost_type_labels() {
		return WC_Inventory_Overview_Landed_Cost_Types::landed_cost_type_labels();
	}

	/**
	 * @deprecated M6 — delegates to WC_Inventory_Overview_Landed_Cost_Types.
	 * @return string[]
	 */
	public static function allowed_cost_types() {
		return WC_Inventory_Overview_Landed_Cost_Types::allowed_cost_types();
	}
}
