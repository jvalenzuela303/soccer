<?php
/**
 * Validador de datos para importación masiva.
 *
 * Valida RUT chileno (formato con guión, con o sin puntos) y dorsales.
 *
 * @package SoccerTrack
 */

declare(strict_types=1);

namespace SportsLeague\Importers;

final class ImportValidator {

	/**
	 * Valida un RUT chileno.
	 * Acepta formatos: 12345678-9 / 12.345.678-9 / 123456789 (sin guión).
	 */
	public function is_valid_rut( string $rut ): bool {
		// Eliminar todo excepto dígitos y K.
		$clean = strtolower( preg_replace( '/[^0-9kK]/', '', $rut ) );

		if ( strlen( $clean ) < 2 ) {
			return false;
		}

		$body = substr( $clean, 0, -1 );
		$dv   = substr( $clean, -1 );

		if ( ! ctype_digit( $body ) ) {
			return false;
		}

		// Calcular dígito verificador.
		$sum  = 0;
		$mult = 2;

		for ( $i = strlen( $body ) - 1; $i >= 0; $i-- ) {
			$sum  += (int) $body[ $i ] * $mult;
			$mult  = $mult === 7 ? 2 : $mult + 1;
		}

		$remainder = 11 - ( $sum % 11 );

		$expected = match ( $remainder ) {
			11      => '0',
			10      => 'k',
			default => (string) $remainder,
		};

		return $dv === $expected;
	}

	/**
	 * Normaliza el RUT al formato sin puntos con guión: "12345678-9".
	 */
	public function normalize_rut( string $rut ): string {
		$clean = strtoupper( preg_replace( '/[^0-9kK]/', '', $rut ) );
		$body  = substr( $clean, 0, -1 );
		$dv    = substr( $clean, -1 );

		return "{$body}-{$dv}";
	}

	/**
	 * Valida que el dorsal esté en rango [1, 99].
	 */
	public function is_valid_dorsal( int $dorsal ): bool {
		return $dorsal >= 1 && $dorsal <= 99;
	}
}
