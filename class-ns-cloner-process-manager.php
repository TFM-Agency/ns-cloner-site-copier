<?php
/**
 * Cloner Process Management class.
 *
 * @package NS_Cloner
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Class NS_Cloner_Process_Manager
 *
 * Manages the cloning process - order of steps, status of background processes, validation, tracking progress,
 * and doing startup and shutdown actions at beginning and end with process_init and process_finish.
 */
class NS_Cloner_Process_Manager {

	/**
	 * Errors generated by cloning process
	 *
	 * Important: notice this is multi-dimensional, unlike the flat $errors property of sections.
	 *
	 * @var array $errors {
	 *      @type string $message Description of error
	 *      @type string $section Optional id of section that generated the error
	 * }
	 */
	private $errors;

	/**
	 * Record new error
	 *
	 * @param string $message Error message.
	 * @param array  $data Additional data to store with message, such as associated section id.
	 */
	public function add_error( $message, $data = array() ) {
		$error          = array( 'message' => $message );
		$this->errors[] = array_merge( $error, $data );
	}

	/**
	 * Get errors property
	 *
	 * @return array
	 */
	public function get_errors() {
		return $this->errors;
	}

	/**
	 * Save a SQL query to be executed when the cloning process is complete
	 *
	 * Used for table renames, alter queries, etc.
	 *
	 * @param string $query Valid SQL command.
	 * @param int    $priority Priority of execution. Higher = sooner.
	 */
	public function add_finish_query( $query, $priority = 10 ) {
		$queries = get_site_option( 'ns_cloner_finish_queries', array() );
		if ( ! isset( $queries[ $priority ] ) ) {
			// Create an array for this priority if it doesn't exist.
			$queries[ $priority ] = array( $query );
		} else {
			// Or just add it to existing priority queue.
			$queries[ $priority ][] = $query;
		}
		update_site_option( 'ns_cloner_finish_queries', $queries );
	}

	/**
	 * Gets all finish queries queued by add_finish_query()
	 *
	 * Formats into a flat array since they are store grouped by priority, for example:
	 * [ 0 => [ 'queryA', 'queryB' ], 10 => [ 'queryC', 'queryD' ] ] will be changed to:
	 * [ 'queryA', 'queryB', 'queryC', 'queryD' ]
	 *
	 * @return array
	 */
	public function get_finish_queries() {
		$result  = array();
		$queries = get_site_option( 'ns_cloner_finish_queries', array() );
		// Order by key, since they'll be grouped by priority.
		ksort( $queries );
		// Flatten, put all queries together in one array.
		foreach ( $queries as $priority => $priority_queries ) {
			$result = array_merge( $result, $priority_queries );
		}
		return $result;
	}

	/*
	______________________________________
	|
	|  Process Methods
	|_____________________________________
	*/

	/**
	 * Define the current request as a cloning process request.
	 *
	 * This registers a DOING_CLONING constant to easily recognize when a clone request is happening
	 * in other code, and it calls process_init for all active sections to register section hooks,
	 * so that we can count on those being active when cloning is happening and not at other times.
	 * Also starts the log (prior to log->start(), any log calls are ignored).
	 *
	 * Example: select tables section filters result of ns_cloner()->get_site_tables, and should
	 * only do so during cloning process when selected tables have been submitted, not during ajax
	 * request to fetch available tables to select.
	 */
	public function doing_cloning() {
		if ( ! defined( 'DOING_CLONING' ) ) {
			define( 'DOING_CLONING', true );
			// Register all section hooks.
			foreach ( ns_cloner()->sections as $section ) {
				$section->maybe_process_init();
			}
			// Activate logging.
			ns_cloner()->log->start();
		}
	}

