<?php
/**
 * Standalone test suite for wm-lksg-supply-auditor.
 *
 * Runs without WordPress. WordPress functions are small fakes; $wpdb is a fake that runs the plugin's SQL on an
 * in-memory SQLite database built from the plugin's own CREATE TABLE statements (Schema::*_sql()), so the tables the
 * tests use cannot drift from the schema. SQL the fake cannot run throws instead of returning null, values come back
 * as strings as they do from MySQL, and PHP warnings and notices fail the case that raised them.
 *
 *   php tests/test-suite.php          exit 0 only when every assertion passes
 *   bash tests/run-mutations.sh       undoes each correction of tests/mutations.php; this suite must fail every time
 *
 * Until 2026-09-15 this file ran against a mock whose get_var() answered 42 to any query it did not recognise and
 * asserted the literal HEALTHY, the BAFA identifier and a `true` "does not throw" case.
 *
 * @package WMCyber\LkSG
 */

declare(strict_types=1);

error_reporting( E_ALL );
ini_set( 'display_errors', '1' );

if ( ! extension_loaded( 'pdo_sqlite' ) ) {
	echo "pdo_sqlite is required for tests/test-suite.php.\n";
	exit( 1 );
}

define( 'ABSPATH', dirname( __DIR__ ) . '/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'OBJECT', 'OBJECT' );

final class LksgRedirect extends RuntimeException {}
final class LksgDie extends RuntimeException {}
final class LksgCliError extends RuntimeException {}

// ---------------------------------------------------------------------------------------------------------------------
// $wpdb on SQLite
// ---------------------------------------------------------------------------------------------------------------------

final class Lksg_Test_WPDB {
	public string $prefix     = 'wp_';
	public string $options    = 'wp_options';
	public int $insert_id     = 0;
	public string $last_error = '';
	/** @var string[] */
	public array $queries = [];
	/** @var string[] ALTER … MODIFY statements (SQLite cannot run them; recorded instead). */
	public array $ddl = [];
	/** @var array<string, int> table => number of inserts to refuse */
	public array $fail_insert_into = [];
	private PDO $pdo;

	public function __construct() {
		$this->pdo = new PDO( 'sqlite::memory:' );
		$this->pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
		$this->pdo->setAttribute( PDO::ATTR_STRINGIFY_FETCHES, true );
		$this->pdo->query( "CREATE TABLE wp_options (option_id INTEGER PRIMARY KEY AUTOINCREMENT, option_name TEXT NOT NULL UNIQUE, option_value TEXT NOT NULL, autoload TEXT NOT NULL DEFAULT 'yes')" );
	}

	public function get_charset_collate(): string {
		return '';
	}

	public function esc_like( $text ): string {
		return addcslashes( (string) $text, '_%\\' );
	}

	public function prepare( $query, ...$args ): string {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		$i   = 0;
		$out = preg_replace_callback(
			'/%(%|i|s|d|f)/',
			function ( array $m ) use ( &$i, $args, $query ): string {
				if ( '%' === $m[1] ) {
					return '%';
				}
				if ( ! array_key_exists( $i, $args ) ) {
					throw new RuntimeException( 'prepare(): placeholder without argument in ' . $query );
				}
				$value = $args[ $i++ ];
				return match ( $m[1] ) {
					'i' => '`' . str_replace( '`', '``', (string) $value ) . '`',
					's' => $this->pdo->quote( (string) $value ),
					'd' => (string) (int) $value,
					'f' => sprintf( '%F', (float) $value ),
				};
			},
			(string) $query
		);
		if ( $i !== count( $args ) ) {
			throw new RuntimeException( 'prepare(): ' . count( $args ) . ' arguments for ' . $i . ' placeholders in ' . $query );
		}
		return (string) $out;
	}

	private function translate( string $sql ): string {
		$s = trim( $sql );
		if ( preg_match( "/^SHOW TABLES LIKE '((?:[^']|'')*)'$/i", $s, $m ) ) {
			$name = preg_replace( '/\\\\(.)/', '$1', str_replace( "''", "'", $m[1] ) );
			return "SELECT name FROM sqlite_master WHERE type = 'table' AND name = " . $this->pdo->quote( (string) $name );
		}
		return (string) preg_replace( "/LIKE ('(?:[^']|'')*')/i", "LIKE $1 ESCAPE '\\\\'", $s );
	}

	private function run( string $sql ): PDOStatement {
		$this->queries[] = $sql;
		try {
			return $this->pdo->query( $this->translate( $sql ) );
		} catch ( PDOException $e ) {
			$this->last_error = $e->getMessage();
			throw new RuntimeException( 'SQL failed: ' . $e->getMessage() . ' in: ' . $sql );
		}
	}

	/** Statement for the test itself (table setup), not something the plugin calls. */
	public function run_raw( string $sql ): void {
		$this->pdo->query( $sql );
	}

	public function query( $sql ) {
		$sql = (string) $sql;
		if ( preg_match( '/^\s*ALTER\s+TABLE\s+\S+\s+MODIFY\b/i', $sql ) ) {
			$this->queries[] = $sql;
			$this->ddl[]     = $sql;
			return true;
		}
		$st = $this->run( $sql );
		return preg_match( '/^\s*(DELETE|UPDATE|INSERT)/i', $sql ) ? $st->rowCount() : true;
	}

	public function get_var( $sql ) {
		$row = $this->run( (string) $sql )->fetch( PDO::FETCH_NUM );
		return false === $row ? null : $row[0];
	}

	public function get_col( $sql ): array {
		return $this->run( (string) $sql )->fetchAll( PDO::FETCH_COLUMN, 0 );
	}

	public function get_row( $sql, $output = OBJECT ) {
		$rows = $this->get_results( $sql, $output );
		return $rows[0] ?? null;
	}

	public function get_results( $sql, $output = OBJECT ): array {
		$sql = (string) $sql;
		if ( preg_match( '/^\s*SHOW COLUMNS FROM `([^`]+)`\s*$/i', $sql, $m ) ) {
			$this->queries[] = $sql;
			$rows = [];
			foreach ( $this->pdo->query( 'PRAGMA table_info(' . $this->pdo->quote( $m[1] ) . ')' )->fetchAll( PDO::FETCH_ASSOC ) as $col ) {
				$rows[] = [ 'Field' => $col['name'], 'Type' => $col['type'], 'Null' => '1' === (string) $col['notnull'] ? 'NO' : 'YES', 'Default' => $col['dflt_value'] ];
			}
		} else {
			$rows = $this->run( $sql )->fetchAll( PDO::FETCH_ASSOC );
		}
		return ARRAY_A === $output ? $rows : array_map( static fn( array $r ): object => (object) $r, $rows );
	}

	public function insert( $table, $data, $format = null ) {
		$table = (string) $table;
		if ( null !== $format && count( (array) $format ) !== count( $data ) ) {
			throw new RuntimeException( 'insert(): ' . count( (array) $format ) . ' formats for ' . count( $data ) . ' columns into ' . $table );
		}
		if ( ( $this->fail_insert_into[ $table ] ?? 0 ) > 0 ) {
			--$this->fail_insert_into[ $table ];
			$this->last_error = 'simulated refusal';
			return false;
		}
		$cols = array_keys( $data );
		$sql  = 'INSERT INTO `' . $table . '` (`' . implode( '`, `', $cols ) . '`) VALUES (' . implode( ', ', array_fill( 0, count( $cols ), '?' ) ) . ')';
		$this->queries[] = $sql;
		try {
			$st = $this->pdo->prepare( $sql );
			$st->execute( array_map( static fn( $v ) => is_bool( $v ) ? (int) $v : $v, array_values( $data ) ) );
		} catch ( PDOException $e ) {
			$this->last_error = $e->getMessage();
			return false;
		}
		$this->insert_id = (int) $this->pdo->lastInsertId();
		return 1;
	}

	public function update( $table, $data, $where, $format = null, $where_format = null ) {
		$set  = implode( ', ', array_map( static fn( $c ) => "`$c` = ?", array_keys( $data ) ) );
		$cond = implode( ' AND ', array_map( static fn( $c ) => "`$c` = ?", array_keys( $where ) ) );
		$st   = $this->pdo->prepare( "UPDATE `$table` SET $set WHERE $cond" );
		$st->execute( array_merge( array_values( $data ), array_values( $where ) ) );
		return $st->rowCount();
	}

	public function delete( $table, $where, $where_format = null ) {
		$cond = implode( ' AND ', array_map( static fn( $c ) => "`$c` = ?", array_keys( $where ) ) );
		$this->queries[] = "DELETE FROM `$table` WHERE $cond";
		$st = $this->pdo->prepare( "DELETE FROM `$table` WHERE $cond" );
		$st->execute( array_values( $where ) );
		return $st->rowCount();
	}

	public function ensure_options_table( string $table ): void {
		$this->pdo->query( "CREATE TABLE IF NOT EXISTS `{$table}` (option_id INTEGER PRIMARY KEY AUTOINCREMENT, option_name TEXT NOT NULL UNIQUE, option_value TEXT NOT NULL, autoload TEXT NOT NULL DEFAULT 'yes')" );
	}

	// Options storage used by the option and transient fakes below.
	public function option_get( string $name ): ?string {
		$st = $this->pdo->prepare( "SELECT option_value FROM `{$this->options}` WHERE option_name = ?" );
		$st->execute( [ $name ] );
		$v = $st->fetchColumn();
		return false === $v ? null : (string) $v;
	}

	public function option_set( string $name, string $value, ?bool $autoload = null ): void {
		$autoload_val = false === $autoload ? 'no' : 'yes';
		$st = $this->pdo->prepare( "INSERT INTO `{$this->options}` (option_name, option_value, autoload) VALUES (?, ?, ?) ON CONFLICT(option_name) DO UPDATE SET option_value = excluded.option_value, autoload = excluded.autoload" );
		$st->execute( [ $name, $value, $autoload_val ] );
	}

	public function option_autoload( string $name ): ?string {
		$st = $this->pdo->prepare( "SELECT autoload FROM `{$this->options}` WHERE option_name = ?" );
		$st->execute( [ $name ] );
		$v = $st->fetchColumn();
		return false === $v ? null : (string) $v;
	}

	public function option_delete( string $name ): void {
		$st = $this->pdo->prepare( "DELETE FROM `{$this->options}` WHERE option_name = ?" );
		$st->execute( [ $name ] );
	}
}

/**
 * CREATE TABLE of Schema.php → SQLite: the auto-increment id becomes INTEGER PRIMARY KEY AUTOINCREMENT, `unsigned`
 * goes, non-unique keys go, UNIQUE KEY becomes a unique index.
 *
 * @return string[]
 */
function lksg_sqlite_ddl( string $mysql ): array {
	if ( ! preg_match( '/^\s*CREATE TABLE\s+(\S+)\s*\((.*)\)\s*;\s*$/s', $mysql, $m ) ) {
		throw new RuntimeException( 'unexpected CREATE TABLE: ' . $mysql );
	}
	$table   = $m[1];
	$columns = [];
	$indexes = [];
	foreach ( preg_split( '/,\s*\n/', trim( $m[2] ) ) as $line ) {
		$line = trim( $line );
		if ( preg_match( '/^PRIMARY KEY/i', $line ) || preg_match( '/^KEY\s/i', $line ) ) {
			continue;
		}
		if ( preg_match( '/^UNIQUE KEY\s+(\w+)\s*\(([^)]+)\)/i', $line, $k ) ) {
			$indexes[] = "CREATE UNIQUE INDEX {$k[1]}_{$table} ON {$table} ({$k[2]})";
			continue;
		}
		if ( preg_match( '/^(\w+)\s+bigint\(20\) unsigned NOT NULL AUTO_INCREMENT$/i', $line, $c ) ) {
			$columns[] = "{$c[1]} INTEGER PRIMARY KEY AUTOINCREMENT";
			continue;
		}
		$columns[] = (string) preg_replace( '/\s+unsigned\b/i', '', $line );
	}
	return array_merge( [ "CREATE TABLE {$table} (" . implode( ', ', $columns ) . ')' ], $indexes );
}

function lksg_fresh_db(): Lksg_Test_WPDB {
	$GLOBALS['lksg_current_blog_id'] = 1;
	$GLOBALS['lksg_blog_stack']      = [];
	$db              = new Lksg_Test_WPDB();
	$GLOBALS['wpdb'] = $db;
	$p               = $db->prefix . 'wm_lksg_';
	foreach ( [
		\WMCyber\LkSG\Database\Schema::suppliers_sql( $p . 'suppliers', '', true ),
		\WMCyber\LkSG\Database\Schema::assessments_sql( $p . 'risk_assessments', '' ),
		\WMCyber\LkSG\Database\Schema::audits_sql( $p . 'audits', '' ),
		\WMCyber\LkSG\Database\Schema::complaints_sql( $p . 'complaints', '' ),
		\WMCyber\LkSG\Database\Schema::corrective_actions_sql( $p . 'corrective_actions', '' ),
	] as $sql ) {
		foreach ( lksg_sqlite_ddl( $sql ) as $statement ) {
			$db->run_raw( $statement );
		}
	}
	return $db;
}

// ---------------------------------------------------------------------------------------------------------------------
// WordPress fakes
// ---------------------------------------------------------------------------------------------------------------------

$GLOBALS['lksg_caps']                  = [ 'manage_options' => true ];
$GLOBALS['lksg_user']                  = 1;
$GLOBALS['lksg_routes']                = [];
$GLOBALS['lksg_enqueued']              = [];
$GLOBALS['lksg_unique']                = 0;
$GLOBALS['lksg_is_multisite']          = false;
$GLOBALS['lksg_network_active']        = [];
$GLOBALS['lksg_sites']                 = [ 1 ];
$GLOBALS['lksg_current_blog_id']       = 1;
$GLOBALS['lksg_blog_stack']            = [];
$GLOBALS['lksg_switched_blogs']        = [];
$GLOBALS['lksg_restored_blogs']        = 0;
$GLOBALS['lksg_dbdelta_should_throw']  = false;
$GLOBALS['lksg_activation_hooks']      = [];
$GLOBALS['lksg_deactivation_hooks']    = [];

function add_action( $tag, $callback, $priority = 10, $args = 1 ) { $GLOBALS['lksg_actions'][ $tag ][] = $callback; }
function do_action( $tag, ...$args ) { $GLOBALS['lksg_fired_actions'][ $tag ][] = $args; }
function add_filter( $tag, $callback, $priority = 10, $args = 1 ) { $GLOBALS['lksg_filters'][ $tag ][] = $callback; }
function apply_filters( $tag, $value, ...$args ) {
	foreach ( $GLOBALS['lksg_filters'][ $tag ] ?? [] as $cb ) {
		$value = $cb( $value, ...$args );
	}
	return $value;
}
function load_plugin_textdomain( $domain, $deprecated = false, $plugin_rel_path = false ) { return true; }
function plugin_basename( $file ) { return basename( dirname( (string) $file ) ) . '/' . basename( (string) $file ); }
function plugin_dir_path( $file ) { return rtrim( dirname( (string) $file ), '/\\' ) . '/'; }
function plugin_dir_url( $file ) { return 'https://example.test/wp-content/plugins/' . basename( dirname( (string) $file ) ) . '/'; }
function register_activation_hook( $file, $callback ) { $GLOBALS['lksg_activation_hooks'][ plugin_basename( $file ) ] = $callback; }
function register_deactivation_hook( $file, $callback ) { $GLOBALS['lksg_deactivation_hooks'][ plugin_basename( $file ) ] = $callback; }
function is_multisite() { return ! empty( $GLOBALS['lksg_is_multisite'] ); }
function is_plugin_active_for_network( $plugin ) { return ! empty( $GLOBALS['lksg_network_active'][ (string) $plugin ] ); }
function get_sites( $args = [] ) { return $GLOBALS['lksg_sites']; }
function switch_to_blog( $new_blog_id ) {
	$GLOBALS['lksg_blog_stack'][] = [
		'blog_id' => $GLOBALS['lksg_current_blog_id'],
		'prefix'  => $GLOBALS['wpdb']->prefix,
		'options' => $GLOBALS['wpdb']->options,
	];
	$GLOBALS['lksg_switched_blogs'][] = (int) $new_blog_id;
	$GLOBALS['lksg_current_blog_id']  = (int) $new_blog_id;
	$prefix                           = 1 === (int) $new_blog_id ? 'wp_' : 'wp_' . (int) $new_blog_id . '_';
	$GLOBALS['wpdb']->prefix          = $prefix;
	$GLOBALS['wpdb']->options         = $prefix . 'options';
	$GLOBALS['wpdb']->ensure_options_table( $GLOBALS['wpdb']->options );
	return true;
}
function restore_current_blog() {
	if ( empty( $GLOBALS['lksg_blog_stack'] ) ) {
		return false;
	}
	$prev                            = array_pop( $GLOBALS['lksg_blog_stack'] );
	$GLOBALS['lksg_current_blog_id'] = $prev['blog_id'];
	$GLOBALS['wpdb']->prefix         = $prev['prefix'];
	$GLOBALS['wpdb']->options        = $prev['options'];
	++$GLOBALS['lksg_restored_blogs'];
	return true;
}
function dbDelta( $queries = '' ) {
	if ( ! empty( $GLOBALS['lksg_dbdelta_should_throw'] ) ) {
		throw new RuntimeException( 'simulated dbDelta failure' );
	}
	foreach ( (array) $queries as $sql ) {
		foreach ( lksg_sqlite_ddl( (string) $sql ) as $statement ) {
			$statement = (string) preg_replace( '/^CREATE TABLE /', 'CREATE TABLE IF NOT EXISTS ', $statement );
			$statement = (string) preg_replace( '/^CREATE UNIQUE INDEX /', 'CREATE UNIQUE INDEX IF NOT EXISTS ', $statement );
			$GLOBALS['wpdb']->run_raw( $statement );
		}
	}
	return [];
}
function is_admin() { return ! empty( $GLOBALS['lksg_is_admin'] ); }
function wp_register_style( $handle, $src = '', $deps = [], $ver = false ) { $GLOBALS['lksg_registered_styles'][ $handle ] = [ 'src' => $src, 'deps' => $deps, 'ver' => $ver ]; return true; }
function wp_register_script( $handle, $src = '', $deps = [], $ver = false, $args = [] ) { $GLOBALS['lksg_registered_scripts'][ $handle ] = [ 'src' => $src, 'deps' => $deps, 'ver' => $ver, 'args' => $args ]; return true; }
function wp_set_script_translations( $handle, $domain = 'default', $path = '' ) { $GLOBALS['lksg_script_translations'][ $handle ] = [ 'domain' => $domain, 'path' => $path ]; return true; }
function register_block_type( $block_type, $args = [] ) { $GLOBALS['lksg_blocks'][ (string) $block_type ] = $args; return true; }
function register_setting( $option_group, $option_name, $args = [] ) { $GLOBALS['lksg_registered_settings'][ (string) $option_name ] = [ 'group' => $option_group, 'args' => $args ]; }
function register_block_bindings_source( $source_name, $source_properties ) { $GLOBALS['lksg_block_bindings'][ (string) $source_name ] = $source_properties; return true; }

final class GenerateBlocks_Register_Dynamic_Tag {
	public function __construct( public array $config ) {
		$GLOBALS['lksg_gb_tags'][ (string) ( $config['tag'] ?? '' ) ] = $config;
	}
}
function add_shortcode( $tag, $callback ) { $GLOBALS['lksg_shortcodes'][ $tag ] = $callback; }
function __( $text, $domain = 'default' ) { return $text; }
function esc_html__( $text, $domain = 'default' ) { return esc_html( $text ); }
function esc_attr__( $text, $domain = 'default' ) { return esc_attr( $text ); }
function esc_html_e( $text, $domain = 'default' ) { echo esc_html( $text ); }
function esc_attr_e( $text, $domain = 'default' ) { echo esc_attr( $text ); }
function _n( $single, $plural, $number, $domain = 'default' ) { return 1 === (int) $number ? $single : $plural; }
function esc_html( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
function esc_url( $url ) { return str_replace( '&amp;', '&#038;', htmlspecialchars( (string) $url, ENT_QUOTES, 'UTF-8' ) ); }
function esc_url_raw( $url ) { return (string) $url; }
function sanitize_text_field( $str ) {
	if ( is_array( $str ) || is_object( $str ) ) {
		return '';
	}
	return trim( (string) preg_replace( '/[\r\n\t ]+/', ' ', strip_tags( (string) $str ) ) );
}
function sanitize_key( $key ) { return (string) preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) ); }
function absint( $n ) { return abs( (int) $n ); }
function wp_unslash( $value ) { return is_array( $value ) ? array_map( 'wp_unslash', $value ) : ( is_string( $value ) ? stripslashes( $value ) : $value ); }
function number_format_i18n( $number, $decimals = 0 ) { return number_format( (float) $number, (int) $decimals, ',', '.' ); }
function selected( $a, $b = true ) { if ( (string) $a === (string) $b ) { echo ' selected="selected"'; } }
function checked( $a, $b = true ) { if ( (string) $a === (string) $b ) { echo ' checked="checked"'; } }
function wp_nonce_field( $action, $name = '_wpnonce' ) { echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="valid-' . esc_attr( $action ) . '" />'; }
function wp_create_nonce( $action ) { return 'valid-' . $action; }
function wp_verify_nonce( $nonce, $action ) { return 'valid-' . $action === $nonce ? 1 : false; }
function check_admin_referer( $action, $name ) {
	if ( ( $_POST[ $name ] ?? '' ) !== 'valid-' . $action ) {
		throw new LksgDie( 'nonce ' . $action );
	}
	return 1;
}
function add_query_arg( $key, $value, $url ) { return $url . ( str_contains( $url, '?' ) ? '&' : '?' ) . $key . '=' . $value; }
function wp_nonce_url( $url, $action ) { return str_replace( '&', '&amp;', add_query_arg( '_wpnonce', wp_create_nonce( $action ), $url ) ); }
function rest_url( $path = '' ) { return 'https://example.test/wp-json/' . ltrim( (string) $path, '/' ); }
function admin_url( $path = '' ) { return 'https://example.test/wp-admin/' . ltrim( (string) $path, '/' ); }
function current_user_can( $cap ) { return ! empty( $GLOBALS['lksg_caps'][ $cap ] ); }
function get_current_user_id() { return (int) $GLOBALS['lksg_user']; }
function wp_die( $message = '', $code = 500 ) { throw new LksgDie( (string) $message ); }
function wp_safe_redirect( $location, $status = 302 ) { throw new LksgRedirect( (string) $location ); }
function wp_add_dashboard_widget( $id, $title, $callback ) { $GLOBALS['lksg_widgets'][ $id ] = $title; }
function add_menu_page( ...$args ) { $GLOBALS['lksg_menu'][] = $args; }
function wp_enqueue_style( $handle, $src = '', $deps = [], $ver = false ) { $GLOBALS['lksg_enqueued'][] = $handle; }
function wp_enqueue_script( $handle, $src = '', $deps = [], $ver = false, $args = [] ) { $GLOBALS['lksg_enqueued'][] = $handle; }
function wp_unique_id( $prefix = '' ) { return $prefix . ( ++$GLOBALS['lksg_unique'] ); }
function wp_json_encode( $data, $options = 0, $depth = 512 ) { return json_encode( $data, $options, $depth ); }
function wp_salt( $scheme = 'auth' ) { return 'test-salt-' . $scheme; }
function wp_generate_uuid4() {
	$b    = random_bytes( 16 );
	$b[6] = chr( ( ord( $b[6] ) & 0x0f ) | 0x40 );
	$b[8] = chr( ( ord( $b[8] ) & 0x3f ) | 0x80 );
	return vsprintf( '%s%s-%s-%s-%s-%s%s%s', str_split( bin2hex( $b ), 4 ) );
}
function current_time( $type, $gmt = 0 ) { return 'mysql' === $type ? gmdate( 'Y-m-d H:i:s' ) : time(); }
function maybe_serialize( $data ) { return is_array( $data ) || is_object( $data ) ? serialize( $data ) : (string) $data; }
function maybe_unserialize( $data ) { return is_string( $data ) && preg_match( '/^(a|O|s|i|b|d):/', $data ) ? unserialize( $data ) : $data; }
function get_option( $name, $default = false ) {
	$v = $GLOBALS['wpdb']->option_get( (string) $name );
	return null === $v ? $default : maybe_unserialize( $v );
}
function update_option( $name, $value, $autoload = null ) {
	$autoload_flag = null === $autoload ? null : ( false !== $autoload && 'no' !== $autoload );
	$GLOBALS['wpdb']->option_set( (string) $name, maybe_serialize( $value ), $autoload_flag );
	return true;
}
function delete_option( $name ) { $GLOBALS['wpdb']->option_delete( (string) $name ); return true; }
function delete_site_option( $name ) { return delete_option( $name ); }
function get_transient( $key ) {
	$timeout = get_option( '_transient_timeout_' . $key );
	if ( false !== $timeout && (int) $timeout < time() ) {
		return false;
	}
	return get_option( '_transient_' . $key );
}
function set_transient( $key, $value, $expiration = 0 ) {
	update_option( '_transient_' . $key, $value );
	if ( $expiration > 0 ) {
		update_option( '_transient_timeout_' . $key, time() + (int) $expiration );
	}
	$GLOBALS['lksg_transient_expiry'][ $key ] = (int) $expiration;
	return true;
}
function delete_transient( $key ) { delete_option( '_transient_' . $key ); delete_option( '_transient_timeout_' . $key ); return true; }
function is_wp_error( $thing ) { return $thing instanceof WP_Error; }
function register_rest_route( $namespace, $route, $args ) { $GLOBALS['lksg_routes'][ '/' . $namespace . $route ][] = $args; return true; }

class WP_Error {
	public function __construct( public string $code = '', public string $message = '', public mixed $data = null ) {}
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
	public function get_error_data() { return $this->data; }
}
class WP_REST_Request {
	public function __construct( private array $params = [] ) {}
	public function get_params() { return $this->params; }
	public function get_param( $key ) { return $this->params[ $key ] ?? null; }
	public function get_json_params() { return $this->params; }
}
class WP_REST_Response {
	public array $headers = [];
	public function __construct( public mixed $data = null, public int $status = 200 ) {}
	public function get_data() { return $this->data; }
	public function get_status() { return $this->status; }
	public function header( $key, $value ) { $this->headers[ $key ] = $value; }
}
class WP_CLI_Command {}
class WP_CLI {
	public static array $out = [];
	public static function line( $m ) { self::$out[] = [ 'line', (string) $m ]; }
	public static function success( $m ) { self::$out[] = [ 'success', (string) $m ]; }
	public static function warning( $m ) { self::$out[] = [ 'warning', (string) $m ]; }
	public static function error( $m ) { throw new LksgCliError( (string) $m ); }
	public static function add_command( ...$args ) {}
}

// The hub's module contract, so Lksg_Spoke_Adapter.php defines its class.
require_once __DIR__ . '/fixtures/hub-interface-stub.php';

// Load the real plugin entrypoint so constants, autoloader, lifecycle hooks, and wp_initialize_site are wired.
require_once dirname( __DIR__ ) . '/wm-lksg-supply-auditor.php';

use WMCyber\LkSG\Adapters\Lksg_Spoke_Adapter;
use WMCyber\LkSG\Admin\AdminDashboard;
use WMCyber\LkSG\API\RestController;
use WMCyber\LkSG\CLI\LksgCommand;
use WMCyber\LkSG\Core\Plugin;
use WMCyber\LkSG\Core\Settings;
use WMCyber\LkSG\Database\Register;
use WMCyber\LkSG\Database\Schema;
use WMCyber\LkSG\Export\BafaReportExporter;
use WMCyber\LkSG\Frontend\EcosystemIntegration;
use WMCyber\LkSG\Frontend\SupplierPortal;
use WMCyber\LkSG\Risk\Countries;
use WMCyber\LkSG\Risk\RiskFactors;
use WMCyber\LkSG\Risk\RiskMatrixCalculator;

// ---------------------------------------------------------------------------------------------------------------------
// Harness
// ---------------------------------------------------------------------------------------------------------------------

$GLOBALS['lksg_passed'] = 0;
$GLOBALS['lksg_failed'] = 0;

function assert_test( string $name, bool $condition, string $details = '' ): void {
	if ( $condition ) {
		echo "  [PASS] {$name}\n";
		++$GLOBALS['lksg_passed'];
	} else {
		echo "  [FAIL] {$name}" . ( '' !== $details ? ': ' . $details : '' ) . "\n";
		++$GLOBALS['lksg_failed'];
	}
}

/** One case per closure: an exception, warning or notice is a failed case, not a dead suite. */
function lksg_case( string $name, callable $fn ): void {
	set_error_handler( static function ( int $no, string $msg, string $file, int $line ): bool {
		throw new ErrorException( $msg, 0, $no, $file, $line );
	} );
	try {
		$fn();
	} catch ( \Throwable $t ) {
		assert_test( $name, false, get_class( $t ) . ': ' . $t->getMessage() . ' @ ' . basename( $t->getFile() ) . ':' . $t->getLine() );
	} finally {
		restore_error_handler();
	}
}

function lksg_supplier( array $overrides = [] ): int {
	global $wpdb;
	$row = $overrides + [
		'uuid'             => wp_generate_uuid4(),
		'supplier_name'    => 'Probe ' . bin2hex( random_bytes( 3 ) ),
		'country_code'     => 'DE',
		'industry_sector'  => 'it_software',
		'tier_level'       => 1,
		'dependency_ratio' => 10.0,
		'risk_score'       => 20.0,
		'risk_level'       => 'low',
		'audit_status'     => 'evaluated',
		'created_at'       => '2026-03-01 10:00:00',
		'updated_at'       => '2026-03-01 10:00:00',
	];
	if ( false === $wpdb->insert( $wpdb->prefix . 'wm_lksg_suppliers', $row ) ) {
		throw new RuntimeException( 'seed insert failed: ' . $wpdb->last_error );
	}
	return (int) $wpdb->insert_id;
}

function lksg_count( string $table, string $where = '1 = 1' ): int {
	global $wpdb;
	return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM `' . $wpdb->prefix . 'wm_lksg_' . $table . '` WHERE ' . $where );
}

function lksg_render( callable $fn ): string {
	ob_start();
	try {
		$fn();
	} finally {
		$html = (string) ob_get_clean();
	}
	return $html;
}

/** Text of a document without quoted passages and history lines, where earlier claims are cited on purpose. */
function lksg_doc_text( string $path ): string {
	$text = (string) file_get_contents( $path );
	$text = (string) preg_replace( '/"[^"\n]*"|„[^“\n]*“|“[^”\n]*”|«[^»\n]*»/u', '', $text );
	$keep = [];
	foreach ( preg_split( '/\R/', $text ) as $line ) {
		if ( preg_match( '/\b(Until|Bis zum|Hasta el)\b/u', $line ) ) {
			continue;
		}
		$keep[] = $line;
	}
	return implode( "\n", $keep );
}

$calculator = new RiskMatrixCalculator();
$exporter   = new BafaReportExporter();
$uuid_v4    = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

echo "==============================================================================\n";
echo "  WM LKSG SUPPLY AUDITOR — STANDALONE SUITE (SQLite-backed \$wpdb)\n";
echo "==============================================================================\n\n";

// =====================================================================================================================
echo "[GROUP 1] Risk matrix and factor mapping\n";
// =====================================================================================================================

lksg_case( 'certified low-corruption supplier is low, uncertified high-CPI supplier is critical with flags', function () use ( $calculator ) {
	$low  = $calculator->calculate_risk( [ 'country_cpi_risk' => 20.0, 'sector_risk' => 25.0, 'product_risk' => 20.0, 'severity_score' => 15.0, 'probability_score' => 15.0, 'dependency_ratio' => 10.0, 'causal_contribution' => 10.0, 'has_iso_14001' => true, 'has_sa_8000' => true ] );
	$high = $calculator->calculate_risk( [ 'country_cpi_risk' => 85.0, 'sector_risk' => 80.0, 'product_risk' => 85.0, 'severity_score' => 90.0, 'probability_score' => 85.0, 'dependency_ratio' => 85.0, 'causal_contribution' => 90.0, 'has_iso_14001' => false, 'has_sa_8000' => false ] );
	assert_test( 'certified low-corruption supplier is low, uncertified high-CPI supplier is critical with flags', 'low' === $low['level'] && 'critical' === $high['level'] && in_array( 'HIGH_COUNTRY_CORRUPTION_RISK', $high['high_risk_flags'], true ), var_export( [ $low, $high ], true ) );
} );

lksg_case( 'RiskFactors: aliases, clamping, string booleans, CLI and admin mappings reach the calculator', function () use ( $calculator ) {
	$f   = RiskFactors::from_input( [ 'severity_score' => 250, 'probability_score' => -5, 'has_iso_14001' => 'false', 'has_sa_8000' => 'true', 'human_rights_indicator' => 10 ] );
	$cli = RiskFactors::from_cli_args( [ 'country' => 'DE', 'severity' => '95', 'sa8000' => true ] );
	$adm = RiskFactors::from_admin_form( [ 'wm_calc_country' => 'BD', 'wm_calc_sector' => 'textiles', 'wm_calc_severity' => '70' ] );
	assert_test(
		'RiskFactors: aliases, clamping, string booleans, CLI and admin mappings reach the calculator',
		100.0 === $f['severity_score'] && 0.0 === $f['probability_score'] && false === $f['has_iso_14001'] && true === $f['has_sa_8000']
		&& 'critical' === $calculator->calculate_risk( $cli )['level'] && 85.0 === $adm['country_cpi_risk'] && 70.0 === $adm['severity_score'],
		var_export( [ $f, $cli, $adm ], true )
	);
} );

lksg_case( 'audit_status_for_level never files a critical supplier as verified', function () {
	$m = static fn( string $l ): string => RiskFactors::audit_status_for_level( $l );
	assert_test( 'audit_status_for_level never files a critical supplier as verified', 'mitigation_required' === $m( 'critical' ) && 'mitigation_required' === $m( 'high' ) && 'in_review' === $m( 'medium' ) && 'evaluated' === $m( 'low' ) && 'unassessed' === $m( 'bogus' ) );
} );

// =====================================================================================================================
echo "\n[GROUP 2] ISO 3166-1 alpha-2\n";
// =====================================================================================================================

lksg_case( '249 distinct codes; DE, BD, GB valid; XX, UK, EU, GERMANY invalid; lower case normalised', function () {
	assert_test(
		'249 distinct codes; DE, BD, GB valid; XX, UK, EU, GERMANY invalid; lower case normalised',
		249 === count( Countries::CODES ) && 249 === count( array_unique( Countries::CODES ) )
		&& Countries::is_valid( 'DE' ) && Countries::is_valid( 'BD' ) && Countries::is_valid( 'GB' ) && Countries::is_valid( ' de ' )
		&& ! Countries::is_valid( 'XX' ) && ! Countries::is_valid( 'UK' ) && ! Countries::is_valid( 'EU' ) && ! Countries::is_valid( 'GERMANY' ) && ! Countries::is_valid( '' )
		&& 'DE' === Countries::normalize( ' de ' ) && '' === Countries::normalize( [ 'DE' ] ),
		count( Countries::CODES ) . ' codes'
	);
} );

// =====================================================================================================================
echo "\n[GROUP 3] POST /suppliers\n";
// =====================================================================================================================

lksg_fresh_db();
$rest = new RestController( $calculator, $exporter );

lksg_case( 'a missing country is refused with 400 and nothing is stored (was: stored and scored as DE)', function () use ( $rest ) {
	$res = $rest->create_supplier( new WP_REST_Request( [ 'supplier_name' => 'No Country' ] ) );
	assert_test( 'a missing country is refused with 400 and nothing is stored (was: stored and scored as DE)', is_wp_error( $res ) && 'wm_lksg_invalid_country' === $res->get_error_code() && 400 === ( $res->get_error_data()['status'] ?? 0 ) && 0 === lksg_count( 'suppliers' ), var_export( $res, true ) );
} );

lksg_case( '"Germany" and "XX" are refused with 400', function () use ( $rest ) {
	$a = $rest->create_supplier( new WP_REST_Request( [ 'supplier_name' => 'A', 'country_code' => 'Germany' ] ) );
	$b = $rest->create_supplier( new WP_REST_Request( [ 'supplier_name' => 'B', 'country_code' => 'XX' ] ) );
	assert_test( '"Germany" and "XX" are refused with 400', is_wp_error( $a ) && is_wp_error( $b ) && 400 === ( $b->get_error_data()['status'] ?? 0 ) && 0 === lksg_count( 'suppliers' ) );
} );

lksg_case( 'tier 7 is refused; tier "2" is stored as 2; a missing name is refused', function () use ( $rest ) {
	global $wpdb;
	$bad  = $rest->create_supplier( new WP_REST_Request( [ 'supplier_name' => 'T7', 'country_code' => 'DE', 'tier_level' => 7 ] ) );
	$none = $rest->create_supplier( new WP_REST_Request( [ 'country_code' => 'DE' ] ) );
	$ok   = $rest->create_supplier( new WP_REST_Request( [ 'supplier_name' => 'T2', 'country_code' => 'DE', 'tier_level' => '2' ] ) );
	$tier = $ok instanceof WP_REST_Response ? $wpdb->get_var( $wpdb->prepare( 'SELECT tier_level FROM %i WHERE id = %d', $wpdb->prefix . 'wm_lksg_suppliers', $ok->get_data()['supplier_id'] ) ) : null;
	assert_test( 'tier 7 is refused; tier "2" is stored as 2; a missing name is refused', is_wp_error( $bad ) && 'wm_lksg_invalid_tier' === $bad->get_error_code() && is_wp_error( $none ) && 'wm_lksg_invalid_name' === $none->get_error_code() && '2' === $tier, var_export( [ $bad, $tier ], true ) );
} );

lksg_case( 'a valid supplier is stored with its country upper-cased and the uuid of the answer; the history keeps score, level and every factor', function () use ( $rest, $uuid_v4 ) {
	global $wpdb;
	$res = $rest->create_supplier( new WP_REST_Request( [ 'supplier_name' => 'Mine BD', 'country_code' => 'bd', 'industry_sector' => 'mining', 'severity_score' => 60, 'has_sa_8000' => true ] ) );
	$d   = $res instanceof WP_REST_Response ? $res->get_data() : [];
	$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $wpdb->prefix . 'wm_lksg_suppliers', (int) ( $d['supplier_id'] ?? 0 ) ), ARRAY_A );
	$log = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE supplier_id = %d', $wpdb->prefix . 'wm_lksg_risk_assessments', (int) ( $d['supplier_id'] ?? 0 ) ), ARRAY_A );
	$fac = json_decode( (string) ( $log['factors'] ?? '' ), true );
	assert_test(
		'a valid supplier is stored with its country upper-cased and the uuid of the answer; the history keeps score, level and every factor',
		201 === $res->get_status() && $row && 'BD' === $row['country_code'] && 1 === preg_match( $uuid_v4, (string) ( $d['uuid'] ?? '' ) ) && $d['uuid'] === $row['uuid']
		&& $log && abs( (float) $log['composite_score'] - (float) $d['risk']['score'] ) < 0.001 && $d['risk']['level'] === $log['risk_level'] && 'lksg_composite' === $log['risk_category_id']
		&& is_array( $fac ) && 9 === count( $fac ) && 85.0 === (float) $fac['country_cpi_risk'] && true === $fac['has_sa_8000'],
		var_export( [ $d, $row, $log ], true )
	);
} );

