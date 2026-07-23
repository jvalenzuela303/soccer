"""
Prueba de flujo completo CalibraTrack
OI → OT (en_proceso) → Técnico listo_revision → Admin completado

Correos de prueba (llegan al inbox jvalenzuela303@gmail.com):
  [1] OI creada          → técnico  → jvalenzuela303+tecnico@gmail.com
  [2] Técnico listo      → admin    → jvalenzuela303@gmail.com
  [3] OT completada      → cliente  → jvalenzuela303+cliente@gmail.com (+ certificado PDF)

Ejecutar:
  cd /home/jvalenzuela/Desarollo/truetech
  python tests/flujo_completo.py
"""

import os
import re
import sys
import time
from pathlib import Path
from playwright.sync_api import sync_playwright

BASE_URL    = "http://localhost:8088"
ADMIN_USER  = "admin"
ADMIN_PASS  = "admin"
TECNICO_USER = "tecnico1"
TECNICO_PASS = "ucfy5$^oCdc3ibKPj$NdhAO4"

EQUIPO_ID   = "34"   # Grandway GS-401 — GW-OTDR-2024-001
TECNICO_ID  = "2"    # tecnico1 → jvalenzuela303+tecnico@gmail.com
NUMERO_OT   = f"OT-PW-{int(time.time())}"  # único por ejecución

SS_DIR = Path("/home/jvalenzuela/Desarollo/truetech/tests/screenshots")
SS_DIR.mkdir(parents=True, exist_ok=True)

step = 0

def ss(page, label):
    global step
    step += 1
    path = SS_DIR / f"{step:02d}-{label}.png"
    page.screenshot(path=str(path), full_page=True)
    print(f"    📸 {path.name}")

def login(page, user, passwd):
    page.goto(f"{BASE_URL}/wp-login.php")
    page.fill("#user_login", user)
    page.fill("#user_pass", passwd)
    page.click("#wp-submit")
    # Espera cualquier redirección post-login (admin→wp-admin, técnico→panel)
    page.wait_for_load_state("networkidle", timeout=15000)

def logout(page):
    page.goto(f"{BASE_URL}/panel/salir/")
    page.wait_for_load_state("networkidle")

