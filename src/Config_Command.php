<?php

use WP_CLI\ExitException;
use WP_CLI\Utils;
use WP_CLI\WpOrgApi;

/**
 * Generates and reads the wp-config.php file.
 *
 * ## EXAMPLES
 *
 *     # Create standard wp-config.php file.
 *     $ wp config create --dbname=testing --dbuser=wp --dbpass=securepswd --locale=ro_RO
 *     Success: Generated 'wp-config.php' file.
 *
 *     # List constants and variables defined in wp-config.php file.
 *     $ wp config list
 *     +------------------+------------------------------------------------------------------+----------+
 *     | key              | value                                                            | type     |
 *     +------------------+------------------------------------------------------------------+----------+
 *     | table_prefix     | wp_                                                              | variable |
 *     | DB_NAME          | wp_cli_test                                                      | constant |
 *     | DB_USER          | root                                                             | constant |
 *     | DB_PASSWORD      | root                                                             | constant |
 *     | AUTH_KEY         | r6+@shP1yO&$)1gdu.hl[/j;7Zrvmt~o;#WxSsa0mlQOi24j2cR,7i+QM/#7S:o^ | constant |
 *     | SECURE_AUTH_KEY  | iO-z!_m--YH$Tx2tf/&V,YW*13Z_HiRLqi)d?$o-tMdY+82pK$`T.NYW~iTLW;xp | constant |
 *     +------------------+------------------------------------------------------------------+----------+
 *
 *     # Get wp-config.php file path.
 *     $ wp config path
 *     /home/person/htdocs/project/wp-config.php
 *
 *     # Get the table_prefix as defined in wp-config.php file.
 *     $ wp config get table_prefix
 *     wp_
 *
 *     # Set the WP_DEBUG constant to true.
 *     $ wp config set WP_DEBUG true --raw
 *     Success: Updated the constant 'WP_DEBUG' in the 'wp-config.php' file with the raw value 'true'.
 *
 *     # Delete the COOKIE_DOMAIN constant from the wp-config.php file.
 *     $ wp config delete COOKIE_DOMAIN
 *     Success: Deleted the constant 'COOKIE_DOMAIN' from the 'wp-config.php' file.
 *
 *     # Launch system editor to edit wp-config.php file.
 *     $ wp config edit
 *
 *     # Check whether the DB_PASSWORD constant exists in the wp-config.php file.
 *     $ wp config has DB_PASSWORD
 *     $ echo $?
 *     0
 *
 *     # Assert if MULTISITE is true.
 *     $ wp config is-true MULTISITE
 *     $ echo $?
 *     0
 *
 *     # Get new salts for your wp-config.php file.
 *     $ wp config shuffle-salts
 *     Success: Shuffled the salt keys.
 *
 * @package wp-cli
 */
class Config_Command extends WP_CLI_Command {

