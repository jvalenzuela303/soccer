<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<style>
* { margin: 0; padding: 0; box-sizing: border-box; }
body { font-family: helvetica, sans-serif; font-size: 10pt; color: #111; background: #fff; }

.banda-top    { background-color: <?php echo esc_attr( $color_primario ); ?>; height: 6px; width: 100%; }
.banda-bottom { background-color: <?php echo esc_attr( $color_primario ); ?>; height: 6px; width: 100%; margin-top: 24px; }

.contenido { padding: 0 28px; }

/* Encabezado */
.header { padding: 14px 0 10px 0; }
.header-inner { display: table; width: 100%; }
.header-logo { display: table-cell; width: 40%; vertical-align: middle; }
.header-logo img { max-height: 55px; max-width: 160px; }
.header-logo-text { font-size: 20pt; font-weight: bold; color: <?php echo esc_attr( $color_primario ); ?>; }
.header-titulo { display: table-cell; width: 60%; vertical-align: middle; text-align: right; }
.header-titulo h1 { font-size: 15pt; font-weight: bold; color: <?php echo esc_attr( $color_primario ); ?>; text-transform: uppercase; letter-spacing: 0.05em; }

/* Info empresa + OT */
.info-top { display: table; width: 100%; border: 1px solid #ccc; margin-top: 10px; }
.info-empresa { display: table-cell; width: 60%; padding: 8px 12px; font-size: 9pt; line-height: 1.5; }
.info-ot { display: table-cell; width: 40%; padding: 8px 12px; font-size: 9pt; border-left: 1px solid #ccc; vertical-align: middle; }
.info-ot table { width: 100%; }
.info-ot td { padding: 3px 6px; }
.info-ot td:first-child { font-weight: bold; white-space: nowrap; }

/* Secciones */
.seccion { margin-top: 14px; }
.seccion-titulo { font-size: 9pt; font-weight: bold; text-transform: uppercase; letter-spacing: 0.08em; margin-bottom: 5px; color: #333; }
.tabla-datos { width: 100%; border-collapse: collapse; font-size: 9pt; }
.tabla-datos td { border: 1px solid #bbb; padding: 6px 10px; vertical-align: top; }
.tabla-datos td.label { font-weight: bold; width: 160px; background: #f8f8f8; white-space: nowrap; }
.tabla-datos th { border: 1px solid #bbb; padding: 6px 10px; background: #f0f0f0; font-weight: bold; text-align: left; font-size: 9pt; }

/* Cajas de texto */
.caja-texto { border: 1px solid #bbb; padding: 10px 12px; font-size: 9pt; min-height: 54px; white-space: pre-wrap; line-height: 1.6; }

/* Estado badge */
.estado-badge { display: inline-block; padding: 3px 10px; border-radius: 3px; font-size: 9pt; font-weight: bold; }
.estado-en-proceso { background: #fff3cd; color: #856404; border: 1px solid #ffc107; }

/* Firma */
.firma-section { margin-top: 28px; text-align: center; }
.firma-linea { display: inline-block; width: 200px; border-top: 1px solid #555; margin-bottom: 6px; }
.firma-img { max-height: 70px; max-width: 200px; display: block; margin: 0 auto 4px auto; }
.firma-nombre { font-size: 10pt; font-weight: bold; margin-top: 2px; }
.firma-cargo  { font-size: 9pt; color: #444; }
.firma-empresa { font-size: 9pt; color: #444; margin-bottom: 10px; }
.firma-logo-wrap { margin-top: 10px; }
.firma-logo-wrap img { max-height: 36px; }
.firma-logo-text { font-size: 12pt; font-weight: bold; color: <?php echo esc_attr( $color_primario ); ?>; }
.firma-datos { font-size: 8pt; color: #666; margin-top: 3px; }
</style>
</head>
<body>

<div class="banda-top"></div>

<div class="contenido">

<!-- Encabezado -->
<div class="header">
	<div class="header-inner">
		<div class="header-logo">
			<?php if ( ! empty( $logo_src ) ) : ?>
				<img src="<?php echo esc_attr( $logo_src ); ?>" alt="Logo">
			<?php else : ?>
				<span class="header-logo-text"><?php echo esc_html( $empresa_nombre ); ?></span>
			<?php endif; ?>
		</div>
		<div class="header-titulo">
			<h1><?php esc_html_e( 'ORDEN DE TRABAJO', 'calibratrack' ); ?></h1>
		</div>
	</div>
</div>

<!-- Info empresa + N° OT y fecha -->
<div class="info-top">
	<div class="info-empresa">
		<strong><?php echo esc_html( $empresa_nombre ); ?></strong><br>
		<?php if ( $empresa_rut ) : ?>RUT <?php echo esc_html( $empresa_rut ); ?><br><?php endif; ?>
		<?php if ( $empresa_direccion ) : ?><?php echo esc_html( $empresa_direccion ); ?><br><?php endif; ?>
		<?php if ( $empresa_telefono ) : ?>Mesa Central <?php echo esc_html( $empresa_telefono ); ?><?php endif; ?>
	</div>
	<div class="info-ot">
		<table>
			<tr>
				<td>ORDEN DE TRABAJO #</td>
				<td>:</td>
				<td><?php echo esc_html( $numero_ot ); ?></td>
			</tr>
			<tr>
				<td>FECHA</td>
				<td>:</td>
				<td><?php echo esc_html( $fecha ); ?></td>
			</tr>
			<?php if ( $proxima_fecha ) : ?>
			<tr>
				<td>PRÓX. CONTROL</td>
				<td>:</td>
				<td><?php echo esc_html( $proxima_fecha ); ?></td>
			</tr>
			<?php endif; ?>
		</table>
	</div>
</div>

<!-- Datos del cliente -->
<div class="seccion">
	<div class="seccion-titulo">Datos del cliente</div>
	<table class="tabla-datos">
		<tr><td class="label">NOMBRE/EMPRESA</td><td><?php echo esc_html( $cliente_nombre ); ?></td></tr>
		<tr><td class="label">RUT</td><td><?php echo esc_html( $cliente_rut ); ?></td></tr>
		<tr><td class="label">CONTACTO</td><td><?php echo esc_html( trim( $cliente_contacto . ( $cliente_telefono ? ' // ' . $cliente_telefono : '' ) ) ); ?></td></tr>
		<tr><td class="label">DIRECCIÓN</td><td><?php echo esc_html( $cliente_direccion ); ?></td></tr>
		<tr><td class="label">CORREO ELECTRÓNICO</td><td><?php echo esc_html( $cliente_correo ); ?></td></tr>
	</table>
</div>

<!-- Características del equipo -->
<div class="seccion">
	<div class="seccion-titulo">Características del equipo</div>
	<table class="tabla-datos">
		<thead>
			<tr>
				<th>MARCA</th>
				<th>MODELO</th>
				<th>SERIE</th>
				<th>TIPO DE SERVICIO</th>
			</tr>
		</thead>
		<tbody>
			<tr>
				<td><?php echo esc_html( mb_strtoupper( $equipo_marca, 'UTF-8' ) ); ?></td>
				<td><?php echo esc_html( mb_strtoupper( $equipo_modelo, 'UTF-8' ) ); ?></td>
				<td><?php echo esc_html( $equipo_serie ); ?></td>
				<td><?php echo esc_html( mb_strtoupper( $tipo_label, 'UTF-8' ) ); ?></td>
			</tr>
		</tbody>
	</table>
</div>

<!-- Falla reportada por el cliente -->
<div class="seccion">
	<div class="seccion-titulo">Falla o requerimiento reportado por el cliente</div>
	<div class="caja-texto"><?php echo esc_html( $falla_reportada ?: '—' ); ?></div>
</div>

<!-- Trabajo a realizar -->
<div class="seccion">
	<div class="seccion-titulo">Trabajo a realizar // Descripción del servicio</div>
	<div class="caja-texto"><?php
		$texto = trim( $descripcion_trabajo . ( $observaciones ? "\n" . $observaciones : '' ) );
		echo esc_html( $texto ?: '—' );
	?></div>
</div>

<!-- Firma del técnico -->
<div class="firma-section">
	<?php if ( ! empty( $firma_src ) ) : ?>
		<img src="<?php echo esc_attr( $firma_src ); ?>" alt="Firma" class="firma-img">
	<?php else : ?>
		<div class="firma-linea"></div>
	<?php endif; ?>
	<div class="firma-nombre"><?php echo esc_html( mb_strtoupper( $tecnico_nombre, 'UTF-8' ) ); ?></div>
	<div class="firma-cargo"><?php echo esc_html( $tecnico_cargo ); ?></div>
	<div class="firma-empresa"><?php echo esc_html( $empresa_nombre ); ?></div>
	<div class="firma-logo-wrap">
		<?php if ( ! empty( $logo_src ) ) : ?>
			<img src="<?php echo esc_attr( $logo_src ); ?>" alt="Logo">
		<?php else : ?>
			<span class="firma-logo-text"><?php echo esc_html( $empresa_nombre ); ?></span>
		<?php endif; ?>
		<div class="firma-datos">
			<?php if ( $empresa_rut ) : ?>RUT <?php echo esc_html( $empresa_rut ); ?><?php endif; ?>
			<?php if ( $empresa_rut && $empresa_telefono ) : ?> &nbsp;|&nbsp; <?php endif; ?>
			<?php if ( $empresa_telefono ) : ?>Tel: <?php echo esc_html( $empresa_telefono ); ?><?php endif; ?>
		</div>
	</div>
</div>

</div><!-- /contenido -->

<div class="banda-bottom"></div>

</body>
</html>