lksg_case( 'a missing sector leaves sector and product at the default 50 (was: manufacturing)', function () use ( $rest ) {
	$res = $rest->create_supplier( new WP_REST_Request( [ 'supplier_name' => 'No Sector', 'country_code' => 'VN' ] ) );
	$f   = $res instanceof WP_REST_Response ? $res->get_data()['factors'] : [];
	assert_test( 'a missing sector leaves sector and product at the default 50 (was: manufacturing)', 50.0 === ( $f['sector_risk'] ?? null ) && 50.0 === ( $f['product_risk'] ?? null ) && 60.0 === ( $f['country_cpi_risk'] ?? null ), var_export( $f, true ) );
} );

lksg_case( 'a refused supplier row answers 500; a refused history row answers 500 and removes the supplier row again', function () use ( $rest ) {
	global $wpdb;
	$before = lksg_count( 'suppliers' );
	$wpdb->fail_insert_into[ $wpdb->prefix . 'wm_lksg_suppliers' ] = 1;
	$a = $rest->create_supplier( new WP_REST_Request( [ 'supplier_name' => 'Refused', 'country_code' => 'DE' ] ) );
	$wpdb->fail_insert_into[ $wpdb->prefix . 'wm_lksg_risk_assessments' ] = 1;
	$b = $rest->create_supplier( new WP_REST_Request( [ 'supplier_name' => 'No History', 'country_code' => 'DE' ] ) );
	assert_test(
		'a refused supplier row answers 500; a refused history row answers 500 and removes the supplier row again',
		is_wp_error( $a ) && 500 === ( $a->get_error_data()['status'] ?? 0 ) && is_wp_error( $b ) && 'wm_lksg_assessment_not_recorded' === $b->get_error_code() && $before === lksg_count( 'suppliers' ) && 0 === lksg_count( 'suppliers', "supplier_name = 'No History'" ),
		var_export( [ $a, $b, $before, lksg_count( 'suppliers' ) ], true )
	);
} );