	/**
	 * Start cloning process
	 */
	public function init() {
		// Initialize reporting, logging, section hooks.
		ns_cloner()->report->set_start_time();
		$this->doing_cloning();

		// Run startup hook - useful for adjusting the process before anything starts.
		do_action( 'ns_cloner_process_init', ns_cloner_request()->get( 'clone_mode' ) );

		// Don't allow more than one cloning process to be run at one time.
		if ( $this->is_in_progress() ) {
			$this->add_error( __( 'A cloning process is already in progress. Please wait until it completes.', 'ns_cloner' ) );
			return;
		}

		// Validate the current request (trigger individual section validation).
		$this->validate();

		// Check for errors, and don't proceed if found.
		if ( ! empty( $this->errors ) ) {
			$this->add_error( __( 'Validation errors found.', 'ns-cloner-site-copier' ) );
			return;
		} else {
			do_action( 'ns_cloner_validated' );
		}

		// Delete the exit flag from the last time.
		delete_site_option( 'ns_cloner_exited' );

		// Set the current user id, so that the original user id can always be accessed by background processes.
		ns_cloner_request()->set( 'user_id', get_current_user_id() );

		// Save request so it will be available to background processes.
		// Most modes will update and save it again later, but this makes sure the base request is always saved.
		ns_cloner_request()->save();

		ns_sql_foreign_key_checks( 0 );

		// Trigger all the actions registered for this clone mode (start background processes, etc).
		$clone_mode = ns_cloner_request()->get( 'clone_mode' );
		do_action( "ns_cloner_process_{$clone_mode}" );
	}

	/**
	 * Validate request variables for the current cloning mode
	 *
	 * This populates the $errors property but does nothing else - it is up to the
	 * calling function to check the value of $errors and determine action accordingly.
	 * Implements the 'ns_cloner_validation_errors' hook to allow things other than
	 * sections to perform validation if needed.
	 *
	 * @param string $section_id ID for specific section to validate ($id property of section class).
	 *      If blank, validate all sections that are supported for the current clone mode.
	 */
	public function validate( $section_id = '' ) {
		$clone_mode = ns_cloner_request()->get( 'clone_mode' );

		// Validate sections.
		if ( ! empty( $section_id ) ) {
			$sections = array( ns_cloner()->get_section( $section_id ) );
		} else {
			$sections = ns_cloner()->sections;
		}
		foreach ( $sections as $id => $section ) {
			if ( in_array( $clone_mode, $section->modes_supported, true ) ) {
				$section->validate();
				foreach ( $section->get_errors() as $error_message ) {
					$this->add_error( $error_message, array( 'section' => $section->id ) );
				}
			}
		}
	}

	/**
	 * Calls $this->finish only if all current processes are complete
	 *
	 * Sometimes this could get called twice in a short time window, so use locking.
	 * The lock, like NS_Cloner_Process::process_lock, uses direct database queries,
	 * not via transient or site options functions because those have caching that
	 * can get in the way.
	 */
	public function maybe_finish() {
		// Check that this isn't already being run in another parallel session.
		$lock = $this->get_finish_lock();
		if ( $lock ) {
			ns_cloner()->log->log( "DETECTED already running finish $lock, skipping" );
			return;
		}
		// If it's not in progress, finish already happened.
		if ( $this->is_in_progress() ) {
			$progress = $this->get_progress();
			if ( 'complete' === $progress['status'] ) {
				// Set a unique lock.
				$finish_lock_id = wp_generate_password( 8 );
				ns_cloner()->db->query(
					ns_prepare_option_query(
						'INSERT INTO {table} ( {key}, {value} ) VALUES( %s, %s )',
						array( 'ns_cloner_finish_lock', $finish_lock_id )
					)
				);
				ns_cloner()->log->log( "SETTING finish lock *$finish_lock_id*" );
				// Then wait 0.5 seconds and check again to make sure a simultaneous lock hasn't been set.
				// If the set lock isn't from this (earlier) instance, bail and let the later instance take over.
				usleep( apply_filters( 'ns_cloner_process_lock_delay', 0.5 * 1000000 ) );
				$current_lock = $this->get_finish_lock();
				if ( $current_lock !== $finish_lock_id ) {
					ns_cloner()->log->log( "DETECTED simultaneous finish call *$current_lock*, ending" );
					exit;
				}
				$this->finish();
				// Remove lock for next time.
				ns_cloner()->db->query(
					ns_prepare_option_query(
						'DELETE FROM {table} WHERE {key} = %s',
						'ns_cloner_finish_lock'
					)
				);
			}
		}
	}

