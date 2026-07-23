<?php
/**
 * Utilidades compartidas y listas de opciones registradas una sola vez.
 *
 * REGLA: Los arrays de opciones para selects (tipo_equipo, tipo_evento)
 * se definen ÚNICAMENTE aquí. No duplicarlos en formularios ni en validaciones.
 *
 * @package CalibraTrack
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CalibraTrack_Helpers
 */
class CalibraTrack_Helpers {

	/**
	 * Devuelve las opciones válidas para el campo tipo_equipo.
	 *
	 * Usar switch/array en lugar de enum (PHP 7.4 — sin enum 8.1).
	 * Formato: slug => etiqueta traducible.
	 *
	 * @return array<string, string>
	 */
	public static function get_tipos_equipo() {
		return array(
			'otdr'             => __( 'OTDR', 'calibratrack' ),
			'power_meter'      => __( 'Medidor de potencia (Power Meter)', 'calibratrack' ),
			'fuente_luz'       => __( 'Fuente de luz', 'calibratrack' ),
			'empalmadora'      => __( 'Empalmadora de fusión', 'calibratrack' ),
			'certificador_red' => __( 'Certificador de red', 'calibratrack' ),
			'otro'             => __( 'Otro', 'calibratrack' ),
		);
	}

	/**
	 * Devuelve las opciones válidas para el campo tipo_evento.
	 *
	 * @return array<string, string>
	 */
	public static function get_tipos_evento() {
		return array(
			'reparacion'             => __( 'Reparación', 'calibratrack' ),
			'mantencion_calibracion' => __( 'Mantención y/o calibración', 'calibratrack' ),
		);
	}

	/**
	 * Devuelve los estados posibles de vigencia de un equipo.
	 *
	 * @return array<string, string>
	 */
	public static function get_estados_equipo() {
		return array(
			'vigente'    => __( 'Vigente', 'calibratrack' ),
			'por_vencer' => __( 'Por vencer', 'calibratrack' ),
			'vencido'    => __( 'Vencido', 'calibratrack' ),
			'sin_evento' => __( 'Sin eventos registrados', 'calibratrack' ),
		);
	}

	/**
	 * Devuelve los 4 estados posibles de una Orden de Trabajo.
	 * Fuente única de verdad — ningún otro archivo define esta lista.
	 *
	 * @return array<string, array{label: string, clase: string}>
	 */
	public static function get_estados_servicio() {
		return array(
			'en_proceso'     => array(
				'label' => __( 'En proceso', 'calibratrack' ),
				'clase' => 'ct-badge--por-vencer',
			),
			'en_ejecucion'   => array(
				'label' => __( 'En ejecución', 'calibratrack' ),
				'clase' => 'ct-badge--en-ejecucion',
			),
			'listo_revision' => array(
				'label' => __( 'Listo para revisión', 'calibratrack' ),
				'clase' => 'ct-badge--listo-revision',
			),
			'completado'     => array(
				'label' => __( 'Completado', 'calibratrack' ),
				'clase' => 'ct-badge--vigente',
			),
		);
	}

	/**
	 * Calcula el estado de vigencia de un equipo a partir de la próxima
	 * fecha de control de su evento de servicio más reciente.
	 *
	 * Umbrales:
	 *   - vencido    : fecha < hoy
	 *   - por_vencer : hoy <= fecha <= hoy + 30 días
	 *   - vigente    : fecha > hoy + 30 días
	 *
	 * @param string $proxima_fecha_control Fecha ISO 8601 (YYYY-MM-DD). Cadena vacía = sin evento.
	 * @return string Slug del estado (vigente | por_vencer | vencido | sin_evento).
	 */
	public static function calcular_estado_vigencia( $proxima_fecha_control ) {
		if ( empty( $proxima_fecha_control ) ) {
			return 'sin_evento';
		}

		$hoy           = new DateTime( 'today', new DateTimeZone( 'America/Santiago' ) );
		$fecha_control = DateTime::createFromFormat( 'Y-m-d', $proxima_fecha_control, new DateTimeZone( 'America/Santiago' ) );

		if ( false === $fecha_control ) {
			return 'sin_evento';
		}

		// Diferencia en días (positivo = fecha en el futuro).
		$diff_dias = (int) $hoy->diff( $fecha_control )->format( '%r%a' );

		if ( $diff_dias < 0 ) {
			return 'vencido';
		}

		if ( $diff_dias <= 30 ) {
			return 'por_vencer';
		}

		return 'vigente';
	}

