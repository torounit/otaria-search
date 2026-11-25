<?php
/**
 * The WP-CLI API used by includes/CLI.php and includes/Plugin.php, for PHPStan only.
 *
 * The php-stubs/wp-cli-stubs package cannot be used: 2.12 only allows wordpress-stubs up to 6.x.
 *
 * @package Otaria_Search
 */

namespace {

	class WP_CLI {
		/**
		 * @param string                 $name
		 * @param callable|object|string $callable
		 * @param array<string, mixed>   $args
		 */
		public static function add_command( $name, $callable, $args = array() ): bool {}

		/**
		 * @param string $message
		 */
		public static function log( $message ): void {}

		/**
		 * @param string $message
		 */
		public static function success( $message ): void {}
	}
}

namespace cli\progress {

	class Bar {
		public function tick( int $increment = 1, ?string $msg = null ): void {}

		public function finish(): void {}
	}
}

namespace WP_CLI {

	class NoOp {
		public function tick( int $increment = 1, ?string $msg = null ): void {}

		public function finish(): void {}
	}
}

namespace WP_CLI\Utils {

	/**
	 * @param array<string, mixed> $assoc_args
	 * @param string               $flag
	 * @param mixed                $default
	 * @return mixed
	 */
	function get_flag_value( $assoc_args, $flag, $default = null ) {}

	/**
	 * @param string $message
	 * @param int    $count
	 * @param int    $interval
	 * @return \cli\progress\Bar|\WP_CLI\NoOp
	 */
	function make_progress_bar( $message, $count, $interval = 100 ) {}
}