lksg_case( 'routes declare their arguments: country validated, declaration required, sector enum, per_page up to 100, year bounds', function () use ( $rest ) {
	$GLOBALS['lksg_routes'] = [];
	$rest->register();
	$r         = $GLOBALS['lksg_routes'];
	$post      = array_values( array_filter( $r['/wm-lksg/v1/suppliers'] ?? [], static fn( $e ) => 'POST' === $e['methods'] ) )[0]['args'] ?? [];
	$get       = array_values( array_filter( $r['/wm-lksg/v1/suppliers'] ?? [], static fn( $e ) => 'GET' === $e['methods'] ) )[0]['args'] ?? [];
	$sd        = $r['/wm-lksg/v1/self-disclosure'][0]['args'] ?? [];
	$year      = $r['/wm-lksg/v1/bafa-report'][0]['args']['year'] ?? [];
	$validator = $post['country_code']['validate_callback'] ?? null;
	assert_test(
		'routes declare their arguments: country validated, declaration required, sector enum, per_page up to 100, year bounds',
		true === ( $post['country_code']['required'] ?? false ) && is_callable( $validator ) && true === $validator( 'DE' ) && $validator( 'XX' ) instanceof WP_Error
		&& true === ( $sd['declaration_accepted']['required'] ?? false ) && 'boolean' === ( $sd['declaration_accepted']['type'] ?? '' ) && SupplierPortal::SECTOR_KEYS === ( $sd['industry_sector']['enum'] ?? null )
		&& 100 === ( $get['per_page']['maximum'] ?? 0 ) && Settings::MIN_YEAR === ( $year['minimum'] ?? 0 ) && Settings::max_year() === ( $year['maximum'] ?? 0 ),
		var_export( [ $post, $sd, $get, $year ], true )
	);
} );

