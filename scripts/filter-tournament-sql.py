#!/usr/bin/env python3
"""
Extrae todos los datos de un torneo específico desde demo-data.sql
y genera un SQL limpio listo para importar en WordPress.

Uso:
  python3 scripts/filter-tournament-sql.py 17 soccertrack/scripts/demo-data.sql > torneo17-wp.sql
"""

import sys
import re
from typing import Optional


def parse_values(values_str: str) -> list[list[str]]:
    """
    Parsea el bloque VALUES de un REPLACE INTO.
    Divide correctamente tuplas con strings que contienen comas, quotes escapadas, etc.
    Retorna lista de listas de tokens (como strings crudos del SQL).
    """
    tuples = []
    current_tuple: list[str] = []
    current_token = ""
    depth = 0          # nivel de paréntesis
    in_string = False
    escape_next = False

    i = 0
    while i < len(values_str):
        ch = values_str[i]

        if escape_next:
            current_token += ch
            escape_next = False
            i += 1
            continue

        if ch == '\\' and in_string:
            current_token += ch
            escape_next = True
            i += 1
            continue

        if ch == "'" and not in_string:
            in_string = True
            current_token += ch
            i += 1
            continue

        if ch == "'" and in_string:
            # Check for escaped quote ''
            if i + 1 < len(values_str) and values_str[i + 1] == "'":
                current_token += "''"
                i += 2
                continue
            in_string = False
            current_token += ch
            i += 1
            continue

        if in_string:
            current_token += ch
            i += 1
            continue

        # Not in string
        if ch == '(':
            if depth == 0:
                # Start of a new tuple
                depth = 1
                current_tuple = []
                current_token = ""
            else:
                depth += 1
                current_token += ch
            i += 1
            continue

        if ch == ')':
            depth -= 1
            if depth == 0:
                # End of tuple
                current_tuple.append(current_token.strip())
                tuples.append(current_tuple)
                current_token = ""
            else:
                current_token += ch
            i += 1
            continue

        if ch == ',' and depth == 1:
            # Column separator within tuple
            current_tuple.append(current_token.strip())
            current_token = ""
            i += 1
            continue

        current_token += ch
        i += 1

    return tuples


def unquote(token: str) -> str:
    """Quita las comillas simples de un token SQL string. Retorna el valor como string."""
    token = token.strip()
    if token.upper() == 'NULL':
        return ''
    if token.startswith("'") and token.endswith("'"):
        return token[1:-1].replace("\\'", "'").replace("''", "'")
    return token


def get_col_index(columns: list[str], name: str) -> int:
    try:
        return columns.index(name)
    except ValueError:
        return -1


def parse_replace_into(line: str):
    """
    Parsea una línea REPLACE INTO.
    Retorna (table_name, [col1, col2,...], [[val1,val2,...], ...]) o None.
    """
    m = re.match(
        r"REPLACE INTO `([^`]+)` \(([^)]+)\) VALUES (.+);?\s*$",
        line,
        re.DOTALL
    )
    if not m:
        return None

    table = m.group(1)
    raw_cols = m.group(2)
    raw_values = m.group(3).rstrip(';')

    columns = [c.strip().strip('`') for c in raw_cols.split(',')]
    tuples = parse_values(raw_values)

    return table, columns, tuples


def build_replace_into(table: str, columns: list[str], tuples: list[list[str]]) -> str:
    col_list = ', '.join(f'`{c}`' for c in columns)
    rows = []
    for t in tuples:
        rows.append('(' + ', '.join(t) + ')')
    return f"REPLACE INTO `{table}` ({col_list}) VALUES\n" + ',\n'.join(rows) + ';'


