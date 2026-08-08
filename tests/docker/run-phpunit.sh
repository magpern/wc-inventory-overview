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
	# Default: M1/M2 focused suites, M3 Inventory Position, plus existing unit/integration smoke.
	FILTER_ARGS=( --filter 'Test_WC_IO_Schema_Assertion|Test_WC_IO_PO_|Test_WC_IO_Suppliers_|Test_DB_Transaction|Test_WC_IO_Inventory_Position_|Test_WC_IO_Goods_Receipt_|Test_WC_IO_Goods_Receipts_|Test_WC_IO_Receipt_Lines_|Test_WC_IO_Restock_Service_Reversal|Test_WC_IO_Batch_Migration_|Test_WC_IO_Landed_Cost_Types_|Test_WC_IO_Expected_Delivery_|Test_WC_IO_No_Sibling_Plugin_Coupling' )
fi

echo "Running PHPUnit ${FILTER_ARGS[*]}..."
exec php vendor/bin/phpunit "${FILTER_ARGS[@]}"
