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
	FILTER_ARGS=( --filter 'Test_WC_IO_Schema_Assertion|Test_WC_IO_PO_|Test_WC_IO_PO_Print_|Test_WC_IO_Suppliers_|Test_DB_Transaction|Test_WC_IO_Inventory_Position_|Test_WC_IO_Goods_Receipt_|Test_WC_IO_Goods_Receipts_|Test_WC_IO_Receipt_Lines_|Test_WC_IO_Restock_Service_Reversal|Test_WC_IO_Batch_Migration_|Test_WC_IO_Landed_Cost_Types_|Test_WC_IO_Expected_Delivery_|Test_WC_IO_No_Sibling_Plugin_Coupling|Test_WC_IO_Close_Short_With_Qty_Received|Test_WC_IO_Supplier_Lead_Time_|Test_WC_IO_Expected_Date_Suggestion_|Test_WC_IO_Expected_Deadline|Test_WC_IO_Supplier_On_Time_Rate_|Test_WC_IO_Supplier_Order_History_|Test_WC_IO_Supplier_Spend_|Test_WC_IO_Settings_|Test_WC_IO_Supplier_Merge_|Test_WC_IO_Schema_V11_Upgrade|Test_WC_IO_Exchange_Rate_|Test_WC_IO_Danger_Zone_|Test_WC_IO_Dashboard_|Test_WC_IO_Movements_Rendering_|Test_WC_IO_Order_Profit_Rendering_|Test_WC_IO_Product_Profitability_Rendering_|Test_WC_IO_Reporting_Controller_|Test_WC_IO_Restock_Rendering_|Test_WC_IO_Restock_Mutation_|Test_WC_IO_Restock_Cost_Adjustment_Preview_|Test_WC_IO_Restock_Controller_|Test_WC_IO_Overview_Rendering_|Test_WC_IO_Overview_Bulk_Action_|Test_WC_IO_Overview_Inline_Stock_Ajax_|Test_WC_IO_Overview_Csv_Export_|Test_WC_IO_Overview_Controller_|Test_WC_IO_Reorder_Signal_|Test_WC_IO_Summary_|Test_WC_IO_Overview_Summary_Cards_' )
fi

echo "Running PHPUnit ${FILTER_ARGS[*]}..."
exec php vendor/bin/phpunit "${FILTER_ARGS[@]}"
