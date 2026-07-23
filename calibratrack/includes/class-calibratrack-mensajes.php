<?php
/**
 * Mensajería interna por OT.
 *
 * Gestiona el hilo de mensajes entre administrador y técnico en el contexto
 * de una Orden de Trabajo específica. Los mensajes son asíncronos (similar a
 * comentarios de ticket) y se almacenan en la tabla calibratrack_mensajes.
 *
 * DECISIÓN: se usa tabla custom en lugar de wp_comments porque:
 *   - Los comentarios de WP llevan overhead de moderación, spam y taxonomía
 *     que no aplica aquí.
 *   - Necesitamos el flag `leido` por usuario para contadores de no leídos.
 *   - La tabla ya existe en el mismo servidor (misma conexión $wpdb) — sin costo
 *     adicional de JOIN cross-table.
 *
 * @package CalibraTrack
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CalibraTrack_Mensajes
 *
 * Todos los métodos son estáticos — no hay estado de instancia.
 */
class CalibraTrack_Mensajes {

	/**
	 * Guarda un mensaje nuevo en la tabla calibratrack_mensajes.
	 *
	 * @param int    $evento_id Post ID de la OT a la que pertenece el mensaje.
	 * @param int    $autor_id  User ID del autor del mensaje.
	 * @param string $texto     Texto del mensaje (ya sanitizado antes de llamar).
	 * @return int|false ID del mensaje insertado, o false en error.
	 */
	public static function save( $evento_id, $autor_id, $texto ) {
		global $wpdb;

		$evento_id = (int) $evento_id;
		$autor_id  = (int) $autor_id;
		// sanitize_textarea_field se aplica aquí como segunda capa de defensa;
		// el handler AJAX ya sanitizó antes de llamar a save().
		$texto = sanitize_textarea_field( (string) $texto );

		if ( $evento_id <= 0 || $autor_id <= 0 || '' === $texto ) {
			return false;
		}

		// Truncar al máximo permitido por el negocio (1000 chars).
		if ( mb_strlen( $texto, 'UTF-8' ) > 1000 ) {
			$texto = mb_substr( $texto, 0, 1000, 'UTF-8' );
		}

		$table = CalibraTrack_DB::get_table_mensajes();

		$result = $wpdb->insert(
			$table,
			array(
				'evento_id' => $evento_id,
				'autor_id'  => $autor_id,
				'mensaje'   => $texto,
				'fecha'     => current_time( 'mysql' ),
				'leido'     => 0,
			),
			array( '%d', '%d', '%s', '%s', '%d' )
		);

		if ( false === $result ) {
			return false;
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Retorna todos los mensajes de una OT ordenados cronológicamente ASC.
	 *
	 * Cada elemento del array es un stdClass con:
	 *   id, evento_id, autor_id, autor_nombre, autor_rol, mensaje, fecha, leido
	 *
	 * NOTA: autor_rol se determina consultando los roles del usuario en la tabla
	 * de WP, NO con current_user_can() — este método puede llamarse fuera del
	 * contexto de una request HTTP (por ejemplo desde un callback de correo) y
	 * current_user_can() solo aplica al usuario logueado en el momento.
	 *
	 * @param int $evento_id Post ID de la OT.
	 * @return array Array de stdClass. Vacío si no hay mensajes.
	 */
	public static function get_by_evento( $evento_id ) {
		global $wpdb;

		$evento_id = (int) $evento_id;
		if ( $evento_id <= 0 ) {
			return array();
		}

		$table = CalibraTrack_DB::get_table_mensajes();

		$filas = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, evento_id, autor_id, mensaje, fecha, leido
				FROM {$table}
				WHERE evento_id = %d
				ORDER BY fecha ASC, id ASC",
				$evento_id
			)
		);

		if ( empty( $filas ) ) {
			return array();
		}

		foreach ( $filas as $fila ) {
			$user_data = get_userdata( (int) $fila->autor_id );
			if ( $user_data ) {
				$fila->autor_nombre = $user_data->display_name;
				// Determinar rol comparando las capabilities del usuario.
				// Se compara contra el array de roles del WP_User, no con current_user_can().
				$roles             = (array) $user_data->roles;
				$fila->autor_rol   = in_array( 'administrator', $roles, true ) ? 'admin' : 'tecnico';
			} else {
				$fila->autor_nombre = __( 'Usuario eliminado', 'calibratrack' );
				$fila->autor_rol    = 'tecnico';
			}
		}

		return $filas;
	}

