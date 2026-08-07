<?php
/**
 * Immutable value object implementing the Expected Delivery Result contract (M7, API v1).
 *
 * @package WC_Inventory_Overview
 */

defined( 'ABSPATH' ) || exit;

/**
 * Sole implementation of WC_Inventory_Overview_Expected_Delivery_Result_Interface.
 *
 * Immutable: private constructor, private properties, no setters. Instances are
 * safely reusable by callers for the duration of a request — the same instance
 * answers identically every time it is asked.
 */
final class WC_Inventory_Overview_Expected_Delivery_Result implements WC_Inventory_Overview_Expected_Delivery_Result_Interface {

	/**
	 * @var int
	 */
	private $api_version;

	/**
	 * @var bool
	 */
	private $available_now;

	/**
	 * @var string
	 */
	private $state;

	/**
	 * @var string|null
	 */
	private $expected_date;

	/**
	 * @var string|null
	 */
	private $confidence;

	/**
	 * @param int         $api_version   API_VERSION of the producing Service.
	 * @param bool        $available_now WooCommerce's is_in_stock() answer.
	 * @param string      $state         One of the STATE_* constants.
	 * @param string|null $expected_date Raw Y-m-d, only for STATE_EXPECTED_DATE.
	 * @param string|null $confidence    'exact'|'estimated', only for STATE_EXPECTED_DATE.
	 */
	private function __construct( int $api_version, bool $available_now, string $state, ?string $expected_date, ?string $confidence ) {
		$this->api_version   = $api_version;
		$this->available_now = $available_now;
		$this->state         = $state;
		$this->expected_date = $expected_date;
		$this->confidence    = $confidence;
	}

	/**
	 * @param int         $api_version   API_VERSION of the producing Service.
	 * @param bool        $available_now WooCommerce's is_in_stock() answer.
	 * @param string      $state         One of the STATE_* constants.
	 * @param string|null $expected_date Raw Y-m-d, only for STATE_EXPECTED_DATE.
	 * @param string|null $confidence    'exact'|'estimated', only for STATE_EXPECTED_DATE.
	 * @return self
	 */
	public static function create( int $api_version, bool $available_now, string $state, ?string $expected_date, ?string $confidence ): self {
		return new self( $api_version, $available_now, $state, $expected_date, $confidence );
	}

	/**
	 * @inheritDoc
	 */
	public function api_version(): int {
		return $this->api_version;
	}

	/**
	 * @inheritDoc
	 */
	public function available_now(): bool {
		return $this->available_now;
	}

	/**
	 * @inheritDoc
	 */
	public function state(): string {
		return $this->state;
	}

	/**
	 * @inheritDoc
	 */
	public function expected_date(): ?string {
		return $this->expected_date;
	}

	/**
	 * @inheritDoc
	 */
	public function confidence(): ?string {
		return $this->confidence;
	}
}