	/**
	 * Get the unique ID of the current finish lock, if there is one.
	 *
	 * @return string|null
	 */
	protected function get_finish_lock() {
		return ns_cloner()->db->get_var(
			ns_prepare_option_query(
				'SELECT {value} FROM {table} WHERE {key} = %s',
				'ns_cloner_finish_lock'
			)
		);
	}

	/**
	 * Finish the cloning process
	 */
	public function finish() {
		$this->doing_cloning();
		ns_cloner()->log->log( 'ENTERING *finish*' );
		$target_id     = ns_cloner_request()->get( 'target_id' );
		$target_title  = ns_cloner_request()->get( 'target_title' );
		$target_prefix = ns_cloner_request()->get( 'target_prefix' );

		// Use this do do any finish/cleanup/reporting actions.
		do_action( 'ns_cloner_process_finish' );

		// Perform any cleanup queries registered during process.
		foreach ( $this->get_finish_queries() as $query ) {
			ns_cloner()->log->log( "RUNNING finish query: $query" );
			ns_cloner()->db->query( $query );
			ns_cloner()->log->handle_any_db_errors();
		}

		// Update target title since it will have been overwritten by cloned options.
		// Use direct db query because otherwise object caching + cache flush can cause update to be lost.
		if ( ns_cloner_request()->is_mode( 'core' ) ) {
			if ( ! empty( $target_id ) && ! empty( $target_title ) ) {
				ns_cloner()->db->update(
					$target_prefix . 'options',
					array( 'option_value' => $target_title ),
					array( 'option_name' => 'blogname' )
				);
			}
		}

		ns_sql_foreign_key_checks( 1 );

		// Flush cache if checkbox to flush cache is enabled.
		// Do not flush cache if sites are different servers.
		if ( (bool) ns_cloner_request()->get( 'flush_cache' ) && ! empty( $target_id ) ) {
			// Switch to blog only affects database and not functions.
			ns_cloner()->log->log( 'FLUSHING CACHE' );
			wp_cache_flush();
		}

		// Log and report timing details.
		ns_cloner()->report->set_end_time();
		$start_time = ns_cloner()->report->get_start_time();
		$end_time   = ns_cloner()->report->get_end_time();
		$total_time = ns_cloner()->report->get_elapsed_time();
		$minutes    = floor( $total_time / 60 );
		$seconds    = ceil( $total_time % 60 );
		ns_cloner()->report->add_report( __( 'Start Time', 'ns-cloner-site-copier' ), $start_time );
		ns_cloner()->report->add_report( __( 'End Time', 'ns-cloner-site-copier' ), $end_time );
		ns_cloner()->report->add_report( __( 'Total Time', 'ns-cloner-site-copier' ), "{$minutes} min. {$seconds} sec." );
		ns_cloner()->log->log( 'END TIME: ' . $end_time );
		ns_cloner()->log->log( 'TOTAL_TIME: ' . "{$minutes} min. {$seconds} sec." );

		// Report details specific to the current mode (via report function provided when registering the mode).
		call_user_func( ns_cloner()->get_mode()->report );

		// Report number of items processed by each background process (tables, rows, users, files, etc).
		foreach ( $this->get_current_processes() as $process_id => $progress ) {
			$process = ns_cloner()->get_process( $process_id );
			if ( ! empty( $process->report_label ) ) {
				$completed = $progress['completed'];
				/* translators: number of items copied in clone operation */
				$report_string = _n( '%d item processed', '%d items processed', $completed, 'ns-cloner-site-copier' );
				ns_cloner()->report->add_report( $process->report_label, sprintf( $report_string, $completed ) );
			}
		}

		// Report number of text replacements made in site content.
		$replacements = (int) ns_cloner()->report->get_report( '_replacements' );
		/* translators: number of text replacements made on site content */
		$report_string = _n( '%d replacement made', '%d replacements made', $replacements, 'ns-cloner-site-copier' );
		ns_cloner()->report->add_report( __( 'Replacements', 'ns-cloner-site-copier' ), sprintf( $report_string, $replacements ) );

		// Clear all process data (except report).
		$this->exit_processes();
	}

