# Subagentes de Claude Code — plugin CalibraTrack

Este paquete trae 6 subagentes especializados para desarrollar el plugin de WordPress con
Claude Code, más un `CLAUDE.md` de contexto compartido.

## Instalación

1. Copia `CLAUDE.md` a la raíz del repositorio del plugin.
2. Copia la carpeta `agents/` dentro de `.claude/agents/` en ese mismo repositorio
   (crea `.claude/` si no existe):
   ```
   tu-repo/
     CLAUDE.md
     .claude/
       agents/
         wordpress-plugin-architect.md
         php-backend-developer.md
         frontend-verification-developer.md
         qr-document-generator.md
         security-auditor.md
         qa-testing-agent.md
   ```
3. Copia también tu especificación técnica a `tu-repo/docs/especificacion-tecnica-trazabilidad.md`
   — el `CLAUDE.md` la referencia como fuente de verdad, así que debe existir en esa ruta
   (o ajusta la ruta en `CLAUDE.md` si la guardas en otro lugar).
4. Abre Claude Code en la raíz del repo. Los subagentes quedan disponibles automáticamente；
   Claude los invoca solo cuando la tarea calza con su descripción, o puedes pedirlo
   explícito: "usa el agente security-auditor para revisar la subida de archivos".

## Los 6 agentes y para qué sirve cada uno

| Agente | Cuándo se usa |
|---|---|
| `wordpress-plugin-architect` | Al empezar, y cada vez que haya que decidir estructura (CPTs, roles, dónde va cada cosa) |
| `database-schema-agent` | Diseño de tablas custom, migraciones con `dbDelta()`, índices, optimización de queries `$wpdb` sobre MariaDB 10.6.25 |
| `php-backend-developer` | Lógica de negocio: formularios, cálculos, endpoint de verificación, permisos |
| `frontend-verification-developer` | Página pública de verificación y pantallas del panel técnico |
| `qr-document-generator` | Generación de QR y, si aplica, generación automática de PDF |
| `security-auditor` | Revisión de uploads, permisos y el endpoint público — antes de cerrar cualquier feature sensible |
| `qa-testing-agent` | Verificar los criterios de aceptación de la especificación y compatibilidad PHP 7.4 |

## Orden de trabajo sugerido (fase 1 / MVP)

1. `wordpress-plugin-architect` — define estructura, CPTs, roles.
2. `database-schema-agent` — junto con el paso anterior, diseña las tablas custom que se
   necesiten (ej. `items_costo`) y sus índices sobre MariaDB 10.6.25.
3. `php-backend-developer` — implementa CPTs, formularios, cálculos, endpoint REST.
4. `frontend-verification-developer` — construye la página pública y el panel técnico
   sobre esos datos.
5. `qr-document-generator` — agrega generación de QR (y evalúa PDF automático si se decide).
6. `security-auditor` — audita todo lo anterior contra el checklist de §9 de la especificación.
7. `qa-testing-agent` — verifica los criterios de aceptación de §13 antes de dar por
   cerrada la fase.

No es un orden estrictamente secuencial — en la práctica vas y vuelves entre agentes a
medida que aparecen dudas, pero conviene no saltarse el paso 5 antes de mostrarle el
verificador público a un cliente real.

## Nota

Estos agentes asumen **WordPress 6.8.5, PHP 7.4.33 y MariaDB 10.6.25 (hosting compartido
cPanel/CloudLinux)** como entorno objetivo — está escrito explícitamente en `CLAUDE.md`
para que ningún agente proponga sintaxis de PHP 8+, features de servidor no disponibles en
hosting compartido, o dependa de privilegios de base de datos que probablemente no tengas.
Si en algún momento cambias de hosting o actualizas versiones, hay que actualizar esa
sección antes de seguir desarrollando, o vas a terminar con restricciones que ya no aplican.
