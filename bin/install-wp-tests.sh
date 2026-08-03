#!/usr/bin/env bash

# Install WordPress test library for PHPUnit
# Based on wordpress-plugin-tests setup
# Usage: bash bin/install-wp-tests.sh <db_name> <db_user> <db_pass> <db_host>

set -e

if [[ $# -lt 3 ]]; then
	echo "Usage: $0 <db_name> <db_user> <db_pass> [db_host]"
	exit 1
fi

DB_NAME=$1
DB_USER=$2
DB_PASS=$3
DB_HOST=${4:-localhost:3306}

WP_TESTS_DIR=/tmp/wordpress-tests-lib
WP_CORE_DIR=/tmp/wordpress-core

# Set versions to match docker setup.
WP_VERSION=${WP_VERSION:-6.4.2}
WP_TESTS_TAG=${WP_TESTS_TAG:-${WP_VERSION}}

download() {
	if [ `which curl` ]; then
		curl -s "$1" > "$2"
	elif [ `which wget` ]; then
		wget -nv -O "$2" "$1"
	fi
}

if [ -d "$WP_TESTS_DIR" ]; then
	echo "WordPress tests library already installed at ${WP_TESTS_DIR}"
	exit 0
fi

echo "Setting up WordPress tests library version ${WP_TESTS_TAG}..."

mkdir -p "$WP_TESTS_DIR" "$WP_CORE_DIR"

cd "$WP_CORE_DIR"

# Download WordPress.
echo "Downloading WordPress ${WP_VERSION}..."
download "https://wordpress.org/wordpress-${WP_VERSION}.tar.gz" "wordpress.tar.gz"
tar --strip-components=1 -zxmf wordpress.tar.gz

# Download test suite.
echo "Downloading WordPress test suite..."
mkdir -p "$WP_TESTS_DIR/includes"
download "https://develop.svn.wordpress.org/tags/${WP_TESTS_TAG}/tests/phpunit/includes/functions.php" \
	"$WP_TESTS_DIR/includes/functions.php"
download "https://develop.svn.wordpress.org/tags/${WP_TESTS_TAG}/tests/phpunit/includes/bootstrap.php" \
	"$WP_TESTS_DIR/includes/bootstrap.php"
download "https://develop.svn.wordpress.org/tags/${WP_TESTS_TAG}/tests/phpunit/includes/testcase.php" \
	"$WP_TESTS_DIR/includes/testcase.php"

# wp-tests-config.php
if [ ! -f "wp-tests-config.php" ]; then
	echo "Creating wp-tests-config.php..."
	download "https://develop.svn.wordpress.org/tags/${WP_TESTS_TAG}/wp-tests-config-sample.php" "wp-tests-config.php"
	sed -i "s:dirname( __FILE__ ) . '/src/':${WP_CORE_DIR}/:g" wp-tests-config.php
	sed -i "s:DATABASE_NAME', 'wordpress_test':DATABASE_NAME', '${DB_NAME}':g" wp-tests-config.php
	sed -i "s:DB_USER', 'wordpress':DB_USER', '${DB_USER}':g" wp-tests-config.php
	sed -i "s:DB_PASSWORD', 'password':DB_PASSWORD', '${DB_PASS}':g" wp-tests-config.php
	sed -i "s:DB_HOST', 'localhost':DB_HOST', '${DB_HOST}':g" wp-tests-config.php
fi

echo "WordPress test library ready at ${WP_TESTS_DIR}"