	/**
	 * Valida el formato y dígito verificador de un RUT chileno.
	 * Acepta formatos: 12345678-9, 12.345.678-9, 12345678-K.
	 *
	 * @param string $rut RUT a validar.
	 * @return bool
	 */
	public static function validar_rut( $rut ) {
		// Limpiar puntos y espacios, dejar solo dígitos, guión y K.
		$rut = preg_replace( '/[\.\s]/', '', strtoupper( trim( $rut ) ) );

		if ( ! preg_match( '/^(\d{1,8})-([0-9K])$/', $rut, $matches ) ) {
			return false;
		}

		$numero = (int) $matches[1];
		$dv     = $matches[2];

		// Cálculo del dígito verificador (algoritmo módulo 11).
		$suma    = 0;
		$factor  = 2;
		$n       = $numero;

		while ( $n > 0 ) {
			$suma   += ( $n % 10 ) * $factor;
			$n       = (int) ( $n / 10 );
			$factor  = ( $factor < 7 ) ? $factor + 1 : 2;
		}

		$resto  = $suma % 11;
		$dv_esp = 11 - $resto;

		$dv_calculado = '';
		if ( 11 === $dv_esp ) {
			$dv_calculado = '0';
		} elseif ( 10 === $dv_esp ) {
			$dv_calculado = 'K';
		} else {
			$dv_calculado = (string) $dv_esp;
		}

		return $dv === $dv_calculado;
	}

	/**
	 * Calcula el total de items_costo (subtotal, IVA 19%, total).
	 *
	 * @param array $items Array de items: cada uno con 'cantidad' y 'precio_unitario'.
	 * @return array{ subtotal: float, iva: float, total: float }
	 */
	public static function calcular_totales_costo( array $items ) {
		$subtotal = 0.0;

		foreach ( $items as $item ) {
			$cantidad        = isset( $item['cantidad'] ) ? (float) $item['cantidad'] : 0.0;
			$precio_unitario = isset( $item['precio_unitario'] ) ? (float) $item['precio_unitario'] : 0.0;
			$subtotal       += $cantidad * $precio_unitario;
		}

		$iva   = round( $subtotal * 0.19, 2 );
		$total = round( $subtotal + $iva, 2 );

		return array(
			'subtotal' => round( $subtotal, 2 ),
			'iva'      => $iva,
			'total'    => $total,
		);
	}

	/**
	 * Genera un token único no adivinable para nombres de archivos PDF.
	 * Usa wp_generate_uuid4() disponible desde WP 4.7.
	 *
	 * @return string UUID v4 sin guiones.
	 */
	public static function generar_token_archivo() {
		return str_replace( '-', '', wp_generate_uuid4() );
	}

	/**
	 * Genera el siguiente número de Orden de Trabajo correlativo.
	 *
	 * Formato: OT-YYYY-NNN (ej. OT-2026-042).
	 * El contador se reinicia cada año. Se almacena en la opción
	 * calibratrack_ot_counter_{YYYY} para evitar colisiones en entornos
	 * con múltiples técnicos usando wp_query_vars.
	 *
	 * @return string Número OT formateado.
	 */
	public static function generar_numero_ot() {
		$anio       = (int) gmdate( 'Y' );
		$option_key = 'calibratrack_ot_counter_' . $anio;

		// Incremento atómico: leer → incrementar → guardar.
		$contador = (int) get_option( $option_key, 0 );
		$contador++;
		update_option( $option_key, $contador, false );

		return sprintf( 'OT-%d-%03d', $anio, $contador );
	}

	/**
	 * Genera el siguiente número correlativo de Orden de Ingreso para el año en curso.
	 * Formato: OI-{AÑO}-{correlativo 3 dígitos} → OI-2026-001.
	 * Mismo mecanismo que generar_numero_ot(): contador anual en wp_options.
	 *
	 * @return string
	 */
	public static function generar_numero_oi() {
		$anio       = (int) gmdate( 'Y' );
		$option_key = 'calibratrack_oi_counter_' . $anio;

		$contador = (int) get_option( $option_key, 0 );
		$contador++;
		update_option( $option_key, $contador, false );

		return sprintf( 'OI-%d-%03d', $anio, $contador );
	}

	// ─── Helpers de selects y carga de valores ───────────────────────────────

	/**
	 * Devuelve todos los equipos para el select del formulario.
	 *
	 * @return WP_Post[]
	 */
	public static function get_equipos_para_select() {
		return get_posts( array(
			'post_type'      => CalibraTrack_CPT_Equipo::SLUG,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'title',
			'order'          => 'ASC',
		) );
	}

