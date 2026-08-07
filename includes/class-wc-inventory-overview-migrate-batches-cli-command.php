<?php
/**
 * WP-CLI: `wp wc-io migrate-batches` — legacy Batch Intake -> Goods Receipt
 * migration (M6).
 *
 * Operator-initiated, dry-run by default — modeled directly on
 * WC_Inventory_Overview_Reconcile_CLI_Command's shape (M5): no --apply flag
 * means strictly read-only preview, matching that tool's own default. --verify
 * is the permanent reconciliation mode for this data going forward (M6 plan
 * §Required analysis, point 8), mirroring reconcile-qty-received's --fix
 * precedent. Every write this command performs goes through
 * WC_Inventory_Overview_Batch_Migration_Service — one call per batch, one
 * transaction per batch (Invariant M6-1) — never raw SQL from this file.
 *
 * @package WC_Inventory_Overview
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_CLI' ) ) {
	return;
}

/**
 * `wp wc-io migrate-batches [--apply] [--verify] [--batch=<id>] [--rollback=<id>] [--limit=<n>]`
 */
class WC_Inventory_Overview_Migrate_Batches_CLI_Command {

	/**
	 * Migrate, verify, or roll back legacy Batch Intake history into Goods Receipts.
	 *
	 * ## OPTIONS
	 *
	 * [--apply]
	 * : Perform the migration. Without this flag, the command is strictly
	 * read-only — it reports what would be migrated but writes nothing.
	 *
	 * [--verify]
	 * : Read-only drift check of already-migrated batches against the Goods
	 * Receipts they were materialized into. Never repairs. Ignored together
	 * with --apply or --rollback.
	 *
	 * [--batch=<id>]
	 * : Limit to one legacy batch id. Without this, every eligible batch is
	 * processed (subject to --limit).
	 *
	 * [--rollback=<id>]
	 * : Undo one batch's migration (deletes the migrated receipt/lines/costs,
	 * clears movement references, clears tracking columns). Prompts for
	 * confirmation unless --yes is also passed. Ignored together with
	 * --apply or --verify.
	 *
	 * [--limit=<n>]
	 * : Cap the number of batches processed in one run (migrate/dry-run modes
	 * only).
	 *
	 * ## EXAMPLES
	 *
	 *     wp wc-io migrate-batches
	 *     wp wc-io migrate-batches --apply
	 *     wp wc-io migrate-batches --apply --limit=50
	 *     wp wc-io migrate-batches --batch=42 --apply
	 *     wp wc-io migrate-batches --verify
	 *     wp wc-io migrate-batches --rollback=42
	 *
	 * @param array<int,string>    $args       Positional args (unused).
	 * @param array<string,string> $assoc_args Associative args.
	 */
	public function __invoke( $args, $assoc_args ) {
		$rollback_id = isset( $assoc_args['rollback'] ) ? absint( $assoc_args['rollback'] ) : 0;
		if ( $rollback_id > 0 ) {
			$this->run_rollback( $rollback_id, $assoc_args );
			return;
		}

		if ( isset( $assoc_args['verify'] ) ) {
			$this->run_verify( $assoc_args );
			return;
		}

		$this->run_migrate( $assoc_args );
	}

