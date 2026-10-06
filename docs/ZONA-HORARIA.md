# Zona horaria del facturador (hora de Perú)

**Fecha:** 2026-10-06 · **Regla de producto:** todo se muestra y se filtra en hora de Perú (`America/Lima`), porque el sistema opera en Perú.

## Qué se encontró en producción (solo SELECT, 2026-10-06)

Las columnas `datetime` **no comparten zona horaria**: cada proceso PHP escribe con la suya.

| Proceso | Zona con la que escribe | Columnas |
|---|---|---|
| Peticiones web (PHP-FPM) | UTC | `fiscal_documents.created_at`, `queued_at`, `attended_at`, alertas creadas desde el panel |
| Worker / CLI (`php.ini`: `date.timezone = Europe/Berlin`) | Berlín (UTC+1 invierno / +2 verano) | `updated_at`, `sent_at`, `accepted_at`, `rejected_at`, `next_retry_at`, y todas las filas de `fiscal_audit_logs` |

Evidencia: en los 3 000 documentos más recientes, `created_at` y su primer evento de auditoría difieren exactamente **2 h** (y `accepted_at` de un mismo documento está 2 h "adelante" de su `created_at`). Además `MySQL NOW()` y `UTC_TIMESTAMP()` coinciden (el servidor MySQL está en UTC).

Doctrine hidrata todo con la zona del proceso que lee. La API corre en la web (UTC), así que una fecha escrita por el worker se interpretaba **2 h corrida** (más tarde de lo real) y los umbrales de "atascado" o las ventanas de 24 h salían desfasadas.

## Qué hace el código ahora

`src/Service/Fiscal/FiscalClock.php` concentra la regla en un solo lugar:

- `webToLimaIso()` — columnas de la web: el instante hidratado ya es correcto; se muestra con offset `-05:00`.
- `cliToLimaIso()` / `fromCli()` — columnas del worker: se reinterpreta la hora de pared con la zona del CLI y se convierte a Lima.
- `limaMidnightIn($tz)` — "hoy" de Lima expresado en el reloj de la columna que se filtra.
- `cliNow()` — ventanas relativas ("últimas 24 h") en el reloj del worker.

Aplicado en: tarjetas y gráficas de Operaciones (con etiquetas de hora en Lima), detección de "atascados" y edad de cada documento, ventana de 24 h de reintentos/errores por tenant y alertas, y las fechas devueltas en lista, detalle y cola. Filtros por rango de fechas de documentos (`parseDateFilter`) ya convertían el día de Lima a UTC y siguen igual (`created_at` es de la web).

La zona del CLI se toma de `FISCAL_CLI_TIMEZONE` (por defecto `Europe/Berlin`, que es el valor real de producción y de los entornos locales).

## Pendiente (decisión de infraestructura, no se tocó)

Lo ideal es **un solo reloj**: poner `date.timezone = America/Lima` en el pool de PHP-FPM **y** en el `php.ini` del CLI, y **normalizar el histórico** (convertir cada columna desde la zona en que se escribió). Eso modifica datos de producción y exige:

1. Respaldo previo y ventana de mantenimiento.
2. Convertir por columna y por origen (p. ej. `updated_at` está mezclada: filas solo tocadas por la web están en UTC y las tocadas por el worker en Berlín), lo que requiere una regla por fila con verificación previa.
3. Después, `FISCAL_CLI_TIMEZONE` pasa a ser igual a la zona web (con ambas en Lima, `FiscalClock` no convierte nada).

Mientras tanto, lo que NO está corregido (se desconoce qué proceso escribió cada fila): fechas de `fiscal_emit_attempts`, `outbound_email_logs`, `fiscal_webhook_events` y las filas de auditoría que aparecen en la línea de tiempo del detalle.
