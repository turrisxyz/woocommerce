<?php
/**
 * SettingsImportExport class file.
 */

namespace Automattic\WooCommerce\Internal;

use Automattic\WooCommerce\Utilities\ArrayUtil;

/**
 * Class to import and export the WooCommerce settings.
 *
 * @package Automattic\WooCommerce\Internal
 */
class SettingsImportExport {

	/**
	 * Id of the ajax action for settings export.
	 */
	const AJAX_EXPORT_ACTION = 'wc_export_settings';

	/**
	 * Id of the ajax action for settings import.
	 */
	const AJAX_IMPORT_ACTION = 'wc_import_settings';

	/**
	 * Name of the HTML input to select verbose export.
	 */
	const VERBOSE_EXPORT_INPUT_NAME = 'export_settings_verbose';

	/**
	 * Name of the HTML input to select pretty-printed export.
	 */
	const PRETTY_PRINT_EXPORT_INPUT_NAME = 'export_settings_pretty_printed';

	/**
	 * Name of the HTML input to select import mode.
	 */
	const IMPORT_MODE_INPUT_NAME = 'import_settings_mode';

	/**
	 * Name of the HTML input to select the file to import.
	 */
	const FILE_NAME_INPUT_NAME = 'settings-import-file';

	/**
	 * The injected instance of DownloadUtil.
	 *
	 * @var DownloadUtil
	 */
	private $download_util;

	/**
	 * Class initialization, invoked by the DI container.
	 *
	 * @internal
	 * @param DownloadUtil $download_util The instance of DownloadUtil to use.
	 */
	final public function init( DownloadUtil $download_util ) {
		$this->download_util = $download_util;
	}

	/**
	 * Class constructor.
	 */
	public function __construct() {
		add_action(
			'wp_ajax_' . static::AJAX_EXPORT_ACTION,
			function() {
				$this->export_settings();
				die();
			}
		);

		add_action(
			'wp_ajax_' . static::AJAX_IMPORT_ACTION,
			function() {
				$this->import_settings();
			}
		);
	}

	/**
	 * Generate a JSON file with all the existing settings and send it as a file.
	 */
	private function export_settings() {
		$this->verify_nonce( static::AJAX_EXPORT_ACTION );

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$verbose       = 'on' === ArrayUtil::get_value_or_default( $_GET, self::VERBOSE_EXPORT_INPUT_NAME );
		$settings_data = $verbose ? $this->get_settings_verbose() : $this->get_settings_simple();

		$json_options  =
			'on' === ArrayUtil::get_value_or_default( $_GET, self::PRETTY_PRINT_EXPORT_INPUT_NAME ) ?
			JSON_PRETTY_PRINT : 0;
		$settings_json = wp_json_encode( $settings_data, $json_options );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$filename = sprintf( 'woocommerce-settings-%s.json', gmdate( 'Ymdgi' ) );
		$this->download_util->download_as_attachment( $filename, 'application/json', $settings_json );
	}

	/**
	 * Verify the nonce received in the request.
	 *
	 * @param string $action_name Action name for the nonce verification.
	 */
	private function verify_nonce( string $action_name ) {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( ! isset( $_REQUEST['_wpnonce'] ) || ! wp_verify_nonce( wp_unslash( $_REQUEST['_wpnonce'] ), $action_name ) ) {
			header( 'HTTP/1.1 403 Forbidden' );
			exit;
		}
	}

	/**
	 * Generate a verbose representation of all the existing WooCommerce settings
	 * (includes pages, sections, and settings titles, descriptions and default values).
	 *
	 * @return array An array containing verbose information about all the WooCommerce settings.
	 */
	private function get_settings_verbose() {
		$pages_data = array();

		$setting_pages = \WC_Admin_Settings::get_settings_pages();
		foreach ( $setting_pages as $settings_page ) {
			$page_data = array(
				'id'       => $settings_page->get_id(),
				'label'    => $settings_page->get_label(),
				'sections' => array(),
			);

			$page_sections = $settings_page->get_sections();

			foreach ( $page_sections as $section_id => $section_title ) {
				$section_data = array(
					'id'    => $section_id,
					'title' => $section_title,
				);

				$settings_data    = array();
				$section_settings = $settings_page->get_settings( $section_id );
				foreach ( $section_settings as $setting ) {
					if ( ! $setting['id'] || 'sectionend' === $setting['type'] || 'title' === $setting['type'] ) {
						continue;
					}

					$setting_data = array( 'id' => $setting['id'] );

					$setting_info_keys = array( 'title', 'desc', 'desc_hint', 'type', 'default' );
					foreach ( $setting_info_keys as $key ) {
						$value = ArrayUtil::get_value_or_default( $setting, $key );
						if ( null !== $value ) {
							$key                  = str_replace( 'desc', 'description', $key );
							$setting_data[ $key ] = $value;
						}
					}

					$setting_data['value'] = get_option( $setting['id'] );

					if ( ! empty( $setting_data ) ) {
						$settings_data[] = $setting_data;
					}
				}
				$section_data['settings'] = $settings_data;

				if ( ! empty( $section_data['settings'] ) ) {
					$page_data['sections'][] = $section_data;
				}
			}

			if ( ! empty( $page_data['sections'] ) ) {
				$pages_data[] = $page_data;
			}
		}

		return array( 'woocommerce_settings_pages' => $pages_data );
	}

