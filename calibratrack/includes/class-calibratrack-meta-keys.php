<?php
/**
 * Fuente única de todas las meta keys usadas en el plugin.
 *
 * REGLA ARQUITECTÓNICA: Ningún otro archivo del plugin debe definir o
 * concatenar strings de meta keys. Toda referencia a un meta key debe
 * hacerse a través de las constantes de esta clase.
 *
 * Esto evita errores de tipeo, facilita refactoring y garantiza que el
 * nombre de cada campo salga de un único lugar.
 *
 * @package CalibraTrack
 */

// Seguridad: bloquear acceso directo.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CalibraTrack_Meta_Keys
 *
 * Constantes de meta keys para los tres CPTs del plugin.
 * Prefijo: calibratrack_ en todas las keys.
 */
class CalibraTrack_Meta_Keys {

	// ─── CPT: equipo ───────────────────────────────────────────────────────────

	/**
	 * Número de serie del equipo (único en el sistema).
	 * Tipo: string. Indexado para búsqueda rápida.
	 */
	const EQUIPO_SERIE = 'calibratrack_serie';

	/**
	 * Marca del equipo (ej. Grandway, EXFO, Fluke Networks, Fujikura).
	 * Tipo: string.
	 */
	const EQUIPO_MARCA = 'calibratrack_marca';

	/**
	 * Modelo del equipo (ej. GS-401).
	 * Tipo: string.
	 */
	const EQUIPO_MODELO = 'calibratrack_modelo';

	/**
	 * Tipo de equipo. Valores válidos definidos en CalibraTrack_Helpers::get_tipos_equipo().
	 * Tipo: string (slug). NO usar enum PHP — ver restricciones PHP 7.4.
	 */
	const EQUIPO_TIPO = 'calibratrack_tipo_equipo';

	/**
	 * ID del post (CPT cliente) propietario del equipo.
	 * Tipo: integer (post ID).
	 */
	const EQUIPO_CLIENTE_PROPIETARIO = 'calibratrack_cliente_propietario';

	/**
	 * Fecha de ingreso del equipo al sistema (YYYY-MM-DD).
	 * Tipo: string (fecha ISO 8601).
	 */
	const EQUIPO_FECHA_INGRESO = 'calibratrack_fecha_ingreso_sistema';

	/**
	 * ID del attachment WP del código QR generado.
	 * Tipo: integer (attachment ID).
	 */
	const EQUIPO_CODIGO_QR = 'calibratrack_codigo_qr';

	/**
	 * Token de verificación pública del equipo (UUID v4).
	 * Generado automáticamente al crear el equipo. Se usa en la URL del QR y del correo
	 * para que el enlace de verificación no sea predecible por enumeración de series.
	 * Tipo: string (UUID v4, formato xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx).
	 */
	const EQUIPO_VERIFICACION_TOKEN = 'calibratrack_verificacion_token';

	/**
	 * Estado calculado del equipo (vigente / por_vencer / vencido).
	 * NOTA: Este campo NO se almacena permanentemente — se calcula on-the-fly
	 * desde proxima_fecha_control del evento más reciente.
	 * Si se necesita cache, usar transients con TTL de 1 hora máximo.
	 * Tipo: string.
	 */
	const EQUIPO_ESTADO_CACHE = 'calibratrack_estado_cache';

	// ─── CPT: cliente ──────────────────────────────────────────────────────────

	/**
	 * Nombre de la empresa cliente.
	 * Tipo: string.
	 */
	const CLIENTE_NOMBRE_EMPRESA = 'calibratrack_nombre_empresa';

	/**
	 * RUT de la empresa (formato chileno: XX.XXX.XXX-Y).
	 * CAMPO PRIVADO — nunca exponer en respuestas públicas.
	 * Tipo: string.
	 */
	const CLIENTE_RUT = 'calibratrack_rut';

	/**
	 * Nombre de la persona de contacto.
	 * CAMPO PRIVADO — nunca exponer en respuestas públicas.
	 * Tipo: string.
	 */
	const CLIENTE_CONTACTO_NOMBRE = 'calibratrack_contacto_nombre';

	/**
	 * Teléfono de contacto.
	 * CAMPO PRIVADO — nunca exponer en respuestas públicas.
	 * Tipo: string.
	 */
	const CLIENTE_TELEFONO = 'calibratrack_telefono';

	/**
	 * Correo electrónico de contacto.
	 * CAMPO PRIVADO — nunca exponer en respuestas públicas.
	 * Tipo: string.
	 */
	const CLIENTE_CORREO = 'calibratrack_correo';

	/**
	 * Dirección física del cliente.
	 * CAMPO PRIVADO — nunca exponer en respuestas públicas.
	 * Tipo: string.
	 */
	const CLIENTE_DIRECCION = 'calibratrack_direccion';

	// ─── CPT: evento_servicio ──────────────────────────────────────────────────

	/**
	 * ID del post (CPT equipo) al que pertenece este evento.
	 * Tipo: integer (post ID).
	 */
	const EVENTO_EQUIPO_ID = 'calibratrack_equipo_id';

	/**
	 * Número/folio de la orden de trabajo (ej. "OT96").
	 * Tipo: string.
	 */
	const EVENTO_NUMERO_OT = 'calibratrack_numero_ot';

