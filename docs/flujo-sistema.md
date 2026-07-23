# Flujo del sistema CalibraTrack

## Diagrama de flujo OI → OT → Certificado

```mermaid
flowchart TD
    %% ── ACTORES ──────────────────────────────────────────────
    ADMIN(["👤 Admin"])
    TECNICO(["🔧 Técnico"])
    CLIENTE(["🏢 Cliente"])

    %% ── PASO 1: Orden de Ingreso ─────────────────────────────
    ADMIN -->|"Ingresa el equipo\ncon falla reportada"| OI["📋 Crear Orden de Ingreso\n/panel/nueva-oi/"]
    OI --> OI_GUARDADA[("💾 OI guardada\nen BD")]
    OI_GUARDADA -->|"📧 Email 1\nN° OI · equipo · falla\nSIN montos"| TECNICO

    %% ── PASO 2: Orden de Trabajo ─────────────────────────────
    OI_GUARDADA --> OT["📄 Crear Orden de Trabajo\n/panel/nueva-ot/?ct_oi_id=..."]
    OT -->|"OI pre-seleccionada\nautomáticamente"| OT_FORM["Completa:\n· N° OT\n· Fecha ejecución\n· Próx. control\n· Ítems de costo 💰"]
    OT_FORM --> OT_GUARDADA[("💾 OT guardada\nestado: en_proceso")]
    OT_GUARDADA -->|"📧 Email 2\nOT PDF adjunta\ncon montos"| CLIENTE
    OT_GUARDADA -->|"📧 Email 3\nN° OT · OI vinculada\nSIN montos 🔒"| TECNICO

    %% ── PASO 3: Técnico trabaja ──────────────────────────────
    TECNICO -->|"Accede a\n/panel/evento/{id}/"| PANEL_TEC["🖥️ Panel técnico\nVe: equipo, descripción,\nevid. fotográfica, estado\nNO VE precios 🔒"]
    PANEL_TEC --> TEC_TRABAJO["Realiza el servicio\nAgrega:\n· Descripción del trabajo\n· Observaciones\n· Fotos de evidencia"]
    TEC_TRABAJO --> TEC_ESTADO{{"Elige estado"}}

    TEC_ESTADO -->|"En proceso"| TEC_GUARDAR_PARCIAL["💾 Guarda avance\nsin notificación"]
    TEC_GUARDAR_PARCIAL --> TEC_ESTADO

    TEC_ESTADO -->|"Listo para revisión"| LISTO["💾 Estado actualizado:\nlisto_revision"]
    LISTO -->|"📧 Email 4\nOT lista · acción requerida"| ADMIN

    %% ── PASO 4: Admin revisa y completa ──────────────────────
    ADMIN -->|"Accede a\n/panel/ot/{id}/"| PANEL_ADMIN["🖥️ Panel admin\nVe todo: costos,\ndescripción técnica,\nestado actual"]
    PANEL_ADMIN --> ADMIN_REVISION["Revisa el trabajo\ndel técnico"]
    ADMIN_REVISION --> ADMIN_ESTADO{{"¿Aprueba?"}}

    ADMIN_ESTADO -->|"Solicita correcciones\n(mensaje en OT)"| MSG["💬 Mensaje al técnico"]
    MSG -->|"📧 Email\nnotificación"| TECNICO
    TECNICO --> PANEL_TEC

    ADMIN_ESTADO -->|"Completado"| COMPLETADO["💾 Estado: completado\nGenera certificado PDF 📄\nGenera QR de verificación"]
    COMPLETADO -->|"📧 Email 5\nCertificado PDF adjunto\n+ QR de verificación"| CLIENTE

    %% ── PASO 5: Verificación pública ─────────────────────────
    COMPLETADO --> PUB["🌐 Página pública\n/verificar/{serie}/\nEquipo: Vigente ✅"]
    CLIENTE -->|"Escanea QR\no busca por serie"| PUB

    %% ── ESTILOS ───────────────────────────────────────────────
    classDef actor fill:#dbeafe,stroke:#3b82f6,color:#1e3a8a,font-weight:bold
    classDef store fill:#d1fae5,stroke:#10b981,color:#065f46
    classDef email fill:#fef9c3,stroke:#ca8a04,color:#713f12
    classDef panel fill:#f3e8ff,stroke:#9333ea,color:#581c87
    classDef decision fill:#fff7ed,stroke:#ea580c,color:#7c2d12

    class ADMIN,TECNICO,CLIENTE actor
    class OI_GUARDADA,OT_GUARDADA,LISTO,COMPLETADO,TEC_GUARDAR_PARCIAL store
    class PUB panel
```

---

## Resumen de notificaciones por email

| # | Disparado por | Destinatario | Contenido | Montos |
|---|--------------|-------------|-----------|--------|
| 1 | Admin crea OI | **Técnico** | N° OI, equipo, serie, tipo servicio, falla reportada, link a OI | ❌ No |
| 2 | Admin crea OT | **Cliente** | OT PDF adjunta, detalle del servicio | ✅ Sí |
| 3 | Admin crea OT | **Técnico** | N° OT, OI vinculada, equipo, fechas, falla | ❌ No |
| 4 | Técnico → Listo para revisión | **Admin** | N° OT, técnico, equipo, link para completar | ❌ No |
| 5 | Admin → Completado | **Cliente** | Certificado PDF adjunto, QR verificación | ❌ No |

> **Mensajería interna** (chat en OT): cuando admin o técnico escriben un mensaje en la OT, el otro recibe notificación por email.

---

## Roles y accesos

| Pantalla / URL | Admin | Técnico |
|----------------|-------|---------|
| `/panel/nueva-oi/` | ✅ Crear | ❌ |
| `/panel/oi/{id}/` | ✅ Editar | 👁️ Solo lectura |
| `/panel/nueva-ot/` | ✅ Crear | ❌ |
| `/panel/ot/{id}/` | ✅ Editar completo + precios | ❌ Redirige |
| `/panel/evento/{id}/` | ✅ (redirige a /ot/) | ✅ Editar sin precios |
| `/panel/equipos/` | ✅ | ✅ Solo sus equipos |
| `/panel/eventos/` | ✅ Todos | ✅ Solo sus OTs |
| `/verificar/{serie}/` | 🌐 Público | 🌐 Público |
| `/wp-admin/` | ✅ | ❌ Bloqueado |

---

## Estados de una OT

```
en_proceso → en_ejecucion → listo_revision → completado
   ↑_____________↑_______________↑
         (el técnico puede retroceder)

Solo el ADMIN puede pasar a → completado
```
