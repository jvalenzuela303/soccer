<?php
/**
 * Enum nativo PHP 8.1 para el estado de un partido.
 *
 * @package SoccerTrack
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

enum EstadoPartido: string {
	case Programado  = 'programado';
	case EnCurso     = 'en_curso';
	case Finalizado  = 'finalizado';
	case Suspendido  = 'suspendido';
	case Aplazado    = 'aplazado';

	public function label(): string {
		return match( $this ) {
			self::Programado => __( 'Programado', 'soccertrack' ),
			self::EnCurso    => __( 'En Curso', 'soccertrack' ),
			self::Finalizado => __( 'Finalizado', 'soccertrack' ),
			self::Suspendido => __( 'Suspendido', 'soccertrack' ),
			self::Aplazado   => __( 'Aplazado', 'soccertrack' ),
		};
	}

	/** Indica si el partido puede recibir eventos (goles, tarjetas, etc.). */
	public function acepta_eventos(): bool {
		return $this === self::EnCurso;
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
