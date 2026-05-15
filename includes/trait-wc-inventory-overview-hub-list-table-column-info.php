<?php
/**
 * Column resolution for WP_List_Table on the Inventory & Profit hub screen.
 *
 * @package WC_Inventory_Overview
 */

defined( 'ABSPATH' ) || exit;

/**
 * Avoid {@see get_column_headers()} static cache when another caller primed it with [] before this table's constructor ran.
 */
trait WC_Inventory_Overview_Hub_List_Table_Column_Info {

	/**
	 * @return array{0: array<string, string>, 1: string[], 2: array<string, array<int, mixed>>, 3: string}
	 */
	protected function get_column_info() {
		if ( isset( $this->_column_headers ) && is_array( $this->_column_headers ) ) {
			if ( 4 === count( $this->_column_headers ) ) {
				return $this->_column_headers;
			}

			$column_headers = array( array(), array(), array(), $this->get_primary_column_name() );
			foreach ( $this->_column_headers as $key => $value ) {
				$column_headers[ $key ] = $value;
			}

			$this->_column_headers = $column_headers;

			return $this->_column_headers;
		}

		$hook    = "manage_{$this->screen->id}_columns";
		$columns = apply_filters( $hook, array() );
		if ( ! is_array( $columns ) || array() === $columns ) {
			$columns = $this->get_columns();
		}

		$hidden = get_hidden_columns( $this->screen );

		$sortable_columns = $this->get_sortable_columns();
		$_sortable        = apply_filters( "manage_{$this->screen->id}_sortable_columns", $sortable_columns );

		$sortable = array();
		foreach ( $_sortable as $id => $data ) {
			if ( empty( $data ) ) {
				continue;
			}

			$data = (array) $data;
			if ( ! isset( $data[1] ) ) {
				$data[1] = false;
			}
			if ( ! isset( $data[2] ) ) {
				$data[2] = '';
			}
			if ( ! isset( $data[3] ) ) {
				$data[3] = false;
			}
			if ( ! isset( $data[4] ) ) {
				$data[4] = false;
			}

			$sortable[ $id ] = $data;
		}

		$primary               = $this->get_primary_column_name();
		$this->_column_headers = array( $columns, $hidden, $sortable, $primary );

		return $this->_column_headers;
	}
}
