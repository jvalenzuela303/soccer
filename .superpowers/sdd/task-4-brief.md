# Task 4: Fix OT List — `author` → `calibratrack_tecnico_responsable` + badges

## Context
CalibraTrack WordPress plugin. The technician OT list (dashboard.php tech section + lista-eventos.php) currently filters by `post_author`. Admins create OTs (so admin is the author), meaning technicians see zero OTs. Must switch to `calibratrack_tecnico_responsable` meta filter. Also update the tech section to show OT service state badges instead of equipment vigency badges, and update the filter dropdown for the 4 OT states.

## Files to Modify
- `calibratrack/templates/panel/dashboard.php` — technician section only (else block starting ~line 745)
- `calibratrack/templates/panel/lista-eventos.php`

## Global Constraints
- PHP 7.4: no `enum`, `match`, `?->`, constructor promotion, union types, named args
- WPCS: escape all HTML output
- `CalibraTrack_Meta_Keys::EVENTO_TIPO_DOCUMENTO` and `EVENTO_TECNICO_RESPONSABLE` are constants

## Changes in `dashboard.php`

### Change 1: Replace `author` filter in `$hay_filtros` branch (line 759-768)

Current code at lines 759-768:
```php
	if ( $hay_filtros ) {
		$query_args = array(
			'post_type'      => 'evento_servicio',
			'post_status'    => 'publish',
			'author'         => get_current_user_id(),
			'posts_per_page' => $per_page,
			'paged'          => $paged,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'meta_query'     => array( 'relation' => 'AND' ),
		);
```

Replace with (remove `author`, add meta_query with tipo_documento=ot and tecnico_responsable):
```php
	if ( $hay_filtros ) {
		$query_args = array(
			'post_type'      => 'evento_servicio',
			'post_status'    => 'publish',
			'posts_per_page' => $per_page,
			'paged'          => $paged,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'meta_query'     => array(
				'relation' => 'AND',
				array(
					'key'   => CalibraTrack_Meta_Keys::EVENTO_TIPO_DOCUMENTO,
					'value' => 'ot',
				),
				array(
					'key'     => CalibraTrack_Meta_Keys::EVENTO_TECNICO_RESPONSABLE,
					'value'   => get_current_user_id(),
					'compare' => '=',
					'type'    => 'NUMERIC',
				),
			),
		);
```

### Change 2: Replace state filter switch (lines 778-821) with OT state filter

Current code (lines 778-821) is a big switch/case block filtering by proxima_fecha_control dates. Replace the entire block:
```php
		if ( '' !== $filtro_estado ) {
			$hoy        = date( 'Y-m-d' );
			$hoy_plus30 = date( 'Y-m-d', strtotime( '+30 days' ) );
			switch ( $filtro_estado ) {
				case 'vigente':
					...
				case 'por_vencer':
					...
				case 'vencido':
					...
				case 'sin_evento':
					...
			}
		}
```

Replace with:
```php
		if ( '' !== $filtro_estado ) {
			$query_args['meta_query'][] = array(
				'key'     => CalibraTrack_Meta_Keys::EVENTO_ESTADO_SERVICIO,
				'value'   => $filtro_estado,
				'compare' => '=',
			);
		}
```

### Change 3: Replace `author` filter in else branch (lines 866-873)

Current code at lines 866-873:
```php
	} else {
		$eventos     = get_posts( array(
			'post_type'      => 'evento_servicio',
			'post_status'    => 'publish',
			'author'         => get_current_user_id(),
			'posts_per_page' => 10,
			'orderby'        => 'date',
			'order'          => 'DESC',
		) );
```

Replace with:
```php
	} else {
		$eventos     = get_posts( array(
			'post_type'      => 'evento_servicio',
			'post_status'    => 'publish',
			'posts_per_page' => 10,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'meta_query'     => array(
				'relation' => 'AND',
				array(
					'key'   => CalibraTrack_Meta_Keys::EVENTO_TIPO_DOCUMENTO,
					'value' => 'ot',
				),
				array(
					'key'     => CalibraTrack_Meta_Keys::EVENTO_TECNICO_RESPONSABLE,
					'value'   => get_current_user_id(),
					'compare' => '=',
					'type'    => 'NUMERIC',
				),
			),
		) );
```

### Change 4: Replace filter dropdown (lines 936-942)

Current code at lines 936-942:
```php
				<select name="estado" class="ct-select ct-filter-select">
					<option value=""><?php esc_html_e( 'Todos los estados', 'calibratrack' ); ?></option>
					<option value="vigente"    <?php selected( $filtro_estado, 'vigente' ); ?>><?php esc_html_e( 'Vigente', 'calibratrack' ); ?></option>
					<option value="por_vencer" <?php selected( $filtro_estado, 'por_vencer' ); ?>><?php esc_html_e( 'Por vencer', 'calibratrack' ); ?></option>
					<option value="vencido"    <?php selected( $filtro_estado, 'vencido' ); ?>><?php esc_html_e( 'Vencido', 'calibratrack' ); ?></option>
					<option value="sin_evento" <?php selected( $filtro_estado, 'sin_evento' ); ?>><?php esc_html_e( 'Sin fecha', 'calibratrack' ); ?></option>
				</select>
```

