#!/bin/bash
# Setup script for ephemeral test WordPress environment
# Runs inside the wordpress container via docker compose run

set -e

echo "Setting up test WordPress environment..."

# WordPress core install
wp core install \
  --url=http://localhost \
  --title="WC Inventory Overview Tests" \
  --admin_user=admin \
  --admin_password=admin \
  --admin_email=test@localhost.local \
  --skip-email

echo "Activated WordPress core."

# Install and activate WooCommerce
wp plugin install woocommerce --activate
echo "Installed and activated WooCommerce."

# Install and activate the plugin under test
wp plugin activate wc-inventory-overview
echo "Activated wc-inventory-overview plugin."

# Verify plugin activation
wp plugin is-active wc-inventory-overview && echo "Plugin activation verified."

echo "Test environment ready."
