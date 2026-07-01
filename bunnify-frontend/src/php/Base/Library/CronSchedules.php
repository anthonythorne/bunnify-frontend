<?php
/**
 * Library for defining custom cron schedules.
 *
 * File Path: src/php/Base/Library/CronSchedules.php
 *
 * @package BunnifyFrontend\Base
 */

namespace BunnifyFrontend\Base\Library;

/**
 * Defines custom cron schedule constants and methods.
 */
class CronSchedules {

	public const CRON_FIVE_MINUTE    = 'five_minute';
	public const CRON_TEN_MINUTE     = 'ten_minute';
	public const CRON_QUARTER_HOURLY = 'quarter_hourly';
	public const CRON_HALF_HOURLY    = 'half_hourly';
	public const CRON_HOURLY         = 'hourly';
	public const CRON_THREE_HOURLY   = 'three_hourly';
	public const CRON_SIX_HOURLY     = 'six_hourly';
	public const CRON_TWELVE_HOURLY  = 'twelve_hourly';
	public const CRON_DAILY          = 'daily';
	public const CRON_WEEKLY         = 'weekly';

	/**
	 * Get the custom cron schedules.
	 *
	 * @return array[] Array of cron schedule definitions.
	 */
	public static function get_schedules(): array {

		$text_domain = apply_filters( 'base_text_domain', 'custom' );

		return [
			self::CRON_FIVE_MINUTE    => [
				'interval' => 5 * MINUTE_IN_SECONDS,
				'display'  => __( 'Once every 5 minutes', $text_domain ), // phpcs:ignore
			],
			self::CRON_TEN_MINUTE     => [
				'interval' => 10 * MINUTE_IN_SECONDS,
				'display'  => __( 'Once every 10 minutes', $text_domain ), // phpcs:ignore
			],
			self::CRON_QUARTER_HOURLY => [
				'interval' => 15 * MINUTE_IN_SECONDS,
				'display'  => __( 'Once every 15 minutes', $text_domain ), // phpcs:ignore
			],
			self::CRON_HALF_HOURLY    => [
				'interval' => 30 * MINUTE_IN_SECONDS,
				'display'  => __( 'Once every 30 minutes', $text_domain ), // phpcs:ignore
			],
			self::CRON_HOURLY         => [
				'interval' => HOUR_IN_SECONDS,
				'display'  => __( 'Once every hour', $text_domain ), // phpcs:ignore
			],
			self::CRON_THREE_HOURLY   => [
				'interval' => 3 * HOUR_IN_SECONDS,
				'display'  => __( 'Once every 3 hours', $text_domain ), // phpcs:ignore
			],
			self::CRON_SIX_HOURLY     => [
				'interval' => 6 * HOUR_IN_SECONDS,
				'display'  => __( 'Once every 6 hours', $text_domain ), // phpcs:ignore
			],
			self::CRON_TWELVE_HOURLY  => [
				'interval' => 12 * HOUR_IN_SECONDS,
				'display'  => __( 'Once every 12 hours', $text_domain ), // phpcs:ignore
			],
			self::CRON_DAILY          => [
				'interval' => DAY_IN_SECONDS,
				'display'  => __( 'Daily', $text_domain ), // phpcs:ignore
			],
			self::CRON_WEEKLY         => [
				'interval' => WEEK_IN_SECONDS,
				'display'  => __( 'Weekly', $text_domain ), // phpcs:ignore
			],
		];
	}
}
