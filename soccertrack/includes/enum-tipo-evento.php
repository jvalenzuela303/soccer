<?php
/**
 * Enum nativo PHP 8.1 para tipos de evento en un partido.
 *
 * En CalibraTrack (PHP 7.4) esto era un array estático en Helpers.
 * Con PHP 8.2 usamos enum backed string para type-safety real.
 *
 * @package SoccerTrack
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

enum TipoEvento: string {
	case Gol           = 'gol';
	case Asistencia    = 'asistencia';
	case TarjetaAma    = 'tarjeta_amarilla';
	case TarjetaRoja   = 'tarjeta_roja';
	case Sustitucion   = 'sustitucion';
	case PenalFallado  = 'penal_fallado';
	case GolPropio     = 'gol_propio';

	/**
	 * Etiqueta traducible del evento.
	 */
	public function label(): string {
		return match( $this ) {
			self::Gol          => __( 'Gol', 'soccertrack' ),
			self::Asistencia   => __( 'Asistencia', 'soccertrack' ),
			self::TarjetaAma   => __( 'Tarjeta Amarilla', 'soccertrack' ),
			self::TarjetaRoja  => __( 'Tarjeta Roja', 'soccertrack' ),
			self::Sustitucion  => __( 'Sustitución', 'soccertrack' ),
			self::PenalFallado => __( 'Penal Fallado', 'soccertrack' ),
			self::GolPropio    => __( 'Gol en Propia', 'soccertrack' ),
		};
	}

	/**
	 * Devuelve todos los casos como array slug => etiqueta (para selects HTML).
	 *
	 * @return array<string, string>
	 */
	public static function options(): array {
		return array_column(
			array_map(
				static fn( self $case ) => [ $case->value, $case->label() ],
				self::cases()
			),
			1,
			0
		);
	}
}
