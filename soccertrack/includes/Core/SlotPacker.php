<?php
/**
 * SlotPacker — distribuye partidos en bloques horarios con capacidad máxima.
 *
 * Sin dependencias de WordPress. Algoritmo puro, testeable en aislamiento.
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SlotPacker {

	/**
	 * Calcula la asignación de fecha/hora para cada partido.
	 *
	 * @param int[]  $match_ids   IDs de partidos a programar, en orden de juego.
	 * @param array  $slots       [['time'=>'19:00','max_matches'=>8], ...] ordenado por time asc.
	 * @param int    $weekday     Día de juego según date('w'): 0=dom, 1=lun, 2=mar … 6=sáb.
	 * @param string $start_from  Fecha mínima YYYY-MM-DD desde la cual programar.
	 * @return array  [['match_id'=>N,'date'=>'YYYY-MM-DD','time'=>'HH:MM:SS','slot_index'=>0], ...]
	 */
	public static function calculate(
		array  $match_ids,
		array  $slots,
		int    $weekday,
		string $start_from,
	): array {
		if ( empty( $match_ids ) || empty( $slots ) ) {
			return [];
		}

		// Ordenar slots por hora ascendente.
		usort( $slots, static fn( array $a, array $b ) => $a['time'] <=> $b['time'] );

		$current_date = self::next_weekday_from( $start_from, $weekday );
		$slot_index   = 0;
		$used_in_slot = 0;
		$result       = [];

		foreach ( $match_ids as $match_id ) {
			// Si agotamos todos los slots del día → avanzar al próximo día disponible.
			if ( $slot_index >= count( $slots ) ) {
				$current_date = self::next_weekday_from(
					wp_date( 'Y-m-d', strtotime( $current_date . ' +7 days' ) ), // phpcs:ignore WordPress.DateTime.RestrictedFunctions
					$weekday
				);
				$slot_index   = 0;
				$used_in_slot = 0;
			}

			$slot = $slots[ $slot_index ];

			$result[] = [
				'match_id'   => (int) $match_id,
				'date'       => $current_date,
				'time'       => self::normalize_time( $slot['time'] ),
				'slot_index' => $slot_index,
			];

			++$used_in_slot;

			if ( $used_in_slot >= (int) $slot['max_matches'] ) {
				++$slot_index;
				$used_in_slot = 0;
			}
		}

		return $result;
	}

	/**
	 * Devuelve la fecha YYYY-MM-DD del próximo $weekday >= $from.
	 *
	 * @param string $from     YYYY-MM-DD
	 * @param int    $weekday  0=dom … 6=sáb (date('w'))
	 */
	private static function next_weekday_from( string $from, int $weekday ): string {
		$ts         = strtotime( $from );
		$current_dw = (int) wp_date( 'w', $ts ); // phpcs:ignore WordPress.DateTime.RestrictedFunctions
		$days_ahead = ( $weekday - $current_dw + 7 ) % 7;
		return wp_date( 'Y-m-d', strtotime( "+{$days_ahead} days", $ts ) ); // phpcs:ignore WordPress.DateTime.RestrictedFunctions
	}

	/**
	 * Normaliza 'HH:MM' o 'HH:MM:SS' a 'HH:MM:SS'.
	 */
	private static function normalize_time( string $time ): string {
		return preg_match( '/^\d{2}:\d{2}$/', $time ) ? $time . ':00' : $time;
	}
}