// =====================================================================================================================
echo "\n[GROUP 4] POST /self-disclosure\n";
// =====================================================================================================================

lksg_fresh_db();
$_SERVER['REMOTE_ADDR'] = '203.0.113.7';
$GLOBALS['lksg_caps']   = [];
$sd_valid               = [ 'supplier_name' => 'Lieferant GmbH', 'country_code' => 'vn', 'industry_sector' => 'textiles', 'tier_level' => 2, 'has_iso_14001' => true, 'has_sa_8000' => false, 'has_iso_45001' => true, 'declaration_accepted' => true, 'risk_score' => 1.0, 'risk_level' => 'low' ];

lksg_case( 'an accepted self-disclosure answers 201 with a uuid reference and no auto-increment id; the row is unassessed', function () use ( $rest, $sd_valid, $uuid_v4 ) {
	global $wpdb;
	$res = $rest->submit_self_disclosure( new WP_REST_Request( $sd_valid ) );
	$d   = $res instanceof WP_REST_Response ? $res->get_data() : [];
	$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE uuid = %s', $wpdb->prefix . 'wm_lksg_suppliers', (string) ( $d['reference'] ?? '' ) ), ARRAY_A );
	assert_test(
		'an accepted self-disclosure answers 201 with a uuid reference and no auto-increment id; the row is unassessed',
		201 === $res->get_status() && 1 === preg_match( $uuid_v4, (string) ( $d['reference'] ?? '' ) ) && ! array_key_exists( 'supplier_id', $d )
		&& $row && 'unassessed' === $row['risk_level'] && 'self_disclosed' === $row['audit_status'] && 0.0 === (float) $row['risk_score'] && 'VN' === $row['country_code'] && '2' === $row['tier_level'],
		var_export( [ $d, $row ], true )
	);
} );

lksg_case( 'the audit row keeps the declared certifications, the declared text and v2, and NULL for the unassessed scores', function () {
	global $wpdb;
	$a = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id DESC LIMIT 1', $wpdb->prefix . 'wm_lksg_audits' ), ARRAY_A );
	assert_test(
		'the audit row keeps the declared certifications, the declared text and v2, and NULL for the unassessed scores',
		$a && '1' === $a['declared_iso_14001'] && '0' === $a['declared_sa_8000'] && '1' === $a['declared_iso_45001'] && RestController::declaration_text() === $a['declaration_text'] && 'v2' === $a['signature_version']
		&& null === $a['human_rights_score'] && null === $a['environmental_score'] && null === $a['child_labor_risk'] && null === $a['forced_labor_risk'] && 64 === strlen( (string) $a['digital_signature'] ),
		var_export( $a, true )
	);
} );

lksg_case( 'the stored signature verifies; a changed certification, text or time does not; a row without v2 is not verifiable', function () {
	global $wpdb;
	$a    = (array) $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id DESC LIMIT 1', $wpdb->prefix . 'wm_lksg_audits' ), ARRAY_A );
	$sa   = [ 'declared_sa_8000' => '1' ] + $a;
	$text = [ 'declaration_text' => 'Etwas anderes.' ] + $a;
	$time = [ 'created_at' => '2020-01-01 00:00:00' ] + $a;
	$old  = [ 'signature_version' => null ] + $a;
	assert_test(
		'the stored signature verifies; a changed certification, text or time does not; a row without v2 is not verifiable',
		true === RestController::verify_signature( $a ) && false === RestController::verify_signature( $sa ) && false === RestController::verify_signature( $text ) && false === RestController::verify_signature( $time ) && null === RestController::verify_signature( $old ),
		var_export( [ RestController::verify_signature( $a ), RestController::verify_signature( $sa ), RestController::verify_signature( $text ) ], true )
	);
} );

lksg_case( 'no declaration, "false", "0" or an array is refused; XX, an unknown sector and tier 5 are refused', function () use ( $rest, $sd_valid ) {
	$codes = [];
	foreach ( [ [ 'declaration_accepted' => null ], [ 'declaration_accepted' => 'false' ], [ 'declaration_accepted' => '0' ], [ 'declaration_accepted' => [ true ] ], [ 'country_code' => 'XX' ], [ 'industry_sector' => 'anything-at-all' ], [ 'tier_level' => 5 ] ] as $change ) {
		$body = array_merge( $sd_valid, $change );
		if ( array_key_exists( 'declaration_accepted', $change ) && null === $change['declaration_accepted'] ) {
			unset( $body['declaration_accepted'] );
		}
		$res     = $rest->submit_self_disclosure( new WP_REST_Request( $body ) );
		$codes[] = is_wp_error( $res ) ? $res->get_error_code() : 'accepted';
	}
	assert_test( 'no declaration, "false", "0" or an array is refused; XX, an unknown sector and tier 5 are refused', [ 'wm_lksg_no_declaration', 'wm_lksg_no_declaration', 'wm_lksg_no_declaration', 'wm_lksg_no_declaration', 'wm_lksg_invalid_country', 'wm_lksg_invalid_sector', 'wm_lksg_invalid_tier' ] === $codes, implode( ', ', $codes ) );
} );

lksg_case( 'a refused audit row answers 500 and removes the supplier row again', function () use ( $rest, $sd_valid ) {
	global $wpdb;
	$before = lksg_count( 'suppliers' );
	$wpdb->fail_insert_into[ $wpdb->prefix . 'wm_lksg_audits' ] = 1;
	$res = $rest->submit_self_disclosure( new WP_REST_Request( [ 'supplier_name' => 'Kein Audit' ] + $sd_valid ) );
	assert_test( 'a refused audit row answers 500 and removes the supplier row again', is_wp_error( $res ) && 500 === ( $res->get_error_data()['status'] ?? 0 ) && $before === lksg_count( 'suppliers' ), var_export( [ $res, $before, lksg_count( 'suppliers' ) ], true ) );
} );

lksg_case( 'five submissions per hour from the first one: the sixth answers 429 and later ones do not extend the window', function () use ( $rest, $sd_valid ) {
	$key = RestController::rate_limit_key( '198.51.100.9' );
	$_SERVER['REMOTE_ADDR'] = '198.51.100.9';
	$started = time() - 1200;
	set_transient( $key, [ 'count' => 3, 'started' => $started ], 2400 );
	$s4 = $rest->submit_self_disclosure( new WP_REST_Request( $sd_valid ) );
	$w4 = get_transient( $key );
	$ex = $GLOBALS['lksg_transient_expiry'][ $key ] ?? 0;
	$s5 = $rest->submit_self_disclosure( new WP_REST_Request( $sd_valid ) );
	$s6 = $rest->submit_self_disclosure( new WP_REST_Request( $sd_valid ) );
	set_transient( $key, [ 'count' => 5, 'started' => time() - 3700 ], 60 );
	$s7 = $rest->submit_self_disclosure( new WP_REST_Request( $sd_valid ) );
	$w7 = get_transient( $key );
	$_SERVER['REMOTE_ADDR'] = '203.0.113.7';
	assert_test(
		'five submissions per hour from the first one: the sixth answers 429 and later ones do not extend the window',
		$s4 instanceof WP_REST_Response && 4 === ( $w4['count'] ?? 0 ) && $started === ( $w4['started'] ?? 0 ) && $ex <= 2400 && $ex >= 2390
		&& $s5 instanceof WP_REST_Response && is_wp_error( $s6 ) && 429 === ( $s6->get_error_data()['status'] ?? 0 ) && $s7 instanceof WP_REST_Response && 1 === ( $w7['count'] ?? 0 ),
		var_export( [ $w4, $ex, $s6, $w7 ], true )
	);
} );

lksg_case( 'messages use umlauts; the declaration cites § 2 Abs. 2 Nr. 1 bis 3 and § 2 Abs. 3 instead of "gravierenden Umweltbelastungen"', function () {
	$src = (string) file_get_contents( WM_LKSG_PATH . 'includes/API/RestController.php' );
	assert_test(
		'messages use umlauts; the declaration cites § 2 Abs. 2 Nr. 1 bis 3 and § 2 Abs. 3 instead of "gravierenden Umweltbelastungen"',
		0 === preg_match( '/Uebermitt|uebermitt|spaeter|Erklaerung|bestaetig/', $src ) && str_contains( RestController::declaration_text(), '§ 2 Abs. 2 Nr. 1 bis 3 LkSG' ) && str_contains( RestController::declaration_text(), '§ 2 Abs. 3 LkSG' ) && ! str_contains( RestController::declaration_text(), 'gravierenden' )
	);
} );

lksg_case( 'SEC-02: /self-disclosure uses check_self_disclosure_access with wm_lksg_rl_* HMAC rate-limiting, filterable via wm_lksg_rest_rate_limit and bypassed for manage_options', function () use ( $rest ) {
	$GLOBALS['lksg_routes'] = [];
	$rest->register();
	$perm = $GLOBALS['lksg_routes']['/wm-lksg/v1/self-disclosure'][0]['permission_callback'] ?? null;

	$_SERVER['REMOTE_ADDR'] = '198.51.100.44';
	$rl_key                 = RestController::rest_rate_limit_key( '198.51.100.44' );
	delete_transient( $rl_key );

	$GLOBALS['lksg_caps']    = [];
	$GLOBALS['lksg_filters'] = [
		'wm_lksg_rest_rate_limit' => [ static fn(): int => 2 ],
	];
	$req = new WP_REST_Request( [] );
	$r1  = $rest->check_self_disclosure_access( $req );
	$r2  = $rest->check_self_disclosure_access( $req );
	$r3  = $rest->check_self_disclosure_access( $req );

	$GLOBALS['lksg_caps'] = [ 'manage_options' => true ];
	$admin_bypass         = $rest->check_self_disclosure_access( $req );
	$GLOBALS['lksg_filters'] = [];

	assert_test(
		'SEC-02: /self-disclosure uses check_self_disclosure_access with wm_lksg_rl_* HMAC rate-limiting, filterable via wm_lksg_rest_rate_limit and bypassed for manage_options',
		[ $rest, 'check_self_disclosure_access' ] === $perm
		&& str_starts_with( $rl_key, 'wm_lksg_rl_' )
		&& ! str_contains( $rl_key, '198.51.100.44' )
		&& true === $r1 && true === $r2
		&& is_wp_error( $r3 ) && 'wm_lksg_rate_limited' === $r3->get_error_code() && 429 === ( $r3->get_error_data()['status'] ?? 0 )
		&& true === $admin_bypass,
		var_export( [ $perm, $rl_key, $r1, $r2, $r3, $admin_bypass ], true )
	);
} );

$GLOBALS['lksg_caps'] = [ 'manage_options' => true ];

// =====================================================================================================================
echo "\n[GROUP 5] Register and GET /suppliers\n";
// =====================================================================================================================

lksg_fresh_db();
for ( $n = 0; $n < 56; $n++ ) {
	$score = 30 + $n;
	lksg_supplier( [ 'supplier_name' => sprintf( 'Scored %02d', $n ), 'risk_score' => $score, 'risk_level' => $score >= 75 ? 'critical' : ( $score >= 50 ? 'high' : 'medium' ), 'created_at' => sprintf( '2026-02-%02d 09:00:00', 1 + $n % 28 ) ] );
	if ( 20 === $n ) {
		lksg_supplier( [ 'supplier_name' => 'SELF-DISCLOSED BD', 'country_code' => 'BD', 'risk_score' => 0.0, 'risk_level' => 'unassessed', 'audit_status' => 'self_disclosed' ] );
	}
}

lksg_case( 'counts over the whole register: 57 in total, 1 unassessed, 56 assessed, high or critical counted', function () {
	$c = Register::counts();
	assert_test( 'counts over the whole register: 57 in total, 1 unassessed, 56 assessed, high or critical counted', 57 === $c['total'] && 1 === $c['unassessed'] && 56 === $c['assessed'] && 36 === $c['high_or_critical'], var_export( $c, true ) );
} );

lksg_case( 'page 1 holds 50 rows with the unassessed self-disclosure first; page 2 the other 7; per_page is capped at 100', function () {
	$p1 = Register::page( 1, 50 );
	$p2 = Register::page( 2, 50 );
	$p9 = Register::page( 1, 500 );
	assert_test(
		'page 1 holds 50 rows with the unassessed self-disclosure first; page 2 the other 7; per_page is capped at 100',
		57 === $p1['total'] && 2 === $p1['pages'] && 50 === count( $p1['rows'] ) && 'SELF-DISCLOSED BD' === $p1['rows'][0]['supplier_name'] && 7 === count( $p2['rows'] ) && 100 === $p9['per_page'] && 57 === count( $p9['rows'] ),
		var_export( [ $p1['rows'][0]['supplier_name'] ?? null, count( $p2['rows'] ), $p9['per_page'] ], true )
	);
} );

lksg_case( 'GET /suppliers answers with total, pages and the X-WP-Total headers', function () use ( $rest ) {
	$res = $rest->get_suppliers( new WP_REST_Request( [ 'page' => 2, 'per_page' => 50 ] ) );
	$d   = $res instanceof WP_REST_Response ? $res->get_data() : [];
	assert_test( 'GET /suppliers answers with total, pages and the X-WP-Total headers', 57 === ( $d['total'] ?? 0 ) && 2 === ( $d['page'] ?? 0 ) && 7 === count( $d['suppliers'] ?? [] ) && '57' === ( $res->headers['X-WP-Total'] ?? '' ) && '2' === ( $res->headers['X-WP-TotalPages'] ?? '' ), var_export( [ $d['total'] ?? null, $res->headers ?? null ], true ) );
} );

lksg_case( 'without the suppliers table the counts are null and GET /suppliers answers 500', function () use ( $rest ) {
	global $wpdb;
	$wpdb->query( 'DROP TABLE `wp_wm_lksg_suppliers`' );
	$c   = Register::counts();
	$res = $rest->get_suppliers( new WP_REST_Request( [] ) );
	assert_test( 'without the suppliers table the counts are null and GET /suppliers answers 500', null === $c['total'] && null === $c['unassessed'] && is_wp_error( $res ) && 500 === ( $res->get_error_data()['status'] ?? 0 ), var_export( [ $c, $res ], true ) );
} );