	/**
	 * End all background processes and reset their data, plus clear saved reports and request data.
	 *
	 * @param mixed $error Optional error message to end log with if exiting unexpectedly.
	 */
	public function exit_processes( $error = '' ) {
		$this->doing_cloning();
		ns_cloner()->log->log( 'ENTERING *exit_processes*' );

		// Process Cloner analytics.
		ns_cloner_analytics()->process_cloner_result(
			$this->get_current_processes(),
			ns_cloner_request()->get( 'clone_mode' ),
			ns_cloner()->report->get_elapsed_time(),
			$error
		);

		// Log and report the error, if present.
		if ( $error ) {
			ns_cloner()->log->log( array( 'CALLED exit with error message:', $error ) );
			ns_cloner()->log->log( array( 'Other errors from prccess_manager:', $this->get_errors() ) );
			// Add this to the errors array so that it can be displayed immediately by any ajax requests
			// that will see it (for example, process_init).
			$this->add_error( $error );
			// Also add to the report, so that if the error was caused by a background progress OR
			// the page got closed or for some other reason the error won't be shown via ajax, it can
			// be shown next time the page is open. Note that if $clear_report is true, this gets erased.
			ns_cloner()->report->add_report( '_error', $error );
		}

		// Report log file location for debugging.
		if ( ns_cloner()->log->is_debug() ) {
			ns_cloner()->report->add_report( __( 'Log File', 'ns-cloner-site-copier' ), ns_cloner()->log->get_url() );
		}

		// Cancel running background processes, as well as clear data from any completed ones.
		foreach ( $this->get_current_processes( true ) as $process_id => $progress ) {
			$process = ns_cloner()->get_process( $process_id );
			$process->cancel();
		}

		// Save flag so that currently running batches can end (otherwise the data might be in memory
		// of another session and keep running for a while before realizing that the queue was cleared.
		update_site_option( 'ns_cloner_exited', '1' );

		// Log the current saved report data.
		ns_cloner()->log->log( array( 'REPORT DATA:', ns_cloner()->report->get_all_reports() ) );

		// Provide last hook while request, report and log are still accessible.
		do_action( 'ns_cloner_process_exit' );

		// Clear saved request data and end log.
		delete_site_option( 'ns_cloner_finish_queries' );
		ns_cloner()->db->query(
			ns_prepare_option_query(
				'DELETE FROM {table} WHERE {key} = %s',
				'ns_cloner_finish_lock'
			)
		);
		ns_cloner_request()->delete();
		ns_cloner()->log->end();
	}

	/*
	______________________________________
	|
	|  Cloning Steps
	|_____________________________________
	*/

