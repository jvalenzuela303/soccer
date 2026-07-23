/**
 * Prueba de flujo completo CalibraTrack
 * Admin: OI → OT (en_proceso) → Técnico: completa OT → Admin recibe notificación
 *
 * Correos de prueba (inbox: jvalenzuela303@gmail.com):
 *   - OI creada  → técnico → jvalenzuela303+tecnico@gmail.com
 *   - OT creada  → cliente → jvalenzuela303+cliente@gmail.com
 *   - OT complet.→ cliente → jvalenzuela303+cliente@gmail.com (certificado)
 *   - OT complet.→ admin   → jvalenzuela303@gmail.com (NEW: notif. admin)
 *
 * Ejecutar: node tests/flujo-completo.js
 */

'use strict';

const { chromium } = require('playwright');
const { mkdirSync } = require('fs');

const BASE_URL    = 'http://localhost:8088';
const ADMIN_USER  = 'admin';
const ADMIN_PASS  = 'admin';
const TECNICO_USER = 'tecnico1';
const TECNICO_PASS = 'ucfy5$^oCdc3ibKPj$NdhAO4';

// IDs de datos de prueba (verificados en la BD)
const EQUIPO_ID  = 34;  // Grandway GS-401 — GW-OTDR-2024-001
const TECNICO_ID = 2;   // tecnico1

const SS_DIR = '/home/jvalenzuela/Desarollo/truetech/tests/screenshots';

let stepCount = 0;

async function ss(page, label) {
  stepCount++;
  const n = String(stepCount).padStart(2, '0');
  const path = `${SS_DIR}/${n}-${label}.png`;
  await page.screenshot({ path, fullPage: true });
  console.log(`    📸 ${n}-${label}.png`);
}

