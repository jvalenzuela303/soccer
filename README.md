# CalibraTrack — Plugin de WordPress

Plugin de trazabilidad y verificación pública de calibraciones y mantenciones de equipos de fibra óptica para **TrueTech SpA**.

## ¿Qué hace?

Reemplaza el flujo manual en Word para registrar y certificar servicios técnicos sobre equipos como OTDR, power meters, fuentes de luz, empalmadoras de fusión y certificadores de red.

### Flujo principal

1. **Admin crea Orden de Ingreso (OI)** → el técnico recibe notificación por correo
2. **Admin crea Orden de Trabajo (OT)** vinculada a la OI → el cliente recibe notificación
3. **Técnico realiza el servicio** y marca la OT como "Listo para revisión" → el admin recibe alerta
4. **Admin marca la OT como "Completado"** → se genera automáticamente el certificado PDF y se envía al cliente con evidencia fotográfica adjunta

### Verificación pública

Cualquier tercero puede ingresar a `/verificar/{serie}/` en el sitio web para consultar el historial de calibraciones de un equipo y descargar el certificado PDF, sin necesidad de cuenta.

## Funcionalidades

- Gestión de clientes y equipos desde el panel de WordPress (`/wp-admin/`)
- Panel frontend para técnicos y administradores (`/panel/`)
- Roles diferenciados: administrador y técnico (sin acceso a precios ni a registros de otros técnicos)
- Generación automática de certificados PDF al completar una OT
- Notificaciones por correo en cada etapa del flujo (OI → OT → revisión → completado)
- Impresión de OI y OT desde el listado del panel
- Página pública de verificación por número de serie
- Liquidación de pagos a técnicos con estado de pago y número de factura
- Recordatorios automáticos de próximo control para equipos en mantenimiento

## Stack técnico

- **WordPress 6.8.5** + PHP 7.4.33
- **MariaDB 10.6** (hosting compartido cPanel/CloudLinux)
- Librerías: FPDF (PDF), endroid/qr-code (QR), PHPMailer (SMTP)
- Entorno local: Docker (ver `docker-compose.yml`)

## Versiones

| Versión | Descripción |
|---------|-------------|
| 1.1.0   | Impresión de OI y OT desde el panel principal |
| 1.0.9   | Adjuntos en certificado: fotos + docs + QR |
| 1.0.8   | Liquidación técnicos, notificación OI al cliente |
| 1.0.7   | Flujo OI→OT, notificaciones por correo, descarga pública de certificado |

## Instalación local

```bash
docker-compose up -d
```

Luego instalar el plugin desde `/wp-admin/plugins.php` subiendo el zip de la versión correspondiente.
# soccer
# soccer
