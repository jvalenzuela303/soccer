# Task 3 Report

## Changes Made

### Change 1: 4-state validation in `guardar_ot()` — line 870-872
**File:** `calibratrack/includes/class-calibratrack-panel.php`
**Lines:** 870-872 (post-edit line numbers)

Old: `in_array( $estado_raw, array( 'en_proceso', 'completado' ), true )`
New: `in_array( $estado_raw, array( 'en_proceso', 'en_ejecucion', 'listo_revision', 'completado' ), true )`

Self-review: PASS — array now contains all 4 states.

### Change 2: Email condition in `guardar_ot()` — line 1013
**File:** `calibratrack/includes/class-calibratrack-panel.php`
**Line:** 1013

Old: `if ( 'en_proceso' === $estado_anterior && class_exists( 'CalibraTrack_Mailer' ) )`
New: `if ( 'completado' !== $estado_anterior && class_exists( 'CalibraTrack_Mailer' ) )`

Self-review: PASS — condition now fires for any transition TO `completado` from a non-`completado` state.

### Change 3: Email condition in `procesar_guardar_evento()` — line 1165
**File:** `calibratrack/includes/class-calibratrack-panel.php`
**Line:** 1165

Old: `if ( 'en_proceso' === $estado_anterior && class_exists( 'CalibraTrack_Mailer' ) )`
New: `if ( 'completado' !== $estado_anterior && class_exists( 'CalibraTrack_Mailer' ) )`

Self-review: PASS — same fix applied to older handler used in new-OT creation path.

### Change 4: Rewrite auth block in `handle_editar_evento()` — lines 462-475
**File:** `calibratrack/includes/class-calibratrack-panel.php`
**Lines:** 462-475

Replaced single `$autor_id` check with:
1. Admin early-return redirect to `/panel/ot/{id}/` (lines 462-466)
2. Meta-based technician auth check using `CalibraTrack_Meta_Keys::EVENTO_TECNICO_RESPONSABLE` (lines 468-475), falling back to `post_author` if meta is empty (0 !== $user_id guard handles unset meta returning 0 safely only when $user_id > 0 — see concern below)

### Change 5: `es_tecnico => true` in `load_template()` call — line 501
**File:** `calibratrack/includes/class-calibratrack-panel.php`
**Line:** 501

Added `'es_tecnico' => true` to the template variable array passed by `handle_editar_evento()`.

Self-review: PASS — key present alongside existing keys.

## Concerns

**Minor concern — meta-based auth when meta is unset:**
`get_post_meta()` returns an empty string `''` when a meta key does not exist, which casts to `(int) 0`. If the current logged-in user's ID happens to equal `0` (which cannot happen for an authenticated user in WordPress), this would incorrectly grant access. Since WordPress always assigns user IDs starting at `1`, `$user_id` is always >= 1 for any authenticated user, so `0 !== $user_id` is always true, meaning an unset meta falls through to the `$autor_id` check correctly. No actual security issue — but worth noting if meta population logic changes in the future.

**No security-auditor flag needed** for these changes. The auth block change tightens (not loosens) access control. No file uploads or REST endpoints were touched.