	/**
	 * Tipo de evento. Valores válidos definidos en CalibraTrack_Helpers::get_tipos_evento().
	 * Tipo: string (slug). NO usar enum PHP — ver restricciones PHP 7.4.
	 */
	const EVENTO_TIPO = 'calibratrack_tipo_evento';

	/**
	 * Fecha de ejecución del servicio (YYYY-MM-DD).
	 * Tipo: string (fecha ISO 8601).
	 */
	const EVENTO_FECHA_EJECUCION = 'calibratrack_fecha_ejecucion';

	/**
	 * Próxima fecha de control/calibración (YYYY-MM-DD).
	 * Usada para calcular el estado de vigencia del equipo.
	 * Tipo: string (fecha ISO 8601).
	 */
	const EVENTO_PROXIMA_FECHA_CONTROL = 'calibratrack_proxima_fecha_control';

	/**
	 * ID del usuario WP responsable (técnico que ejecutó el servicio).
	 * Tipo: integer (user ID).
	 */
	const EVENTO_TECNICO_RESPONSABLE = 'calibratrack_tecnico_responsable';

	/**
	 * Falla reportada por el cliente al ingresar el equipo.
	 * Tipo: string (texto largo).
	 */
	const EVENTO_FALLA_REPORTADA = 'calibratrack_falla_reportada';

	/**
	 * Descripción del trabajo realizado / solución aplicada.
	 * Tipo: string (texto largo).
	 */
	const EVENTO_DESCRIPCION_TRABAJO = 'calibratrack_descripcion_trabajo';

	/**
	 * Observaciones adicionales, hallazgos, recomendaciones.
	 * Tipo: string (texto largo).
	 */
	const EVENTO_OBSERVACIONES = 'calibratrack_observaciones';

	/**
	 * IDs de attachments WP de la evidencia fotográfica (JSON array de integers).
	 * Almacenado como JSON en postmeta para mantener orden y evitar tabla extra.
	 * Tipo: string (JSON). Decodificar con json_decode() al usar.
	 */
	const EVENTO_EVIDENCIA_FOTOGRAFICA = 'calibratrack_evidencia_fotografica';

	/**
	 * IDs de attachments WP de documentos adjuntos al trabajo (PDF, etc).
	 * Subidos por el técnico para avalar el trabajo realizado.
	 * Tipo: string (JSON array de integers). Decodificar con json_decode() al usar.
	 */
	const EVENTO_DOCUMENTOS_ADJUNTOS = 'calibratrack_documentos_adjuntos';

	/**
	 * Indica si el servicio tiene garantía (1 = sí, 0 = no).
	 * Tipo: integer (boolean-like).
	 */
	const EVENTO_GARANTIA = 'calibratrack_garantia';

	/**
	 * Días de garantía (solo relevante si EVENTO_GARANTIA = 1).
	 * Tipo: integer.
	 */
	const EVENTO_DIAS_GARANTIA = 'calibratrack_dias_garantia';

	/**
	 * Estado del servicio. Controla el flujo de dos correos al cliente.
	 * Valores: 'en_proceso' (por defecto al crear) | 'completado' (al finalizar).
	 * Al crear → Email 1 con OT. Al marcar completado → Email 2 con certificado + QR.
	 * Tipo: string.
	 */
	const EVENTO_ESTADO_SERVICIO = 'calibratrack_estado_servicio';

	/**
	 * ID del attachment WP del certificado PDF.
	 * El nombre del archivo debe ser no adivinable (hash/UUID) — ver §9 de la especificación.
	 * Tipo: integer (attachment ID).
	 */
	const EVENTO_CERTIFICADO_PDF = 'calibratrack_certificado_pdf';

	/**
	 * ID del attachment WP de la orden de trabajo PDF.
	 * El nombre del archivo debe ser no adivinable (hash/UUID) — ver §9 de la especificación.
	 * Tipo: integer (attachment ID).
	 */
	const EVENTO_ORDEN_TRABAJO_PDF = 'calibratrack_orden_trabajo_pdf';

	/**
	 * Tipo de documento del evento: 'ingreso' (Orden de Ingreso) o 'ot' (Orden de Trabajo).
	 * Valor por defecto implícito: 'ot' — garantiza compatibilidad con eventos existentes.
	 * Tipo: string.
	 */
	const EVENTO_TIPO_DOCUMENTO = 'calibratrack_tipo_documento';

	/**
	 * Número correlativo de la Orden de Ingreso. Formato: OI-{AÑO}-{NNN}.
	 * Solo aplica cuando EVENTO_TIPO_DOCUMENTO = 'ingreso'.
	 * Tipo: string (ej. 'OI-2026-001').
	 */
	const EVENTO_NUMERO_OI = 'calibratrack_numero_oi';

	/**
	 * ID del post (evento_servicio tipo 'ingreso') al que está vinculada esta OT.
	 * Solo aplica cuando EVENTO_TIPO_DOCUMENTO = 'ot'.
	 * Tipo: integer (post ID). 0 si no tiene OI vinculada.
	 */
	const EVENTO_INGRESO_RELACIONADO_ID = 'calibratrack_ingreso_relacionado_id';

	// NOTA sobre items_costo:
	// Los ítems de costo NO se almacenan como postmeta porque son una lista de largo
	// variable con estructura compuesta (detalle, cantidad, precio_unitario). Se almacenan
	// en la tabla custom `{$wpdb->prefix}calibratrack_items_costo`, relacionada por
	// evento_id (post ID). Ver CalibraTrack_DB::create_tables() y docs/decisiones.md.
}
