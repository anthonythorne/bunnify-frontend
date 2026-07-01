<?php
/**
 * Trait for WP Cron functionality which is used by Controllers.
 *
 * File Path: src/php/Base/Traits/CronTrait.php
 *
 * @package BunnifyFrontend\Base
 */

namespace BunnifyFrontend\Base\Traits;

/**
 * Trait for WP Cron functionality within Controllers.
 *
 * Note: Any class using this trait must call `initialize_cron()` in the `set_up()` method to
 * ensure the cron functionality is hooked into the WordPress lifecycle.
 */
trait CronTrait {
	use LoggingTrait;

	/**
	 * Automatically schedule the cron and register WP CLI on trait use.
	 * 
	 * Priority is set to 999 to ensure the cron is scheduled after all other hooks and
	 * customisations are done by the controller that uses this trait.
	 *
	 * @return void
	 */
	public function initialize_cron(): void {
		// Schedule the cron job.
		add_action( 'init', [ $this, 'schedule_wp_cron_job' ], 999 );

		// WP CLI command.
		add_action( 'init', [ $this, 'register_wp_cli_command' ], 999 );

		// WP Cron command.
		add_action( 'init', [ $this, 'register_wp_cron' ], 999 );
	}

	/**
	 * Schedule the wp_cron job automatically, or remove it if disabled.
	 *
	 * @return void
	 */
	public function schedule_wp_cron_job(): void {

		// Bail early if the command slug is not set.
		if ( ! $this->is_cron_enabled() ) {
			return;
		}

		// Schedule the cron if it's not already scheduled.
		if ( $this->schedule_cron_automatically() && ! \wp_next_scheduled( $this->get_command_slug() ) ) {
			\wp_schedule_event( time(), $this->schedule_cron_recurrence(), $this->get_command_slug() );
		} elseif ( ! $this->schedule_cron_automatically() ) {
			$this->unschedule_wp_cron_job();
		}
	}

	/**
	 * Unschedule the wp_cron job if it exists.
	 *
	 * @return void
	 */
	protected function unschedule_wp_cron_job(): void {

		// Bail early if the command slug is not set.
		if ( ! $this->get_command_slug() ) {
			return;
		}

		$next_scheduled = wp_next_scheduled( $this->get_command_slug() );

		// Bail early if the cron job is not scheduled.
		if ( ! $next_scheduled ) {
			return;
		}
		
		\wp_unschedule_event( $next_scheduled, $this->get_command_slug() );
	}

	/**
	 * Register the WP Cron callback.
	 *
	 * @return void
	 */
	public function register_wp_cron(): void {
		add_action( $this->get_command_slug(), [ $this, 'cron_run' ] );
	}

	/**
	 * Execute the cron job.
	 *
	 * @return void
	 */
	public function cron_run(): void {

		// Bail early if cron is disabled.
		if ( ! $this->is_cron_enabled() ) {
			return;
		}

		$this->log_message( self::LOG_LEVEL_INFO, "Cron running: {$this->get_command_slug()}" );
		$this->run_command( [], [] );
		$this->log_message( self::LOG_LEVEL_INFO, "Cron completed: {$this->get_command_slug()}" );
	}

	/**
	 * Enable or disable the cron job.
	 *
	 * @return bool
	 */
	public function is_cron_enabled(): bool {

		return apply_filters( "is_cron_command_enabled_{$this->get_command_slug()}", true );
	}

	/**
	 * Execute the WP CLI command or cron job.
	 * 
	 * The implementation class must define this method to perform
	 * the actual work when the cron or CLI command is run.
	 *
	 * @param array $args       Command arguments.
	 * @param array $assoc_args Associated command arguments.
	 * @return void
	 */
	abstract public function run_command( $args, $assoc_args ): void;

	/**
	 * Get the slug for the WP CLI command and WP Cron name.
	 *
	 * @return string The command slug.
	 */
	abstract public function get_command_slug(): string;

	/**
	 * Define the cron recurrence schedule.
	 * 
	 * Example:
	 * return \BunnifyFrontend\Base\Library\CronSchedules::CRON_SIX_HOURLY;
	 *
	 * @return string The recurrence schedule.
	 */
	abstract public function schedule_cron_recurrence(): string;
}