	/**
	 * Create a new site/blog on the network (step 1 for core mode)
	 */
	public function create_site() {
		$source_id    = ns_cloner_request()->get( 'source_id' );
		$target_name  = ns_cloner_request()->get( 'target_name', '' );
		$target_title = ns_cloner_request()->get( 'target_title', '' );

		// Sanitize.
		$target_name = strtolower( trim( $target_name ) );

		// Try to create new site.
		$source    = get_site( $source_id );
		$site_data = array(
			'title'   => $target_title,
			'user_id' => ns_cloner_request()->get( 'user_id' ),
			'public'  => $source->public,
			'lang_id' => $source->lang_id,
		);
		if ( is_subdomain_install() ) {
			$site_data += array(
				'domain' => $target_name . '.' . preg_replace( '|^www\.|', '', get_current_site()->domain ),
				'path'   => get_current_site()->path,
			);
		} else {
			$site_data += array(
				'domain' => get_current_site()->domain,
				'path'   => get_current_site()->path . $target_name . '/',
			);
		}
		ns_cloner()->log->log( array( 'Attempting to create site with data:', $site_data ) );
		if ( function_exists( 'wp_insert_site' ) ) {
			$target_id = wp_insert_site( $site_data );
		} else {
			// Backwards compatibility for pre 5.1.
			$target_id = wpmu_create_blog(
				$site_data['domain'],
				$site_data['path'],
				$site_data['title'],
				$site_data['user_id']
			);
		}

		// Handle results.
		if ( ! is_wp_error( $target_id ) ) {
			ns_cloner()->log->log( "New site '$target_title' (" . get_site_url( $target_id ) . ') created.' );
			ns_cloner_request()->set( 'target_id', $target_id );
			ns_cloner_request()->set_up_vars();
			ns_cloner_request()->save();
		} else {
			$this->exit_processes( 'Error creating site:' . $target_id->get_error_message() );
		}
	}

	/**
	 * Clone the source site's tables (step 2 for core mode)
	 */
	public function copy_tables() {
		$tables_process = ns_cloner()->get_process( 'tables' );
		$source_prefix  = ns_cloner_request()->get( 'source_prefix' );
		$target_prefix  = ns_cloner_request()->get( 'target_prefix' );
		$source_id      = ns_cloner_request()->get( 'source_id' );
		$target_id      = ns_cloner_request()->get( 'target_id' );

		// Makes sure that the target prefix is not somehow the same as the source.
		// Shouldn't be possible, but is irreversibly destructive if it does, so make sure.
		if ( $source_prefix === $target_prefix ) {
			$this->exit_processes( __( 'Source and target prefix the same. Cannot clone tables.', 'ns-cloner-site-copier' ) );
			return;
		}

		// Queue table cloning background process.
		$source_tables = ns_reorder_tables( ns_cloner()->get_site_tables( $source_id ) );
		foreach ( $source_tables as $source_table ) {
			$target_table = preg_replace( "|^$source_prefix|", $target_prefix, $source_table );
			$target_table = apply_filters( 'ns_cloner_target_table', $target_table );
			$table_data   = array(
				'source_table' => $source_table,
				'target_table' => $target_table,
				'source_id'    => $source_id,
				'target_id'    => $target_id,
			);
			$tables_process->push_to_queue( $table_data );
			ns_cloner()->log->log( "QUEUEING clone of *$source_table* to *$target_table*" );
		}

		// Run Background Process for cloning tables.
		$tables_process->save()->dispatch();
	}

	/**
	 * Copy the source site's files (step 3 for core mode)
	 */
	public function copy_files() {
		$files_process = ns_cloner()->get_process( 'files' );
		$source_dir    = ns_cloner_request()->get( 'source_upload_dir' );
		$target_dir    = ns_cloner_request()->get( 'target_upload_dir' );

		// Makes sure that the target uploads dir is not somehow the same as the source.
		// Shouldn't be possible, but is irreversibly destructive if it does, so make sure.
		if ( $source_dir === $target_dir && ns_cloner_request()->get( 'do_copy_files', true ) ) {
			$this->exit_processes( __( 'Source and target uploads directories are the same. Cannot clone files.', 'ns-cloner-site-copier' ) );
			return;
		}

		// Queue file copy background process.
		$num_files = ns_recursive_dir_copy_by_process( $source_dir, $target_dir, $files_process );
		ns_cloner()->log->log( "QUEUEING *$num_files* files from *$source_dir* to *$target_dir*" );

		// Run background process for copying files.
		$files_process->save()->dispatch();
	}

	/*
	______________________________________
	|
	|  Manage Progress
	|_____________________________________
	*/