	/**
	 * Devuelve todos los clientes para el select del formulario.
	 *
	 * @return WP_Post[]
	 */
	public static function get_clientes_para_select() {
		return get_posts( array(
			'post_type'      => CalibraTrack_CPT_Cliente::SLUG,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'title',
			'order'          => 'ASC',
			'no_found_rows'  => true,
		) );
	}

	/**
	 * Devuelve los usuarios con rol tecnico_calibracion o administrator para el select.
	 *
	 * @return WP_User[]
	 */
	public static function get_tecnicos_para_select() {
		return get_users( array(
			'role__in' => array( 'tecnico_calibracion', 'administrator' ),
			'orderby'  => 'display_name',
			'order'    => 'ASC',
		) );
	}

	/**
	 * Devuelve las OIs publicadas disponibles para el select de vinculación en formulario OT.
	 *
	 * Se excluyen las OIs que ya tienen una OT asociada (relación 1:1 OI→OT).
	 * Si se pasa $current_oi_id, esa OI se incluye aunque ya tenga una OT (caso de edición:
	 * la OI que ya pertenece a la OT que se está editando debe seguir apareciendo seleccionada).
	 *
	 * @param int $current_oi_id ID de la OI ya vinculada a la OT en edición (0 para OT nueva).
	 * @return WP_Post[]
	 */
	public static function get_ois_para_select( $current_oi_id = 0 ) {
		global $wpdb;

		$current_oi_id = (int) $current_oi_id;
		$meta_key_tipo = CalibraTrack_Meta_Keys::EVENTO_TIPO_DOCUMENTO;
		$meta_key_rel  = CalibraTrack_Meta_Keys::EVENTO_INGRESO_RELACIONADO_ID;

		// IDs de OIs que ya tienen una OT vinculada, excluyendo la OI actual en edición.
		// IMPORTANTE: NO usar CAST(meta_value AS UNSIGNED) — envolver la columna en una
		// función impide que MariaDB use el índice. Los IDs se almacenan como strings en
		// wp_postmeta; comparar directamente con '%s' es correcto y permite usar el índice.
		if ( $current_oi_id > 0 ) {
			$ois_tomadas = $wpdb->get_col( $wpdb->prepare(
				"SELECT pm.meta_value
				FROM {$wpdb->postmeta} pm
				INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id AND p.post_status = 'publish'
				INNER JOIN {$wpdb->postmeta} pm_tipo
				       ON pm_tipo.post_id  = pm.post_id
				      AND pm_tipo.meta_key = %s
				      AND pm_tipo.meta_value = 'ot'
				WHERE pm.meta_key = %s
				  AND pm.meta_value != ''
				  AND pm.meta_value != '0'
				  AND pm.meta_value != %s",
				$meta_key_tipo,
				$meta_key_rel,
				(string) $current_oi_id
			) );
		} else {
			$ois_tomadas = $wpdb->get_col( $wpdb->prepare(
				"SELECT pm.meta_value
				FROM {$wpdb->postmeta} pm
				INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id AND p.post_status = 'publish'
				INNER JOIN {$wpdb->postmeta} pm_tipo
				       ON pm_tipo.post_id  = pm.post_id
				      AND pm_tipo.meta_key = %s
				      AND pm_tipo.meta_value = 'ot'
				WHERE pm.meta_key = %s
				  AND pm.meta_value != ''
				  AND pm.meta_value != '0'",
				$meta_key_tipo,
				$meta_key_rel
			) );
		}

		$args = array(
			'post_type'      => CalibraTrack_CPT_EventoServicio::SLUG,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'meta_query'     => array(
				array(
					'key'   => $meta_key_tipo,
					'value' => 'ingreso',
				),
			),
		);

		if ( ! empty( $ois_tomadas ) ) {
			$args['post__not_in'] = array_map( 'intval', $ois_tomadas );
		}

		return get_posts( $args );
	}

	/**
	 * Carga los valores de una OI existente para pre-poblar el formulario.
	 *
	 * @param int $evento_id Post ID del evento.
	 * @return array
	 */
	public static function cargar_valores_oi( $evento_id ) {
		return array(
			'equipo_id'       => (int) get_post_meta( $evento_id, CalibraTrack_Meta_Keys::EVENTO_EQUIPO_ID, true ),
			'tecnico_id'      => (int) get_post_meta( $evento_id, CalibraTrack_Meta_Keys::EVENTO_TECNICO_RESPONSABLE, true ),
			'tipo'            => (string) get_post_meta( $evento_id, CalibraTrack_Meta_Keys::EVENTO_TIPO, true ),
			'fecha_ejecucion' => (string) get_post_meta( $evento_id, CalibraTrack_Meta_Keys::EVENTO_FECHA_EJECUCION, true ),
			'falla_reportada' => (string) get_post_meta( $evento_id, CalibraTrack_Meta_Keys::EVENTO_FALLA_REPORTADA, true ),
		);
	}