def run():
    with sync_playwright() as pw:
        browser = pw.chromium.launch(headless=False, slow_mo=200)
        ctx  = browser.new_context(viewport={"width": 1280, "height": 900})
        page = ctx.new_page()
        ot_id = None

        try:
            # ──────────────────────────────────────────────────────────
            # PASO 1: Admin — Login
            # ──────────────────────────────────────────────────────────
            print("\n[1] Admin: Login…")
            login(page, ADMIN_USER, ADMIN_PASS)
            print("    ✓ Admin autenticado")
            ss(page, "01-admin-login")

            # ──────────────────────────────────────────────────────────
            # PASO 2: Admin — Crear Orden de Ingreso (OI)
            # ──────────────────────────────────────────────────────────
            print("\n[2] Admin: Crear OI…")
            page.goto(f"{BASE_URL}/panel/nueva-oi/")
            page.wait_for_load_state("networkidle")

            page.select_option('select[name="equipo_id"]', value=EQUIPO_ID)
            page.select_option('select[name="tecnico_id"]', value=TECNICO_ID)
            page.select_option('select[name="tipo"]', value="calibracion")
            page.fill('input[name="fecha_ejecucion"]', "2026-07-17")
            page.fill('textarea[name="falla_reportada"]',
                      "Prueba Playwright — equipo con lecturas inconsistentes de atenuación.")

            ss(page, "02-form-oi")
            page.click('button[type="submit"]')
            page.wait_for_load_state("networkidle")
            page.wait_for_timeout(1000)
            ss(page, "03-oi-guardada")

            url_oi = page.url
            m = re.search(r'/oi/(\d+)/', url_oi)
            oi_id = m.group(1) if m else None
            print(f"    ✓ OI creada — ID: {oi_id} → email enviado al técnico")

            # ──────────────────────────────────────────────────────────
            # PASO 3: Admin — Crear OT vinculada (estado: en_proceso)
            # ──────────────────────────────────────────────────────────
            print("\n[3] Admin: Crear OT (en_proceso)…")
            ot_url = f"{BASE_URL}/panel/nueva-ot/"
            if oi_id:
                ot_url += f"?ct_oi_id={oi_id}"

            page.goto(ot_url)
            page.wait_for_load_state("networkidle")

            if oi_id:
                current_val = page.locator('select[name="ingreso_relacionado_id"]').input_value()
                if current_val != oi_id:
                    page.select_option('select[name="ingreso_relacionado_id"]', value=oi_id)

            page.select_option('select[name="equipo_id"]', value=EQUIPO_ID)
            page.select_option('select[name="tecnico_id"]', value=TECNICO_ID)
            page.fill('input[name="numero_ot"]', NUMERO_OT)
            page.select_option('select[name="tipo"]', value="calibracion")
            page.fill('input[name="fecha_ejecucion"]', "2026-07-17")
            page.fill('input[name="proxima_fecha"]', "2027-07-17")
            page.fill('textarea[name="descripcion_trabajo"]',
                      "Revisión inicial — pendiente trabajo técnico.")
            page.fill('input[name="calibratrack_items[0][detalle]"]', "Calibración OTDR")
            page.fill('input[name="calibratrack_items[0][cantidad]"]', "1")
            page.fill('input[name="calibratrack_items[0][precio_unitario]"]', "75000")

            # Estado: en_proceso (el técnico va a actualizarlo)
            page.select_option('select[name="estado_servicio"]', value="en_proceso")

            ss(page, "04-form-ot")
            page.click('button[type="submit"]')
            page.wait_for_load_state("networkidle")
            page.wait_for_timeout(1500)
            ss(page, "05-ot-guardada")

            url_ot = page.url
            m2 = re.search(r'/ot/(\d+)/', url_ot)
            ot_id = m2.group(1) if m2 else None
            if ot_id:
                print(f"    ✓ OT creada — ID: {ot_id} N°: {NUMERO_OT} (estado: en_proceso)")
            else:
                # Puede haber error de validación — verificar screenshot
                try:
                    err_text = page.locator(".ct-alert--error").first.text_content()
                    print(f"    ❌ Error al crear OT: {err_text[:120]}")
                except Exception:
                    print(f"    ⚠ OT ID no capturado — URL: {url_ot[:80]}")

            # ──────────────────────────────────────────────────────────
            # PASO 4: Técnico — Login y actualizar OT a listo_revision
            # ──────────────────────────────────────────────────────────
            print("\n[4] Técnico: Login y marcar OT como 'Listo para revisión'…")
            logout(page)
            page.wait_for_timeout(500)

            login(page, TECNICO_USER, TECNICO_PASS)
            print("    ✓ Técnico autenticado")
            ss(page, "06-tecnico-login")

            # El técnico ve su OT en /panel/evento/{ot_id}/
            if ot_id:
                page.goto(f"{BASE_URL}/panel/evento/{ot_id}/")
            else:
                # Fallback: ir a la lista y abrir la primera OT que NO esté completada
                page.goto(f"{BASE_URL}/panel/eventos/")
                page.wait_for_load_state("networkidle")
                # Buscar el botón "Editar" de una fila que no tenga badge "Completado"
                rows = page.locator("table tbody tr").all()
                edit_href = None
                for row in rows:
                    badge_text = row.locator(".ct-badge").first.text_content().strip() if row.locator(".ct-badge").count() else ""
                    if "Completado" not in badge_text:
                        edit_link = row.locator("a:has-text('Editar')").first
                        if edit_link.count():
                            edit_href = edit_link.get_attribute("href")
                            break
                if edit_href:
                    page.goto(edit_href)
                else:
                    print("    ⚠ No se encontró OT editable en el panel del técnico")

            page.wait_for_load_state("networkidle")
            ss(page, "07-tecnico-ve-ot")

            # Completar descripción del trabajo
            desc = page.locator('textarea[name="descripcion_trabajo"]').first
            desc.fill("Calibración completa realizada. Lecturas corregidas dentro de tolerancia. Equipo listo.")

            # Cambiar estado a listo_revision
            page.select_option('select[name="estado_servicio"]', value="listo_revision")

            ss(page, "08-tecnico-form-listo")
            page.click('button[type="submit"]')
            page.wait_for_load_state("networkidle")
            page.wait_for_timeout(1500)
            ss(page, "09-tecnico-guardado")

            alert_tec = ""
            try:
                alert_tec = page.locator(".ct-alert--success").first.text_content()
            except Exception:
                pass
            if "actualizado" in alert_tec.lower():
                print("    ✓ OT actualizada a 'listo_revision' → email enviado al admin")
            else:
                print(f"    ⚠ Revisar screenshot (texto: {alert_tec[:80]})")

            # ──────────────────────────────────────────────────────────
            # PASO 5: Admin — Revisar OT y marcar como completado
            # ──────────────────────────────────────────────────────────
            print("\n[5] Admin: Revisar OT y marcar como 'Completado'…")
            logout(page)
            page.wait_for_timeout(500)

            login(page, ADMIN_USER, ADMIN_PASS)
            print("    ✓ Admin autenticado")
            ss(page, "10-admin-relogin")

            if ot_id:
                page.goto(f"{BASE_URL}/panel/ot/{ot_id}/")
            else:
                page.goto(f"{BASE_URL}/panel/eventos/")
                page.wait_for_load_state("networkidle")
                first_link = page.locator("table tbody tr:first-child a").first
                page.goto(first_link.get_attribute("href"))

            page.wait_for_load_state("networkidle")
            ss(page, "11-admin-ve-ot")

            # Verificar badge de estado actual (debe ser listo_revision)
            try:
                estado_badge = page.locator(".ct-badge").first.text_content().strip()
                print(f"    Estado actual de la OT: {estado_badge}")
            except Exception:
                pass

            # Cambiar estado a completado
            page.select_option('select[name="estado_servicio"]', value="completado")

            ss(page, "12-admin-form-completar")
            print("    → Guardando con estado 'Completado — Emitir certificado'…")
            page.click('button[type="submit"]')
            page.wait_for_load_state("networkidle", timeout=45000)
            page.wait_for_timeout(3000)
            ss(page, "13-ot-completada")

            try:
                cert_link = page.locator(
                    "a:has-text('Ver Certificado PDF'), a:has-text('Ver certificado PDF')"
                ).first
                cert_href = cert_link.get_attribute("href")
                print(f"    ✓ Certificado disponible: {cert_href}")
            except Exception:
                print("    ⚠ No se encontró link al certificado")

            # ──────────────────────────────────────────────────────────
            # PASO 6: Verificar página pública
            # ──────────────────────────────────────────────────────────
            print("\n[6] Verificar página pública del equipo…")
            page.goto(f"{BASE_URL}/verificar/GW-OTDR-2024-001/")
            page.wait_for_load_state("networkidle")
            ss(page, "14-verificacion-publica")

            body_text = page.locator("body").text_content()
            if "Vigente" in body_text:
                print("    ✓ Equipo aparece como Vigente en la página pública")

            # ──────────────────────────────────────────────────────────
            # RESUMEN
            # ──────────────────────────────────────────────────────────
            print("\n" + "═" * 62)
            print("✅  FLUJO COMPLETO EJECUTADO")
            print("─" * 62)
            print("Revisa tu inbox en jvalenzuela303@gmail.com:\n")
            print("  📧 [1] OI creada → al técnico")
            print("     Para: jvalenzuela303+tecnico@gmail.com")
            print("     Asunto: [Nueva asignación] …\n")
            print("  📧 [2] OT lista para revisión → al admin")
            print("     Para: jvalenzuela303@gmail.com")
            print(f"     Asunto: [CalibraTrack] OT {NUMERO_OT} — Listo para revisión\n")
            print("  📧 [3] OT completada + certificado → al cliente")
            print("     Para: jvalenzuela303+cliente@gmail.com")
            print("     Asunto: Calibración completado — equipo …\n")
            print(f"Screenshots guardados en: {SS_DIR}/")
            print("═" * 62 + "\n")

        except Exception as e:
            print(f"\n❌ Error: {e}")
            try:
                page.screenshot(path=str(SS_DIR / "ERROR.png"), full_page=True)
                print(f"   📸 Screenshot de error guardado")
            except Exception:
                pass
            sys.exit(1)

        finally:
            browser.close()

if __name__ == "__main__":
    run()
