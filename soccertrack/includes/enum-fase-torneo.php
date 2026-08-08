<?php
/**
 * Enum nativo PHP 8.1 para las fases de un torneo.
 *
 * @package SoccerTrack
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

enum FaseTorneo: string {
	case FaseGrupos     = 'fase_grupos';
	case Octavos        = 'octavos';
	case Cuartos        = 'cuartos';
	case Semifinal      = 'semifinal';
	case TercerPuesto   = 'tercer_puesto';
	case Final          = 'final';

	public function label(): string {
		return match( $this ) {
			self::FaseGrupos   => __( 'Fase de Grupos', 'soccertrack' ),
			self::Octavos      => __( 'Octavos de Final', 'soccertrack' ),
			self::Cuartos      => __( 'Cuartos de Final', 'soccertrack' ),
			self::Semifinal    => __( 'Semifinal', 'soccertrack' ),
			self::TercerPuesto => __( 'Tercer Puesto', 'soccertrack' ),
			self::Final        => __( 'Final', 'soccertrack' ),
		};
	}

	/** @return array<string, string> */
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