	/**
	 * Carga los valores de una OT existente para pre-poblar el formulario.
	 *
	 * @param int $evento_id Post ID del evento.
	 * @return array
	 */
	public static function cargar_valores_ot( $evento_id ) {
		$items_raw = CalibraTrack_DB::get_items_costo( $evento_id );
		$items     = array();
		foreach ( $items_raw as $item ) {
			$items[] = array(
				'detalle'         => $item->detalle,
				'cantidad'        => $item->cantidad,
				'precio_unitario' => $item->precio_unitario,
			);
		}
		$estado = (string) get_post_meta( $evento_id, CalibraTrack_Meta_Keys::EVENTO_ESTADO_SERVICIO, true );
		return array(
			'equipo_id'              => (int) get_post_meta( $evento_id, CalibraTrack_Meta_Keys::EVENTO_EQUIPO_ID, true ),
			'tecnico_id'             => (int) get_post_meta( $evento_id, CalibraTrack_Meta_Keys::EVENTO_TECNICO_RESPONSABLE, true ),
			'numero_ot'              => (string) get_post_meta( $evento_id, CalibraTrack_Meta_Keys::EVENTO_NUMERO_OT, true ),
			'tipo'                   => (string) get_post_meta( $evento_id, CalibraTrack_Meta_Keys::EVENTO_TIPO, true ),
			'fecha_ejecucion'        => (string) get_post_meta( $evento_id, CalibraTrack_Meta_Keys::EVENTO_FECHA_EJECUCION, true ),
			'proxima_fecha'          => (string) get_post_meta( $evento_id, CalibraTrack_Meta_Keys::EVENTO_PROXIMA_FECHA_CONTROL, true ),
			'falla_reportada'        => (string) get_post_meta( $evento_id, CalibraTrack_Meta_Keys::EVENTO_FALLA_REPORTADA, true ),
			'descripcion_trabajo'    => (string) get_post_meta( $evento_id, CalibraTrack_Meta_Keys::EVENTO_DESCRIPCION_TRABAJO, true ),
			'observaciones'          => (string) get_post_meta( $evento_id, CalibraTrack_Meta_Keys::EVENTO_OBSERVACIONES, true ),
			'estado_servicio'        => '' !== $estado ? $estado : 'en_proceso',
			'ingreso_relacionado_id' => (int) get_post_meta( $evento_id, CalibraTrack_Meta_Keys::EVENTO_INGRESO_RELACIONADO_ID, true ),
			'items'                  => $items,
		);
	}

	/**
	 * Comprueba si un número de OT ya existe en otra OT publicada.
	 *
	 * @param string $numero_ot        Número a verificar.
	 * @param int    $excluir_evento_id Post ID de la OT en edición (0 para nueva). Se excluye
	 *                                  para que editar sin cambiar el número no dispare error.
	 * @return bool True si el número ya está en uso en otra OT.
	 */
	public static function numero_ot_existe( $numero_ot, $excluir_evento_id = 0 ) {
		global $wpdb;

		$numero_ot        = (string) $numero_ot;
		$excluir_evento_id = (int) $excluir_evento_id;

		if ( '' === $numero_ot ) {
			return false;
		}

		if ( $excluir_evento_id > 0 ) {
			$count = $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*)
				FROM {$wpdb->postmeta} pm
				INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				WHERE pm.meta_key   = %s
				  AND pm.meta_value = %s
				  AND p.post_status = 'publish'
				  AND pm.post_id   != %d",
				CalibraTrack_Meta_Keys::EVENTO_NUMERO_OT,
				$numero_ot,
				$excluir_evento_id
			) );
		} else {
			$count = $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*)
				FROM {$wpdb->postmeta} pm
				INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				WHERE pm.meta_key   = %s
				  AND pm.meta_value = %s
				  AND p.post_status = 'publish'",
				CalibraTrack_Meta_Keys::EVENTO_NUMERO_OT,
				$numero_ot
			) );
		}

		return ( (int) $count ) > 0;
	}
}