def main():
    if len(sys.argv) < 3:
        print("Uso: python3 filter-tournament-sql.py <tournament_id> <demo-data.sql>", file=sys.stderr)
        sys.exit(1)

    tid = int(sys.argv[1])
    sql_file = sys.argv[2]

    with open(sql_file, 'r', encoding='utf-8') as f:
        content = f.read()

    lines = content.split('\n')

    # ── Paso 1: recolectar IDs relacionados con el torneo ─────────────────────
    venue_ids: set[str] = set()
    team_ids: set[str] = set()
    player_ids: set[str] = set()

    for line in lines:
        if not line.startswith('REPLACE INTO'):
            continue
        parsed = parse_replace_into(line)
        if not parsed:
            continue
        table, columns, tuples = parsed
        short = table.split('ds_')[-1] if 'ds_' in table else table

        if short == 'tournament_venues':
            tid_idx = get_col_index(columns, 'tournament_id')
            vid_idx = get_col_index(columns, 'venue_id')
            for t in tuples:
                if tid_idx >= 0 and unquote(t[tid_idx]) == str(tid):
                    venue_ids.add(t[vid_idx].strip())

        elif short == 'teams':
            tid_idx = get_col_index(columns, 'tournament_id')
            id_idx  = get_col_index(columns, 'id')
            for t in tuples:
                if tid_idx >= 0 and unquote(t[tid_idx]) == str(tid):
                    team_ids.add(t[id_idx].strip())

        elif short == 'team_players':
            team_id_idx   = get_col_index(columns, 'team_id')
            player_id_idx = get_col_index(columns, 'player_id')
            for t in tuples:
                if t[team_id_idx].strip() in team_ids:
                    player_ids.add(t[player_id_idx].strip())

    print(f"-- venue_ids: {sorted(venue_ids)}", file=sys.stderr)
    print(f"-- team_ids:  {len(team_ids)} equipos", file=sys.stderr)
    print(f"-- player_ids: {len(player_ids)} jugadores", file=sys.stderr)

    # ── Paso 2: generar SQL filtrado ──────────────────────────────────────────
    out_lines = []
    out_lines.append(f"-- SoccerTrack — Torneo {tid} Export")
    out_lines.append(f"-- Generado desde demo-data.sql")
    out_lines.append(f"-- Prefijo: wp_ (ajustar con sed si es necesario)")
    out_lines.append("")
    out_lines.append("SET FOREIGN_KEY_CHECKS=0;")
    out_lines.append("SET UNIQUE_CHECKS=0;")
    out_lines.append("SET NAMES utf8mb4;")
    out_lines.append("")

    for line in lines:
        # Pasar comentarios de sección
        if line.startswith('--'):
            out_lines.append(line)
            continue

        if line.startswith('SET') or line.strip() == '':
            continue  # ya los pusimos arriba / al final

        if not line.startswith('REPLACE INTO'):
            continue

        parsed = parse_replace_into(line)
        if not parsed:
            out_lines.append(line)
            continue

        table, columns, tuples = parsed
        short = table.split('ds_')[-1] if 'ds_' in table else table

        filtered: list[list[str]] = []

        if short == 'tournaments':
            id_idx = get_col_index(columns, 'id')
            filtered = [t for t in tuples if t[id_idx].strip() == str(tid)]

        elif short == 'tournament_venues':
            tid_idx = get_col_index(columns, 'tournament_id')
            filtered = [t for t in tuples if unquote(t[tid_idx]) == str(tid)]

        elif short == 'venues':
            id_idx = get_col_index(columns, 'id')
            filtered = [t for t in tuples if t[id_idx].strip() in venue_ids]

        elif short == 'courts':
            vid_idx = get_col_index(columns, 'venue_id')
            filtered = [t for t in tuples if t[vid_idx].strip() in venue_ids]

        elif short == 'teams':
            tid_idx = get_col_index(columns, 'tournament_id')
            filtered = [t for t in tuples if unquote(t[tid_idx]) == str(tid)]

        elif short == 'team_players':
            team_id_idx = get_col_index(columns, 'team_id')
            filtered = [t for t in tuples if t[team_id_idx].strip() in team_ids]

        elif short == 'players':
            id_idx = get_col_index(columns, 'id')
            filtered = [t for t in tuples if t[id_idx].strip() in player_ids]

        elif short == 'staff':
            filtered = tuples  # catálogo global, incluir todo

        elif short == 'playoff_brackets':
            tid_idx = get_col_index(columns, 'tournament_id')
            filtered = [t for t in tuples if unquote(t[tid_idx]) == str(tid)]

        elif short == 'matches':
            tid_idx = get_col_index(columns, 'tournament_id')
            filtered = [t for t in tuples if unquote(t[tid_idx]) == str(tid)]

        elif short == 'match_events':
            tid_idx = get_col_index(columns, 'tournament_id')
            filtered = [t for t in tuples if unquote(t[tid_idx]) == str(tid)]

        elif short == 'disciplinary_sanctions':
            tid_idx = get_col_index(columns, 'tournament_id')
            filtered = [t for t in tuples if unquote(t[tid_idx]) == str(tid)]

        else:
            # Tabla desconocida: incluir todo
            filtered = tuples

        if filtered:
            out_lines.append(build_replace_into(table, columns, filtered))
            out_lines.append("")

    out_lines.append("SET FOREIGN_KEY_CHECKS=1;")
    out_lines.append("SET UNIQUE_CHECKS=1;")
    out_lines.append("")
    out_lines.append(f"SELECT CONCAT('Torneo {tid} importado: ', COUNT(*), ' equipos') AS resultado")
    out_lines.append(f"FROM `wp_ds_teams` WHERE tournament_id = {tid};")

    print('\n'.join(out_lines))


if __name__ == '__main__':
    main()