(async () => {
  mkdirSync(SS_DIR, { recursive: true });
  stepCount = 0;

  const browser = await chromium.launch({ headless: false, slowMo: 200 });
  const ctx  = await browser.newContext({ viewport: { width: 1280, height: 900 } });
  const page = await ctx.newPage();

  try {

    // ─────────────────────────────────────────────────────────────────
    // PASO 1: Login como admin
    // ─────────────────────────────────────────────────────────────────
    console.log('\n[1] Login como admin…');
    await page.goto(`${BASE_URL}/wp-login.php`);
    await page.fill('#user_login', ADMIN_USER);
    await page.fill('#user_pass', ADMIN_PASS);
    await page.click('#wp-submit');
    await page.waitForURL('**/wp-admin/**');
    console.log('    ✓ Autenticado');
    await ss(page, 'admin-login-ok');

    // ─────────────────────────────────────────────────────────────────
    // PASO 2: Crear Orden de Ingreso (OI)
    // ─────────────────────────────────────────────────────────────────
    console.log('\n[2] Crear Orden de Ingreso (OI)…');
    await page.goto(`${BASE_URL}/panel/nueva-oi/`);
    await page.waitForLoadState('networkidle');
    await ss(page, 'form-oi-vacio');

    await page.selectOption('select[name="equipo_id"]', { value: String(EQUIPO_ID) });
    await page.selectOption('select[name="tecnico_id"]', { value: String(TECNICO_ID) });
    await page.selectOption('select[name="tipo"]', 'mantencion_calibracion');
    await page.fill('input[name="fecha_ejecucion"]', '2026-07-20');
    await page.fill('textarea[name="falla_reportada"]', 'Prueba automatizada Playwright — verificación de notificaciones al admin.');

    await ss(page, 'form-oi-lleno');
    await page.click('button[type="submit"]');
    await page.waitForLoadState('networkidle');
    await ss(page, 'oi-guardada');

    const urlOI = page.url();
    const oiMatch = urlOI.match(/\/oi\/(\d+)\//);
    const oiId = oiMatch ? parseInt(oiMatch[1]) : null;
    console.log(`    ✓ OI guardada — ID: ${oiId}, URL: ${urlOI}`);

    // ─────────────────────────────────────────────────────────────────
    // PASO 3: Crear OT vinculada a la OI (estado: en_proceso)
    // ─────────────────────────────────────────────────────────────────
    console.log('\n[3] Crear Orden de Trabajo (OT) — estado en_proceso…');

    const otUrl = oiId
      ? `${BASE_URL}/panel/nueva-ot/?ct_oi_id=${oiId}`
      : `${BASE_URL}/panel/nueva-ot/`;

    await page.goto(otUrl);
    await page.waitForLoadState('networkidle');
    await ss(page, 'form-ot-vacio');

    // Verificar OI pre-seleccionada
    if (oiId) {
      const oiSel = page.locator('select[name="ingreso_relacionado_id"]').first();
      const oiSelVal = await oiSel.inputValue().catch(() => '');
      if (oiSelVal !== String(oiId)) {
        await page.selectOption('select[name="ingreso_relacionado_id"]', { value: String(oiId) });
        console.log('    → OI vinculada manualmente');
      } else {
        console.log('    ✓ OI pre-seleccionada automáticamente');
      }
    }

    await page.selectOption('select[name="equipo_id"]', { value: String(EQUIPO_ID) });
    await page.selectOption('select[name="tecnico_id"]', { value: String(TECNICO_ID) });

    const tsOT = Date.now().toString().slice(-6);
    const numeroOT = `OT-PW-${tsOT}`;
    await page.fill('input[name="numero_ot"]', numeroOT);
    await page.selectOption('select[name="tipo"]', 'mantencion_calibracion');
    await page.fill('input[name="fecha_ejecucion"]', '2026-07-20');
    await page.fill('input[name="proxima_fecha"]', '2027-07-20');
    await page.fill('textarea[name="descripcion_trabajo"]', 'Trabajo a completar por técnico. Prueba Playwright.');
    await page.fill('input[name="calibratrack_items[0][detalle]"]', 'Calibración OTDR completa');
    await page.fill('input[name="calibratrack_items[0][cantidad]"]', '1');
    await page.fill('input[name="calibratrack_items[0][precio_unitario]"]', '85000');

    // Estado: en_proceso (el técnico será quien lo complete)
    await page.selectOption('select[name="estado_servicio"]', 'en_proceso');

    await ss(page, 'form-ot-lleno');
    await page.click('button[type="submit"]');
    await page.waitForLoadState('networkidle', { timeout: 30000 });
    await page.waitForTimeout(1000);
    await ss(page, 'ot-guardada-en-proceso');

    const urlOT = page.url();
    const otMatch = urlOT.match(/\/ot\/(\d+)\//);
    const otId = otMatch ? parseInt(otMatch[1]) : null;
    console.log(`    ✓ OT guardada — ID: ${otId}, N°: ${numeroOT}, URL: ${urlOT}`);

    // ─────────────────────────────────────────────────────────────────
    // PASO 4: Login como técnico y completar la OT
    // ─────────────────────────────────────────────────────────────────
    console.log('\n[4] Login como técnico y completar la OT…');
    await page.goto(`${BASE_URL}/panel/salir/`);
    await page.waitForLoadState('networkidle');
    await page.goto(`${BASE_URL}/panel/login/`);
    await page.waitForLoadState('networkidle');

    // Login del técnico
    const loginUser = page.locator('input[name="log"], input[name="username"], #user_login').first();
    const loginPass = page.locator('input[name="pwd"], input[name="password"], #user_pass').first();
    await loginUser.fill(TECNICO_USER);
    await loginPass.fill(TECNICO_PASS);
    await page.locator('input[type="submit"], button[type="submit"]').first().click();
    await page.waitForLoadState('networkidle');
    await ss(page, 'tecnico-login-ok');
    console.log(`    ✓ Técnico autenticado — URL: ${page.url()}`);

    // Navegar a la OT como técnico (ruta correcta: /panel/evento/{id}/)
    if (otId) {
      await page.goto(`${BASE_URL}/panel/evento/${otId}/`);
      await page.waitForLoadState('networkidle');
      await ss(page, 'tecnico-ve-ot');

      // Agregar descripción del trabajo
      const descField = page.locator('textarea[name="descripcion_trabajo"]').first();
      if (await descField.count()) {
        await descField.fill('Calibración completada con equipo patrón NIST. Valores dentro de tolerancia. Prueba automatizada.');
      }

      // Cambiar estado a "listo_revision" — esto notifica al admin
      const estadoSel = page.locator('select[name="estado_servicio"]').first();
      if (await estadoSel.count()) {
        await estadoSel.selectOption('listo_revision');
        console.log('    → Estado seleccionado: listo_revision (notifica al admin)');
      } else {
        console.log('    ⚠ No se encontró selector de estado');
      }

      await ss(page, 'tecnico-form-listo-revision');
      await page.locator('button[type="submit"]').first().click();
      await page.waitForLoadState('networkidle', { timeout: 30000 });
      await page.waitForTimeout(1500);
      await ss(page, 'tecnico-estado-guardado');

      const bodyText = await page.locator('body').textContent().catch(() => '');
      if (bodyText.includes('listo') || bodyText.includes('revision') || bodyText.includes('actualiz') || bodyText.includes('Listo')) {
        console.log('    ✓ Estado "Listo para revisión" guardado por el técnico');
      } else {
        console.log(`    ⚠ Verificar screenshot — URL: ${page.url()}`);
      }

      // ───────────────────────────────────────────────────────────────
      // PASO 4b: Admin completa la OT (genera certificado + email cliente)
      // ───────────────────────────────────────────────────────────────
      console.log('\n[4b] Admin recibe notificación y completa la OT…');
      await page.goto(`${BASE_URL}/wp-login.php`);
      await page.fill('#user_login', ADMIN_USER);
      await page.fill('#user_pass', ADMIN_PASS);
      await page.click('#wp-submit');
      await page.waitForURL('**/wp-admin/**');
      console.log('    ✓ Admin re-autenticado');

      await page.goto(`${BASE_URL}/panel/ot/${otId}/`);
      await page.waitForLoadState('networkidle');
      await ss(page, 'admin-ve-ot-listo-revision');

      // Marcar como completado
      const adminEstadoSel = page.locator('select[name="estado_servicio"]').first();
      if (await adminEstadoSel.count()) {
        await adminEstadoSel.selectOption('completado');
        console.log('    → Admin selecciona: completado');
      }

      await ss(page, 'admin-form-completar');
      await page.locator('button[type="submit"]').first().click();
      await page.waitForLoadState('networkidle', { timeout: 30000 });
      await page.waitForTimeout(2000);
      await ss(page, 'admin-ot-completada');

      const certLink = page.locator('a:has-text("Ver certificado PDF"), a:has-text("Ver Certificado PDF")').first();
      if (await certLink.count()) {
        const certHref = await certLink.getAttribute('href');
        console.log(`    ✓ Certificado generado: ${certHref}`);
      } else {
        console.log('    ⚠ No se encontró link al certificado — verificar screenshot');
      }
    } else {
      console.log('    ⚠ No se pudo obtener el ID de la OT — saltando pasos del técnico/admin');
    }

    // ─────────────────────────────────────────────────────────────────
    // PASO 5: Verificar la página pública del equipo
    // ─────────────────────────────────────────────────────────────────
    console.log('\n[5] Verificar página pública del equipo…');
    await page.goto(`${BASE_URL}/verificar/GW-OTDR-2024-001/`);
    await page.waitForLoadState('networkidle');
    await ss(page, 'verificacion-publica');

    const verText = await page.locator('body').textContent().catch(() => '');
    if (verText.includes('Vigente') || verText.includes('calibraci') || verText.includes('manten')) {
      console.log('    ✓ Página de verificación pública muestra el equipo');
    } else {
      console.log('    ⚠ Verificar screenshot de la página pública');
    }

    // ─────────────────────────────────────────────────────────────────
    // RESUMEN
    // ─────────────────────────────────────────────────────────────────
    console.log('\n' + '═'.repeat(60));
    console.log('✅  FLUJO COMPLETO EJECUTADO EXITOSAMENTE');
    console.log('─'.repeat(60));
    console.log('Revisa tu inbox en jvalenzuela303@gmail.com:');
    console.log('');
    console.log('  📧 Email 1 — OI creada → notif. al técnico');
    console.log('     Para: jvalenzuela303+tecnico@gmail.com');
    console.log('');
    console.log('  📧 Email 2 — OT creada → notif. al cliente');
    console.log('     Para: jvalenzuela303+cliente@gmail.com');
    console.log('');
    console.log('  📧 Email 3 — Técnico → "Listo para revisión" → notif. al admin');
    console.log('     Para: jvalenzuela303@gmail.com  ← este es el que debe llegar');
    console.log('');
    console.log('  📧 Email 4 — Admin completa OT → certificado al cliente');
    console.log('     Para: jvalenzuela303+cliente@gmail.com');
    console.log('');
    console.log(`Screenshots guardados en: ${SS_DIR}/`);
    console.log('═'.repeat(60) + '\n');

  } catch (err) {
    console.error('\n❌ Error:', err.message);
    await page.screenshot({ path: `${SS_DIR}/ERROR.png`, fullPage: true }).catch(() => {});
    console.log(`   📸 Screenshot de error: ${SS_DIR}/ERROR.png`);
    process.exitCode = 1;
  } finally {
    await browser.close();
  }
})();