	/**
	 * Migrate (or, without --apply, preview) eligible batches.
	 *
	 * @param array<string,string> $assoc_args Associative args.
	 */
	private function run_migrate( array $assoc_args ): void {
		$apply     = isset( $assoc_args['apply'] );
		$batch_id  = isset( $assoc_args['batch'] ) ? absint( $assoc_args['batch'] ) : 0;
		$limit     = isset( $assoc_args['limit'] ) ? absint( $assoc_args['limit'] ) : 0;

		$eligible_ids = WC_Inventory_Overview_Batch_Migration_Service::list_eligible_batch_ids( $limit, $batch_id );

		if ( $batch_id > 0 && empty( $eligible_ids ) ) {
			$already = WC_Inventory_Overview_Batch_Migration_Service::list_migrated_batch_ids( $batch_id );
			if ( ! empty( $already ) ) {
				WP_CLI::warning( sprintf( 'Batch #%d was already migrated. Nothing to do.', $batch_id ) );
			} else {
				WP_CLI::warning( sprintf( 'Batch #%d not found.', $batch_id ) );
			}
			return;
		}

		if ( empty( $eligible_ids ) ) {
			WP_CLI::success( sprintf( '0 batches found, nothing to migrate. (%d already migrated.)', count( WC_Inventory_Overview_Batch_Migration_Service::list_migrated_batch_ids() ) ) );
			return;
		}

		$migrated = 0;
		$failed   = 0;

		foreach ( $eligible_ids as $id ) {
			if ( ! $apply ) {
				WP_CLI::log( sprintf( 'Would migrate batch #%d.', $id ) );
				continue;
			}

			$result = WC_Inventory_Overview_Batch_Migration_Service::migrate_batch( $id );
			if ( is_wp_error( $result ) ) {
				++$failed;
				WP_CLI::warning( sprintf( 'Batch #%d: migration failed — %s', $id, $result->get_error_message() ) );
				continue;
			}

			++$migrated;
			WP_CLI::log(
				sprintf(
					'Batch #%d -> Goods Receipt %s (#%d): %d line(s), %d cost row(s), %d movement(s) backfilled.',
					$id,
					$result['receipt_number'],
					$result['receipt_id'],
					$result['lines_migrated'],
					$result['costs_migrated'],
					$result['movements_backfilled']
				)
			);
		}

		if ( ! $apply ) {
			WP_CLI::success( sprintf( '%d batch(es) would be migrated (dry run — pass --apply to migrate).', count( $eligible_ids ) ) );
			return;
		}

		WP_CLI::success( sprintf( 'Migrated: %d, Failed: %d.', $migrated, $failed ) );
	}

	/**
	 * Read-only drift check of already-migrated batches. Never repairs.
	 *
	 * @param array<string,string> $assoc_args Associative args.
	 */
	private function run_verify( array $assoc_args ): void {
		$batch_id = isset( $assoc_args['batch'] ) ? absint( $assoc_args['batch'] ) : 0;

		$ids = WC_Inventory_Overview_Batch_Migration_Service::list_migrated_batch_ids( $batch_id );
		if ( empty( $ids ) ) {
			WP_CLI::success( $batch_id > 0 ? sprintf( 'Batch #%d has not been migrated — nothing to verify.', $batch_id ) : 'No migrated batches to verify.' );
			return;
		}

		$verified = 0;
		$drifted  = 0;

		foreach ( $ids as $id ) {
			$result = WC_Inventory_Overview_Batch_Migration_Service::verify_batch( $id );
			if ( is_wp_error( $result ) ) {
				WP_CLI::warning( sprintf( 'Batch #%d: %s', $id, $result->get_error_message() ) );
				continue;
			}

			if ( $result['ok'] ) {
				++$verified;
				continue;
			}

			++$drifted;
			WP_CLI::log( sprintf( 'Batch #%d (Goods Receipt #%d): drift detected —', $id, $result['receipt_id'] ) );
			foreach ( $result['drift'] as $line ) {
				WP_CLI::log( '  - ' . $line );
			}
		}

		WP_CLI::success( sprintf( 'Verified: %d, Drift found: %d.', $verified, $drifted ) );
	}

	/**
	 * Roll back one batch's migration after explicit operator confirmation.
	 *
	 * @param int                   $batch_id   Legacy batch id.
	 * @param array<string,string>  $assoc_args Associative args (for --yes).
	 */
	private function run_rollback( int $batch_id, array $assoc_args ): void {
		WP_CLI::confirm(
			sprintf( 'Roll back batch #%d\'s migration? This deletes its migrated Goods Receipt, lines, and costs, and clears its movement references. Current stock and cost are never touched.', $batch_id ),
			$assoc_args
		);

		$result = WC_Inventory_Overview_Batch_Migration_Service::rollback_batch( $batch_id );
		if ( is_wp_error( $result ) ) {
			WP_CLI::warning( sprintf( 'Batch #%d: rollback failed — %s', $batch_id, $result->get_error_message() ) );
			return;
		}

		WP_CLI::success( sprintf( 'Batch #%d: migration rolled back.', $batch_id ) );
	}
}

WP_CLI::add_command( 'wc-io migrate-batches', 'WC_Inventory_Overview_Migrate_Batches_CLI_Command' );