Replace with:
```php
				<select name="estado" class="ct-select ct-filter-select">
					<option value=""><?php esc_html_e( 'Todos los estados', 'calibratrack' ); ?></option>
					<?php foreach ( CalibraTrack_Helpers::get_estados_servicio() as $slug => $cfg ) : ?>
						<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $filtro_estado, $slug ); ?>>
							<?php echo esc_html( $cfg['label'] ); ?>
						</option>
					<?php endforeach; ?>
				</select>
```

### Change 5: Replace badge generation in foreach loop (lines 982-989)

Current code at lines 982-989:
```php
							$estado      = $proxima ? CalibraTrack_Helpers::calcular_estado_vigencia( $proxima ) : 'sin_evento';
							$estados_cfg = array(
								'vigente'    => array( 'clase' => 'ct-badge--vigente',    'label' => __( 'Vigente', 'calibratrack' ) ),
								'por_vencer' => array( 'clase' => 'ct-badge--por-vencer', 'label' => __( 'Por vencer', 'calibratrack' ) ),
								'vencido'    => array( 'clase' => 'ct-badge--vencido',    'label' => __( 'Vencido', 'calibratrack' ) ),
								'sin_evento' => array( 'clase' => 'ct-badge--sin-evento', 'label' => __( 'Sin fecha', 'calibratrack' ) ),
							);
							$estado_info = isset( $estados_cfg[ $estado ] ) ? $estados_cfg[ $estado ] : $estados_cfg['sin_evento'];
```

Replace with:
```php
							$estado_srv     = (string) get_post_meta( $ev->ID, CalibraTrack_Meta_Keys::EVENTO_ESTADO_SERVICIO, true );
							if ( empty( $estado_srv ) ) { $estado_srv = 'en_proceso'; }
							$estados_srv_cfg = CalibraTrack_Helpers::get_estados_servicio();
							$estado_info    = isset( $estados_srv_cfg[ $estado_srv ] ) ? $estados_srv_cfg[ $estado_srv ] : $estados_srv_cfg['en_proceso'];
```

**Note:** The variable after this change is still `$estado_info` (used in the HTML below), but `$proxima` is still fetched at line 977 — leave that line alone even though it's no longer used for the badge. There may be a linting warning but no functional issue.

## Changes in `lista-eventos.php`

### Change 6: Replace `author` filter (line 117)

Current code at lines 115-118:
```php
// El técnico solo ve sus propios eventos; el admin ve todos.
if ( ! $es_admin ) {
	$query_args['author'] = get_current_user_id();
}
```

Replace with:
```php
// El técnico solo ve OTs donde es el responsable asignado.
if ( ! $es_admin ) {
	$query_args['meta_query'][] = array(
		'key'     => CalibraTrack_Meta_Keys::EVENTO_TECNICO_RESPONSABLE,
		'value'   => get_current_user_id(),
		'compare' => '=',
		'type'    => 'NUMERIC',
	);
}
```

### Change 7: Replace `$estados_servicio_cfg` array (lines 135-138)

Current code at lines 135-138:
```php
$estados_servicio_cfg = array(
	'en_proceso' => array( 'clase' => 'ct-badge--por-vencer', 'label' => __( 'En proceso', 'calibratrack' ) ),
	'completado' => array( 'clase' => 'ct-badge--vigente',    'label' => __( 'Completado', 'calibratrack' ) ),
);
```

Replace with:
```php
$estados_servicio_cfg = CalibraTrack_Helpers::get_estados_servicio();
```

### Change 8: Replace `$estados_servicio_cfg` array in `dashboard.php` admin section (lines 188-191)

Current code at lines 188-191:
```php
	$estados_servicio_cfg = array(
		'en_proceso' => array( 'clase' => 'ct-badge--por-vencer', 'label' => __( 'En proceso', 'calibratrack' ) ),
		'completado' => array( 'clase' => 'ct-badge--vigente',    'label' => __( 'Completado', 'calibratrack' ) ),
	);
```

Replace with:
```php
	$estados_servicio_cfg = CalibraTrack_Helpers::get_estados_servicio();
```

## Verification
Read back the modified sections and confirm:
1. Both `author` filters removed from dashboard.php tech section; replaced with meta_query
2. State filter switch replaced with simple OT state meta_query
3. Filter dropdown loops over `get_estados_servicio()` (4 options)
4. Badge uses `EVENTO_ESTADO_SERVICIO` meta, not `calcular_estado_vigencia()`
5. lista-eventos.php `author` filter replaced with meta_query
6. Both `$estados_servicio_cfg` arrays (dashboard.php line 188 and lista-eventos.php line 135) replaced with one-liner calling `get_estados_servicio()`
