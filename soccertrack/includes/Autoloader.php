<?php
/**
 * PSR-4 Autoloader para el namespace SportsLeague\.
 *
 * Mapeo: SportsLeague\Core\DatabaseInstaller
 *      → SOCCERTRACK_DIR . 'includes/Core/DatabaseInstaller.php'
 *
 * @package SoccerTrack
 */

declare(strict_types=1);

namespace SportsLeague;

final class Autoloader {

	/**
	 * Registra el autoloader en el stack de spl_autoload.
	 */
	public static function register(): void {
		spl_autoload_register( static function ( string $class ): void {
			if ( ! str_starts_with( $class, 'SportsLeague\\' ) ) {
				return;
			}
			$relative = substr( $class, strlen( 'SportsLeague\\' ) );
			$path     = SOCCERTRACK_DIR . 'includes/' . str_replace( '\\', '/', $relative ) . '.php';

			if ( is_file( $path ) ) {
				require_once $path;
			}
		} );
	}
}