	/**
	 * Get progress data for currently running background process
	 *
	 * This uses current processes to get the progress data for each individual background process,
	 * and then it aggregates them together, much like NS_Cloner_Process::get_total_progress()
	 * aggregates batches into overall progress for the process, except this then further aggregates
	 * those by-process progress results into an overall view of the entire cloning operation, across
	 * all background processes.
	 *
	 * @return array|bool
	 */
	public function get_progress() {
		$by_process  = array();
		$total       = 0;
		$completed   = 0;
		$queue_empty = true;
		$processes   = $this->get_current_processes();

		// If no processes and no report, there's nothing to return - get_progress shouldn't have needed
		// to be called, so there was probably an error and exit_processes() was called.
		if ( empty( $processes ) && empty( ns_cloner()->report->get_all_reports() ) ) {
			// Try to get error message.
			$default_error = __( 'An unknown error occurred. Check the logs for info.', 'ns-cloner-site-copier' );
			$this->add_error( ns_cloner()->report->get_report( '_error' ) ?: $default_error );
			return false;
		}

		// If there are no processes but there IS a report, it means that the operation has finished.
		if ( empty( $processes ) && ! empty( ns_cloner()->report->get_all_reports() ) ) {
			return array(
				'status' => 'reported',
				'report' => ns_cloner()->report->get_all_reports(),
			);
		}

		// Otherwise - process must be in progress, so prepare progress data.
		foreach ( $processes as $process_id => $progress ) {
			$process = ns_cloner()->get_process( $process_id );
			// Calculate the combined total of expected and completed objects for all background processes.
			$total     += $progress['total'];
			$completed += $progress['completed'];
			// Make sure to check both for running and empty queue, because the queue could be empty
			// but still have a pending teleport request that was fired via $process->after_handle().
			if ( ! $process->is_queue_empty() || $process->is_process_running() ) {
				$queue_empty = false;
			}
			// Also prepare to return data for each individual process.
			$by_process[ $process_id ] = array(
				'label'      => $process->report_label,
				'progress'   => $progress,
				'dispatched' => get_site_option( "ns_cloner_{$process_id}_process_dispatched" ),
				'nonce'      => $process->get_nonce(),
			);
		}
		return array(
			'total'      => $total,
			'completed'  => $completed,
			'percentage' => $total > 0 ? round( ( 100 * $completed ) / $total ) : 0,
			'status'     => $queue_empty ? 'complete' : 'in_progress',
			'processes'  => $by_process,
		);
	}

	/**
	 * Get all background process batches in the database, and group by which process created them
	 *
	 * @param bool $suppress_filters Whether to enable checking all processes, or only a filtered subset.
	 * @return array
	 */
	private function get_current_processes( $suppress_filters = false ) {
		$processes     = array();
		$all_processes = ns_cloner()->processes;
		// Add filter here so that addons/mode can disable checking for unused processes to speed up code.
		if ( ! $suppress_filters ) {
			$all_processes = apply_filters( 'ns_cloner_processes_to_check', $all_processes );
		}
		ns_cloner()->log->log( array( 'CHECKING progress for processes:', array_keys( $all_processes ) ) );
		foreach ( $all_processes as $process_id => $process ) {
			$progress = $process->get_total_progress();
			// Only add to the results if it this process has more than one object in it.
			// Check both the total (for normal cases), as well as is_queue_empty so that if
			// an error occurs and a process is running but missing progress data, it isn't invisible.
			if ( $progress['total'] > 0 || ! $process->is_queue_empty() ) {
				$processes[ $process_id ] = $progress;
			}
		}
		return $processes;
	}

	/**
	 * Check if there are any background processes in progress
	 *
	 * Note that this will return true if there are background processes with the status 'complete',
	 * even if none have the actual 'in_progress' status - the idea is that if they have not been
	 * cleared via $this->exit_processes(), the overall operation is still in progress.
	 *
	 * @param bool $suppress_filters Whether to enable checking all processes, or only a filtered subset.
	 * @return bool
	 */
	public function is_in_progress( $suppress_filters = false ) {
		return count( $this->get_current_processes( $suppress_filters ) ) > 0;
	}

}
