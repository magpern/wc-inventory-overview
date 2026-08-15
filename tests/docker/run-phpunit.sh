#!/usr/bin/env bash
# Bootstrap WP PHPUnit libs + WooCommerce, then run the plugin suite.
set -euo pipefail

PLUGIN_DIR=/plugin
WP_TESTS_DIR=${WP_TESTS_DIR:-/tmp/wordpress-tests-lib}
WP_CORE_DIR=${WP_CORE_DIR:-/tmp/wordpress}
WP_VERSION=${WP_VERSION:-6.7.2}
DB_HOST=${WORDPRESS_DB_HOST:-db}
DB_NAME=${WORDPRESS_DB_NAME:-wordpress_test}
DB_USER=${WORDPRESS_DB_USER:-wordpress}
DB_PASS=${WORDPRESS_DB_PASSWORD:-wordpress}

download() {
	curl -fsSL "$1" -o "$2"
}

if [[ ! -f "${WP_CORE_DIR}/wp-settings.php" ]]; then
	echo "Downloading WordPress ${WP_VERSION}..."
	download "https://wordpress.org/wordpress-${WP_VERSION}.tar.gz" /tmp/wordpress.tar.gz
	rm -rf /tmp/wordpress-src "${WP_CORE_DIR}"
	mkdir -p /tmp/wordpress-src
	tar -xzf /tmp/wordpress.tar.gz -C /tmp/wordpress-src
	mv /tmp/wordpress-src/wordpress "${WP_CORE_DIR}"
	rm -rf /tmp/wordpress-src
fi

if [[ ! -f "${WP_TESTS_DIR}/includes/bootstrap.php" ]]; then
	echo "Downloading WordPress develop PHPUnit library ${WP_VERSION}..."
	rm -rf "${WP_TESTS_DIR}" /tmp/wordpress-develop
	mkdir -p /tmp
	download "https://github.com/WordPress/wordpress-develop/archive/refs/tags/${WP_VERSION}.tar.gz" /tmp/wp-develop.tar.gz
	tar -xzf /tmp/wp-develop.tar.gz -C /tmp
	DEV_DIR="/tmp/wordpress-develop-${WP_VERSION}"
	mkdir -p "${WP_TESTS_DIR}"
	cp -a "${DEV_DIR}/tests/phpunit/includes" "${WP_TESTS_DIR}/includes"
	cp -a "${DEV_DIR}/tests/phpunit/data" "${WP_TESTS_DIR}/data"
fi

# Install Composer locally if missing.
if [[ ! -x /tmp/bin/composer ]]; then
	echo "Installing Composer..."
	mkdir -p /tmp/bin
	download https://getcomposer.org/download/latest-stable/composer.phar /tmp/bin/composer
	chmod +x /tmp/bin/composer
fi
export PATH="/tmp/bin:${PATH}"

# Install the plugin's own dev dependencies (PHPUnit, PHPCS...) declared in
# composer.json/composer.lock. Previously missing here entirely, so
# vendor/bin/phpunit never existed and every run of this script failed at
# the final step with "Could not open input file: vendor/bin/phpunit" --
# discovered running this from a genuinely clean checkout.
if [[ ! -x "${PLUGIN_DIR}/vendor/bin/phpunit" ]]; then
	echo "Installing plugin Composer dependencies..."
	( cd "${PLUGIN_DIR}" && composer install --no-interaction --prefer-dist )
fi

# Polyfills required by modern WP test bootstrap.
if [[ ! -d /tmp/phpunit-polyfills/vendor/yoast/phpunit-polyfills ]]; then
	echo "Installing yoast/phpunit-polyfills..."
	mkdir -p /tmp/phpunit-polyfills
	cd /tmp/phpunit-polyfills
	composer require --no-interaction "yoast/phpunit-polyfills:^2.0"
fi

# WooCommerce into WP plugins dir used by tests.
WC_DIR="${WP_CORE_DIR}/wp-content/plugins/woocommerce"
if [[ ! -f "${WC_DIR}/woocommerce.php" ]]; then
	echo "Downloading WooCommerce..."
	mkdir -p "${WP_CORE_DIR}/wp-content/plugins"
	download "https://downloads.wordpress.org/plugin/woocommerce.latest-stable.zip" /tmp/woocommerce.zip
	cd /tmp
	unzip -qo woocommerce.zip -d "${WP_CORE_DIR}/wp-content/plugins"
fi

# Symlink plugin under test into WP plugins dir.
mkdir -p "${WP_CORE_DIR}/wp-content/plugins"
ln -sfn "${PLUGIN_DIR}" "${WP_CORE_DIR}/wp-content/plugins/wc-inventory-overview"