	/**
	 * Marca como leídos los mensajes de una OT que NO fueron escritos por $usuario_id.
	 *
	 * La lógica es: "estoy viendo el hilo, por lo tanto los mensajes que no escribí
	 * los acabo de leer". Los propios mensajes del usuario no cambian (ya los conoce).
	 *
	 * @param int $evento_id  Post ID de la OT.
	 * @param int $usuario_id User ID del usuario que está viendo el hilo.
	 * @return void
	 */
	public static function marcar_leidos( $evento_id, $usuario_id ) {
		global $wpdb;

		$evento_id  = (int) $evento_id;
		$usuario_id = (int) $usuario_id;

		if ( $evento_id <= 0 || $usuario_id <= 0 ) {
			return;
		}

		$table = CalibraTrack_DB::get_table_mensajes();

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table}
				SET leido = 1
				WHERE evento_id = %d
				  AND autor_id  != %d
				  AND leido = 0",
				$evento_id,
				$usuario_id
			)
		);
	}

	/**
	 * Retorna los mensajes de una OT con id > $ultimo_id (mensajes nuevos desde el último poll).
	 *
	 * Enriquece igual que get_by_evento(): agrega autor_nombre y autor_rol.
	 * Se usa desde el endpoint AJAX de polling.
	 *
	 * @param int $evento_id Post ID de la OT.
	 * @param int $ultimo_id ID del último mensaje conocido por el cliente (0 = todos).
	 * @return array Array de stdClass. Vacío si no hay mensajes nuevos.
	 */
	public static function get_nuevos( $evento_id, $ultimo_id ) {
		global $wpdb;

		$evento_id = (int) $evento_id;
		$ultimo_id = (int) $ultimo_id;

		if ( $evento_id <= 0 ) {
			return array();
		}

		$table = CalibraTrack_DB::get_table_mensajes();

		$filas = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, evento_id, autor_id, mensaje, fecha, leido
				FROM {$table}
				WHERE evento_id = %d
				  AND id > %d
				ORDER BY fecha ASC, id ASC",
				$evento_id,
				$ultimo_id
			)
		);

		if ( empty( $filas ) ) {
			return array();
		}

		foreach ( $filas as $fila ) {
			$user_data = get_userdata( (int) $fila->autor_id );
			if ( $user_data ) {
				$fila->autor_nombre = $user_data->display_name;
				$roles              = (array) $user_data->roles;
				$fila->autor_rol    = in_array( 'administrator', $roles, true ) ? 'admin' : 'tecnico';
			} else {
				$fila->autor_nombre = __( 'Usuario eliminado', 'calibratrack' );
				$fila->autor_rol    = 'tecnico';
			}
		}

		return $filas;
	}

	/**
	 * Cuenta los mensajes no leídos para el $usuario_id en una OT.
	 *
	 * "No leído para el usuario" significa: mensajes en esa OT escritos por OTRO
	 * autor (no por el propio $usuario_id) y con leido = 0.
	 *
	 * @param int $evento_id  Post ID de la OT.
	 * @param int $usuario_id User ID del usuario cuya bandeja se consulta.
	 * @return int Cantidad de mensajes no leídos.
	 */
	public static function count_no_leidos( $evento_id, $usuario_id ) {
		global $wpdb;

		$evento_id  = (int) $evento_id;
		$usuario_id = (int) $usuario_id;

		if ( $evento_id <= 0 || $usuario_id <= 0 ) {
			return 0;
		}

		$table = CalibraTrack_DB::get_table_mensajes();

		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*)
				FROM {$table}
				WHERE evento_id = %d
				  AND autor_id  != %d
				  AND leido = 0",
				$evento_id,
				$usuario_id
			)
		);

		return (int) $count;
	}
}