// =====================================================================================================================
echo "\n[GROUP 6] Yearly register summary (route /bafa-report)\n";
// =====================================================================================================================

lksg_fresh_db();
lksg_supplier( [ 'supplier_name' => 'Old T1', 'tier_level' => 1, 'created_at' => '2025-05-01 08:00:00', 'risk_level' => 'high', 'risk_score' => 60 ] );
lksg_supplier( [ 'supplier_name' => 'Old T2', 'tier_level' => 2, 'created_at' => '2025-11-30 23:59:59', 'risk_level' => 'low' ] );
lksg_supplier( [ 'supplier_name' => 'New T1', 'tier_level' => 1, 'created_at' => '2026-01-01 00:00:00', 'risk_level' => 'critical', 'risk_score' => 80 ] );
lksg_supplier( [ 'supplier_name' => 'New Self', 'tier_level' => 3, 'created_at' => '2026-04-01 12:00:00', 'risk_level' => 'unassessed', 'risk_score' => 0 ] );
foreach ( [ '2025-06-01 00:00:00', '2026-02-01 00:00:00', '2026-12-31 23:59:59' ] as $assessment_date ) {
	$GLOBALS['wpdb']->insert( 'wp_wm_lksg_risk_assessments', [ 'assessment_id' => wp_generate_uuid4(), 'supplier_id' => 1, 'assessment_date' => $assessment_date, 'risk_category_id' => 'lksg_composite', 'action_trigger' => 'NO_ACTION', 'created_at' => $assessment_date ] );
}
$GLOBALS['wpdb']->insert( 'wp_wm_lksg_audits', [ 'supplier_id' => 4, 'execution_date' => '2026-04-01 12:00:00', 'audit_type' => 'self_disclosure', 'created_at' => '2026-04-01 12:00:00' ] );
$GLOBALS['wpdb']->insert( 'wp_wm_lksg_audits', [ 'supplier_id' => 1, 'execution_date' => '2026-05-01 12:00:00', 'audit_type' => 'onsite', 'created_at' => '2026-05-01 12:00:00' ] );

lksg_case( 'the year filters: 2025 counts 2 suppliers at its end, 2026 counts 4 (was: the same numbers for every year)', function () use ( $exporter ) {
	$y25 = $exporter->generate_bafa_report_data( 2025 );
	$y26 = $exporter->generate_bafa_report_data( 2026 );
	assert_test(
		'the year filters: 2025 counts 2 suppliers at its end, 2026 counts 4 (was: the same numbers for every year)',
		2 === $y25['register_at_period_end']['suppliers'] && 4 === $y26['register_at_period_end']['suppliers'] && 1 === $y25['recorded_in_period']['risk_assessments'] && 2 === $y26['recorded_in_period']['risk_assessments'] && 0 === $y25['recorded_in_period']['self_disclosures'] && '2026-12-31' === $y26['period_utc']['until'],
		var_export( [ $y25['register_at_period_end'], $y25['recorded_in_period'], $y26['register_at_period_end'], $y26['recorded_in_period'] ], true )
	);
} );

lksg_case( 'tier 1 counts only tier 1; unassessed, high or critical and self-disclosures are counted separately', function () use ( $exporter ) {
	$r = $exporter->generate_bafa_report_data( 2026 );
	$a = $r['register_at_period_end'];
	assert_test(
		'tier 1 counts only tier 1; unassessed, high or critical and self-disclosures are counted separately',
		2 === $a['tier_1_direct'] && 2 === $a['tier_2_or_higher'] && 1 === $a['unassessed'] && 2 === $a['high_or_critical_level_now'] && 1 === $r['recorded_in_period']['self_disclosures'],
		var_export( $r, true )
	);
} );

lksg_case( 'no fixed statements: no true anywhere, no BAFA identifier, no officer or gateway; what is not recorded is listed', function () use ( $exporter ) {
	$r     = $exporter->generate_bafa_report_data( 2026 );
	$trues = 0;
	array_walk_recursive( $r, static function ( $v ) use ( &$trues ): void {
		$trues += true === $v ? 1 : 0;
	} );
	$json = (string) json_encode( $r );
	assert_test(
		'no fixed statements: no true anywhere, no BAFA identifier, no officer or gateway; what is not recorded is listed',
		0 === $trues && BafaReportExporter::REPORT_TYPE === $r['report_type'] && ! str_contains( $json, 'JAHRESBERICHT' ) && ! str_contains( $json, 'Compliance Officer' ) && ! str_contains( $json, 'Gateway' ) && isset( $r['not_recorded']['policy_statement'], $r['not_recorded']['complaints_procedure'] ) && str_contains( $r['note'], 'not a report under § 10 Abs. 2 LkSG' ),
		$json
	);
} );

lksg_case( 'a missing table gives null counts and is listed', function () use ( $exporter ) {
	global $wpdb;
	$wpdb->query( 'DROP TABLE `wp_wm_lksg_audits`' );
	$r = $exporter->generate_bafa_report_data( 2026 );
	assert_test( 'a missing table gives null counts and is listed', null === $r['recorded_in_period']['self_disclosures'] && in_array( 'audits', $r['missing_tables'], true ) && 4 === $r['register_at_period_end']['suppliers'], var_export( $r, true ) );
} );

lksg_case( 'GET /bafa-report: ?year=2025 reports 2025, no year reports the settings year, 1999 answers 400', function () use ( $rest ) {
	update_option( Settings::OPTION, [ 'reporting_year' => 2026 ] );
	$a = $rest->get_bafa_report( new WP_REST_Request( [ 'year' => 2025 ] ) );
	$b = $rest->get_bafa_report( new WP_REST_Request( [] ) );
	$c = $rest->get_bafa_report( new WP_REST_Request( [ 'year' => 1999 ] ) );
	assert_test( 'GET /bafa-report: ?year=2025 reports 2025, no year reports the settings year, 1999 answers 400', 2025 === ( $a->get_data()['report']['reporting_year'] ?? 0 ) && 2026 === ( $b->get_data()['report']['reporting_year'] ?? 0 ) && is_wp_error( $c ) && 400 === ( $c->get_error_data()['status'] ?? 0 ) );
} );

// =====================================================================================================================
echo "\n[GROUP 7] Settings\n";
// =====================================================================================================================

lksg_fresh_db();

lksg_case( 'without a stored year the previous calendar year applies; an out-of-range stored year is ignored', function () {
	$default = Settings::reporting_year();
	update_option( Settings::OPTION, [ 'reporting_year' => 5000 ] );
	assert_test( 'without a stored year the previous calendar year applies; an out-of-range stored year is ignored', (int) gmdate( 'Y' ) - 1 === $default && (int) gmdate( 'Y' ) - 1 === Settings::reporting_year() );
} );

lksg_case( 'saving keeps the keys the form does not show (was: sync_hinschg and officer_email reset on every save)', function () {
	update_option( Settings::OPTION, [ 'sync_hinschg' => true, 'officer_email' => 'compliance@example.test', 'high_risk_threshold' => 80 ] );
	$ok     = Settings::save_reporting_year( '2025' );
	$stored = get_option( Settings::OPTION );
	assert_test( 'saving keeps the keys the form does not show (was: sync_hinschg and officer_email reset on every save)', true === $ok && 2025 === $stored['reporting_year'] && true === $stored['sync_hinschg'] && 'compliance@example.test' === $stored['officer_email'] && 80 === $stored['high_risk_threshold'], var_export( $stored, true ) );
} );

lksg_case( 'years outside 2000 to next year and non-years are refused and change nothing', function () {
	$refused = ! Settings::save_reporting_year( '1999' ) && ! Settings::save_reporting_year( (string) ( Settings::max_year() + 1 ) ) && ! Settings::save_reporting_year( 'abcd' ) && ! Settings::save_reporting_year( '20255' ) && ! Settings::save_reporting_year( [ 2025 ] );
	assert_test( 'years outside 2000 to next year and non-years are refused and change nothing', $refused && 2025 === Settings::reporting_year() );
} );

lksg_case( 'Settings::register exposes wm_lksg_delete_data_on_uninstall with default 0 and opt-in toggle', function () {
	$GLOBALS['lksg_registered_settings'] = [];
	Settings::register();
	$reg = $GLOBALS['lksg_registered_settings']['wm_lksg_delete_data_on_uninstall'] ?? null;
	$default_off = Settings::delete_data_on_uninstall();
	Settings::save_delete_data_on_uninstall( true );
	$turned_on = Settings::delete_data_on_uninstall();
	Settings::save_delete_data_on_uninstall( false );
	$turned_off = Settings::delete_data_on_uninstall();
	assert_test(
		'Settings::register exposes wm_lksg_delete_data_on_uninstall with default 0 and opt-in toggle',
		is_array( $reg ) && '0' === ( $reg['args']['default'] ?? '' ) && false === $default_off && true === $turned_on && false === $turned_off,
		var_export( [ $reg, $default_off, $turned_on, $turned_off ], true )
	);
} );

// =====================================================================================================================
echo "\n[GROUP 8] Hub adapter\n";
// =====================================================================================================================

lksg_fresh_db();

lksg_case( 'health is HEALTHY with all tables and a passing self-test, ERROR without a table or with a failing self-test', function () {
	global $wpdb;
	$always_low = static fn( array $f ): array => [ 'score' => 1.0, 'level' => 'low', 'high_risk_flags' => [], 'action_trigger' => 'NO_ACTION' ];
	$healthy    = ( new Lksg_Spoke_Adapter() )->get_health_status();
	$broken     = ( new Lksg_Spoke_Adapter( $always_low ) )->get_health_status();
	$self       = ( new Lksg_Spoke_Adapter( $always_low ) )->execute_action( 'self_test' );
	$wpdb->query( 'DROP TABLE `wp_wm_lksg_complaints`' );
	$missing = ( new Lksg_Spoke_Adapter() )->get_health_status();
	assert_test( 'health is HEALTHY with all tables and a passing self-test, ERROR without a table or with a failing self-test', 'HEALTHY' === $healthy && 'ERROR' === $broken && false === $self['success'] && str_contains( $self['message'], 'fehlgeschlagen' ) && 'ERROR' === $missing, var_export( [ $healthy, $broken, $self, $missing ], true ) );
} );

lksg_fresh_db();

lksg_case( 'risk_eval uses its parameters: severity 0 and 100, DE and BD give different results (was: 56.30 for everything)', function () {
	$ad = new Lksg_Spoke_Adapter();
	$s0 = $ad->execute_action( 'risk_eval', [ 'severity_score' => 0 ] )['result']['score'] ?? null;
	$s1 = $ad->execute_action( 'risk_eval', [ 'severity_score' => 100 ] )['result']['score'] ?? null;
	$de = $ad->execute_action( 'risk_eval', [ 'country_code' => 'DE' ] )['factors']['country_cpi_risk'] ?? null;
	$bd = $ad->execute_action( 'risk_eval', [ 'country_code' => 'BD' ] )['factors']['country_cpi_risk'] ?? null;
	assert_test( 'risk_eval uses its parameters: severity 0 and 100, DE and BD give different results (was: 56.30 for everything)', null !== $s0 && $s0 < $s1 && 15.0 === $de && 85.0 === $bd, var_export( [ $s0, $s1, $de, $bd ], true ) );
} );

lksg_case( 'telemetry counts the register and names no report engine; self-test PASS', function () {
	lksg_supplier( [ 'risk_level' => 'unassessed', 'risk_score' => 0 ] );
	lksg_supplier( [ 'risk_level' => 'critical', 'risk_score' => 90 ] );
	$t = ( new Lksg_Spoke_Adapter() )->get_telemetry_metrics();
	assert_test( 'telemetry counts the register and names no report engine; self-test PASS', 2 === $t['suppliers_registered'] && 1 === $t['suppliers_unassessed'] && 1 === $t['suppliers_high_or_critical'] && 'PASS' === $t['risk_matrix_self_test'] && ! isset( $t['bafa_export_engine'] ) && ! isset( $t['completed_audits'] ) && 0 === $t['missing_tables'], var_export( $t, true ) );
} );

lksg_case( 'settings link opens the settings tab; the SBOM has no compliance or authority properties; unknown actions answer in plain text', function () {
	$ad    = new Lksg_Spoke_Adapter();
	$names = array_column( $ad->get_sbom_data()['properties'], 'name' );
	$msg   = $ad->execute_action( '<b>x</b>' )['message'];
	assert_test(
		'settings link opens the settings tab; the SBOM has no compliance or authority properties; unknown actions answer in plain text',
		str_contains( (string) $ad->get_settings_url(), 'page=wm-lksg-auditor&tab=settings' ) && [] === array_filter( $names, static fn( $n ) => str_starts_with( $n, 'wm:compliance' ) || str_starts_with( $n, 'wm:authority' ) ) && ! str_contains( $ad->get_description(), 'CSDDD' ) && str_contains( $msg, '<b>x</b>' ),
		var_export( [ $names, $msg ], true )
	);
} );

// =====================================================================================================================
echo "\n[GROUP 9] Admin screen and widget\n";
// =====================================================================================================================

lksg_fresh_db();
$admin     = new AdminDashboard( $calculator, $exporter );
$forbidden = [ 'AUDITORÍA', 'DISPONIBLE', 'PREPARADO', 'VINCULADO', 'Conforme', 'CSDDD', 'Risikomanagement aktiv', 'Berichtsbereit', 'requeridas', 'cumple', 'marca en rojo', 'Foxconn', 'Hetzner', 'Umbral', 'high_risk_threshold' ];

lksg_case( 'the widget on an empty register says so and is not green (was: "Risikomanagement aktiv", "Berichtsbereit")', function () use ( $admin ) {
	$empty = lksg_render( [ $admin, 'render_dashboard_widget' ] );
	lksg_supplier( [ 'risk_level' => 'unassessed', 'risk_score' => 0 ] );
	$open = lksg_render( [ $admin, 'render_dashboard_widget' ] );
	assert_test(
		'the widget on an empty register says so and is not green (was: "Risikomanagement aktiv", "Berichtsbereit")',
		str_contains( $empty, 'Noch keine Lieferanten im Register' ) && ! str_contains( $empty, 'is-valid' ) && ! str_contains( $empty, 'Berichtsbereit' ) && str_contains( $open, 'is-warning' ) && str_contains( $open, '1 Selbstauskunft nicht bewertet' ),
		$empty . "\n----\n" . $open
	);
} );