# Deterministic database isolation: every suite invocation starts from an empty
# schema. The MariaDB data dir is already tmpfs-backed (wiped when the db
# container stops), but a long-lived db container reused across local runs can
# accumulate dbDelta / InnoDB row-format drift ("Row size too large"). Dropping
# and recreating the database before WP's own install keeps CI and local
# repeated runs equivalent without requiring a manual `down -v` between them.
DB_ROOT_PASS=${WORDPRESS_DB_ROOT_PASSWORD:-root}
echo "Resetting test database ${DB_NAME} on ${DB_HOST}..."
mysql \
	--host="${DB_HOST}" \
	--user=root \
	--password="${DB_ROOT_PASS}" \
	--protocol=TCP \
	--connect-timeout=30 \
	-e "DROP DATABASE IF EXISTS \`${DB_NAME}\`; CREATE DATABASE \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'%'; FLUSH PRIVILEGES;"

cat > "${WP_TESTS_DIR}/wp-tests-config.php" <<EOF
<?php
define( 'ABSPATH', '${WP_CORE_DIR}/' );
define( 'WP_DEFAULT_THEME', 'default' );
define( 'WP_DEBUG', true );
define( 'DB_NAME', '${DB_NAME}' );
define( 'DB_USER', '${DB_USER}' );
define( 'DB_PASSWORD', '${DB_PASS}' );
define( 'DB_HOST', '${DB_HOST}' );
define( 'DB_CHARSET', 'utf8' );
define( 'DB_COLLATE', '' );
\$table_prefix = 'wptests_';
define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'WC IO Tests' );
define( 'WP_PHP_BINARY', 'php' );
define( 'WPLANG', '' );
EOF

export WP_TESTS_DIR
export WP_TESTS_PHPUNIT_POLYFILLS_PATH=/tmp/phpunit-polyfills/vendor/yoast/phpunit-polyfills

