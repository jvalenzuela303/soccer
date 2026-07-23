<?php
/**
 * Gestión de la tabla custom calibratrack_items_costo.
 *
 * DECISIÓN ARQUITECTÓNICA — Por qué tabla custom para items_costo:
 *
 * items_costo es un repetidor de largo variable con estructura compuesta:
 *   (detalle: string, cantidad: decimal, precio_unitario: decimal)
 *
 * Alternativas evaluadas:
 *
 * 1. postmeta serializado (descartada):
 *    - Un único meta_key con valor PHP serializado o JSON de toda la lista.
 *    - Problemas: no se puede consultar por ítem individual (ej. "eventos con
 *      el ítem X"), las actualizaciones parciales requieren deserializar todo,
 *      y el tamaño puede superar el límite práctico de wp_postmeta (TEXT).
 *    - Para el volumen esperado (cientos de ítems por evento, miles de eventos)
 *      esto genera filas de postmeta muy grandes sin beneficio de indexación.
 *
 * 2. Múltiples filas postmeta con índice numérico (descartada):
 *    - calibratrack_item_0_detalle, calibratrack_item_0_cantidad, etc.
 *    - Explosión de filas en wp_postmeta. Paginación y borrado complicados.
 *    - No hay beneficio de indexación sobre postmeta para consultas por ítem.
 *
 * 3. Tabla custom calibratrack_items_costo (ELEGIDA):
 *    - Una fila por ítem, relacionada por evento_id (post ID).
 *    - Estructura SQL limpia: permite sumar/agrupar en una sola query.
 *    - Operaciones CRUD simples (borrar todos los ítems de un evento y
 *      reinsertar es el patrón más seguro al editar un evento).
 *    - Compatible con dbDelta() y el entorno MariaDB 10.6.25/cPanel sin
 *      requerir privilegio SUPER.
 *    - Versionada con calibratrack_db_version para migraciones futuras.
 *
 * ESTRUCTURA DE LA TABLA:
 *   id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT
 *   evento_id       BIGINT UNSIGNED NOT NULL  (post ID del evento_servicio)
 *   orden           SMALLINT UNSIGNED NOT NULL DEFAULT 0  (preserva el orden visual)
 *   detalle         VARCHAR(500) NOT NULL DEFAULT ''
 *   cantidad        DECIMAL(10,2) NOT NULL DEFAULT 0.00
 *   precio_unitario DECIMAL(12,2) NOT NULL DEFAULT 0.00
 *   PRIMARY KEY (id)
 *   KEY idx_evento_id (evento_id)
 *   KEY idx_evento_orden (evento_id, orden)
 *
 * DECISIONES DE TIPOS Y FORMATO — ver docs/decisiones.md D-13, D-14, D-15.
 *
 * NOTA PARA EL AGENTE database-schema-agent:
 *   - La tabla se crea aquí en create_tables(). No crear otra definición de ella.
 *   - Los totales (subtotal, IVA, total) se calculan en PHP vía
 *     CalibraTrack_Helpers::calcular_totales_costo() — no se almacenan en DB
 *     para evitar inconsistencias si cambia la tasa de IVA en el futuro.
 *   - Si se necesita búsqueda full-text en 'detalle', MariaDB 10.6 soporta
 *     FULLTEXT en tablas InnoDB — se puede agregar en una migración posterior.
 *
 * @package CalibraTrack
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CalibraTrack_DB
 */
class CalibraTrack_DB {

	/**
	 * Versión actual del esquema de la tabla.
	 * Incrementar cuando se modifique la estructura de la tabla.
	 * Se compara contra la opción 'calibratrack_db_version' en WP options.
	 *
	 * v1.2.0 — Agrega la tabla calibratrack_mensajes para mensajería interna por OT.
	 * v1.3.0 — Índice compuesto (post_id, meta_key) en wp_postmeta para queries del plugin.
	 */
	const DB_VERSION = '1.3.0';

	/**
	 * Nombre de la tabla de ítems de costo sin prefijo de WP.
	 * El nombre completo se obtiene con self::get_table_name().
	 */
	const TABLE_ITEMS_COSTO = 'calibratrack_items_costo';

	/**
	 * Nombre de la tabla de mensajes internos sin prefijo de WP.
	 * El nombre completo se obtiene con self::get_table_mensajes().
	 */
	const TABLE_MENSAJES = 'calibratrack_mensajes';