lksg_fresh_db();
for ( $n = 0; $n < 56; $n++ ) {
	lksg_supplier( [ 'supplier_name' => sprintf( 'Scored %02d', $n ), 'risk_score' => 30 + $n, 'risk_level' => 30 + $n >= 50 ? 'high' : 'medium' ] );
}
lksg_supplier( [ 'supplier_name' => 'SELF-DISCLOSED BD', 'risk_level' => 'unassessed', 'risk_score' => 0, 'audit_status' => 'self_disclosed' ] );

lksg_case( 'the overview counts all 57 suppliers, lists 50 per page with the self-disclosure first and pages the rest (was: 50 as "evaluated")', function () use ( $admin ) {
	$_GET  = [ 'tab' => 'overview' ];
	$html  = lksg_render( [ $admin, 'render_dashboard_page' ] );
	$_GET  = [];
	preg_match( '#<tbody>(.*?)</tbody>#s', $html, $m );
	$rows  = substr_count( $m[1] ?? '', '<tr>' );
	$first = strpos( $html, 'SELF-DISCLOSED BD' );
	$other = strpos( $html, 'Scored 55' );
	assert_test(
		'the overview counts all 57 suppliers, lists 50 per page with the self-disclosure first and pages the rest (was: 50 as "evaluated")',
		str_contains( $html, '>57<' ) && str_contains( $html, '>56<' ) && 50 === $rows && false !== $first && false !== $other && $first < $other && str_contains( $html, 'Seite 1 von 2' ) && str_contains( $html, 'paged=2' ),
		"rows {$rows}"
	);
} );

lksg_case( 'no tab, notice or widget carries the fixed claims, inline styles or emoji', function () use ( $admin, $forbidden ) {
	$html = lksg_render( [ $admin, 'render_dashboard_widget' ] );
	foreach ( [ 'overview', 'calculator', 'export', 'settings', 'help' ] as $tab ) {
		$_GET  = [ 'tab' => $tab, 'saved' => '1', 'seeded' => '0', 'year_error' => '1' ];
		$html .= lksg_render( [ $admin, 'render_dashboard_page' ] );
	}
	$_GET = [];
	$hits = array_values( array_filter( $forbidden, static fn( $s ) => str_contains( $html, $s ) ) );
	$src  = (string) file_get_contents( WM_LKSG_PATH . 'includes/Admin/AdminDashboard.php' );
	assert_test(
		'no tab, notice or widget carries the fixed claims, inline styles or emoji',
		[] === $hits && ! str_contains( $html, 'style=' ) && 0 === preg_match( '/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}]/u', $html . $src ) && 0 === preg_match( '/#[0-9a-fA-F]{6}\b|#[0-9a-fA-F]{3}\b/', $src ),
		implode( ', ', $hits )
	);
} );

lksg_case( 'an unknown level gets a neutral badge, not the green low badge', function () {
	$unknown = AdminDashboard::level_badge( '' );
	$low     = AdminDashboard::level_badge( 'low' );
	assert_test( 'an unknown level gets a neutral badge, not the green low badge', ! str_contains( $unknown['class'], 'wm-status-ok' ) && 'Unbekannt' === $unknown['label'] && str_contains( $low['class'], 'wm-status-ok' ) && 'wm-status-pill' === AdminDashboard::level_badge( 'unassessed' )['class'] );
} );

lksg_case( 'the export tab shows the register summary and links it with a REST nonce and the year (was: 401)', function () use ( $admin ) {
	update_option( Settings::OPTION, [ 'reporting_year' => 2025 ] );
	$_GET = [ 'tab' => 'export' ];
	$html = lksg_render( [ $admin, 'render_dashboard_page' ] );
	$_GET = [];
	assert_test( 'the export tab shows the register summary and links it with a REST nonce and the year (was: 401)', 1 === preg_match( '#href="[^"]*bafa-report[^"]*year=2025[^"]*_wpnonce=valid-wp_rest#', $html ) && str_contains( $html, 'wm_lksg_register_summary' ) && str_contains( $html, 'Jahresübersicht 2025' ), $html );
} );

lksg_case( 'settings: a posted year is stored and redirects with saved=1; an invalid year redirects with year_error=1; no capability or a forged nonce stops', function () use ( $admin ) {
	$go = static function () use ( $admin ): string {
		try {
			$admin->handle_form_actions();
		} catch ( LksgRedirect $r ) {
			return $r->getMessage();
		} catch ( LksgDie $d ) {
			return 'die:' . $d->getMessage();
		}
		return 'none';
	};
	$_POST = [ 'wm_lksg_action' => 'save_settings', 'wm_lksg_nonce' => 'valid-wm_lksg_save_settings', 'reporting_year' => '2024' ];
	$ok    = $go();
	$year  = Settings::reporting_year();
	$_POST['reporting_year'] = '1850';
	$bad = $go();
	$GLOBALS['lksg_caps'] = [];
	$denied = $go();
	$GLOBALS['lksg_caps']    = [ 'manage_options' => true ];
	$_POST['wm_lksg_nonce']  = 'forged';
	$_POST['reporting_year'] = '2023';
	$forged = $go();
	$_POST  = [];
	assert_test( 'settings: a posted year is stored and redirects with saved=1; an invalid year redirects with year_error=1; no capability or a forged nonce stops', str_contains( $ok, 'saved=1' ) && 2024 === $year && str_contains( $bad, 'year_error=1' ) && str_starts_with( $denied, 'die:' ) && str_starts_with( $forged, 'die:' ) && 2024 === Settings::reporting_year(), var_export( [ $ok, $bad, $denied, $forged ], true ) );
} );

lksg_case( 'the settings tab has only the year; no threshold field', function () use ( $admin ) {
	$_GET = [ 'tab' => 'settings' ];
	$html = lksg_render( [ $admin, 'render_dashboard_page' ] );
	$_GET = [];
	assert_test( 'the settings tab has only the year; no threshold field', str_contains( $html, 'name="reporting_year"' ) && str_contains( $html, 'max="' . Settings::max_year() . '"' ) && ! str_contains( $html, 'threshold' ) && 1 === substr_count( $html, '<input type="number"' ) );
} );

lksg_case( 'family sheets load on the plugin page and on the WordPress dashboard, nowhere else', function () use ( $admin ) {
	$GLOBALS['lksg_enqueued'] = [];
	$admin->enqueue_admin_assets( 'index.php' );
	$dash = $GLOBALS['lksg_enqueued'];
	$GLOBALS['lksg_enqueued'] = [];
	$admin->enqueue_admin_assets( 'toplevel_page_wm-lksg-auditor' );
	$page = $GLOBALS['lksg_enqueued'];
	$GLOBALS['lksg_enqueued'] = [];
	$admin->enqueue_admin_assets( 'edit.php' );
	assert_test( 'family sheets load on the plugin page and on the WordPress dashboard, nowhere else', [ 'wm-admin-tokens', 'wm-admin-ui' ] === $dash && [ 'wm-admin-tokens', 'wm-admin-ui' ] === $page && [] === $GLOBALS['lksg_enqueued'] );
} );

lksg_fresh_db();

lksg_case( 'demo suppliers: made-up names, levels and scores from the calculator, logged as demo_seed, added once', function () use ( $admin, $calculator ) {
	global $wpdb;
	$first  = $admin->seed_demo_data();
	$second = $admin->seed_demo_data();
	$rows   = $wpdb->get_results( 'SELECT s.supplier_name, s.risk_level, s.risk_score, a.factors, a.risk_category_id FROM `wp_wm_lksg_suppliers` s JOIN `wp_wm_lksg_risk_assessments` a ON a.supplier_id = s.id', ARRAY_A );
	$ok     = 4 === $first && 0 === $second && 4 === count( $rows ) && 4 === lksg_count( 'suppliers' );
	$levels = [];
	foreach ( $rows as $r ) {
		$again    = $calculator->calculate_risk( (array) json_decode( (string) $r['factors'], true ) );
		$levels[] = $r['risk_level'];
		$ok       = $ok && str_starts_with( $r['supplier_name'], 'Beispiel: ' ) && $again['level'] === $r['risk_level'] && abs( $again['score'] - (float) $r['risk_score'] ) < 0.01 && 'demo_seed' === $r['risk_category_id'];
	}
	sort( $levels );
	assert_test( 'demo suppliers: made-up names, levels and scores from the calculator, logged as demo_seed, added once', $ok && [ 'critical', 'high', 'low', 'medium' ] === $levels, var_export( [ $first, $second, $rows ], true ) );
} );

lksg_fresh_db();

lksg_case( 'calculator: saves once per form token; a reload stores nothing; the tier comes from the form; no token saves nothing', function () use ( $admin ) {
	$token = str_repeat( 'ab', 16 );
	$_POST = [ 'wm_lksg_calc_nonce' => 'valid-wm_lksg_calc', 'wm_calc_name' => 'Rechner GmbH', 'wm_calc_country' => 'IN', 'wm_calc_sector' => 'agriculture', 'wm_calc_tier' => '2', 'wm_calc_severity' => '60', 'wm_calc_probability' => '50', 'wm_calc_dependency' => '10', 'wm_calc_causality' => '10', 'save_to_register' => '1', 'wm_calc_token' => $token ];
	$one   = $admin->calculate();
	$two   = $admin->calculate();
	$count = lksg_count( 'suppliers' );
	$tier  = lksg_count( 'suppliers', "tier_level = 2 AND supplier_name = 'Rechner GmbH'" );
	unset( $_POST['wm_calc_token'] );
	$three = $admin->calculate();
	$_POST = [];
	assert_test( 'calculator: saves once per form token; a reload stores nothing; the tier comes from the form; no token saves nothing', true === $one['saved'] && true === $two['duplicate'] && false === $two['saved'] && 1 === $count && 1 === $tier && true === $three['failed'] && 1 === lksg_count( 'suppliers' ), var_export( [ $one['saved'], $two, $three['failed'] ], true ) );
} );

lksg_case( 'calculator: an invalid country or a forged nonce is an error; a refused history row saves nothing', function () use ( $admin ) {
	global $wpdb;
	$base  = [ 'wm_lksg_calc_nonce' => 'valid-wm_lksg_calc', 'wm_calc_name' => 'X', 'wm_calc_country' => 'XX', 'wm_calc_tier' => '1', 'save_to_register' => '1', 'wm_calc_token' => str_repeat( 'cd', 16 ) ];
	$_POST = $base;
	$bad   = $admin->calculate();
	$_POST = [ 'wm_lksg_calc_nonce' => 'forged' ] + $base;
	$nonce = $admin->calculate();
	$_POST = [ 'wm_calc_country' => 'DE', 'wm_calc_token' => str_repeat( 'ef', 16 ) ] + $base;
	$wpdb->fail_insert_into['wp_wm_lksg_risk_assessments'] = 1;
	$log   = $admin->calculate();
	$_POST = [];
	assert_test( 'calculator: an invalid country or a forged nonce is an error; a refused history row saves nothing', null !== $bad['error'] && null !== $nonce['error'] && true === $log['failed'] && 1 === lksg_count( 'suppliers' ), var_export( [ $bad['error'], $nonce['error'], $log['failed'], lksg_count( 'suppliers' ) ], true ) );
} );

lksg_case( 'the calculator result explains the level without legal duties; the form carries a token', function () use ( $admin ) {
	$_GET  = [ 'tab' => 'calculator' ];
	$_POST = [ 'wm_lksg_calc_nonce' => 'valid-wm_lksg_calc', 'wm_calc_name' => 'Low GmbH', 'wm_calc_country' => 'DE', 'wm_calc_sector' => 'it_software', 'wm_calc_tier' => '1', 'wm_calc_severity' => '5', 'wm_calc_probability' => '5', 'wm_calc_dependency' => '5', 'wm_calc_causality' => '5' ];
	$html  = lksg_render( [ $admin, 'render_dashboard_page' ] );
	$_GET  = [];
	$_POST = [];
	assert_test( 'the calculator result explains the level without legal duties; the form carries a token', str_contains( $html, 'belegt nicht, dass keine Risiken bestehen' ) && 1 === preg_match( '/name="wm_calc_token" value="[a-f0-9]{32}"/', $html ) && ! str_contains( $html, 'garantías' ) && ! str_contains( $html, 'obligatoria' ) );
} );

// =====================================================================================================================
echo "\n[GROUP 10] WP-CLI\n";
// =====================================================================================================================

lksg_fresh_db();

lksg_case( 'wp lksg score without --country scores the country level 50 and says so (was: DE, 15)', function () {
	$r = LksgCommand::score_report( 'Acme', [] );
	assert_test( 'wp lksg score without --country scores the country level 50 and says so (was: DE, 15)', null === $r['error'] && str_contains( implode( "\n", $r['lines'] ), 'country not given' ) && str_contains( implode( "\n", $r['lines'] ), 'country 50.0' ), implode( "\n", $r['lines'] ) );
} );

lksg_case( 'wp lksg score --country=XX is an error; --country=bd uses the BD tier', function () {
	$bad = LksgCommand::score_report( 'Acme', [ 'country' => 'XX' ] );
	$bd  = LksgCommand::score_report( 'Acme', [ 'country' => 'bd' ] );
	assert_test( 'wp lksg score --country=XX is an error; --country=bd uses the BD tier', null !== $bad['error'] && str_contains( implode( "\n", $bd['lines'] ), 'country 85.0 (tier of BD)' ), var_export( [ $bad, $bd['lines'] ], true ) );
} );

lksg_case( 'wp lksg export-bafa --year=2025 prints the 2025 summary; --year=abc is an error', function () {
	WP_CLI::$out = [];
	( new LksgCommand() )->export_bafa( [], [ 'year' => '2025' ] );
	$json = json_decode( (string) ( WP_CLI::$out[0][1] ?? '' ), true );
	$err  = '';
	try {
		( new LksgCommand() )->export_bafa( [], [ 'year' => 'abc' ] );
	} catch ( LksgCliError $e ) {
		$err = $e->getMessage();
	}
	assert_test( 'wp lksg export-bafa --year=2025 prints the 2025 summary; --year=abc is an error', 2025 === ( $json['reporting_year'] ?? 0 ) && str_contains( WP_CLI::$out[1][1] ?? '', 'not a report' ) && str_contains( $err, '--year' ), var_export( WP_CLI::$out, true ) );
} );

