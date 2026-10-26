# Guías de remisión por PSE (producción): consulta de CDR y reenvíos

## Problema (oct-2026)
Las guías 09/31 emitidas por PSE en producción no devuelven ticket ni CDR en el envío. La consulta de CDR
(`FiscalCdrRecoveryService::recover()`) forzaba el canal por ticket del API REST GRE para TODA guía; para
una guía PSE ese canal responde "no se puede consultar desde aquí" sin preguntar a nadie, así que el ciclo
automático (`FiscalCdrConsultProcessor`) agotaba sus intentos sin consultas reales y dejaba la guía en
`error/manual_only`. Además cada reenvío de la misma guía creaba un envío nuevo (el PSE responde 200 a todos).

## Qué hace ahora
- **Canal de consulta**: guía por PSE en producción → `GET {pse}/api/cpe/consultar/{RUC}-{09|31}-{SERIE}-{NUMERO}`
  (`consultPse`). Guía por API REST (beta NubeFact o `sunat_direct`) → por ticket, igual que antes.
- **Ciclo automático**: guías PSE reintentan hasta 12 veces con espera creciente (1, 2, 4, 8, 16, 32 y luego
  60 min, ≈ 6 h). Las de API REST siguen con 8 intentos (~50 min). `manual_only` solo al agotar la ventana;
  si ninguna consulta llegó al proveedor (sin token/URL/ticket) el mensaje lo dice en lugar de afirmar que
  "SUNAT/PSE no confirmó".
- **Intentos**: el camino `STATUS_SENT` (ticket sin CDR / PSE sin CDR) ahora incrementa `retry_count`.
- **Ticket**: el primer ticket de una guía se conserva; el de un reenvío solo queda en el log
  (`fiscal_ticket_kept_original`).
- **Reenvíos bloqueados**: una guía enviada (`sent_at`) en `sent`, o en `error` `manual_only`/`permanent`:
  - `FiscalEmitProcessor::process()` no la reenvía (salvo `force`).
  - `POST /documents/{uuid}/send|retry` y las acciones masivas `send|retry` la rechazan/omiten (409 / skipped).
  - `FiscalDocumentService::enqueue()` (POST del ERP) devuelve el documento existente sin pisar su snapshot
    ni encolar un nuevo envío (salvo `reissue`).
  - `force` (individual o masivo) sigue siendo el override explícito: el payload lleva `force: true`.

## Reabrir guías atascadas
Comando idempotente; nunca reenvía. Por defecto es ensayo (solo lista):

```bash
php bin/console app:fiscal:gre-pse-reopen                       # lista candidatas, no llama a nadie
php bin/console app:fiscal:gre-pse-reopen --uuid=<document_uuid> --execute
php bin/console app:fiscal:gre-pse-reopen --execute --rearm     # además re-arma el ciclo automático
```

Candidatas: guías PSE, `sent_at` no nulo, sin CDR, no atendidas, en `sent` o `error/manual_only`.
Con `--execute` consulta al PSE; si devuelve un CDR real lo aplica (aceptada/observada/rechazada según el CDR) y
deja la sincronización al tenant pendiente de decisión, como siempre. Sin CDR persiste la respuesta cruda en
`pse_response_json.last_consult` y no cambia el estado.

## Verificación con UNA guía
No se pudo probar contra ValidaPSE que `/api/cpe/consultar` devuelva CDR para guías. Elegir una guía atascada,
ejecutar `--uuid=<uuid> --execute` (o "Consultar CDR" en el dashboard) y revisar `provider_detail` /
`pse_response_json.last_consult.raw_response`: debe aparecer `PSE · HTTP 200 · isSuccess=… · estado=…` con el
endpoint `…/consultar/RUC-09-SERIE-NUM`. Si trae `cdr` (o `xml` con ApplicationResponse) se aplica solo. Si
responde `isSuccess=true` sin CDR, la guía existe pero el PSE no entrega su CDR: quedará pendiente y la decisión
es la aceptación manual por usuario ya existente. Si responde 400/"no encontrado", revisar el formato del nombre.

## Contrato oficial de ValidaPSE (openapi.json, revisado antes del push)
- `GET /api/cpe/consultar/{RUC-TIPO-SERIE-NUMERO}` recibe `token_acceso` como **query param** y responde
  `cdr` (base64), `codigo_sunat`, `descripcion_sunat`, `isSuccess`. La consulta ahora envía también el query
  param (se conserva el Bearer) y lee `codigo_sunat`/`descripcion_sunat` como estado/mensaje si no vienen
  `estado`/`mensaje`.
- La documentación pública no menciona guías ni GRE en ningún endpoint, ni duplicados: sigue sin estar
  confirmado que la consulta devuelva CDR para guías. Los endpoints de producción con una empresa en servidor
  DEMO responden 202 «No está configurado en ambiente PRODUCCIÓN EN EL PORTAL.» (mismo caso a descartar si
  una consulta no encuentra nada).
