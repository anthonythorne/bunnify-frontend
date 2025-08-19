<?php
/**
 * Trait for handling option data storage and retrieval which is used by Controllers.
 *
 * File Path: src/php/Base/Traits/OptionDataTrait.php
 *
 * @package BunnifyFrontend\Base
 */

namespace BunnifyFrontend\Base\Traits;

/**
 * Trait for handling option data storage and retrieval.
 */
trait OptionDataTrait {

	/**
	 * Set the option data.
	 *
	 * @param string $updated_date Date time string, signifying when the data was actually updated.
	 * @param array  $data         The data being stored.
	 * @param bool   $autoload      Should the option be autoloaded.
	 *
	 * @return bool
	 */
	public static function set_option( $updated_date, $data = [], $autoload = false ): bool {
		$value = [
			'checked_date' => current_time( 'mysql', false ),
			'updated_date' => $updated_date,
		];

		if ( $data ) {
			$value['data'] = $data;
		}

		return update_option( self::get_option_name(), $value, $autoload );
	}

	/**
	 * Return the option data.
	 *
	 * @return false|mixed
	 */
	public static function get_option(): mixed {
		return get_option( self::get_option_name() );
	}

	/**
	 * Get the date that the CLI/Command was last run, regardless of if data was updated.
	 *
	 * @return mixed|string
	 */
	public static function get_option_checked_date() {
		$option = self::get_option();

		if ( ! $option || ! isset( $option['checked_date'] ) ) {
			return '';
		}

		return $option['checked_date'];
	}

	/**
	 * Get the date that the CLI/Command was last run, and the data was updated.
	 *
	 * @return mixed|string
	 */
	public static function get_option_updated_date() {
		$option = self::get_option();

		if ( ! $option || ! isset( $option['updated_date'] ) ) {
			return '';
		}

		return $option['updated_date'];
	}

	/**
	 * Get the data stored.
	 *
	 * @return mixed|string
	 */
	public static function get_option_data() {
		$option = self::get_option();

		if ( ! $option || ! isset( $option['data'] ) ) {
			return [];
		}

		return $option['data'];
	}

	/**
	 * Get the option name for storing data.
	 *
	 * @return string
	 */
	public static function get_option_name() {
		return get_called_class()::OPTION_NAME;
	}
}