// =====================================================================================================================
echo "\n[GROUP 11] Self-disclosure portal\n";
// =====================================================================================================================

lksg_case( 'two portals on a page get their own IDs, no inline script or style, a required declaration checkbox and the assets', function () use ( $calculator ) {
	$GLOBALS['lksg_enqueued'] = [];
	$portal = new SupplierPortal( $calculator );
	$html   = $portal->render_portal() . $portal->render_portal();
	preg_match_all( '/id="(wm-lksg-portal-\d+)-company"/', $html, $ids );
	assert_test(
		'two portals on a page get their own IDs, no inline script or style, a required declaration checkbox and the assets',
		2 === count( array_unique( $ids[1] ) ) && 2 === substr_count( $html, 'data-wm-lksg-portal' ) && ! str_contains( $html, '<script' ) && ! str_contains( $html, 'style=' )
		&& 2 === preg_match_all( '/name="declaration_accepted" value="1" required/', $html ) && in_array( 'wm-lksg-portal', $GLOBALS['lksg_enqueued'], true )
		&& 0 === preg_match( '/Konformität|CSDDD|rechtsverbindlich|BSCI/u', $html ),
		$html
	);
} );

lksg_case( 'the portal script binds each form once, sends the checkbox state and uses no fixed IDs', function () {
	$js = (string) file_get_contents( WM_LKSG_PATH . 'assets/js/wm-lksg-portal.js' );
	assert_test(
		'the portal script binds each form once, sends the checkbox state and uses no fixed IDs',
		! str_contains( $js, 'getElementById' ) && str_contains( $js, "querySelectorAll( 'form[data-wm-lksg-portal]' )" ) && str_contains( $js, "declaration_accepted: !! field( 'declaration_accepted' ).checked" ) && str_contains( $js, 'wmLksgBound' ) && str_contains( $js, 'answer.status !== 201' )
	);
} );

lksg_case( 'the portal sheet keeps colours and dimensions on custom-property lines', function () {
	$hits    = [];
	$comment = false;
	foreach ( preg_split( '/\R/', (string) file_get_contents( WM_LKSG_PATH . 'assets/css/wm-lksg-portal.css' ) ) as $i => $line ) {
		if ( $comment || str_starts_with( trim( $line ), '/*' ) ) {
			$comment = ! str_contains( $line, '*/' );
			continue;
		}
		if ( preg_match( '/^\s*--[\w-]+\s*:/', $line ) ) {
			continue;
		}
		if ( preg_match( '/#[0-9a-fA-F]{3,8}\b|\b\d+(\.\d+)?(px|rem|em)\b/', $line ) ) {
			$hits[] = ( $i + 1 ) . ': ' . trim( $line );
		}
	}
	assert_test( 'the portal sheet keeps colours and dimensions on custom-property lines', [] === $hits, implode( ' | ', $hits ) );
} );

lksg_case( 'every portal sector has a profile and the route accepts exactly those', function () {
	$ok = array_keys( SupplierPortal::sector_labels() ) === SupplierPortal::SECTOR_KEYS;
	foreach ( SupplierPortal::SECTOR_KEYS as $key ) {
		$ok = $ok && null !== RiskFactors::sector_profile( $key );
	}
	assert_test( 'every portal sector has a profile and the route accepts exactly those', $ok );
} );

lksg_case( 'Gutenberg block wm/lksg-supplier-portal has block.json with editorScript, compiled build/index.js + asset.php, and supports block attributes', function () use ( $calculator ) {
	$GLOBALS['lksg_registered_scripts']  = [];
	$GLOBALS['lksg_script_translations'] = [];
	$GLOBALS['lksg_blocks']              = [];
	$portal = new SupplierPortal( $calculator );
	$portal->register_block();

	$block_json_path = WM_LKSG_PATH . 'blocks/supplier-portal/block.json';
	$block_meta      = is_file( $block_json_path ) ? json_decode( (string) file_get_contents( $block_json_path ), true ) : null;
	$asset_path      = WM_LKSG_PATH . 'build/index.asset.php';
	$build_js_path   = WM_LKSG_PATH . 'build/index.js';
	$html_custom     = $portal->render_portal( [ 'showIntro' => false, 'defaultTier' => 2 ] );

	assert_test(
		'Gutenberg block wm/lksg-supplier-portal has block.json with editorScript, compiled build/index.js + asset.php, and supports block attributes',
		is_array( $block_meta )
		&& 'wm/lksg-supplier-portal' === ( $block_meta['name'] ?? '' )
		&& 'wm-lksg-blocks-editor' === ( $block_meta['editorScript'] ?? '' )
		&& is_file( $asset_path ) && is_file( $build_js_path )
		&& isset( $GLOBALS['lksg_registered_scripts']['wm-lksg-blocks-editor'] )
		&& 'wm-lksg-supply-auditor' === ( $GLOBALS['lksg_script_translations']['wm-lksg-blocks-editor']['domain'] ?? '' )
		&& isset( $GLOBALS['lksg_blocks'][ WM_LKSG_PATH . 'blocks/supplier-portal' ] )
		&& str_contains( $html_custom, '<option value="2" selected="selected">' )
		&& 1 === substr_count( $html_custom, 'wm-lksg-portal-intro' ),
		var_export( [ $block_meta, array_keys( $GLOBALS['lksg_registered_scripts'] ), array_keys( $GLOBALS['lksg_blocks'] ) ], true )
	);
} );

lksg_case( 'Ecosystem extensibility: wm_lksg_ready action, wm_lksg_risk_score filter, WP 6.5+ Block Bindings wm-lksg/metrics and GenerateBlocks {{wm_lksg_metric}}', function () use ( $calculator ) {
	lksg_fresh_db();
	update_option( Schema::VERSION_OPTION, Schema::VERSION );
	lksg_supplier( [ 'risk_level' => 'unassessed', 'risk_score' => 0.0 ] );
	lksg_supplier( [ 'risk_level' => 'critical', 'risk_score' => 88.0 ] );

	$GLOBALS['lksg_fired_actions']  = [];
	$GLOBALS['lksg_block_bindings'] = [];
	$GLOBALS['lksg_gb_tags']        = [];
	$GLOBALS['lksg_filters']        = [];

	Plugin::instance()->boot();
	$eco = new EcosystemIntegration();
	$eco->register_block_bindings();
	$eco->register_generateblocks_tags();

	$binding = $GLOBALS['lksg_block_bindings']['wm-lksg/metrics'] ?? null;
	$gb_tag  = $GLOBALS['lksg_gb_tags']['wm_lksg_metric'] ?? null;

	$GLOBALS['lksg_filters']['wm_lksg_risk_score'] = [
		static fn( array $res ): array => array_merge( $res, [ 'score' => 91.25 ] ),
	];
	$filtered_risk = $calculator->calculate_risk( RiskFactors::from_input( [] ) );
	$GLOBALS['lksg_filters'] = [];

	assert_test(
		'Ecosystem extensibility: wm_lksg_ready action, wm_lksg_risk_score filter, WP 6.5+ Block Bindings wm-lksg/metrics and GenerateBlocks {{wm_lksg_metric}}',
		! empty( $GLOBALS['lksg_fired_actions']['wm_lksg_ready'] )
		&& is_array( $binding ) && '2' === $eco->get_bound_metric_value( [ 'key' => 'total' ] )
		&& '1' === $eco->get_bound_metric_value( [ 'key' => 'unassessed' ] )
		&& '1' === $eco->get_bound_metric_value( [ 'key' => 'high_or_critical' ] )
		&& is_array( $gb_tag ) && '2' === $eco->render_generateblocks_tag( [ 'key' => 'total' ] )
		&& 91.25 === $filtered_risk['score'],
		var_export( [ array_keys( $GLOBALS['lksg_fired_actions'] ), $binding, $gb_tag, $filtered_risk ], true )
	);
} );

// =====================================================================================================================
echo "\n[GROUP 12] Uninstall\n";
// =====================================================================================================================

lksg_fresh_db();

lksg_case( 'D-UNINSTALL: uninstall preserves all five tables, options and transients by default when wm_lksg_delete_data_on_uninstall is off', function () {
	global $wpdb;
	update_option( 'wm_lksg_settings', [ 'reporting_year' => 2025 ] );
	update_option( 'wm_lksg_schema_version', '3' );
	set_transient( 'wm_lksg_sd_abc', [ 'count' => 1, 'started' => time() ], 60 );
	if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
		define( 'WP_UNINSTALL_PLUGIN', 'wm-lksg-supply-auditor/wm-lksg-supply-auditor.php' );
	}
	( static function (): void {
		include WM_LKSG_PATH . 'uninstall.php';
	} )();
	$left = (int) $wpdb->get_var( "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name LIKE 'wp\\_wm\\_lksg\\_%'" );
	assert_test(
		'D-UNINSTALL: uninstall preserves all five tables, options and transients by default when wm_lksg_delete_data_on_uninstall is off',
		5 === $left && is_array( get_option( 'wm_lksg_settings' ) ) && '3' === get_option( 'wm_lksg_schema_version' ) && false !== get_transient( 'wm_lksg_sd_abc' ),
		"tables left {$left}"
	);
} );

lksg_case( 'uninstall drops all five tables, plugin options and transients when wm_lksg_delete_data_on_uninstall is opted in, and nothing else', function () {
	global $wpdb;
	update_option( 'wm_lksg_delete_data_on_uninstall', '1' );
	update_option( 'wm_lksg_settings', [ 'reporting_year' => 2025 ] );
	update_option( 'wm_lksg_schema_version', '3' );
	set_transient( 'wm_lksg_sd_abc', [ 'count' => 1, 'started' => time() ], 60 );
	set_transient( 'wm_lksg_calc_def', 1, 60 );
	set_transient( 'wmxlksg_other', 1, 60 );
	update_option( 'blogname', 'Demo' );
	if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
		define( 'WP_UNINSTALL_PLUGIN', 'wm-lksg-supply-auditor/wm-lksg-supply-auditor.php' );
	}
	( static function (): void {
		include WM_LKSG_PATH . 'uninstall.php';
	} )();
	$left = (int) $wpdb->get_var( "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name LIKE 'wp\\_wm\\_lksg\\_%'" );
	assert_test(
		'uninstall drops all five tables, plugin options and transients when wm_lksg_delete_data_on_uninstall is opted in, and nothing else',
		0 === $left && false === get_option( 'wm_lksg_delete_data_on_uninstall' ) && false === get_option( 'wm_lksg_settings' ) && false === get_option( 'wm_lksg_schema_version' ) && false === get_transient( 'wm_lksg_sd_abc' ) && false === get_option( '_transient_timeout_wm_lksg_calc_def' ) && 1 === (int) get_transient( 'wmxlksg_other' ) && 'Demo' === get_option( 'blogname' ),
		"tables left {$left}"
	);
} );

// =====================================================================================================================
echo "\n[GROUP 13] Schema\n";
// =====================================================================================================================

lksg_case( 'Schema.php has the ABSPATH guard; version 3; a supplier without a level defaults to unassessed', function () {
	$src = (string) file_get_contents( WM_LKSG_PATH . 'includes/Database/Schema.php' );
	assert_test( 'Schema.php has the ABSPATH guard; version 3; a supplier without a level defaults to unassessed', 1 === preg_match( "/if \\( ! defined\\( 'ABSPATH' \\) \\) \\{\\s*exit;\\s*\\}\\s*final class Schema/", $src ) && '3' === Schema::VERSION && str_contains( Schema::suppliers_sql( 't', '', true ), "risk_level varchar(16) NOT NULL DEFAULT 'unassessed'" ) );
} );

lksg_case( 'an existing audits table gets an ALTER that makes the four score columns nullable; a current one gets none', function () {
	$db      = lksg_fresh_db();
	$current = Schema::make_audit_scores_nullable( 'wp_wm_lksg_audits' );
	$ddl_now = count( $db->ddl );
	$db->run_raw( 'CREATE TABLE wp_old_audits (id INTEGER PRIMARY KEY, human_rights_score decimal(5,2) NOT NULL DEFAULT 0.00, environmental_score decimal(5,2) NOT NULL DEFAULT 0.00, child_labor_risk tinyint(1) NOT NULL DEFAULT 0, forced_labor_risk tinyint(1) NOT NULL DEFAULT 0)' );
	$old = Schema::make_audit_scores_nullable( 'wp_old_audits' );
	$sql = implode( "\n", $db->ddl );
	assert_test(
		'an existing audits table gets an ALTER that makes the four score columns nullable; a current one gets none',
		[] === $current && 0 === $ddl_now && 4 === count( $old ) && 4 === count( $db->ddl ) && str_contains( $sql, 'MODIFY human_rights_score decimal(5,2) NULL DEFAULT NULL' ) && str_contains( $sql, 'MODIFY forced_labor_risk tinyint(1) NULL DEFAULT NULL' ) && str_contains( $sql, '`wp_old_audits`' ),
		var_export( [ $current, $old, $sql ], true )
	);
} );

lksg_case( 'backfill_uuids gives rows without a uuid a distinct v4 uuid and leaves the rest', function () use ( $uuid_v4 ) {
	$db = lksg_fresh_db();
	$db->run_raw( "CREATE TABLE wp_legacy (id INTEGER PRIMARY KEY, uuid TEXT NOT NULL DEFAULT '')" );
	$db->run_raw( "INSERT INTO wp_legacy (id, uuid) VALUES (1, ''), (2, ''), (3, '11111111-2222-4333-8444-555555555555')" );
	$done = Schema::backfill_uuids( 'wp_legacy', 'uuid' );
	$col  = $db->get_col( 'SELECT uuid FROM wp_legacy ORDER BY id' );
	assert_test( 'backfill_uuids gives rows without a uuid a distinct v4 uuid and leaves the rest', 2 === $done && 1 === preg_match( $uuid_v4, $col[0] ) && 1 === preg_match( $uuid_v4, $col[1] ) && $col[0] !== $col[1] && '11111111-2222-4333-8444-555555555555' === $col[2] );
} );

lksg_case( 'missing_tables names a dropped table', function () {
	$db = lksg_fresh_db();
	$db->query( 'DROP TABLE `wp_wm_lksg_corrective_actions`' );
	assert_test( 'missing_tables names a dropped table', [ 'corrective_actions' ] === Schema::missing_tables() );
} );