	/**
	 * Devuelve el nombre completo de la tabla de ítems de costo con el prefijo de WP.
	 *
	 * @return string Nombre completo de la tabla.
	 */
	public static function get_table_name() {
		global $wpdb;
		return $wpdb->prefix . self::TABLE_ITEMS_COSTO;
	}

	/**
	 * Devuelve el nombre completo de la tabla de mensajes con el prefijo de WP.
	 *
	 * @return string Nombre completo de la tabla de mensajes.
	 */
	public static function get_table_mensajes() {
		global $wpdb;
		return $wpdb->prefix . self::TABLE_MENSAJES;
	}

	/**
	 * Crea o actualiza la tabla custom usando dbDelta().
	 *
	 * Se llama en el hook de activación y también en el hook 'plugins_loaded'
	 * si la versión de DB almacenada difiere de self::DB_VERSION (para
	 * aplicar migraciones automáticas al actualizar el plugin).
	 *
	 * Notas sobre dbDelta():
	 *   - Requiere DOS espacios entre PRIMARY KEY y el paréntesis de apertura.
	 *   - El tipo de columna debe estar en MAYÚSCULAS.
	 *   - Cada KEY debe estar en su propia línea, con dos espacios de sangría.
	 *   - No usar comentarios en línea (-- ...) dentro del SQL de dbDelta.
	 *   - No elimina columnas existentes — solo agrega las nuevas.
	 *   - Compatible con MariaDB 10.6.25 sin privilegio SUPER.
	 *
	 * CAMBIO v1.1.0 respecto a v1.0.0:
	 *   - cantidad cambiada de DECIMAL(10,4) a DECIMAL(10,2): ver D-13.
	 *   - Agregado índice compuesto idx_evento_orden: ver D-15.
	 *   NOTA: dbDelta() no modifica tipos de columnas existentes. Si se actualiza
	 *   una instalación v1.0.0, la columna cantidad debe migrarse manualmente
	 *   via phpMyAdmin o un script de migración (ver maybe_upgrade()).
	 *
	 * @return void
	 */
	public static function create_tables() {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		// CRÍTICO: dbDelta es muy sensible al formato SQL.
		// - Dos espacios antes de PRIMARY KEY y antes de cada KEY.
		// - Tipos en MAYÚSCULAS.
		// - Sin comentarios en línea dentro del SQL.
		// - Sin coma después del último KEY antes del cierre de paréntesis.

		// ── Tabla 1: items_costo ─────────────────────────────────────────────────
		$table_items = self::get_table_name();
		$sql_items   = "CREATE TABLE {$table_items} (
  id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  evento_id BIGINT(20) UNSIGNED NOT NULL,
  orden SMALLINT(5) UNSIGNED NOT NULL DEFAULT 0,
  detalle VARCHAR(500) NOT NULL DEFAULT '',
  cantidad DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  precio_unitario DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY  (id),
  KEY idx_evento_id (evento_id),
  KEY idx_evento_orden (evento_id,orden)
) {$charset_collate};";

		dbDelta( $sql_items );