	/**
	 * List of characters that are valid for a key name.
	 *
	 * @var string
	 */
	const VALID_KEY_CHARACTERS = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*()-_ []{}<>~`+=,.;:/?|';

	/**
	 * List of default constants that are generated by WordPress Core.
	 *
	 * @string
	 */
	const DEFAULT_SALT_CONSTANTS = [
		'AUTH_KEY',
		'SECURE_AUTH_KEY',
		'LOGGED_IN_KEY',
		'NONCE_KEY',
		'AUTH_SALT',
		'SECURE_AUTH_SALT',
		'LOGGED_IN_SALT',
		'NONCE_SALT',
	];

	/**
	 * Retrieve the initial locale from the WordPress version file.
	 *
	 * @return string Initial locale if present, or an empty string if not.
	 */
	private static function get_initial_locale() {
		global $wp_local_package;

		include ABSPATH . '/wp-includes/version.php';

		if ( ! empty( $wp_local_package ) ) {
			return $wp_local_package;
		}

		return '';
	}

	/**
	 * Generates a wp-config.php file.
	 *
	 * Creates a new wp-config.php with database constants, and verifies that
	 * the database constants are correct.
	 *
	 * ## OPTIONS
	 *
	 * --dbname=<dbname>
	 * : Set the database name.
	 *
	 * --dbuser=<dbuser>
	 * : Set the database user.
	 *
	 * [--dbpass=<dbpass>]
	 * : Set the database user password.
	 *
	 * [--dbhost=<dbhost>]
	 * : Set the database host.
	 * ---
	 * default: localhost
	 * ---
	 *
	 * [--dbprefix=<dbprefix>]
	 * : Set the database table prefix.
	 * ---
	 * default: wp_
	 * ---
	 *
	 * [--dbcharset=<dbcharset>]
	 * : Set the database charset.
	 * ---
	 * default: utf8
	 * ---
	 *
	 * [--dbcollate=<dbcollate>]
	 * : Set the database collation.
	 * ---
	 * default:
	 * ---
	 *
	 * [--locale=<locale>]
	 * : Set the WPLANG constant. Defaults to $wp_local_package variable.
	 *
	 * [--extra-php]
	 * : If set, the command copies additional PHP code into wp-config.php from STDIN.
	 *
	 * [--skip-salts]
	 * : If set, keys and salts won't be generated, but should instead be passed via `--extra-php`.
	 *
	 * [--skip-check]
	 * : If set, the database connection is not checked.
	 *
	 * [--force]
	 * : Overwrites existing files, if present.
	 *
	 * [--config-file=<path>]
	 * : Specify the file path to the config file to be created. Defaults to the root of the
	 * WordPress installation and the filename "wp-config.php".
	 *
	 * [--insecure]
	 * : Retry API download without certificate validation if TLS handshake fails. Note: This makes the request vulnerable to a MITM attack.
	 *
	 * ## EXAMPLES
	 *
	 *     # Standard wp-config.php file
	 *     $ wp config create --dbname=testing --dbuser=wp --dbpass=securepswd --locale=ro_RO
	 *     Success: Generated 'wp-config.php' file.
	 *
	 *     # Enable WP_DEBUG and WP_DEBUG_LOG
	 *     $ wp config create --dbname=testing --dbuser=wp --dbpass=securepswd --extra-php <<PHP
	 *     define( 'WP_DEBUG', true );
	 *     define( 'WP_DEBUG_LOG', true );
	 *     PHP
	 *     Success: Generated 'wp-config.php' file.
	 *
	 *     # Avoid disclosing password to bash history by reading from password.txt
	 *     # Using --prompt=dbpass will prompt for the 'dbpass' argument
	 *     $ wp config create --dbname=testing --dbuser=wp --prompt=dbpass < password.txt
	 *     Success: Generated 'wp-config.php' file.
	 */
	public function create( $_, $assoc_args ) {
		if ( ! Utils\get_flag_value( $assoc_args, 'force' ) ) {
			if ( isset( $assoc_args['config-file'] ) && file_exists( $assoc_args['config-file'] ) ) {
				$this->config_file_already_exist_error( basename( $assoc_args['config-file'] ) );
			} elseif ( ! isset( $assoc_args['config-file'] ) && Utils\locate_wp_config() ) {
				$this->config_file_already_exist_error( 'wp-config.php' );
			}
		}

		if ( empty( $assoc_args['dbprefix'] ) ) {
			WP_CLI::error( '--dbprefix cannot be empty' );
		}
		if ( preg_match( '|[^a-z0-9_]|i', $assoc_args['dbprefix'] ) ) {
			WP_CLI::error( '--dbprefix can only contain numbers, letters, and underscores.' );
		}

		// Check DB connection. To make command more portable, we are not using MySQL CLI and using
		// mysqli directly instead, as $wpdb is not accessible in this context.
		if ( ! Utils\get_flag_value( $assoc_args, 'skip-check' ) ) {
			// phpcs:disable WordPress.DB.RestrictedFunctions
			$mysql = mysqli_init();
			mysqli_report( MYSQLI_REPORT_STRICT );
			try {
				// Accept similar format to one used by parse_db_host() e.g. 'localhost:/tmp/mysql.sock'
				$socket     = '';
				$host       = $assoc_args['dbhost'];
				$socket_pos = strpos( $host, ':/' );
				if ( false !== $socket_pos ) {
					$socket = substr( $host, $socket_pos + 1 );
					$host   = substr( $host, 0, $socket_pos );
				}

				if ( file_exists( $socket ) ) {
					// If dbhost is a path to a socket
					mysqli_real_connect( $mysql, null, $assoc_args['dbuser'], $assoc_args['dbpass'], null, null, $socket );
				} else {
					// If dbhost is a hostname or IP address
					mysqli_real_connect( $mysql, $host, $assoc_args['dbuser'], $assoc_args['dbpass'] );
				}
			} catch ( mysqli_sql_exception $exception ) {
				WP_CLI::error( 'Database connection error (' . $exception->getCode() . ') ' . $exception->getMessage() );
			}
			// phpcs:enable WordPress.DB.RestrictedFunctions
		}

		$defaults = [
			'dbhost'      => 'localhost',
			'dbpass'      => '',
			'dbprefix'    => 'wp_',
			'dbcharset'   => 'utf8',
			'dbcollate'   => '',
			'locale'      => self::get_initial_locale(),
			'config-file' => rtrim( ABSPATH, '/\\' ) . '/wp-config.php',
		];

		if ( ! Utils\get_flag_value( $assoc_args, 'skip-salts' ) ) {
			try {
				$defaults['keys-and-salts']    = true;
				$defaults['auth-key']          = self::unique_key();
				$defaults['secure-auth-key']   = self::unique_key();
				$defaults['logged-in-key']     = self::unique_key();
				$defaults['nonce-key']         = self::unique_key();
				$defaults['auth-salt']         = self::unique_key();
				$defaults['secure-auth-salt']  = self::unique_key();
				$defaults['logged-in-salt']    = self::unique_key();
				$defaults['nonce-salt']        = self::unique_key();
				$defaults['wp-cache-key-salt'] = self::unique_key();
			} catch ( Exception $e ) {
				$defaults['keys-and-salts']     = false;
				$defaults['keys-and-salts-alt'] = self::fetch_remote_salts(
					(bool) Utils\get_flag_value( $assoc_args, 'insecure', false )
				);
			}
		}

		if ( Utils\wp_version_compare( '4.0', '<' ) ) {
			$defaults['add-wplang'] = true;
		} else {
			$defaults['add-wplang'] = false;
		}

		$path = $defaults['config-file'];
		if ( ! empty( $assoc_args['config-file'] ) ) {
			$path = $assoc_args['config-file'];
		}

		if ( ! empty( $assoc_args['extra-php'] ) ) {
			$defaults['extra-php'] = $this->escape_config_value( 'extra-php', $assoc_args['extra-php'] );
		}

		$command_root = Utils\phar_safe_path( dirname( __DIR__ ) );
		$out          = Utils\mustache_render( "{$command_root}/templates/wp-config.mustache", $defaults );

		// Output the default config file at path specified in assoc args.
		$wp_config_file_name = basename( $path );
		$bytes_written       = file_put_contents( $path, $out );

		if ( ! $bytes_written ) {
			WP_CLI::error( "Could not create new '{$wp_config_file_name}' file." );
		}

		$assoc_args = array_merge( $defaults, $assoc_args );

		// 'extra-php' from STDIN is retrieved after escaping to avoid breaking
		// the PHP code.
		if ( Utils\get_flag_value( $assoc_args, 'extra-php' ) === true ) {
			$assoc_args['extra-php'] = file_get_contents( 'php://stdin' );
		}

		$options = [
			'raw'       => false,
			'add'       => true,
			'normalize' => true,
		];

		$config_keys = [
			'dbhost'    => array(
				'name' => 'DB_HOST',
				'type' => 'constant',
			),
			'dbpass'    => array(
				'name' => 'DB_PASSWORD',
				'type' => 'constant',
			),
			'dbprefix'  => array(
				'name' => 'table_prefix',
				'type' => 'variable',
			),
			'dbcharset' => array(
				'name' => 'DB_CHARSET',
				'type' => 'constant',
			),
			'dbcollate' => array(
				'name' => 'DB_COLLATE',
				'type' => 'constant',
			),
			'locale'    => array(
				'name' => 'WPLANG',
				'type' => 'constant',
			),
			'dbname'    => array(
				'name' => 'DB_NAME',
				'type' => 'constant',
			),
			'dbuser'    => array(
				'name' => 'DB_USER',
				'type' => 'constant',
			),
		];

		try {
			$config_transformer = new WPConfigTransformer( $path );

			foreach ( $config_keys as $key => $const ) {

				$value = $assoc_args[ $key ];
				if ( ! empty( $value ) ) {
					$config_transformer->update( $const['type'], $const['name'], $value, $options );
				}
			}
		} catch ( Exception $exception ) {
			//Remove the default moustache wp-config.php template file.
			if ( file_exists( $assoc_args['config-file'] ) ) {
				unlink( $path );
			}

			WP_CLI::error( "Could not create new '{$wp_config_file_name}' file.\nReason: {$exception->getMessage()}" );
		}

		WP_CLI::success( "Generated '{$wp_config_file_name}' file." );
	}

	/**
	 * Gives error when wp-config already exist and try to create it.
	 *
	 * @param string $wp_config_file_name Config file name.
	 * @return void
	 */
	private function config_file_already_exist_error( $wp_config_file_name ) {
		WP_CLI::error( "The '{$wp_config_file_name}' file already exists." );
	}

	/**
	 * Launches system editor to edit the wp-config.php file.
	 *
	 * ## OPTIONS
	 *
	 * [--config-file=<path>]
	 * : Specify the file path to the config file to be edited. Defaults to the root of the
	 * WordPress installation and the filename "wp-config.php".
	 *
	 * ## EXAMPLES
	 *
	 *     # Launch system editor to edit wp-config.php file
	 *     $ wp config edit
	 *
	 *     # Edit wp-config.php file in a specific editor
	 *     $ EDITOR=vim wp config edit
	 *
	 * @when before_wp_load
	 */
	public function edit( $_, $assoc_args ) {
		$path                = $this->get_config_path( $assoc_args );
		$wp_config_file_name = basename( $path );
		$contents            = file_get_contents( $path );
		$r                   = Utils\launch_editor_for_input( $contents, $wp_config_file_name, 'php' );
		if ( false === $r ) {
			WP_CLI::warning( "No changes made to {$wp_config_file_name}, aborted." );
		} else {
			file_put_contents( $path, $r );
		}
	}

	/**
	 * Gets the path to wp-config.php file.
	 *
	 * ## EXAMPLES
	 *
	 *     # Get wp-config.php file path
	 *     $ wp config path
	 *     /home/person/htdocs/project/wp-config.php
	 *
	 * @when before_wp_load
	 */
	public function path() {
		WP_CLI::line( $this->get_config_path( array() ) );
	}

	/**
	 * Lists variables, constants, and file includes defined in wp-config.php file.
	 *
	 * ## OPTIONS
	 *
	 * [<filter>...]
	 * : Name or partial name to filter the list by.
	 *
	 * [--fields=<fields>]
	 * : Limit the output to specific fields. Defaults to all fields.
	 *
	 * [--format=<format>]
	 * : Render output in a particular format.
	 * Dotenv is limited to non-object values.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 *   - yaml
	 *   - dotenv
	 * ---
	 *
	 * [--strict]
	 * : Enforce strict matching when a filter is provided.
	 *
	 * [--config-file=<path>]
	 * : Specify the file path to the config file to be read. Defaults to the root of the
	 * WordPress installation and the filename "wp-config.php".
	 *
	 * ## EXAMPLES
	 *
	 *     # List constants and variables defined in wp-config.php file.
	 *     $ wp config list
	 *     +------------------+------------------------------------------------------------------+----------+
	 *     | key              | value                                                            | type     |
	 *     +------------------+------------------------------------------------------------------+----------+
	 *     | table_prefix     | wp_                                                              | variable |
	 *     | DB_NAME          | wp_cli_test                                                      | constant |
	 *     | DB_USER          | root                                                             | constant |
	 *     | DB_PASSWORD      | root                                                             | constant |
	 *     | AUTH_KEY         | r6+@shP1yO&$)1gdu.hl[/j;7Zrvmt~o;#WxSsa0mlQOi24j2cR,7i+QM/#7S:o^ | constant |
	 *     | SECURE_AUTH_KEY  | iO-z!_m--YH$Tx2tf/&V,YW*13Z_HiRLqi)d?$o-tMdY+82pK$`T.NYW~iTLW;xp | constant |
	 *     +------------------+------------------------------------------------------------------+----------+
	 *
	 *     # List only database user and password from wp-config.php file.
	 *     $ wp config list DB_USER DB_PASSWORD --strict
	 *     +------------------+-------+----------+
	 *     | key              | value | type     |
	 *     +------------------+-------+----------+
	 *     | DB_USER          | root  | constant |
	 *     | DB_PASSWORD      | root  | constant |
	 *     +------------------+-------+----------+
	 *
	 *     # List all salts from wp-config.php file.
	 *     $ wp config list _SALT
	 *     +------------------+------------------------------------------------------------------+----------+
	 *     | key              | value                                                            | type     |
	 *     +------------------+------------------------------------------------------------------+----------+
	 *     | AUTH_SALT        | n:]Xditk+_7>Qi=>BmtZHiH-6/Ecrvl(V5ceeGP:{>?;BT^=[B3-0>,~F5z$(+Q$ | constant |
	 *     | SECURE_AUTH_SALT | ?Z/p|XhDw3w}?c.z%|+BAr|(Iv*H%%U+Du&kKR y?cJOYyRVRBeB[2zF-`(>+LCC | constant |
	 *     | LOGGED_IN_SALT   | +$@(1{b~Z~s}Cs>8Y]6[m6~TnoCDpE>O%e75u}&6kUH!>q:7uM4lxbB6[1pa_X,q | constant |
	 *     | NONCE_SALT       | _x+F li|QL?0OSQns1_JZ{|Ix3Jleox-71km/gifnyz8kmo=w-;@AE8W,(fP<N}2 | constant |
	 *     +------------------+------------------------------------------------------------------+----------+
	 *
	 * @when before_wp_load
	 * @subcommand list
	 */
	public function list_( $args, $assoc_args ) {
		$path                = $this->get_config_path( $assoc_args );
		$wp_config_file_name = basename( $path );
		$strict              = Utils\get_flag_value( $assoc_args, 'strict' );
		if ( $strict && empty( $args ) ) {
			WP_CLI::error( 'The --strict option can only be used in combination with a filter.' );
		}

		$default_fields = [
			'name',
			'value',
			'type',
		];

		$defaults = [
			'fields' => implode( ',', $default_fields ),
			'format' => 'table',
		];

		$assoc_args = array_merge( $defaults, $assoc_args );

		$values = self::get_wp_config_vars( $path );

		if ( ! empty( $args ) ) {
			$values = $this->filter_values( $values, $args, $strict );
		}

		if ( empty( $values ) ) {
			WP_CLI::error( "No matching entries found in '{$wp_config_file_name}'." );
		}

		if ( 'dotenv' === $assoc_args['format'] ) {
			return array_walk( $values, array( $this, 'print_dotenv' ) );
		}

		Utils\format_items( $assoc_args['format'], $values, $assoc_args['fields'] );
	}

	/**
	 * Gets the value of a specific constant or variable defined in wp-config.php file.
	 *
	 * ## OPTIONS
	 *
	 * <name>
	 * : Name of the wp-config.php constant or variable.
	 *
	 * [--type=<type>]
	 * : Type of config value to retrieve. Defaults to 'all'.
	 * ---
	 * default: all
	 * options:
	 *   - constant
	 *   - variable
	 *   - all
	 * ---
	 *
	 * [--format=<format>]
	 * : Get value in a particular format.
	 * Dotenv is limited to non-object values.
	 * ---
	 * default: var_export
	 * options:
	 *   - var_export
	 *   - json
	 *   - yaml
	 *   - dotenv
	 * ---
	 *
	 * [--config-file=<path>]
	 * : Specify the file path to the config file to be read. Defaults to the root of the
	 * WordPress installation and the filename "wp-config.php".
	 *
	 * ## EXAMPLES
	 *
	 *     # Get the table_prefix as defined in wp-config.php file.
	 *     $ wp config get table_prefix
	 *     wp_
	 *
	 * @when before_wp_load
	 */
	public function get( $args, $assoc_args ) {
		$value = $this->get_value( $assoc_args, $args );
		WP_CLI::print_value( $value, $assoc_args );
	}

	/**
	 * Determines whether value of a specific defined constant or variable is truthy.
	 *
	 * This determination is made by evaluating the retrieved value via boolval().
	 *
	 * ## OPTIONS
	 *
	 * <name>
	 * : Name of the wp-config.php constant or variable.
	 *
	 * [--type=<type>]
	 * : Type of config value to retrieve. Defaults to 'all'.
	 * ---
	 * default: all
	 * options:
	 *   - constant
	 *   - variable
	 *   - all
	 * ---
	 *
	 * [--config-file=<path>]
	 * : Specify the file path to the config file to be read. Defaults to the root of the
	 * WordPress installation and the filename "wp-config.php".
	 *
	 * ## EXAMPLES
	 *
	 *     # Assert if MULTISITE is true
	 *     $ wp config is-true MULTISITE
	 *     $ echo $?
	 *     0
	 *
	 * @subcommand is-true
	 * @when before_wp_load
	 */
	public function is_true( $args, $assoc_args ) {
		$value = $this->get_value( $assoc_args, $args );

		if ( boolval( $value ) ) {
			WP_CLI::halt( 0 );
		}
		WP_CLI::halt( 1 );
	}

	/**
	 * Get the array of wp-config.php constants and variables.
	 *
	 * @param string $wp_config_path Config file path
	 *
	 * @return array
	 */
	private static function get_wp_config_vars( $wp_config_path = '' ) {
		$wp_cli_original_defined_constants = get_defined_constants();
		$wp_cli_original_defined_vars      = get_defined_vars();
		$wp_cli_original_includes          = get_included_files();

		// phpcs:ignore Squiz.PHP.Eval.Discouraged -- Don't have another way.
		eval( WP_CLI::get_runner()->get_wp_config_code( $wp_config_path ) );

		$wp_config_vars      = self::get_wp_config_diff( get_defined_vars(), $wp_cli_original_defined_vars, 'variable', [ 'wp_cli_original_defined_vars' ] );
		$wp_config_constants = self::get_wp_config_diff( get_defined_constants(), $wp_cli_original_defined_constants, 'constant' );

		foreach ( $wp_config_vars as $name => $value ) {
			if ( 'wp_cli_original_includes' === $value['name'] ) {
				$name_backup = $name;
				break;
			}
		}

		unset( $wp_config_vars[ $name_backup ] );
		$wp_config_vars           = array_values( $wp_config_vars );
		$wp_config_includes       = array_diff( get_included_files(), $wp_cli_original_includes );
		$wp_config_includes_array = [];

		foreach ( $wp_config_includes as $name => $value ) {
			$wp_config_includes_array[] = [
				'name'  => basename( $value ),
				'value' => $value,
				'type'  => 'includes',
			];
		}

		return array_merge( $wp_config_vars, $wp_config_constants, $wp_config_includes_array );
	}

	/**
	 * Sets the value of a specific constant or variable defined in wp-config.php file.
	 *
	 * ## OPTIONS
	 *
	 * <name>
	 * : Name of the wp-config.php constant or variable.
	 *
	 * <value>
	 * : Value to set the wp-config.php constant or variable to.
	 *
	 * [--add]
	 * : Add the value if it doesn't exist yet.
	 * This is the default behavior, override with --no-add.
	 *
	 * [--raw]
	 * : Place the value into the wp-config.php file as is, instead of as a quoted string.
	 *
	 * [--anchor=<anchor>]
	 * : Anchor string where additions of new values are anchored around.
	 * Defaults to "/* That's all, stop editing!".
	 * The special case "EOF" string uses the end of the file as the anchor.
	 *
	 * [--placement=<placement>]
	 * : Where to place the new values in relation to the anchor string.
	 * ---
	 * default: 'before'
	 * options:
	 *   - before
	 *   - after
	 * ---
	 *
	 * [--separator=<separator>]
	 * : Separator string to put between an added value and its anchor string.
	 * The following escape sequences will be recognized and properly interpreted: '\n' => newline, '\r' => carriage return, '\t' => tab.
	 * Defaults to a single EOL ("\n" on *nix and "\r\n" on Windows).
	 *
	 * [--type=<type>]
	 * : Type of the config value to set. Defaults to 'all'.
	 * ---
	 * default: all
	 * options:
	 *   - constant
	 *   - variable
	 *   - all
	 * ---
	 *
	 * [--config-file=<path>]
	 * : Specify the file path to the config file to be modified. Defaults to the root of the
	 * WordPress installation and the filename "wp-config.php".
	 *
	 * ## EXAMPLES
	 *
	 *     # Set the WP_DEBUG constant to true.
	 *     $ wp config set WP_DEBUG true --raw
	 *     Success: Updated the constant 'WP_DEBUG' in the 'wp-config.php' file with the raw value 'true'.
	 *
	 * @when before_wp_load
	 */
	public function set( $args, $assoc_args ) {
		$path                 = $this->get_config_path( $assoc_args );
		$wp_config_file_name  = basename( $path );
		list( $name, $value ) = $args;
		$type                 = Utils\get_flag_value( $assoc_args, 'type' );

		$options = [];

		$option_flags = [
			'raw'       => false,
			'add'       => true,
			'anchor'    => null,
			'placement' => null,
			'separator' => null,
		];

		foreach ( $option_flags as $option => $default ) {
			$option_value = Utils\get_flag_value( $assoc_args, $option, $default );
			if ( null !== $option_value ) {
				$options[ $option ] = $option_value;
				if ( 'separator' === $option ) {
					$options['separator'] = $this->parse_separator( $options['separator'] );
				}
			}
		}

		$adding = false;
		try {
			$config_transformer = new WPConfigTransformer( $path );

			switch ( $type ) {
				case 'all':
					$has_constant = $config_transformer->exists( 'constant', $name );
					$has_variable = $config_transformer->exists( 'variable', $name );
					if ( $has_constant && $has_variable ) {
						WP_CLI::error( "Found both a constant and a variable '{$name}' in the '{$wp_config_file_name}' file. Use --type=<type> to disambiguate." );
					}
					if ( ! $has_constant && ! $has_variable ) {
						if ( ! $options['add'] ) {
							$message = "The constant or variable '{$name}' is not defined in the '{$wp_config_file_name}' file.";
							WP_CLI::error( $message );
						}
						$type   = 'constant';
						$adding = true;
					} else {
						$type = $has_constant ? 'constant' : 'variable';
					}
					break;
				case 'constant':
				case 'variable':
					if ( ! $config_transformer->exists( $type, $name ) ) {
						if ( ! $options['add'] ) {
							WP_CLI::error( "The {$type} '{$name}' is not defined in the '{$wp_config_file_name}' file." );
						}
						$adding = true;
					}
			}

			$config_transformer->update( $type, $name, $value, $options );

		} catch ( Exception $exception ) {
			WP_CLI::error( "Could not process the '{$wp_config_file_name}' transformation.\nReason: {$exception->getMessage()}" );
		}

		$raw = $options['raw'] ? 'raw ' : '';
		if ( $adding ) {
			$message = "Added the {$type} '{$name}' to the '{$wp_config_file_name}' file with the {$raw}value '{$value}'.";
		} else {
			$message = "Updated the {$type} '{$name}' in the '{$wp_config_file_name}' file with the {$raw}value '{$value}'.";
		}

		WP_CLI::success( $message );
	}

	/**
	 * Deletes a specific constant or variable from the wp-config.php file.
	 *
	 * ## OPTIONS
	 *
	 * <name>
	 * : Name of the wp-config.php constant or variable.
	 *
	 * [--type=<type>]
	 * : Type of the config value to delete. Defaults to 'all'.
	 * ---
	 * default: all
	 * options:
	 *   - constant
	 *   - variable
	 *   - all
	 * ---
	 *
	 * [--config-file=<path>]
	 * : Specify the file path to the config file to be modified. Defaults to the root of the
	 * WordPress installation and the filename "wp-config.php".
	 *
	 * ## EXAMPLES
	 *
	 *     # Delete the COOKIE_DOMAIN constant from the wp-config.php file.
	 *     $ wp config delete COOKIE_DOMAIN
	 *     Success: Deleted the constant 'COOKIE_DOMAIN' from the 'wp-config.php' file.
	 *
	 * @when before_wp_load
	 */
	public function delete( $args, $assoc_args ) {
		$path                = $this->get_config_path( $assoc_args );
		$wp_config_file_name = basename( $path );
		list( $name )        = $args;
		$type                = Utils\get_flag_value( $assoc_args, 'type' );

		try {
			$config_transformer = new WPConfigTransformer( $path );

			switch ( $type ) {
				case 'all':
					$has_constant = $config_transformer->exists( 'constant', $name );
					$has_variable = $config_transformer->exists( 'variable', $name );
					if ( $has_constant && $has_variable ) {
						WP_CLI::error( "Found both a constant and a variable '{$name}' in the '{$wp_config_file_name}' file. Use --type=<type> to disambiguate." );
					}
					if ( ! $has_constant && ! $has_variable ) {
						WP_CLI::error( "The constant or variable '{$name}' is not defined in the '{$wp_config_file_name}' file." );
					} else {
						$type = $has_constant ? 'constant' : 'variable';
					}
					break;
				case 'constant':
				case 'variable':
					if ( ! $config_transformer->exists( $type, $name ) ) {
						WP_CLI::error( "The {$type} '{$name}' is not defined in the '{$wp_config_file_name}' file." );
					}
			}

			$config_transformer->remove( $type, $name );

		} catch ( Exception $exception ) {
			WP_CLI::error( "Could not process the '{$wp_config_file_name}' transformation.\nReason: {$exception->getMessage()}" );
		}

		WP_CLI::success( "Deleted the {$type} '{$name}' from the '{$wp_config_file_name}' file." );
	}

	/**
	 * Checks whether a specific constant or variable exists in the wp-config.php file.
	 *
	 * ## OPTIONS
	 *
	 * <name>
	 * : Name of the wp-config.php constant or variable.
	 *
	 * [--type=<type>]
	 * : Type of the config value to set. Defaults to 'all'.
	 * ---
	 * default: all
	 * options:
	 *   - constant
	 *   - variable
	 *   - all
	 * ---
	 *
	 * [--config-file=<path>]
	 * : Specify the file path to the config file to be checked. Defaults to the root of the
	 * WordPress installation and the filename "wp-config.php".
	 *
	 * ## EXAMPLES
	 *
	 *     # Check whether the DB_PASSWORD constant exists in the wp-config.php file.
	 *     $ wp config has DB_PASSWORD
	 *
	 * @when before_wp_load
	 */
	public function has( $args, $assoc_args ) {
		$path                = $this->get_config_path( $assoc_args );
		$wp_config_file_name = basename( $path );
		list( $name )        = $args;
		$type                = Utils\get_flag_value( $assoc_args, 'type' );

		try {
			$config_transformer = new WPConfigTransformer( $path );

			switch ( $type ) {
				case 'all':
					$has_constant = $config_transformer->exists( 'constant', $name );
					$has_variable = $config_transformer->exists( 'variable', $name );
					if ( $has_constant && $has_variable ) {
						WP_CLI::error( "Found both a constant and a variable '{$name}' in the '{$wp_config_file_name}' file. Use --type=<type> to disambiguate." );
					}
					if ( ! $has_constant && ! $has_variable ) {
						WP_CLI::halt( 1 );
					} else {
						WP_CLI::halt( 0 );
					}
					break;
				case 'constant':
				case 'variable':
					if ( ! $config_transformer->exists( $type, $name ) ) {
						WP_CLI::halt( 1 );
					}
					WP_CLI::halt( 0 );
			}
		} catch ( Exception $exception ) {
			WP_CLI::error( "Could not process the '{$wp_config_file_name}' transformation.\nReason: {$exception->getMessage()}" );
		}
	}

	/**
	 * Refreshes the salts defined in the wp-config.php file.
	 *
	 * ## OPTIONS
	 *
	 * [<keys>...]
	 * : One ore more keys to shuffle. If none are provided, this falls back to the default WordPress Core salt keys.
	 *
	 * [--force]
	 * : If an unknown key is requested to be shuffled, add it instead of throwing a warning.
	 *
	 * [--config-file=<path>]
	 * : Specify the file path to the config file to be modified. Defaults to the root of the
	 * WordPress installation and the filename "wp-config.php".
	 *
	 * [--insecure]
	 * : Retry API download without certificate validation if TLS handshake fails. Note: This makes the request vulnerable to a MITM attack.
	 *
	 * ## EXAMPLES
	 *
	 *     # Get new salts for your wp-config.php file
	 *     $ wp config shuffle-salts
	 *     Success: Shuffled the salt keys.
	 *
	 *     # Add a cache key salt to the wp-config.php file
	 *     $ wp config shuffle-salts WP_CACHE_KEY_SALT --force
	 *     Success: Shuffled the salt keys.
	 *
	 * @subcommand shuffle-salts
	 * @when before_wp_load
	 */
	public function shuffle_salts( $args, $assoc_args ) {
		$keys  = $args;
		$force = Utils\get_flag_value( $assoc_args, 'force', false );

		$has_keys = ( ! empty( $keys ) ) ? true : false;

		if ( empty( $keys ) ) {
			$keys = self::DEFAULT_SALT_CONSTANTS;
		}

		$successes = 0;
		$errors    = 0;
		$skipped   = 0;

		$secret_keys = [];

		try {
			foreach ( $keys as $key ) {
				$unique_key = self::unique_key();
				if ( ! $force && ! in_array( $key, self::DEFAULT_SALT_CONSTANTS, true ) ) {
					WP_CLI::warning( "Could not shuffle the unknown key '{$key}'." );
					++$skipped;
					continue;
				}
				$secret_keys[ $key ] = trim( $unique_key );
			}
		} catch ( Exception $ex ) {
			foreach ( $keys as $key ) {
				if ( ! in_array( $key, self::DEFAULT_SALT_CONSTANTS, true ) ) {
					if ( $force ) {
						WP_CLI::warning( "Could not add the key '{$key}' because 'random_int()' is not supported." );
					} else {
						WP_CLI::warning( "Could not shuffle the unknown key '{$key}'." );
					}
					++$skipped;
				}
			}

			$remote_salts = self::fetch_remote_salts( (bool) Utils\get_flag_value( $assoc_args, 'insecure', false ) );
			$remote_salts = explode( "\n", $remote_salts );
			foreach ( $remote_salts as $k => $salt ) {
				if ( ! empty( $salt ) ) {
					$key = self::DEFAULT_SALT_CONSTANTS[ $k ];
					if ( in_array( $key, $keys, true ) ) {
						$secret_keys[ $key ] = trim( substr( $salt, 28, 64 ) );
					}
				}
			}
		}

		$path = $this->get_config_path( $assoc_args );

		try {
			$config_transformer = new WPConfigTransformer( $path );
			foreach ( $secret_keys as $key => $value ) {
				$is_updated = $config_transformer->update( 'constant', $key, (string) $value );
				if ( $is_updated ) {
					++$successes;
				} else {
					++$errors;
				}
			}
		} catch ( Exception $exception ) {
			$wp_config_file_name = basename( $path );
			WP_CLI::error( "Could not process the '{$wp_config_file_name}' transformation.\nReason: {$exception->getMessage()}" );
		}

		if ( $has_keys ) {
			Utils\report_batch_operation_results( 'salt', 'shuffle', count( $keys ), $successes, $errors, $skipped );
		} else {
			WP_CLI::success( 'Shuffled the salt keys.' );
		}
	}

	/**
	 * Filters wp-config.php file configurations.
	 *
	 * @param array $vars
	 * @param array $previous_list
	 * @param string $type
	 * @param array $exclude_list
	 * @return array
	 */
	private static function get_wp_config_diff( $vars, $previous_list, $type, $exclude_list = [] ) {
		$result = [];
		foreach ( $vars as $name => $val ) {
			if ( array_key_exists( $name, $previous_list ) || in_array( $name, $exclude_list, true ) ) {
				continue;
			}
			$out          = [];
			$out['name']  = $name;
			$out['value'] = $val;
			$out['type']  = $type;
			$result[]     = $out;
		}
		return $result;
	}

	/**
	 * Read the salts from the WordPress.org API.
	 *
	 * @param bool   $insecure Optional. Whether to retry without certificate validation on TLS handshake failure.
	 * @return string String with a set of PHP define() statements to define the salts.
	 * @throws ExitException If the remote request failed.
	 */
	private static function fetch_remote_salts( $insecure = false ) {
		try {
			$salts = (string) ( new WpOrgApi( [ 'insecure' => $insecure ] ) )->get_salts();
		} catch ( Exception $exception ) {
			WP_CLI::error( $exception );
		}

		// Adapt whitespace to adhere to WPCS.
		$salts = preg_replace( '/define\(\'(.*?)\'\);/', 'define( \'$1\' );', $salts );

		return $salts;
	}

	/**
	 * Prints the value of a constant or variable defined in the wp-config.php file.
	 *
	 * If the constant or variable is not defined in the wp-config file then an error will be returned.
	 *
	 * @param string $name
	 * @param string $type
	 * @param string $type
	 * @param string $wp_config_file_name Config file name
	 * @return string The value of the requested constant or variable as defined in the wp-config.php file; if the
	 *                requested constant or variable is not defined then the function will print an error and exit.
	 */
	private function return_value( $name, $type, $values, $wp_config_file_name ) {
		$results = [];
		foreach ( $values as $value ) {
			if ( $name === $value['name'] && ( 'all' === $type || $type === $value['type'] ) ) {
				$results[] = $value;
			}
		}

		if ( count( $results ) > 1 ) {
			WP_CLI::error( "Found both a constant and a variable '{$name}' in the '{$wp_config_file_name}' file. Use --type=<type> to disambiguate." );
		}

		if ( ! empty( $results ) ) {
			return $results[0]['value'];
		}

		$type      = 'all' === $type ? 'constant or variable' : $type;
		$names     = array_column( $values, 'name' );
		$candidate = Utils\get_suggestion( $name, $names );

		if ( ! empty( $candidate ) && $candidate !== $name ) {
			WP_CLI::error( "The {$type} '{$name}' is not defined in the '{$wp_config_file_name}' file.\nDid you mean '{$candidate}'?" );
		}

		WP_CLI::error( "The {$type} '{$name}' is not defined in the '{$wp_config_file_name}' file." );
	}

	/**
	 * Generates a unique key/salt for the wp-config file.
	 *
	 * @throws Exception
	 *
	 * @return string
	 */
	private static function unique_key() {
		if ( ! function_exists( 'random_int' ) ) {
			throw new Exception( "'random_int' does not exist" );
		}

		$chars = self::VALID_KEY_CHARACTERS;
		$key   = '';

		for ( $i = 0; $i < 64; $i++ ) {
			// phpcs:ignore PHPCompatibility.FunctionUse.NewFunctions.random_intFound -- Will be called only if function exists.
			$key .= substr( $chars, random_int( 0, strlen( $chars ) - 1 ), 1 );
		}

		return $key;
	}

	/**
	 * Filters the values based on a provider filter key.
	 *
	 * @param array $values
	 * @param array $filters
	 * @param bool $strict
	 *
	 * @return array
	 */
	private function filter_values( $values, $filters, $strict ) {
		$result = [];

		foreach ( $values as $value ) {
			foreach ( $filters as $filter ) {
				if ( $strict && $filter !== $value['name'] ) {
					continue;
				}

				if ( false === strpos( $value['name'], $filter ) ) {
					continue;
				}

				$result[] = $value;
			}
		}

		return $result;
	}

	/**
	 * Gets the path to the wp-config.php file or gives a helpful error if none found.
	 *
	 * @param array $assoc_args associative arguments given while calling wp config subcommand
	 * @return string Path to wp-config.php file.
	 */
	private function get_config_path( $assoc_args ) {
		if ( isset( $assoc_args['config-file'] ) ) {
			$path = $assoc_args['config-file'];
			if ( ! file_exists( $path ) ) {
				$this->config_file_not_found_error( basename( $assoc_args['config-file'] ) );
			}
		} else {
			$path = Utils\locate_wp_config();
			if ( ! $path ) {
				$this->config_file_not_found_error( 'wp-config.php' );
			}
		}
		return $path;
	}

	/**
	 * Gives error the wp-config file not found
	 *
	 * @param string $wp_config_file_name Config file name.
	 * @return void
	 */
	private function config_file_not_found_error( $wp_config_file_name ) {
		WP_CLI::error( "'{$wp_config_file_name}' not found.\nEither create one manually or use `wp config create`." );
	}
	/**
	 * Parses the separator argument, to allow for special character handling.
	 *
	 * Does the following transformations:
	 * - '\n' => "\n" (newline)
	 * - '\r' => "\r" (carriage return)
	 * - '\t' => "\t" (tab)
	 *
	 * @param string $separator Separator string to parse.
	 *
	 * @return mixed Parsed separator string.
	 */
	private function parse_separator( $separator ) {
		$separator = str_replace(
			[ '\n', '\r', '\t' ],
			[ "\n", "\r", "\t" ],
			$separator
		);

		return $separator;
	}

	/**
	 * Gets the value of a specific constant or variable defined in wp-config.php file.
	 *
	 * @param $assoc_args
	 * @param $args
	 *
	 * @return string
	 */
	protected function get_value( $assoc_args, $args ) {
		$path                = $this->get_config_path( $assoc_args );
		$wp_config_file_name = basename( $path );
		list( $name )        = $args;
		$type                = Utils\get_flag_value( $assoc_args, 'type' );

		$value = $this->return_value(
			$name,
			$type,
			self::get_wp_config_vars( $path ),
			$wp_config_file_name
		);

		return $value;
	}

	/**
	 * Writes a provided variable's key and value to stdout, in dotenv format.
	 *
	 * @param array $value
	 */
	private function print_dotenv( array $value ) {
		if ( ! isset( $value['name'] ) || ! isset( $value['type'] ) || 'constant' !== $value['type'] ) {
			return;
		}

		$name           = strtoupper( $value['name'] );
		$variable_value = isset( $value['value'] ) ? $value['value'] : '';

		$variable_value = str_replace( "'", "\'", $variable_value );

		if ( ! is_numeric( $variable_value ) ) {
			$variable_value = "'{$variable_value}'";
		}

		WP_CLI::line( "{$name}={$variable_value}" );
	}

	/**
	 * Escape a config value so it can be safely used within single quotes.
	 *
	 * @param string $key   Key into the arguments array.
	 * @param mixed  $value Value to escape.
	 * @return mixed Escaped value.
	 */
	private function escape_config_value( $key, $value ) {
		// Skip 'extra-php', it mustn't be escaped.
		if ( 'extra-php' === $key ) {
			return $value;
		}

		// Skip 'keys-and-salts-alt' and assume they are safe.
		if ( 'keys-and-salts-alt' === $key && ! empty( $value ) ) {
			return $value;
		}

		if ( is_string( $value ) ) {
			return addslashes( $value );
		}

		return $value;
	}
}