cd "${PLUGIN_DIR}"
FILTER_ARGS=("$@")
if [[ ${#FILTER_ARGS[@]} -eq 0 ]]; then
	# Default: M1–M15 focused blocking suite.
	# Filter notes (substring / regex match on class names):
	# - Prefer prefix_ forms (trailing underscore) when every intended class
	#   shares that prefix (e.g. Test_WC_IO_PO_).
	# - Do NOT use Test_WC_IO_Expected_Deadline_ (trailing underscore): that
	#   silently excludes Test_WC_IO_Expected_Deadline (no trailing segment).
	#   Use Test_WC_IO_Expected_Deadline (no trailing underscore) so both
	#   Expected_Deadline and Expected_Deadline_Architecture match.
	# - Intentionally omitted: M0 golden characterization classes
	#   (Test_Costing_/Test_FX_/Test_Movements_/Test_Cost_Adjustment_) — those
	#   run only in the full --testsuite=integration gate.
	# - M13 (Test_WC_IO_PO_Print_*) is already covered by the existing
	#   Test_WC_IO_PO_ entry (verified via --list-tests before adding this),
	#   but is listed explicitly below too so a future rename of the PO_
	#   prefix can't silently drop M13 coverage.
	# - M14 (Test_WC_IO_Supplier_Order_History_*) is a new, distinct prefix
	#   (not covered by Test_WC_IO_Suppliers_, which is plural/list-table
	#   specific) -- added explicitly below. Test_WC_IO_PO_Values_Bulk
	#   (values_bulk() on Purchase_Orders) is already covered by the
	#   existing Test_WC_IO_PO_ entry.
	# - M15 (Test_WC_IO_Supplier_Spend_*) is a new, distinct prefix -- added
	#   explicitly below (same reasoning as M14's Supplier_Order_History_
	#   entry). Test_WC_IO_PO_Spend_Summary (spend_summary_for_supplier() on
	#   Purchase_Orders) is already covered by the existing Test_WC_IO_PO_
	#   entry (verified via --list-tests before adding this).
	# - M16 (Test_WC_IO_Settings_PO_Delay_Grace_Days) is a new, distinct
	#   prefix -- added explicitly below. M16's other test classes
	#   (Test_WC_IO_Expected_Date_Suggestion_*, Test_WC_IO_PO_Admin,
	#   Test_WC_IO_Inventory_Position_List_Table) are already covered by the
	#   existing Test_WC_IO_Expected_Date_Suggestion_/Test_WC_IO_PO_/
	#   Test_WC_IO_Inventory_Position_ entries (verified via --list-tests
	#   before adding this).
	# - M17 (Test_WC_IO_Supplier_Merge_*) is a new, distinct prefix -- added
	#   explicitly below (same reasoning as M14/M15's Supplier_*_ entries;
	#   distinct from Test_WC_IO_Supplier_Lead_Time_/Order_History_/Spend_,
	#   no collision). Test_WC_IO_Schema_V11_Upgrade is a separate class
	#   (mirrors M6's Test_WC_IO_Batch_Migration_Schema_V10_Upgrade naming
	#   pattern but is deliberately NOT prefixed Test_WC_IO_Batch_Migration_,
	#   since v11 has no batch-migration relationship per the M17 plan's
	#   Part B) -- added explicitly below.
	# - M20 (Admin Controller Decomposition Phase 3) adds nine new, distinct
	#   prefixes for the Restock_Controller/Overview_Controller extraction --
	#   none collide with the existing Test_WC_IO_Restock_Service_Reversal
	#   literal entry (that's Restock_Service's own domain-level test, an
	#   unrelated class name) or any other existing entry (verified via
	#   --list-tests before adding these): Test_WC_IO_Restock_Rendering_,
	#   Test_WC_IO_Restock_Mutation_, Test_WC_IO_Restock_Cost_Adjustment_Preview_,
	#   Test_WC_IO_Restock_Controller_, Test_WC_IO_Overview_Rendering_,
	#   Test_WC_IO_Overview_Bulk_Action_, Test_WC_IO_Overview_Inline_Stock_Ajax_,
	#   Test_WC_IO_Overview_Csv_Export_, Test_WC_IO_Overview_Controller_ --
	#   added explicitly below.
	# - M21 (Position-Aware Reorder Signal) adds two new, distinct prefixes
	#   (verified via --list-tests before adding these; no collision with
	#   any existing entry): Test_WC_IO_Reorder_Signal_ (Resolver,
	#   architecture guards, list-table badges/rollup, capability matrix)
	#   and Test_WC_IO_Summary_ (Summary::build()'s needs_reorder count and
	#   its query-count regression gate). Test_WC_IO_Dashboard_Reorder_Surfaces
	#   is already covered by the existing Test_WC_IO_Dashboard_ entry.
	#   Test_WC_IO_Overview_Summary_Cards_Reorder is a new, distinct prefix
	#   (does not start with Test_WC_IO_Overview_Controller_) -- added
	#   explicitly below as Test_WC_IO_Overview_Summary_Cards_.
	# - M22 (Reorder → Draft PO Quick Action) adds three new, distinct
	#   prefixes (verified via --list-tests before adding these; no
	#   collision with any existing entry): Test_WC_IO_Reorder_Prefill_
	#   (Reorder_Prefill_Service, architecture guards, capability matrix,
	#   security/TOCTOU), Test_WC_IO_Purchase_Order_Lines_Supplier_History
	#   (the new supplier-history repository query -- distinct from
	#   Test_WC_IO_PO_, which doesn't cover this class name), and
	#   Test_WC_IO_List_Table_Reorder_Action_Link (the new quick-action
	#   link -- distinct from Test_WC_IO_Reorder_Signal_, which only covers
	#   M21's own badge/rollup tests). Test_WC_IO_PO_Admin_Reorder_Prefill_Rendering
	#   is already covered by the existing Test_WC_IO_PO_ entry.
	#   Test_WC_IO_Suppliers_Eligibility_And_Bulk_Fetch is already covered
	#   by the existing Test_WC_IO_Suppliers_ entry.
	# - M23 (Replenishment Defaults) adds new, distinct prefixes (verified
	#   via --list-tests before adding these; no collision with any existing
	#   entry): Test_WC_IO_M22_Supplier_Fallback_Characterization (WP-M23-1
	#   characterization of M22's own resolve_supplier()/render_line_row()
	#   behavior, written before any M23 production change),
	#   Test_WC_IO_Replenishment_Defaults_ (the new persistence class,
	#   architecture guards, validation, edge cases, and query-count
	#   performance matrix -- covers Test_WC_IO_Replenishment_Defaults_Architecture,
	#   _Validation, _Persistence, _Capability, _Edge_Cases, _Performance),
	#   Test_WC_IO_Product_Replenishment_Admin (the new product/variation
	#   configuration UI -- distinct from Test_WC_IO_PO_, which doesn't cover
	#   this class name), Test_WC_IO_Preferred_Supplier_Prefill and
	#   Test_WC_IO_Default_Quantity_Prefill (the Reorder_Prefill_Service
	#   integration -- distinct from Test_WC_IO_Reorder_Prefill_, which only
	#   covers M22's own tests).
	# - M24 (Replenishment Planning Screen) adds new, distinct prefixes
	#   (verified via --list-tests before adding these; no collision with
	#   any existing entry): Test_WC_IO_Repository_Include_ (the additive
	#   include passthrough + variation-proof characterization),
	#   Test_WC_IO_Bulk_Repository_Primitives (distinct_supplier_history_
	#   for_items_bulk()/Replenishment_Defaults::get_bulk()),
	#   Test_WC_IO_Supplier_Preference_Resolver (the new pure decider),
	#   Test_WC_IO_Build_Plan_ (Replenishment_Planning_Service::build_plan()'s
	#   own suite), Test_WC_IO_Planning_Tab_ and Test_WC_IO_Planning_Bulk_Action_
	#   (the new admin UI/entry points), Test_WC_IO_Replenishment_Planning_
	#   (query-count + architecture guards). Test_WC_IO_Summary_Extraction_
	#   Characterization is already covered by the existing Test_WC_IO_
	#   Summary_ entry. This default filter also now passes
	#   --exclude-group performance -- M24 adds a small number of tests
	#   that deliberately build large (100-900 product) fixtures to
	#   gather real EXPLAIN/query-count evidence at production-
	#   representative scale; correctness-relevant but not something that
	#   should slow down every quick-start/CI run -- run them explicitly
	#   (see docs/testing.md) as part of a milestone's own
	#   release-readiness validation pass.
	# - M25 (Bulk Draft PO Creation) adds one new, distinct prefix (verified
	#   via --list-tests before adding this; no collision with any existing
	#   entry, including M24's own Test_WC_IO_Replenishment_Planning_):
	#   Test_WC_IO_Replenishment_Commit_ -- covers the new orchestrator
	#   (Replenishment_Commit_Service), the new advisory-lock primitive
	#   (Replenishment_Item_Lock), the new admin controller
	#   (Replenishment_Commit_Admin), the new bulk conflicting-line repository
	#   method, the two test-only failure-injection seams, the full
	#   crafted-POST security matrix, capability/nonce/token isolation, the
	#   Planning-tab commit-form capability gating, and architecture guards.
	#   Test_WC_IO_Replenishment_Commit_Performance's query-count/wall-clock
	#   matrix is entirely @group performance and stays excluded by the same
	#   --exclude-group flag below.
	FILTER_ARGS=( --filter 'Test_WC_IO_Schema_Assertion|Test_WC_IO_PO_|Test_WC_IO_PO_Print_|Test_WC_IO_Suppliers_|Test_DB_Transaction|Test_WC_IO_Inventory_Position_|Test_WC_IO_Goods_Receipt_|Test_WC_IO_Goods_Receipts_|Test_WC_IO_Receipt_Lines_|Test_WC_IO_Restock_Service_Reversal|Test_WC_IO_Batch_Migration_|Test_WC_IO_Landed_Cost_Types_|Test_WC_IO_Expected_Delivery_|Test_WC_IO_No_Sibling_Plugin_Coupling|Test_WC_IO_Close_Short_With_Qty_Received|Test_WC_IO_Supplier_Lead_Time_|Test_WC_IO_Expected_Date_Suggestion_|Test_WC_IO_Expected_Deadline|Test_WC_IO_Supplier_On_Time_Rate_|Test_WC_IO_Supplier_Order_History_|Test_WC_IO_Supplier_Spend_|Test_WC_IO_Settings_|Test_WC_IO_Supplier_Merge_|Test_WC_IO_Schema_V11_Upgrade|Test_WC_IO_Exchange_Rate_|Test_WC_IO_Danger_Zone_|Test_WC_IO_Dashboard_|Test_WC_IO_Movements_Rendering_|Test_WC_IO_Order_Profit_Rendering_|Test_WC_IO_Product_Profitability_Rendering_|Test_WC_IO_Reporting_Controller_|Test_WC_IO_Restock_Rendering_|Test_WC_IO_Restock_Mutation_|Test_WC_IO_Restock_Cost_Adjustment_Preview_|Test_WC_IO_Restock_Controller_|Test_WC_IO_Overview_Rendering_|Test_WC_IO_Overview_Bulk_Action_|Test_WC_IO_Overview_Inline_Stock_Ajax_|Test_WC_IO_Overview_Csv_Export_|Test_WC_IO_Overview_Controller_|Test_WC_IO_Reorder_Signal_|Test_WC_IO_Summary_|Test_WC_IO_Overview_Summary_Cards_|Test_WC_IO_Reorder_Prefill_|Test_WC_IO_Purchase_Order_Lines_Supplier_History|Test_WC_IO_List_Table_Reorder_Action_Link|Test_WC_IO_M22_Supplier_Fallback_Characterization|Test_WC_IO_Replenishment_Defaults_|Test_WC_IO_Product_Replenishment_Admin|Test_WC_IO_Preferred_Supplier_Prefill|Test_WC_IO_Default_Quantity_Prefill|Test_WC_IO_Repository_Include_|Test_WC_IO_Bulk_Repository_Primitives|Test_WC_IO_Supplier_Preference_Resolver|Test_WC_IO_Build_Plan_|Test_WC_IO_Planning_Tab_|Test_WC_IO_Planning_Bulk_Action_|Test_WC_IO_Replenishment_Planning_|Test_WC_IO_Replenishment_Commit_' --exclude-group 'performance' )
fi

echo "Running PHPUnit ${FILTER_ARGS[*]}..."
exec php vendor/bin/phpunit "${FILTER_ARGS[@]}"