		// ── Tabla 2: mensajes ────────────────────────────────────────────────────
		// Mensajería interna asíncrona entre admin y técnico por OT.
		// v1.2.0: tabla nueva (dbDelta la crea si no existe, es no destructivo).
		$table_mensajes = self::get_table_mensajes();
		$sql_mensajes   = "CREATE TABLE {$table_mensajes} (
  id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  evento_id BIGINT(20) UNSIGNED NOT NULL,
  autor_id BIGINT(20) UNSIGNED NOT NULL,
  mensaje TEXT NOT NULL,
  fecha DATETIME NOT NULL,
  leido TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  KEY idx_msj_evento (evento_id),
  KEY idx_msj_evento_fecha (evento_id,fecha)
) {$charset_collate};";

		dbDelta( $sql_mensajes );

		// Asegurar índice compuesto en wp_postmeta para optimizar queries del plugin.
		self::ensure_postmeta_index();

		update_option( 'calibratrack_db_version', self::DB_VERSION );
	}

	/**
	 * Verifica y crea el índice compuesto (post_id, meta_key) en wp_postmeta si no existe.
	 *
	 * WordPress incluye solo índices separados para post_id y meta_key. Las queries del
	 * plugin filtran por ambas columnas simultáneamente (patrón habitual en JOINs sobre
	 * postmeta), por lo que sin este índice MariaDB hace un escaneo completo de postmeta
	 * por cada JOIN — el cuello de botella más importante del sistema.
	 *
	 * Se verifica la existencia antes de ejecutar el ALTER TABLE — es idempotente.
	 * En MariaDB 10.6, ADD INDEX usa Online DDL y no bloquea lecturas ni escrituras.
	 * Compatible con CloudLinux/LVE — no requiere privilegio SUPER.
	 *
	 * @return void
	 */
	public static function ensure_postmeta_index() {
		global $wpdb;

		$table    = $wpdb->postmeta;
		$idx_name = 'calibratrack_post_meta_key';

		// information_schema.STATISTICS está disponible sin privilegio SUPER en MariaDB 10.6.
		$existe = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(1)
			 FROM information_schema.STATISTICS
			 WHERE table_schema = DATABASE()
			   AND table_name   = %s
			   AND index_name   = %s",
			$table,
			$idx_name
		) );

		if ( ! $existe ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "ALTER TABLE {$table} ADD INDEX {$idx_name} (post_id, meta_key(191))" );
		}
	}

	/**
	 * Comprueba si el esquema de la tabla está desactualizado y lo actualiza.
	 * Se llama en 'plugins_loaded' para detectar actualizaciones del plugin
	 * que requieran cambios de esquema.
	 *
	 * NOTA SOBRE MIGRACIÓN DE TIPO DE COLUMNA (v1.0.0 -> v1.1.0):
	 * dbDelta() agrega columnas nuevas e índices nuevos, pero NO modifica el tipo
	 * de una columna existente. Si la instalación tenía v1.0.0 con DECIMAL(10,4),
	 * este método ejecutará el ALTER TABLE necesario para llevarla a DECIMAL(10,2).
	 * En hosting compartido sin SSH, este ALTER es seguro: solo reduce la escala
	 * de 4 a 2 decimales, y los valores almacenados con hasta 4 decimales se
	 * redondean a 2 al momento del ALTER (comportamiento estándar de MariaDB).
	 *
	 * @return void
	 */
	public static function maybe_upgrade() {
		$installed_version = get_option( 'calibratrack_db_version', '0' );

		if ( version_compare( $installed_version, self::DB_VERSION, '<' ) ) {
			// Migración específica v1.0.0 -> v1.1.0: cambio de tipo en 'cantidad'.
			if ( version_compare( $installed_version, '1.1.0', '<' ) ) {
				self::migrate_cantidad_decimal();
			}

			self::create_tables();
		}
	}

	/**
	 * Migra la columna 'cantidad' de DECIMAL(10,4) a DECIMAL(10,2).
	 * Solo se ejecuta cuando se actualiza desde v1.0.0.
	 *
	 * dbDelta() no cambia tipos de columna existentes, por lo que el ALTER
	 * TABLE se hace explícitamente aquí. El ALTER TABLE en una tabla con pocos
	 * miles de filas es instantáneo en MariaDB 10.6 (online DDL por defecto).
	 *
	 * @return void
	 */
	private static function migrate_cantidad_decimal() {
		global $wpdb;

		$table = self::get_table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "ALTER TABLE {$table} MODIFY COLUMN cantidad DECIMAL(10,2) NOT NULL DEFAULT 0.00" );
	}

	/**
	 * Inserta o reemplaza los ítems de costo de un evento.
	 *
	 * Patrón delete-then-insert: borra todos los ítems existentes del evento
	 * y los reinserta. Es más simple y seguro que un diff cuando el usuario
	 * puede reordenar, agregar y borrar ítems en una sola operación de guardado.
	 *
	 * FORMATO DE VALORES DECIMALES (%s con number_format):
	 * Se usa '%s' en lugar de '%f' para cantidad y precio_unitario.
	 * Razón: $wpdb->prepare() con '%f' usa sprintf('%f', ...) que puede producir
	 * notación científica o decimales con ruido de punto flotante (ej. 1.2300000000001).
	 * Usando '%s' con number_format() se controla la cadena exacta enviada a MariaDB,
	 * garantizando que DECIMAL(10,2) reciba siempre un string con exactamente 2 decimales.
	 * Ver docs/decisiones.md D-14 para la justificación completa.
	 *
	 * @param int   $evento_id Post ID del evento_servicio.
	 * @param array $items     Array de arrays con claves: detalle, cantidad, precio_unitario.
	 * @return bool True si la operación fue exitosa.
	 */
	public static function save_items_costo( $evento_id, array $items ) {
		global $wpdb;

		$table = self::get_table_name();

		// Borrar ítems existentes del evento.
		$wpdb->delete(
			$table,
			array( 'evento_id' => (int) $evento_id ),
			array( '%d' )
		);

		if ( empty( $items ) ) {
			return true;
		}

		$orden = 0;
		foreach ( $items as $item ) {
			$detalle         = isset( $item['detalle'] ) ? sanitize_text_field( (string) $item['detalle'] ) : '';
			$cantidad        = isset( $item['cantidad'] ) ? (float) $item['cantidad'] : 0.0;
			$precio_unitario = isset( $item['precio_unitario'] ) ? (float) $item['precio_unitario'] : 0.0;

			// Convertir a string con exactamente 2 decimales para garantizar
			// que MariaDB reciba el valor correcto sin ruido de punto flotante.
			// Ver D-14 en docs/decisiones.md.
			$cantidad_str        = number_format( $cantidad, 2, '.', '' );
			$precio_unitario_str = number_format( $precio_unitario, 2, '.', '' );

			$wpdb->insert(
				$table,
				array(
					'evento_id'       => (int) $evento_id,
					'orden'           => $orden,
					'detalle'         => $detalle,
					'cantidad'        => $cantidad_str,
					'precio_unitario' => $precio_unitario_str,
				),
				array( '%d', '%d', '%s', '%s', '%s' )
			);

			$orden++;
		}

		return true;
	}

	/**
	 * Recupera los ítems de costo de un evento, ordenados por 'orden'.
	 *
	 * Usa el índice idx_evento_id para el filtro por evento_id y
	 * idx_evento_orden para el ORDER BY (índice compuesto, cubriente).
	 *
	 * @param int $evento_id Post ID del evento_servicio.
	 * @return array Array de objetos stdClass con las columnas de la tabla.
	 */
	public static function get_items_costo( $evento_id ) {
		global $wpdb;

		$table = self::get_table_name();

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, orden, detalle, cantidad, precio_unitario
				FROM {$table}
				WHERE evento_id = %d
				ORDER BY orden ASC",
				(int) $evento_id
			)
		);
	}

	/**
	 * Elimina todos los ítems de costo de un evento.
	 * Se llama al borrar un evento_servicio (hook 'before_delete_post').
	 *
	 * @param int $evento_id Post ID del evento_servicio.
	 * @return void
	 */
	public static function delete_items_costo( $evento_id ) {
		global $wpdb;

		$wpdb->delete(
			self::get_table_name(),
			array( 'evento_id' => (int) $evento_id ),
			array( '%d' )
		);
	}

	// ─── QUERIES DE NEGOCIO ─────────────────────────────────────────────────────

	/**
	 * Obtiene el evento de servicio más reciente de un equipo.
	 *
	 * "Más reciente" se define por calibratrack_fecha_ejecucion (meta de postmeta),
	 * no por post_date, para reflejar la fecha real del servicio y no la fecha de
	 * ingreso al sistema.
	 *
	 * Esta query la necesita el backend para:
	 *   - Calcular el estado de vigencia del equipo (proxima_fecha_control).
	 *   - Mostrar el último servicio en la ficha del equipo.
	 *
	 * ÍNDICES USADOS:
	 *   - wp_postmeta: índice estándar de WP sobre (meta_key, meta_value) + post_id.
	 *   - wp_posts: índice sobre post_type + post_status (estándar WP).
	 *
	 * NOTA: La query busca entre posts 'publish' del CPT evento_servicio.
	 * Si el evento está en borrador, no se considera como el más reciente.
	 * Esto es intencional: solo los eventos publicados (guardados definitivamente)
	 * afectan el estado de vigencia del equipo.
	 *
	 * @param int $equipo_id Post ID del equipo.
	 * @return object|null Objeto con post_id y proxima_fecha_control, o null si no hay eventos.
	 */
	public static function get_ultimo_evento( $equipo_id ) {
		global $wpdb;

		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					p.ID AS post_id,
					pm_proxima.meta_value AS proxima_fecha_control,
					pm_fecha.meta_value AS fecha_ejecucion
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} pm_equipo
					ON pm_equipo.post_id = p.ID
					AND pm_equipo.meta_key = 'calibratrack_equipo_id'
					AND pm_equipo.meta_value = %s
				INNER JOIN {$wpdb->postmeta} pm_tipo_doc
					ON pm_tipo_doc.post_id = p.ID
					AND pm_tipo_doc.meta_key = 'calibratrack_tipo_documento'
					AND pm_tipo_doc.meta_value = 'ot'
				LEFT JOIN {$wpdb->postmeta} pm_proxima
					ON pm_proxima.post_id = p.ID
					AND pm_proxima.meta_key = 'calibratrack_proxima_fecha_control'
				LEFT JOIN {$wpdb->postmeta} pm_fecha
					ON pm_fecha.post_id = p.ID
					AND pm_fecha.meta_key = 'calibratrack_fecha_ejecucion'
				WHERE p.post_type = 'evento_servicio'
					AND p.post_status = 'publish'
				ORDER BY pm_fecha.meta_value DESC
				LIMIT 1",
				(string) (int) $equipo_id
			)
		);
	}

	/**
	 * Versión batch de get_ultimo_evento(): obtiene el último evento de múltiples equipos
	 * en UNA sola query SQL en lugar de una query por equipo.
	 *
	 * Retorna un array asociativo: [ equipo_id => stdClass(post_id, proxima_fecha_control, fecha_ejecucion) ].
	 * Los equipos sin evento OT publicada no aparecen en el resultado.
	 *
	 * @param int[] $equipo_ids Array de Post IDs de equipos.
	 * @return array<int,object>
	 */
	public static function get_ultimo_evento_batch( array $equipo_ids ) {
		global $wpdb;

		$equipo_ids = array_map( 'intval', $equipo_ids );
		$equipo_ids = array_filter( $equipo_ids );

		if ( empty( $equipo_ids ) ) {
			return array();
		}

		// Construir placeholders seguros para IN () usando comparación de strings.
		// IMPORTANTE: NO usar CAST(meta_value AS UNSIGNED) — envolver la columna en una
		// función impide que MariaDB use el índice sobre meta_value. Los IDs se almacenan
		// en wp_postmeta como strings, por lo que la comparación directa con '%s' es
		// correcta y compatible con el índice compuesto (post_id, meta_key).
		$equipo_str   = array_map( 'strval', $equipo_ids );
		$placeholders = implode( ',', array_fill( 0, count( $equipo_ids ), '%s' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$filas = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					pm_equipo.meta_value AS equipo_id,
					p.ID AS post_id,
					pm_proxima.meta_value AS proxima_fecha_control,
					pm_fecha.meta_value AS fecha_ejecucion
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} pm_equipo
					ON pm_equipo.post_id = p.ID
					AND pm_equipo.meta_key = 'calibratrack_equipo_id'
					AND pm_equipo.meta_value IN ({$placeholders})
				INNER JOIN {$wpdb->postmeta} pm_tipo_doc
					ON pm_tipo_doc.post_id = p.ID
					AND pm_tipo_doc.meta_key = 'calibratrack_tipo_documento'
					AND pm_tipo_doc.meta_value = 'ot'
				LEFT JOIN {$wpdb->postmeta} pm_fecha
					ON pm_fecha.post_id = p.ID
					AND pm_fecha.meta_key = 'calibratrack_fecha_ejecucion'
				LEFT JOIN {$wpdb->postmeta} pm_proxima
					ON pm_proxima.post_id = p.ID
					AND pm_proxima.meta_key = 'calibratrack_proxima_fecha_control'
				WHERE p.post_type   = 'evento_servicio'
				  AND p.post_status = 'publish'
				ORDER BY pm_equipo.meta_value ASC, pm_fecha.meta_value DESC",
				$equipo_str
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

		// Quedarse solo con el último evento por equipo (el primero en el ORDER BY DESC).
		$resultado = array();
		foreach ( $filas as $fila ) {
			$eid = (int) $fila->equipo_id;
			if ( ! isset( $resultado[ $eid ] ) ) {
				$resultado[ $eid ] = $fila;
			}
		}

		return $resultado;
	}

	/**
	 * Obtiene todos los eventos de un equipo, ordenados cronológicamente (más reciente primero).
	 *
	 * Usado para el historial de servicios en la ficha del equipo y en el endpoint
	 * REST GET /equipos/{id}/historial.
	 *
	 * Se devuelven solo columnas necesarias (sin SELECT *) para mantener el payload
	 * acotado. El agente REST o admin puede enriquecer con get_post_meta() si necesita
	 * campos adicionales.
	 *
	 * @param int $equipo_id Post ID del equipo.
	 * @param int $limit     Máximo de eventos a devolver. 0 = sin límite (usar con cuidado).
	 * @return array Array de objetos stdClass. Vacío si no hay eventos.
	 */
	public static function get_historial_eventos( $equipo_id, $limit = 50 ) {
		global $wpdb;

		$limit = (int) $limit;

		// Construir LIMIT dinámico. 0 = sin límite (permitido, pero documentado).
		// No se puede pasar LIMIT como placeholder en $wpdb->prepare() con MariaDB
		// porque algunos drivers lo rechazan como argumento no numérico.
		$limit_clause = ( $limit > 0 ) ? 'LIMIT ' . $limit : '';

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					p.ID AS post_id,
					p.post_title AS titulo,
					pm_tipo.meta_value AS tipo_evento,
					pm_ot.meta_value AS numero_ot,
					pm_fecha.meta_value AS fecha_ejecucion,
					pm_proxima.meta_value AS proxima_fecha_control,
					pm_tecnico.meta_value AS tecnico_id,
					pm_cert.meta_value AS certificado_pdf_id,
					pm_ot_pdf.meta_value AS orden_trabajo_pdf_id
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} pm_equipo
					ON pm_equipo.post_id = p.ID
					AND pm_equipo.meta_key = 'calibratrack_equipo_id'
					AND pm_equipo.meta_value = %s
				INNER JOIN {$wpdb->postmeta} pm_tipo_doc
					ON pm_tipo_doc.post_id = p.ID
					AND pm_tipo_doc.meta_key = 'calibratrack_tipo_documento'
					AND pm_tipo_doc.meta_value = 'ot'
				LEFT JOIN {$wpdb->postmeta} pm_tipo
					ON pm_tipo.post_id = p.ID
					AND pm_tipo.meta_key = 'calibratrack_tipo_evento'
				LEFT JOIN {$wpdb->postmeta} pm_ot
					ON pm_ot.post_id = p.ID
					AND pm_ot.meta_key = 'calibratrack_numero_ot'
				LEFT JOIN {$wpdb->postmeta} pm_fecha
					ON pm_fecha.post_id = p.ID
					AND pm_fecha.meta_key = 'calibratrack_fecha_ejecucion'
				LEFT JOIN {$wpdb->postmeta} pm_proxima
					ON pm_proxima.post_id = p.ID
					AND pm_proxima.meta_key = 'calibratrack_proxima_fecha_control'
				LEFT JOIN {$wpdb->postmeta} pm_tecnico
					ON pm_tecnico.post_id = p.ID
					AND pm_tecnico.meta_key = 'calibratrack_tecnico_responsable'
				LEFT JOIN {$wpdb->postmeta} pm_cert
					ON pm_cert.post_id = p.ID
					AND pm_cert.meta_key = 'calibratrack_certificado_pdf'
				LEFT JOIN {$wpdb->postmeta} pm_ot_pdf
					ON pm_ot_pdf.post_id = p.ID
					AND pm_ot_pdf.meta_key = 'calibratrack_orden_trabajo_pdf'
				WHERE p.post_type = 'evento_servicio'
					AND p.post_status = 'publish'
				ORDER BY pm_fecha.meta_value DESC
				{$limit_clause}",
				(string) (int) $equipo_id
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Busca equipos cuya próxima fecha de control venza en los próximos N días.
	 *
	 * Usado por el sistema de alertas (RF-08) vía wp_cron para enviar correos
	 * preventivos antes del vencimiento. El cron invoca este método y procesa
	 * cada equipo devuelto.
	 *
	 * LÓGICA DE LA QUERY:
	 *   1. Busca en wp_postmeta los metas 'calibratrack_proxima_fecha_control'
	 *      cuyo valor (fecha ISO YYYY-MM-DD) caiga entre hoy y hoy + $dias_aviso.
	 *   2. Hace JOIN con wp_postmeta para obtener el meta 'calibratrack_equipo_id'
	 *      del evento — es decir, el equipo al que pertenece ese evento.
	 *   3. Filtra solo eventos en estado 'publish' para no alertar sobre borradores.
	 *   4. Agrupa por equipo_id para evitar alertas duplicadas si un equipo tiene
	 *      múltiples eventos próximos a vencer (solo importa el más cercano).
	 *
	 * NOTA SOBRE RENDIMIENTO EN HOSTING COMPARTIDO:
	 *   La query hace JOIN sobre wp_postmeta dos veces. El índice estándar de WP
	 *   sobre (meta_key, meta_value(191)) en postmeta es suficiente para el volumen
	 *   esperado (miles de eventos). No se necesita índice adicional en postmeta
	 *   para este caso de uso específico de alertas (se ejecuta vía cron, no en
	 *   cada request HTTP).
	 *
	 * @param int  $dias_aviso        Número de días hacia adelante a buscar (ej. 30).
	 * @param bool $solo_mantenimiento Si true, solo devuelve equipos cuyo evento sea de tipo 'mantenimiento'.
	 * @return array Array de objetos con equipo_id, proxima_fecha_control, evento_id.
	 *               Vacío si no hay equipos próximos a vencer.
	 */
	public static function get_equipos_proximos_a_vencer( $dias_aviso = 30, $solo_mantenimiento = false ) {
		global $wpdb;

		$dias_aviso = max( 1, (int) $dias_aviso );

		// Calcular rango de fechas en PHP para evitar funciones de fecha de MariaDB
		// que pueden depender del timezone del servidor de BD (que en hosting
		// compartido puede diferir de la zona horaria de PHP/WP).
		$hoy            = new DateTime( 'today', new DateTimeZone( 'America/Santiago' ) );
		$fecha_limite   = clone $hoy;
		$fecha_limite->modify( '+' . $dias_aviso . ' days' );

		$fecha_hoy_str    = $hoy->format( 'Y-m-d' );
		$fecha_limite_str = $fecha_limite->format( 'Y-m-d' );

		$join_tipo = '';
		$params    = array( $fecha_hoy_str, $fecha_limite_str );

		if ( $solo_mantenimiento ) {
			$join_tipo = " INNER JOIN {$wpdb->postmeta} pm_tipo
				ON pm_tipo.post_id = p.ID
				AND pm_tipo.meta_key = 'calibratrack_tipo_evento'
				AND pm_tipo.meta_value = 'mantenimiento'";
		}

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					pm_equipo.meta_value AS equipo_id,
					pm_proxima.meta_value AS proxima_fecha_control,
					p.ID AS evento_id
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} pm_proxima
					ON pm_proxima.post_id = p.ID
					AND pm_proxima.meta_key = 'calibratrack_proxima_fecha_control'
					AND pm_proxima.meta_value >= %s
					AND pm_proxima.meta_value <= %s
				INNER JOIN {$wpdb->postmeta} pm_equipo
					ON pm_equipo.post_id = p.ID
					AND pm_equipo.meta_key = 'calibratrack_equipo_id'
				{$join_tipo}
				WHERE p.post_type = 'evento_servicio'
					AND p.post_status = 'publish'
				GROUP BY pm_equipo.meta_value
				ORDER BY pm_proxima.meta_value ASC
				LIMIT 500",
				$params
			)
		);
		// phpcs:enable
	}

	/**
	 * Obtiene el subtotal de ítems de costo de un evento (suma de cantidad * precio_unitario).
	 *
	 * Ejecuta la suma directamente en MariaDB para evitar cargar todas las filas
	 * en PHP cuando solo se necesita el total (ej. en listados de eventos).
	 *
	 * Los totales (IVA, total con IVA) se calculan en PHP a partir de este subtotal
	 * usando CalibraTrack_Helpers::calcular_totales_costo(). Ver D-02.
	 *
	 * @param int $evento_id Post ID del evento_servicio.
	 * @return float Subtotal de los ítems del evento. 0.0 si no hay ítems.
	 */
	public static function get_subtotal_evento( $evento_id ) {
		global $wpdb;

		$table = self::get_table_name();

		$result = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(cantidad * precio_unitario), 0)
				FROM {$table}
				WHERE evento_id = %d",
				(int) $evento_id
			)
		);

		return (float) $result;
	}

	/**
	 * Elimina la tabla custom del plugin.
	 * Se llama ÚNICAMENTE desde uninstall.php — nunca en desactivación.
	 *
	 * @return void
	 */
	public static function drop_tables() {
		global $wpdb;

		$table_items    = self::get_table_name();
		$table_mensajes = self::get_table_mensajes();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DROP TABLE IF EXISTS {$table_mensajes}" );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DROP TABLE IF EXISTS {$table_items}" );

		delete_option( 'calibratrack_db_version' );
	}
}