lksg_case( 'wp_initialize_site provisions all five tables and schema option on the new blog when network-activated and restores current blog', function () {
	$db                                = new Lksg_Test_WPDB();
	$GLOBALS['wpdb']                   = $db;
	$GLOBALS['lksg_is_multisite']      = true;
	$GLOBALS['lksg_network_active']    = [ plugin_basename( WM_LKSG_FILE ) => true ];
	$GLOBALS['lksg_current_blog_id']   = 1;
	$GLOBALS['lksg_blog_stack']        = [];
	$GLOBALS['lksg_switched_blogs']    = [];
	$GLOBALS['lksg_restored_blogs']    = 0;

	$callbacks = $GLOBALS['lksg_actions']['wp_initialize_site'] ?? [];
	foreach ( $callbacks as $cb ) {
		$cb( (object) [ 'blog_id' => 2 ] );
	}

	$switched_during_hook = $GLOBALS['lksg_switched_blogs'];
	$restored_during_hook = $GLOBALS['lksg_restored_blogs'];
	$blog_id_after_hook   = $GLOBALS['lksg_current_blog_id'];
	$prefix_after_hook    = $db->prefix;
	$stack_after_hook     = $GLOBALS['lksg_blog_stack'];
	$blog1_missing        = Schema::missing_tables();

	switch_to_blog( 2 );
	try {
		$blog2_missing  = Schema::missing_tables();
		$blog2_version  = get_option( Schema::VERSION_OPTION );
		$blog2_autoload = $db->option_autoload( Schema::VERSION_OPTION );
	} finally {
		restore_current_blog();
	}

	$GLOBALS['lksg_is_multisite']   = false;
	$GLOBALS['lksg_network_active'] = [];

	assert_test(
		'wp_initialize_site provisions all five tables and schema option on the new blog when network-activated and restores current blog',
		[] !== $callbacks
		&& [ 2 ] === $switched_during_hook
		&& 1 === $restored_during_hook
		&& 1 === $blog_id_after_hook
		&& 'wp_' === $prefix_after_hook
		&& [] === $stack_after_hook
		&& [] === $blog2_missing
		&& '3' === $blog2_version
		&& 'no' === $blog2_autoload
		&& Schema::TABLES === $blog1_missing,
		var_export( [ $switched_during_hook, $restored_during_hook, $blog2_missing, $blog2_version, $blog2_autoload, $blog1_missing ], true )
	);
} );

lksg_case( 'wp_initialize_site does nothing when not network-activated or outside multisite', function () {
	$db                              = new Lksg_Test_WPDB();
	$GLOBALS['wpdb']                 = $db;
	$GLOBALS['lksg_current_blog_id'] = 1;
	$GLOBALS['lksg_blog_stack']      = [];
	$GLOBALS['lksg_switched_blogs']  = [];
	$GLOBALS['lksg_restored_blogs']  = 0;

	$callbacks = $GLOBALS['lksg_actions']['wp_initialize_site'] ?? [];

	$GLOBALS['lksg_is_multisite']   = true;
	$GLOBALS['lksg_network_active'] = [];
	foreach ( $callbacks as $cb ) {
		$cb( (object) [ 'blog_id' => 3 ] );
	}

	$GLOBALS['lksg_is_multisite']   = false;
	$GLOBALS['lksg_network_active'] = [ plugin_basename( WM_LKSG_FILE ) => true ];
	foreach ( $callbacks as $cb ) {
		$cb( (object) [ 'blog_id' => 4 ] );
	}

	$GLOBALS['lksg_is_multisite']   = false;
	$GLOBALS['lksg_network_active'] = [];

	$blog3_tables = (int) $db->get_var( "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name LIKE 'wp\\_3\\_%'" );
	$blog4_tables = (int) $db->get_var( "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name LIKE 'wp\\_4\\_%'" );

	assert_test(
		'wp_initialize_site does nothing when not network-activated or outside multisite',
		[] !== $callbacks && [] === $GLOBALS['lksg_switched_blogs'] && 0 === $blog3_tables && 0 === $blog4_tables,
		var_export( [ $GLOBALS['lksg_switched_blogs'], $blog3_tables, $blog4_tables ], true )
	);
} );

lksg_case( 'wp_initialize_site restores current blog inside finally even when Schema::activate throws', function () {
	$db                                   = new Lksg_Test_WPDB();
	$GLOBALS['wpdb']                      = $db;
	$GLOBALS['lksg_is_multisite']         = true;
	$GLOBALS['lksg_network_active']       = [ plugin_basename( WM_LKSG_FILE ) => true ];
	$GLOBALS['lksg_current_blog_id']      = 1;
	$GLOBALS['lksg_blog_stack']           = [];
	$GLOBALS['lksg_switched_blogs']       = [];
	$GLOBALS['lksg_restored_blogs']       = 0;
	$GLOBALS['lksg_dbdelta_should_throw'] = true;

	$caught = '';
	try {
		foreach ( $GLOBALS['lksg_actions']['wp_initialize_site'] ?? [] as $cb ) {
			$cb( (object) [ 'blog_id' => 5 ] );
		}
	} catch ( RuntimeException $e ) {
		$caught = $e->getMessage();
	} finally {
		$GLOBALS['lksg_dbdelta_should_throw'] = false;
		$GLOBALS['lksg_is_multisite']         = false;
		$GLOBALS['lksg_network_active']       = [];
	}

	assert_test(
		'wp_initialize_site restores current blog inside finally even when Schema::activate throws',
		'' !== $caught
		&& [ 5 ] === $GLOBALS['lksg_switched_blogs']
		&& 1 === $GLOBALS['lksg_restored_blogs']
		&& 1 === $GLOBALS['lksg_current_blog_id']
		&& 'wp_' === $db->prefix
		&& [] === $GLOBALS['lksg_blog_stack'],
		var_export( [ $caught, $GLOBALS['lksg_switched_blogs'], $GLOBALS['lksg_restored_blogs'], $db->prefix ], true )
	);
} );

lksg_case( 'network-wide activation installs tables across all sites while single-site activation touches only current blog', function () {
	$db                              = new Lksg_Test_WPDB();
	$GLOBALS['wpdb']                 = $db;
	$GLOBALS['lksg_is_multisite']    = true;
	$GLOBALS['lksg_sites']           = [ 1, 6 ];
	$GLOBALS['lksg_current_blog_id'] = 1;
	$GLOBALS['lksg_blog_stack']      = [];
	$GLOBALS['lksg_switched_blogs']  = [];
	$GLOBALS['lksg_restored_blogs']  = 0;

	$hook = $GLOBALS['lksg_activation_hooks'][ plugin_basename( WM_LKSG_FILE ) ] ?? null;
	if ( is_callable( $hook ) ) {
		$hook( true );
	}

	$blog1_missing = Schema::missing_tables();
	switch_to_blog( 6 );
	try {
		$blog6_missing = Schema::missing_tables();
		$blog6_version = get_option( Schema::VERSION_OPTION );
	} finally {
		restore_current_blog();
	}

	$db2                             = new Lksg_Test_WPDB();
	$GLOBALS['wpdb']                 = $db2;
	$GLOBALS['lksg_sites']           = [ 1, 7 ];
	$GLOBALS['lksg_switched_blogs']  = [];
	$GLOBALS['lksg_restored_blogs']  = 0;
	if ( is_callable( $hook ) ) {
		$hook( false );
	}
	$single_blog1_missing = Schema::missing_tables();
	$blog7_tables         = (int) $db2->get_var( "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name LIKE 'wp\\_7\\_%'" );

	$GLOBALS['lksg_is_multisite'] = false;
	$GLOBALS['lksg_sites']        = [ 1 ];

	assert_test(
		'network-wide activation installs tables across all sites while single-site activation touches only current blog',
		is_callable( $hook )
		&& [] === $blog1_missing
		&& [] === $blog6_missing
		&& '3' === $blog6_version
		&& 'wp_' === $db->prefix
		&& [] === $single_blog1_missing
		&& 0 === $blog7_tables,
		var_export( [ $blog1_missing, $blog6_missing, $blog6_version, $single_blog1_missing, $blog7_tables ], true )
	);
} );

lksg_case( 'Schema::activate and Settings store options with autoload false and guard dbDelta and plugin.php requires', function () {
	$db                              = new Lksg_Test_WPDB();
	$GLOBALS['wpdb']                 = $db;
	$GLOBALS['lksg_is_multisite']    = false;
	$GLOBALS['lksg_current_blog_id'] = 1;
	$GLOBALS['lksg_blog_stack']      = [];

	Schema::activate();
	Settings::save_reporting_year( '2025' );
	Settings::save_delete_data_on_uninstall( true );

	$schema_src = (string) file_get_contents( WM_LKSG_PATH . 'includes/Database/Schema.php' );
	$main_src   = (string) file_get_contents( WM_LKSG_FILE );

	assert_test(
		'Schema::activate and Settings store options with autoload false and guard dbDelta and plugin.php requires',
		'no' === $db->option_autoload( Schema::VERSION_OPTION )
		&& 'no' === $db->option_autoload( Settings::OPTION )
		&& 'no' === $db->option_autoload( Settings::DELETE_ON_UNINSTALL_OPTION )
		&& str_contains( $schema_src, "if ( ! function_exists( 'dbDelta' ) )" )
		&& str_contains( $main_src, "if ( ! function_exists( 'is_plugin_active_for_network' ) )" ),
		var_export( [
			$db->option_autoload( Schema::VERSION_OPTION ),
			$db->option_autoload( Settings::OPTION ),
			$db->option_autoload( Settings::DELETE_ON_UNINSTALL_OPTION ),
		], true )
	);
} );

// =====================================================================================================================
echo "\n[GROUP 14] Documents, packaging, WordPress suites, catalogs\n";
// =====================================================================================================================

lksg_case( 'documents describe the code: no compliance badges, CSDDD, automated BAFA report, conformity, CEO or phone number', function () {
	$files = [ 'README.md', 'README.de.md', 'README.es.md', 'readme.txt', 'DEVELOPERS.md', 'CLAUDE.md', 'SECURITY.md', 'CONTRIBUTING.md', 'llms.txt', 'public/humans.txt', 'docs/ARCHITECTURE.md', 'docs/LEGAL-COMPLIANCE.md', 'docs/DEVELOPER-GUIDE.md', 'wm-lksg-supply-auditor.php' ];
	$hits  = [];
	foreach ( $files as $file ) {
		$path = WM_LKSG_PATH . $file;
		if ( ! is_file( $path ) ) {
			$hits[] = $file . ' missing';
			continue;
		}
		$text = lksg_doc_text( $path );
		foreach ( [ '/\bcompliant\b/i', '/CSDDD[- ]?Ready/i', '/automated BAFA|BAFA%20Report|BAFA Report-Automated/i', '/rechtsverbindlich/i', '/Konformität/u', '/\bCEO\b|Geschäftsführer/u', '/WCAG 2\.2 AAA/', '/\+49[-\s]?151/', '/CSP Strict/i', '/Multi-Tier/i', '/Audit-Proof|revisionssicher/i', '/Interlinked/i', '/!\[[^\]]*\]\([^)]*lksg-(banner|ui-showcase)\.jpg/' ] as $re ) {
			if ( preg_match( $re, $text, $m ) ) {
				$hits[] = $file . ': ' . $m[0];
			}
		}
	}
	assert_test( 'documents describe the code: no compliance badges, CSDDD, automated BAFA report, conformity, CEO or phone number', [] === $hits, implode( ' | ', $hits ) );
} );

lksg_case( 'the WordPress suites refuse to write without WM_LKSG_WP_TESTS_WRITE=1 and exit non-zero on a failure', function () {
	$ok = true;
	foreach ( [ 'tests/test-self-disclosure.php', 'tests/test-suppliers-rest.php' ] as $file ) {
		$src = (string) file_get_contents( WM_LKSG_PATH . $file );
		$ok  = $ok && str_contains( $src, "getenv( 'WM_LKSG_WP_TESTS_WRITE' )" ) && 1 === preg_match( '/exit\( \$GLOBALS\[\'wm_\w+_failed\'\] > 0 \? 1 : 0 \);/', $src ) && ! str_contains( $src, 'wp_cache_flush' ) && ! str_contains( $src, "LIKE '_transient_wm_lksg_sd_%'" );
	}
	assert_test( 'the WordPress suites refuse to write without WM_LKSG_WP_TESTS_WRITE=1 and exit non-zero on a failure', $ok );
} );

lksg_case( 'the package leaves out the marketing images; CI runs the suite, the mutations and PHPUnit with the strict configuration', function () {
	$dist = (string) file_get_contents( WM_LKSG_PATH . '.distignore' );
	$ci   = (string) file_get_contents( WM_LKSG_PATH . '.github/workflows/ci.yml' );
	assert_test( 'the package leaves out the marketing images; CI runs the suite, the mutations and PHPUnit with the strict configuration', str_contains( $dist, 'assets/images/' ) && str_contains( $ci, 'php tests/test-suite.php' ) && str_contains( $ci, 'bash tests/run-mutations.sh' ) && str_contains( $ci, 'phpunit --configuration phpunit.xml' ) && str_contains( $ci, 'pdo_sqlite' ) );
} );

lksg_case( 'catalogs: no double-encoded UTF-8, the German source strings are in the template, the Spanish ones are not', function () {
	$bad = [];
	foreach ( glob( WM_LKSG_PATH . 'languages/*.{po,pot}', GLOB_BRACE ) ?: [] as $file ) {
		if ( preg_match( '/Ã[\x{0080}-\x{00BF}]|Â§/u', (string) file_get_contents( $file ) ) ) {
			$bad[] = basename( $file );
		}
	}
	$pot = (string) file_get_contents( WM_LKSG_PATH . 'languages/wm-lksg-supply-auditor.pot' );
	assert_test( 'catalogs: no double-encoded UTF-8, the German source strings are in the template, the Spanish ones are not', [] === $bad && str_contains( $pot, 'msgid "Nicht bewertet"' ) && ! str_contains( $pot, 'msgid "PREPARADO"' ), implode( ', ', $bad ) );
} );

// ---------------------------------------------------------------------------------------------------------------------
echo "\n==============================================================================\n";
echo "  TEST SUITE RESULTS: {$GLOBALS['lksg_passed']} PASSED | {$GLOBALS['lksg_failed']} FAILED\n";
echo "==============================================================================\n\n";

exit( 0 === $GLOBALS['lksg_failed'] ? 0 : 1 );