	/**
	 * Generate a simplified representation of all the existing WooCommerce settings
	 * (includes just settings keys and values).
	 *
	 * @return array An array containing simplified information about all the WooCommerce settings.
	 */
	private function get_settings_simple() {
		$settings_data = array();

		$setting_pages = \WC_Admin_Settings::get_settings_pages();
		foreach ( $setting_pages as $settings_page ) {
			$page_sections = $settings_page->get_sections();

			foreach ( $page_sections as $section_id => $section_title ) {
				$section_settings = $settings_page->get_settings( $section_id );

				foreach ( $section_settings as $setting ) {
					if ( ! $setting['id'] || 'sectionend' === $setting['type'] || 'title' === $setting['type'] ) {
						continue;
					}

					$setting_id    = $setting['id'];
					$setting_value = get_option( $setting_id );

					$settings_data[ $setting_id ] = $setting_value;
				}
			}
		}

		return array( 'woocommerce_settings' => $settings_data );
	}

	/**
	 * Receive a JSON file with settings and create or update them as appropriate.
	 */
	private function import_settings() {
		$this->verify_nonce( static::AJAX_IMPORT_ACTION );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$mode = ArrayUtil::get_value_or_default( $_POST, self::IMPORT_MODE_INPUT_NAME );
		if ( ( 'full' !== $mode && 'create_only' !== $mode && 'replace_only' !== $mode ) || ! isset( $_FILES[ self::FILE_NAME_INPUT_NAME ] ) ) {
			header( 'HTTP/1.1 400 Bad request' );
			exit();
		}

		if ( empty( $_FILES[ self::FILE_NAME_INPUT_NAME ] ) ) {
			$this->import_finished( __( 'No file provided.', 'woocommerce' ) );
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$file_upload_error = wp_unslash( $_FILES[ self::FILE_NAME_INPUT_NAME ]['error'] );
		if ( 0 !== $file_upload_error ) {
			$this->import_finished( $this->get_file_upload_error_message( $file_upload_error ) );
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$file_path = wp_unslash( $_FILES[ self::FILE_NAME_INPUT_NAME ]['tmp_name'] );
		if ( empty( $file_path ) ) {
			$this->import_finished( __( 'No file provided.', 'woocommerce' ) );
		}
		if ( ! is_readable( $file_path ) ) {
			$this->import_finished( __( "Can't read the submitted file.", 'woocommerce' ) );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$file_contents = file_get_contents( $file_path );
		$file_data     = json_decode( $file_contents, true );
		if ( null === $file_data ) {
			$this->import_finished( __( 'Invalid file format.', 'woocommerce' ) );
		}

		$settings = $this->extract_settings( $file_data );
		if ( null === $settings ) {
			$this->import_finished( __( 'Not a valid settings export file.', 'woocommerce' ) );
		}

		$failed_setting_or_settings_count = $this->apply_settings( $settings, $mode );
		if ( is_string( $failed_setting_or_settings_count ) ) {
			/* translators: %s: name of a WordPress option that failed to be created or updated */
			$message = sprintf( __( 'Setting creation or update failed. The setting that failed is: %s', 'woocommerce' ), $failed_setting_or_settings_count );
			$this->import_finished( $message );
		}

		$this->import_finished( $failed_setting_or_settings_count );
	}

	/**
	 * Convert a file upload error code to a proper error message
	 * (https://www.php.net/manual/en/features.file-upload.errors.php).
	 *
	 * @param int $error_code The error code to convert.
	 * @return string The equivalent error message.
	 */
	private function get_file_upload_error_message( $error_code ) {
		switch ( $error_code ) {
			case UPLOAD_ERR_INI_SIZE:
			case UPLOAD_ERR_FORM_SIZE:
				return __( 'The submitted file is too big.', 'woocommerce' );
			case UPLOAD_ERR_PARTIAL:
				return __( 'The submitted file was only partially uploaded.', 'woocommerce' );
			case UPLOAD_ERR_NO_FILE:
				return __( 'No file was uploaded.', 'woocommerce' );
			case UPLOAD_ERR_NO_TMP_DIR:
				return __( 'Temporary folder is missing.', 'woocommerce' );
			case UPLOAD_ERR_CANT_WRITE:
				return __( 'Failed to write file to disk.', 'woocommerce' );
			case UPLOAD_ERR_EXTENSION:
				return __( 'File upload stopped by a PHP extension.', 'woocommerce' );
			default:
				/* translators: %d: file upload error code (https://www.php.net/manual/en/features.file-upload.errors.php) */
				return sprintf( __( 'Unknown file upload error, code %d.', 'woocommerce' ), $error_code );
		}
	}

	/**
	 * Handle the import process finish, redirecting to the original settings page
	 * after adding the appropriate success or error message to the query string.
	 *
	 * @param int|string $error_message_or_settings_count The number of settings that have been crated/updated, or an error message.
	 */
	private function import_finished( $error_message_or_settings_count ) {
		$url = wc_get_raw_referer();
		if ( ! $url ) {
			$url = admin_url( 'admin.php?page=wc-settings&tab=advanced&section=importexport' );
		}
		$url = remove_query_arg( 'wc_error', $url );
		$url = remove_query_arg( 'wc_message', $url );

		if ( is_string( $error_message_or_settings_count ) ) {
			/* translators: %s: error message for settings import */
			$url = add_query_arg( array( 'wc_error' => sprintf( __( 'Error when importing settings: %s', 'woocommerce' ), $error_message_or_settings_count ) ), $url );
		} else {
			if ( 0 === $error_message_or_settings_count ) {
				$message = __( 'No settings were imported (empty file or all the settings were skipped).', 'woocommerce' );
			} else {
				/* translators: %d: non-zero count of settings that have been imported. */
				$message = sprintf( __( 'Settings import completed successfully, %d settings were imported.', 'woocommerce' ), $error_message_or_settings_count );
			}
			$url = add_query_arg( array( 'wc_message' => $message ), $url );
		}

		wp_safe_redirect( $url );
		exit();
	}

	/**
	 * Extract the settings from the data in the received JSON file.
	 *
	 * @param array $file_data The array representation of the JSON file received.
	 * @return array|null An array of setting name - setting value, or null if the JSON file didn't have the proper structure.
	 */
	private function extract_settings( $file_data ) {
		if ( ! is_array( $file_data ) ) {
			return null;
		}

		$settings_data = ArrayUtil::get_value_or_default( $file_data, 'woocommerce_settings_pages' );
		if ( null !== $settings_data ) {
			return $this->extract_settings_from_verbose_file( $settings_data );
		}

		return ArrayUtil::get_value_or_default( $file_data, 'woocommerce_settings' );
	}

	/**
	 * Extract the settings from the data in the received JSON file, assuming it was a "verbose" file.
	 *
	 * @param array $settings_data The value of the 'woocommerce_settings_pages' element in the array representation of the JSON file received.
	 * @return array|null An array of setting name - setting value, or null if the JSON file didn't have the proper structure.
	 */
	private function extract_settings_from_verbose_file( array $settings_data ) {
		$all_settings = array();

		foreach ( $settings_data as $page_data ) {
			$sections = ArrayUtil::get_value_or_default( $page_data, 'sections' );
			if ( ! is_array( $sections ) ) {
				return null;
			}

			foreach ( $sections as $section_data ) {
				$section_settings = ArrayUtil::get_value_or_default( $section_data, 'settings' );
				if ( ! is_array( $section_settings ) ) {
					return null;
				}

				foreach ( $section_settings as $setting_data ) {
					if ( ! isset( $setting_data['id'] ) || ! is_string( $setting_data['id'] ) || ! isset( $setting_data['value'] ) ) {
						return null;
					}

					$all_settings[ $setting_data['id'] ] = $setting_data['value'];
				}
			}
		}

		return $all_settings;
	}

	/**
	 * Create or update the received settings as appropriate.
	 *
	 * @param array  $settings An array of setting name - setting value.
	 * @param string $mode Settings import mode, one of 'full', 'create_only' or 'replace_only'.
	 * @return int|string The count of settings that have been created/updated, or the name of the setting whose creation/update failed.
	 */
	private function apply_settings( array $settings, string $mode ) {
		$count = 0;

		foreach ( $settings as $name => $value ) {
			$previous_value = get_option( $name, null );

			if ( 'create_only' === $mode && null !== $previous_value ) {
				continue;
			}
			if ( 'replace_only' === $mode && null === $previous_value ) {
				continue;
			}

			if ( null === $previous_value ) {
				$success = add_option( $name, $value );
			} elseif ( $value !== $previous_value ) {
				$success = update_option( $name, $value );
			} else {
				continue;
			}

			if ( ! $success ) {
				return $name;
			}

			$count++;
		}

		return $count;
	}
}