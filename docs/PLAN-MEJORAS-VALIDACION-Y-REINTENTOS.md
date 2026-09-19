# Plan de mejoras — Validación de estado SUNAT/PSE y política de reintentos

**Estado: SOLO DOCUMENTACIÓN / AUDITORÍA.** No se ha modificado ningún archivo de código en
`facturador_lycet` para este plan. El objetivo de este documento es dejar registrados los bugs
reales encontrados (con evidencia verificable), las fuentes oficiales investigadas, y las
decisiones de diseño que el usuario ya definió — antes de tocar código.

## 1. Resumen ejecutivo

Se investigaron dos síntomas reportados en producción:

1. Comprobantes que quedan indefinidamente "en proceso" / "sin enviar".
2. El tenant RUC `10758320397` (`ortiz`, tenant_id 384) tiene boletas B001-1, B001-6 y B001-7
   marcadas como `accepted` que, según los datos crudos guardados en el propio sistema, en
   realidad fueron **rechazadas** por PSE en el envío original.

Ambos síntomas comparten una causa de fondo relacionada: el sistema no distingue con suficiente
rigor **qué códigos de error ameritan reintento** y **qué verificación de estado es lo bastante
confiable como para marcar un comprobante como aceptado**.

## 2. Bug confirmado — Falso positivo "aceptado por consulta de estado" (RUC 10758320397)

### 2.1 Evidencia

Tabla `fiscal_documents` (MySQL, BD `tukifac`), tenant_id=384, boletas B001-1 a B001-7:

| Boleta | Cómo se marcó `accepted` | `pse_response_json` de la emisión ORIGINAL | `rejected_at` |
|---|---|---|---|
| B001-2, 3, 4, 5 | Respuesta directa de PSE al emitir: *"La Boleta numero B001-X, ha sido aceptada"* | `isSuccess:true` | `NULL` (correcto) |
| B001-1 | Mensaje: *"Aceptado por SUNAT (validado por consulta de estado; CDR no disponible aún en SUNAT)"* | `{"isSuccess":false,"estado":501,"code":"1033","errores":"El comprobante fue registrado previamente con otros datos... informado anteriormente"}` | Igual a `sent_at` |
| B001-6 | Mismo mensaje de "consulta de estado" | `{"isSuccess":false,"estado":501,"code":"HTTP","errores":"Bad Request"}` | Igual a `sent_at` |
| B001-7 | Mismo mensaje de "consulta de estado" | `{"isSuccess":false,"estado":501,"code":"1033","errores":"...informado anteriormente"}` | Igual a `sent_at` |

Es decir: **el envío original de 1, 6 y 7 fue rechazado por PSE** (`isSuccess:false`), y
posteriormente un mecanismo de "recuperación de CDR" volvió a consultar (a un endpoint distinto:
`GET /api/cpe/consultar/...`) y, al recibir `isSuccess:true` de esa segunda consulta, sobrescribió
el estado a `accepted` — sin descargar nunca un CDR real y sin conciliar la contradicción con el
rechazo original ya guardado. `tenant_sync_state=synced` en los tres casos: el ERP del tenant ya
fue notificado de esta aceptación no confirmada.

### 2.2 Código responsable

`src/Service/Fiscal/FiscalCdrRecoveryService.php`:

- `consultPse()` (línea 267): si la consulta a PSE responde `isSuccess:true`, punto 359-363,
  clasifica como `ACCEPTED` salvo que el mensaje diga explícitamente rechazado — **sin exigir un
  CDR real**.
- `applyValidWithoutCdr()` (línea 386): aplica `STATUS_ACCEPTED` con el mensaje *"Aceptado por
  SUNAT (validado por consulta de estado; CDR no disponible aún en SUNAT)"* — y **no limpia
  `rejected_at`**, por lo que un documento puede quedar con `status=accepted` y `rejected_at`
  poblado simultáneamente (como se ve arriba).
- El propio docblock de la clase (línea 28-29) documenta la intención original: resolver el caso
  *"SUNAT ya aceptó pero no devolvió el CDR"* — un escenario legítimo y distinto de "PSE rechazó y
  luego una consulta aparte dice que existe algo aceptado".

### 2.3 Por qué es peligroso

`B001-1` y `B001-7` fueron rechazadas específicamente con el código **1033** — *"el comprobante
fue registrado previamente con **otros datos**"* — es decir, PSE/SUNAT indica que existe *algo*
con ese mismo serie-número, pero no necesariamente el mismo contenido que se intentó enviar. La
consulta de estado posterior solo confirma "existe algo aceptado" — nunca compara que sea el mismo
XML/hash. El sistema actual da eso por bueno sin verificarlo.

## 3. Punto a verificar — Error SUNAT 3105 ("tributo por línea de afectación IGV")

El usuario planteó la hipótesis de que este error **ya fue corregido** en el origen de los datos
(`backend_go`) y que cualquier ocurrencia debería ser histórica. Los logs de producción
(`/home/tukifac-facturador/logs/fiscal-worker-error.log`, servidor `2.24.110.236`) muestran lo
contrario — **se registra evidencia objetiva, no una conclusión, para que se revise**:

| Fecha | Ocurrencias de código 3105 |
|---|---|
| 2026-07-06 | 1 |
| 2026-07-11 | 2 |
| 2026-08-02/03/05 | 1 cada una |
| 2026-09-14 | 5 |
| 2026-09-15 | 20 |
| 2026-09-16 | 34 |
| 2026-09-17 | 4 |
| 2026-09-18 | 28 |
| **2026-09-19 (hoy)** | **12, la más reciente a las 18:59:47** |

**109 ocurrencias en total en el log actual**, con un salto notorio a partir del 14 de septiembre
(antes era esporádico: 1-2 por fecha en julio/agosto). No encontré en el historial de commits de
`backend_go` entre el 10 y el 15 de septiembre un cambio obviamente relacionado con líneas de
afectación IGV (sí hay cambios de detracción por esas fechas, que es un mecanismo fiscal distinto)
— **queda como pregunta abierta, no como conclusión**, si algo en `backend_go` sigue enviando datos
con esa inconsistencia, o si el patrón de reintento infinito (sección 4) simplemente hace que
ventas antiguas con el mismo defecto se seguían reintentando y acumulando entradas nuevas en el log
cada vez que se reintentan — lo cual explicaría por qué "sigue viéndose" sin que haya ventas nuevas
con el problema. **Recomendación**: antes de descartarlo como histórico, identificar si las 12
ocurrencias de hoy corresponden a comprobantes *nuevos* (creados hoy) o son reintentos de
comprobantes *antiguos* que arrastran el mismo defecto desde su creación — esto se puede resolver
cruzando `document_uuid`/`sale_id` de esos eventos contra la fecha de creación de la venta en
`backend_go`.

## 4. Política de reintentos actual (código real, no supuesta)

`src/Service/Fiscal/FiscalEmitProcessor.php::applyFailure()`:

- **Reintentos "rápidos"**: hasta `FISCAL_MAX_RETRIES` (env var, **no configurada** → default
  **20**), con backoff exponencial `min(3600, 30 × 2^(intento-1))` segundos (30s, 60s, 120s...
  tope 1 hora).
- **Agotados los 20 rápidos**: si el error es transitorio (no permanente), el documento entra en un
  reintento "lento" **cada 900 segundos (15 min) indefinidamente**, "hasta veredicto definitivo de
  SUNAT/PSE" — **sin ningún límite superior**. Esto explica el caso real encontrado: una factura
  (F001-75) con **22 intentos** contra un comprobante que SUNAT ya había marcado anulado/rechazado
  (código 1032).
- Solo se corta el reintento si `errorType === ERROR_PERMANENT`, determinado hoy únicamente por
  `isNonRetryableEmitError()` (línea 659) — una lista de *substrings* de **excepciones PHP** (cert
  inválido, clave privada, etc.), **no** por el código de error que devuelve SUNAT/PSE.

### 4.1 Clasificación por rango de código SUNAT: ya existe, pero solo se aplica al canal directo

`src/Service/Fiscal/Provider/SunatCdrClassifier.php::isBusinessRejectionCode()` (línea 79) **ya
implementa correctamente** los rangos oficiales:

- código < 2000 → excepción de sistema → transitorio (si aplica reintento).
- código ≥ 2000 → rechazo de negocio → terminal (no reintentar tal cual).
- código ≥ 4000 → aceptado con observaciones.

**Pero esta clasificación solo se usa cuando hay un `CdrResponse` real de SUNAT** (envío directo
con certificado digital, vía Greenter). Los códigos que llegan **embebidos en la respuesta de
PSE** (como el 1033 o el 3105 que vimos arriba) **no pasan por este clasificador** — de ahí que en
los logs aparezcan como `"error_type":"transient"` documentos con código 3105, que según el rango
oficial (≥ 2000) debería ser terminal.

## 5. Investigación de códigos oficiales SUNAT (fuentes externas)

Según el FAQ oficial de Greenter (la librería que ya se usa, versión instalada `v5.3.0`, **que es
la última versión publicada en Packagist** — no hace falta actualizarla) y catálogos especializados
de códigos SUNAT consultados:

| Rango | Significado | ¿Reintentar? |
|---|---|---|
| **0100–1999** | Excepciones de sistema (timeout, error interno, etc.) | Sí — "corregir y volver a enviar" |
| **2000–3999** | Rechazo de negocio (estructura, montos, tributos, relaciones) | **No** tal cual — requiere **corregir el comprobante y emitir uno nuevo**, no reintentar el mismo XML |
| **≥ 4000** | Aceptado con observaciones | No es un error — es terminal (aceptado), la corrección es para futuros comprobantes |

Confirmado específicamente para los códigos vistos en los datos reales:

- **1032**: *"El comprobante ya está informado y se encuentra con estado anulado o rechazado"* —
  terminal, no reintentar.
- **1033**: *"El comprobante fue registrado previamente con otros datos"* — terminal, requiere
  investigación manual (posible conflicto de datos), no reintentar a ciegas.
- **3105**: cae en el rango 2000-3999 (validación de estructura/tributos) según el criterio
  oficial → terminal, requiere que la venta origen se corrija en `backend_go`, **no** reintentar el
  mismo XML indefinidamente.

**Pregunta abierta que sigue sin resolver esta investigación**: cuando el envío es vía PSE
(`estado:501` en los datos reales, que no es un código de SUNAT sino un código propio de
ValidaPSE), no está claro cómo obtener el código SUNAT real subyacente en todos los casos — el
campo `errores` a veces sí lo incluye como texto libre (ej. `"...ticket: ... error: ... 3105
(nodo...)"`) y a veces no (ej. B001-6: `"code":"HTTP","errores":"Bad Request"`, sin ningún código
SUNAT identificable). Esto requiere pruebas empíricas contra ValidaPSE o consulta a su soporte —
no se puede resolver solo con documentación.

Fuentes consultadas:
- [Preguntas Frecuentes - Greenter](https://greenter.dev/faq/)
- [Códigos de errores SUNAT - Catálogo de Facturación Electrónica - Mifact](https://mifact.net/codigos-de-errores-sunat-catalogo-de-errores-de-facturacion-electronica-2022/)
- [Catálogo de Códigos de Error SUNAT - Facturalaya](https://facturalaya.com/sys/codigosdeerrorsunat/index)
- [Códigos de error Sunat - NubeFacT](https://www.nubefact.com/codigos-error-sunat/)
- [Códigos de Error SUNAT - Girasol](https://girasol.pe/errores-sunat/)

## 6. Referencia — API oficial de ValidaPSE (proporcionada por el usuario)

Documentación de la API de Comprobantes (CPE) de ValidaPSE, usada como referencia canónica para el
diseño de las dos acciones de consulta (sección 7):

```
Autenticación: Authorization: Bearer TOKEN_ACCESO (por empresa)

POST /api/cpe/generar             — firma el XML (solo producción)
POST /api/cpe/generarenviar       — firma y envía en una sola operación
POST /api/cpe/enviar              — envía un XML ya firmado
GET  /api/cpe/consultar/{ruc-tipo-serie-numero}  — recupera el CDR de SUNAT

Demo: /api/cpe/generar-demo, /api/cpe/generarenviar-demo,
      /api/cpe/enviar-demo, /api/cpe/consultar-demo/{...}

Éxito: {"isSuccess": true, "estado": 200, "codigo_hash": "...", "mensaje": "...",
        "xml": "...", "external_id": "..."}
Error: {"isSuccess": false, "estado": 400, "message": "...", "errors": "..."}
```

Nota: en los datos reales encontrados, `estado` puede venir en 501 (no documentado en los ejemplos
de éxito/error de arriba) — otro punto a aclarar empíricamente con ValidaPSE.

## 7. Decisiones de diseño ya tomadas por el usuario (para el fix, aún no implementado)

1. **Modo SUNAT directo (certificado digital del tenant)**: nunca debe consultar ni hacer fallback
   a PSE. Es un canal completamente independiente.
2. **Modo PSE**: en vez de que el sistema decida automáticamente cuál consulta confiar (como hace
   hoy `FiscalCdrRecoveryService`, causando el falso positivo de la sección 2), debe ofrecer **dos
   acciones manuales separadas** para un operador humano:
   - **"Consultar validez directo a SUNAT"** — usa únicamente la respuesta de SUNAT, sin ningún
     fallback a PSE.
   - **"Consultar al PSE"** — usa únicamente la respuesta de PSE.
   Ninguna de las dos debe combinarse automáticamente en una decisión de "aceptado" sin que el
   operador vea y confirme el resultado.
3. **No modificar la librería Greenter** (vendor) — cualquier corrección debe vivir en la capa
   propia de `facturador_lycet` (los servicios que ya existen: `FiscalCdrRecoveryService`,
   `FiscalEmitProcessor`, los clasificadores), no en `vendor/greenter/*`.
4. **Reintentos**: clasificar por código de error real (rangos de la sección 5) cuáles ameritan
   reintento (excepciones de sistema, 0100-1999) y cuáles no (rechazo de negocio, ≥2000). Límite
   máximo propuesto: **5 intentos totales** (hoy son hasta 20 rápidos + reintentos lentos
   indefinidos — sección 4).

## 8. Decisiones ya tomadas (ronda de preguntas del 2026-09-19)

1. **Límite de reintentos**: 5 intentos **solo para errores transitorios** (código SUNAT
   0100-1999). Un rechazo de negocio (≥2000, incluye 1032/1033/3105) queda en **0 reintentos** —
   pasa directo a estado terminal, no se reintenta el mismo comprobante.
2. **Boletas B001-1/6/7 del tenant `ortiz`**: NO se toca la base de datos todavía. Se espera
   verificación manual directa en el portal de SUNAT antes de decidir si se revierte el estado.
3. **Alcance de esta fase**: se implementa primero y únicamente en `facturador_lycet`. El panel
   central (`frontend_central`) tendrá su propio documento/fase posterior, separado, para exponer
   las mismas funcionalidades ahí — no se toca ese repo en esta fase.
4. **Investigación del error 3105**: aprobada y ejecutada — ver sección 9.2.

## 9. Hallazgos de la investigación adicional (2026-09-19, misma sesión)

### 9.1 CORREGIDO (2026-09-19, misma sesión, con evidencia directa): el disparador NO fue el worker automático — fue un clic humano en "Validar con SUNAT"

**Esta sección reemplaza la conclusión anterior, que era incorrecta.** La primera vez se afirmó
que no hubo ninguna petición HTTP de consulta en la ventana 16:45-16:56 y que el disparador era
`processDueRetries()`. Se volvió a rastrear con más cuidado, con dos hallazgos que cambian la
conclusión por completo:

**1. `processDueRetries()` para `fiscal:cdr_consult` es código muerto.** Se buscó cada llamada a
`FiscalQueueService::scheduleRetry()` en todo el repo (`grep -rn "scheduleRetry" src/`) — solo hay
5, y ninguna usa `QUEUE_CDR_CONSULT`:

| Llamada | Cola que programa |
|---|---|
| `FiscalEmailProcessor.php:89` | `QUEUE_EMAIL` |
| `FiscalEmitProcessor.php:395` | `QUEUE_STATUS_POLL` |
| `FiscalEmitProcessor.php:641` | `$retryQueue` (EMIT/PSE_RETRY) |
| `FiscalStatusPollProcessor.php:237` | `QUEUE_STATUS_POLL` |
| `FiscalWebhookSyncProcessor.php:58` | `QUEUE_WEBHOOK_SYNC` |

El ZSET de Redis que `processDueRetries()` lee para `fiscal:cdr_consult` (línea 190-196) nunca se
llena — nada programa una re-consulta diferida. Esa rama del worker automático, aunque existe en
el código, **nunca se ejecuta en la práctica**. El docblock de `FiscalCdrConsultProcessor` que dice
*"no hay reintentos periódicos automáticos; el usuario decide cuándo volver a consultar"* resulta
ser **correcto**, no incorrecto como se afirmó antes.

**2. El disparador real: consulta manual del dashboard, con evidencia exacta cruzando BD + nginx.**
Se obtuvo el `document_uuid` de B001-1, B001-6 y B001-7 (tenant `ortiz`, RUC 10758320397) desde
`fiscal_documents` y se buscó cada uno en `nginx/access.log` del 19/09. Los tres tienen peticiones
`POST /documents/{uuid}/consult-cdr?via=sunat` cuyo timestamp coincide **al segundo exacto** con el
`accepted_at`/`updated_at` guardado en la base de datos (nginx en hora Lima UTC-5, MySQL en UTC —
diferencia de 5h confirmada con `date` + `NOW()` del propio servidor):

| Boleta | UUID | Petición que coincide | Hora Lima (log) | `accepted_at` (BD, UTC) |
|---|---|---|---|---|
| B001-1 | `a11119ad-adb8-...` | `consult-cdr?via=sunat` (2do intento, tras un 1er `via=sunat` y un `via=pse` que no cambiaron nada) | 11:53:28 | 16:53:28 |
| B001-6 | `d04c3d9c-d0d7-...` | `consult-cdr?via=sunat` (1er intento) | 11:56:53 | 16:56:53 |
| B001-7 | `9f69e071-65ec-...` | `consult-cdr?via=sunat` | 11:45:46 | 16:45:46 |

Todas las peticiones vienen de IPs de Cloudflare con `User-Agent` de navegador (Chrome/Windows) y
`Referer: https://facturador.tukifac.com/dashboard` — es decir, **una persona usando el botón
"Validar con SUNAT" del modal**, no un proceso automático. `sunat_message` guardado en los 3
documentos es idéntico: *"Aceptado por SUNAT (validado por consulta de estado; CDR no disponible
aún en SUNAT). Detalle SUNAT: El comprobante existe y está aceptado."* — confirma que la propia API
de consulta de SUNAT (no PSE) devolvió ese texto para las 3 boletas, y `applyValidWithoutCdr()` lo
tomó como aceptación firme sin haber recibido nunca un CDR real.

**Conclusión revisada (correcta)**: el bug NO es un ciclo automático fuera de control — es que el
botón manual ya existente **"Validar con SUNAT" (`via=sunat`)**, cuando SUNAT responde con un texto
que *suena* a aceptado pero sin CDR adjunto, hoy cambia `status` a `accepted` en el mismo clic, sin
que la persona vea la respuesta cruda antes de que eso ocurra. Esto no cambia el diseño ya
propuesto en la sección 11 (11.1 ya cubre manual y automático por igual, con la misma regla "solo
CDR real mueve `status`") — pero sí cambia la urgencia relativa: el disparador es un flujo que un
operador usa activamente hoy en producción, no una cola de fondo silenciosa.

### 9.2 Alcance real del error 3105 — no son 109 ventas rotas

Cifra corregida (antes solo se había contado líneas de log, que se repiten cada vez que un mismo
documento se reintenta):

- **Solo 14 documentos fiscales distintos** tienen actualmente `sunat_code='3105'` — no 109. Las
  109 líneas de log son reintentos acumulados de esos mismos 14 documentos a lo largo del tiempo.
- Fechas de creación de esos 14: desde **2026-06-27** hasta **2026-09-19 01:55** (hoy). **Sí hay al
  menos un caso nuevo de hoy** (tenant `bluemoon`, venta 14, creada 01:55am, ya con 5 reintentos) —
  no es 100% histórico como se suponía.
- El caso más extremo es tenant `maracay`, venta 101 (creada el **2026-08-04**, hace más de un
  mes): sigue reintentando hoy mismo con **12 reintentos** acumulados, siempre con el mismo 3105 —
  ejemplo directo de por qué hace falta el límite de 5 con clasificación por rango (sección 8.1):
  este documento nunca debió reintentarse pasado el primer rechazo de negocio.

### 9.3 Causa raíz confirmada del error 3105 — ítems manuales con precio 0 y afectación "Gravado"

Se investigó la venta 14 de `bluemoon` (`saas_tenant_bluemoon.tenant_sale_items`) y se encontró el
ítem exacto: `id=124, product_id=NULL, code='MANUAL', description='SEGUNDO PEDIDO', unit_price=
0.000001, igv_affectation_type='10' (Gravado), tax_amount=0.000000`. Es decir: un **ítem manual**
(no del catálogo — el cajero lo escribió directo en el POS/Registrar venta con "Producto manual"),
con precio prácticamente cero, pero dejado con la afectación IGV por defecto **"10 - Gravado"** —
una línea Gravado con IGV calculado en 0.00 es justamente lo que SUNAT rechaza con el código 3105
("el XML debe contener al menos un tributo por línea de afectación por IGV").

**Se confirmó que no es un caso aislado**: se revisó también la venta 101 de `maracay`
(`saas_tenant_maracay`) — mismo patrón exacto: `id=92, product_id=NULL, code='MANUAL',
description='Cachapa con 1/2 queso de mano', unit_price=0.000000, igv_affectation_type='10',
tax_amount=0.000000`. Dos tenants de rubros distintos, mismo patrón — es un problema sistémico de
uso, no un bug puntual de una venta.

Revisado el componente `ManualItemModal` en `frontend_tenant/src/pages/sales/
SalesRegisterPage.tsx` (línea 3258): el campo de precio (`MoneyAmountInput`) **sí permite
legítimamente un precio de 0** (`Math.max(0, v)`, sin mínimo mayor a cero) — y el selector de
"Afectación IGV" si tiene opciones no gravadas (exonerado, inafecto, gratuito, etc.) disponibles.
El problema no es que el sistema fuerce un precio falso — es que **no impide ni advierte** cuando
un cajero deja un ítem con precio 0 (para un cortesía/regalo/referencia como "segundo pedido") pero
sin cambiar la afectación IGV del valor por defecto "10 - Gravado" al tipo correcto para esa
situación (normalmente "Gratuito"/bonificación, código SUNAT distinto).

**Esto no es responsabilidad de `facturador_lycet`** — `facturador_lycet` solo serializa fielmente
lo que le envía `backend_go`. El origen y la corrección de este problema específico están en
`backend_go`/`frontend_tenant` (fuera del alcance de esta fase, que es solo `facturador_lycet`),
pero queda documentado aquí porque explica por completo el error 3105 y descarta la hipótesis
inicial de que fuera un problema del generador de XML de este repositorio o de la librería
Greenter.

## 10. Próximos pasos propuestos (no implementados — a la espera de aprobación)

1. Corregir `FiscalCdrRecoveryService`/`applyValidWithoutCdr()` (llamado tanto desde el botón manual
   "Validar con SUNAT"/"Consultar PSE" como, en teoría, desde el ciclo automático — ver 9.1
   corregida: en la práctica el disparador real confirmado es el botón manual, el ciclo automático
   de `fiscal:cdr_consult` es código muerto hoy) para que **ninguno de los dos** pueda mover un
   documento a `accepted` sin CDR real — separar "consultar" (solo informa) de "aceptar" (acción
   humana explícita), según el diseño de 11.1-11.2.
2. Extender la clasificación por rango de código SUNAT (`SunatCdrClassifier`) para que también se
   aplique a los códigos embebidos en respuestas PSE (1032, 1033, 3105, etc.), no solo al CDR
   directo — hoy esos códigos llegan como `error_type:"transient"` sin pasar por el clasificador.
3. Implementar el límite de 5 reintentos solo para errores transitorios (decisión de la sección 8),
   reemplazando el reintento lento indefinido de `applyFailure()`.
4. **(Fuera de `facturador_lycet`, para una fase aparte en `backend_go`/`frontend_tenant`)**:
   evitar que un ítem manual con precio 0 se pueda guardar/emitir con afectación IGV "Gravado" —
   causa raíz confirmada del error 3105 (sección 9.3). Posibles enfoques a evaluar en esa fase:
   validar en el frontend, o rechazar en `backend_go` al crear la venta.
5. Revisar manualmente en el portal de SUNAT el estado real de B001-1, B001-6 y B001-7 del tenant
   `ortiz` antes de decidir si se revierte su estado en la base de datos.

Ningún paso de esta lista se ejecuta hasta que el usuario lo apruebe explícitamente.

## 11. Diseño técnico propuesto — ciclo automático de validación (NO implementado)

**Actualizado 2026-09-19 tras aclaraciones del usuario sobre cómo funciona realmente la consulta a
PSE** (ver 11.0). Reemplaza el diseño inicial de esta sección (antes basaba el bloqueo en si había
un `rejected_at` previo; ahora la regla es más simple y más estricta). Ningún archivo de código
modificado todavía.

### 11.0 Aclaraciones del usuario que definen la regla final

1. **`estado: 501`** (y cualquier código de `estado` de PSE en general) **no debe tratarse como un
   código de error de SUNAT**. PSE no da suficiente detalle para eso. En ese caso: no reintentar
   automáticamente, pero sí capturar/guardar la respuesta cruda (tiene información útil), y dejar
   el documento disponible para **reenvío manual**.
2. El endpoint `/api/cpe/consultar/...` de ValidaPSE **a veces sí devuelve el CDR real, a veces
   no** — no se puede asumir ninguno de los dos casos como la regla general. Cuando no lo devuelve,
   la respuesta cruda debe mostrarse para que una persona la lea y decida manualmente si ese
   comprobante se sincroniza con el tenant — **nunca de forma automática**, para evitar falsos
   positivos.
3. Con eso confirmado, la regla debe ser la **conservadora**: el ciclo automático nunca marca
   `accepted` sin CDR real, exista o no un rechazo previo — la existencia de un `rejected_at` deja
   de ser la condición que activa el bloqueo (como se había planteado antes); el bloqueo aplica
   siempre que no haya CDR real, punto.

### 11.1 Regla unificada (reemplaza el diseño anterior)

**Solo un CDR real (`CdrResponse` con XML firmado, clasificado por `SunatCdrClassifier`) puede
mover el `status` de un documento a `accepted`/`observed`/`rejected`.** Ninguna otra señal
(`isSuccess` de PSE, el campo `estado`, o el texto de `SunatValidityClassifier`) alcanza por sí
sola para cambiar el estado — ni desde el ciclo automático, ni desde el clic manual. Esto aplica
igual a `consultPse()` y a `consultSunatDirect()`.

Cuando la consulta (automática o manual) **no** trae CDR real:

- Se captura y persiste la respuesta cruda del proveedor en el documento (hoy `provider_detail`
  solo viaja en la respuesta HTTP de la llamada manual y se pierde en el ciclo automático —
  **debe guardarse siempre**, por ejemplo reutilizando `pseResponseJson` o agregando un campo
  nuevo tipo `lastConsultDetail`).
- El `status` **no cambia**.
- **No se reintenta la consulta ni el envío automáticamente** por este resultado.
- El documento queda disponible para dos acciones manuales ya existentes en el dashboard:
  **"Reenviar"** (si se decide que el comprobante nunca llegó bien y hay que reintentarlo) o una
  **nueva acción explícita** "Marcar como aceptado sin CDR" (ver 11.2) si, tras leer la respuesta
  cruda, la persona decide que sí corresponde aceptarlo.

Esto simplifica el `$automatic` que se había propuesto en la versión anterior de este diseño: ya
no hace falta distinguir automático/manual para decidir SI se acepta — en ambos casos la regla es
la misma (nunca sin CDR). Sigue haciendo falta un flag simple para decidir si se **reintenta la
consulta** automáticamente más tarde o se deja quieta esperando acción manual (ver 11.3).

### 11.2 Nueva acción explícita — "Marcar como aceptado sin CDR"

Hoy `applyValidWithoutCdr()` combina en un solo paso "consultar" + "aceptar" — eso es justo lo que
causa el falso positivo, porque el humano ve la respuesta cruda **después** de que el estado ya
cambió (en el `alert()` del dashboard, línea 603 de `views/fiscal_dashboard.html`).

**Propuesta**: separar en dos pasos:

1. `consultPse()`/`consultSunatDirect()` (sin CDR) solo **informan** — devuelven `provider_detail`
   y el veredicto de `SunatValidityClassifier`, pero **no tocan `status`**.
2. Nuevo endpoint/acción `POST /documents/{uuid}/accept-without-cdr` (solo disponible cuando ya se
   consultó y no hay CDR) → ejecuta lo que hoy hace `applyValidWithoutCdr()`, pero como una
   decisión humana explícita y separada, nunca como efecto colateral de "consultar". En el
   dashboard: un botón nuevo que aparece **después** de ver la respuesta cruda en pantalla (no en
   un `alert()`), para que la persona lea antes de decidir — no que decida y después vea qué dijo.

Este endpoint nuevo **no se ofrece nunca desde el ciclo automático** — no existe ninguna llamada a
él fuera de un clic humano explícito.

### 11.3 Cambio — clasificar códigos SUNAT embebidos en respuestas PSE (para saber si reintentar el ENVÍO, no la consulta)

Esto sigue aplicando igual que en la versión anterior de este diseño, y es independiente del punto
11.1 (aquí hablamos de si se reintenta el **envío** del comprobante tras un fallo de emisión, no de
si se acepta tras una consulta):

`SunatCdrClassifier::isBusinessRejectionCode()` (ya correcto, sección 4.1) solo se usa hoy con un
`CdrResponse` real. Las respuestas de PSE traen el código SUNAT real a veces en el campo `code` del
JSON (ej. `"code":"1033"`, `"code":"3105"` — ya vistos en los datos reales), y a veces no (ej.
`"code":"HTTP"`, o el `estado:501` sin más detalle de la sección 11.0 punto 1 — ese caso, por
instrucción del usuario, **tampoco** se mapea a un código SUNAT).

**Propuesta**: cuando el campo `code` de la respuesta PSE sea un código SUNAT numérico
identificable, pasarlo por `SunatCdrClassifier::isBusinessRejectionCode()` igual que con el CDR
directo, en vez de asumir `error_type: transient` por defecto. Cuando no sea identificable
(`"HTTP"`, `estado` sin código SUNAT claro, etc.), **no inventar una clasificación** — tratarlo
como caso sin veredicto claro, sin reintento automático, disponible para reenvío manual (mismo
criterio que 11.1).

### 11.4 Cambio — límite de 5 reintentos, solo transitorios (envío, no consulta)

En `FiscalEmitProcessor::applyFailure()` (línea 606-655):

- Cambiar el default de `maxRetries()` (línea 594) de `20` a `5`.
- El branch actual "agotados los rápidos → reintento lento cada 900s indefinidamente" (línea
  645-652) se **elimina** para errores de negocio (ya cortados antes por el cambio de 11.3) y para
  códigos no identificables (11.0 punto 1). Para errores realmente transitorios que agoten los 5
  intentos, pasar a un estado terminal `STATUS_ERROR` con **`retryable=false`** (corregido
  2026-09-19, ver 13.10 — poner solo `nextRetryAt=null` no alcanza) — disponible para reintento
  manual desde el dashboard (`btnRetry`, ya existe, no depende de `retryable`), pero ya no para que
  el propio sistema siga insistiendo solo vía el barrido de huérfanos (13.10).

### 11.5 Qué pasa con `processDueRetries()` para la cola `fiscal:cdr_consult`

**Actualizado 2026-09-19 — esta pregunta queda resuelta, no hace falta decidir nada.** Se confirmó
(sección 9.1, corregida) que ningún lugar del código llama `scheduleRetry(..., QUEUE_CDR_CONSULT)`
— el ZSET de esa cola nunca se llena, así que la rama de `processDueRetries()` que la reencola
(línea 190-196) es código muerto: existe pero nunca corre en producción. No hay que decidir si
limitarla a 5 intentos ni nada parecido, porque no hay ningún reintento automático real que limitar
hoy. El docblock de `FiscalCdrConsultProcessor` (línea 12-16) que dice *"no hay reintentos
periódicos automáticos; el usuario decide cuándo volver a consultar"* **es correcto tal cual está**
— no hace falta corregirlo (al revés de lo que se dijo en la versión anterior de esta sección).

Dado esto, no se propone tocar `processDueRetries()` ni la cola `fiscal:cdr_consult` en esta fase.
Si en el futuro se decide agregar un reintento automático real de consulta (hoy no existe), esa
sería una función nueva, no una corrección de algo que ya está retrayendo — y en ese caso sí
aplicaría limitarla, siguiendo la misma regla 11.1 (nunca acepta sin CDR, automático o no).

Lo que sí conviene revisar, dado el hallazgo real de 9.1: si conviene que el botón manual **"Validar
con SUNAT"** tenga algún límite de clics repetidos en poco tiempo (se vieron 2 clics seguidos en
7 segundos para B001-1) — no como protección contra falsos positivos (eso ya lo resuelve 11.1), sino
para no saturar la API de SUNAT si alguien hace clic varias veces seguidas mientras espera una
respuesta. Punto menor, no bloqueante.

### 11.6 Qué NO cambia

- No se toca `vendor/greenter/*` (restricción ya acordada).
- El modo SUNAT directo (`consultSunatDirect()`) queda cubierto por la misma regla 11.1
  automáticamente, sin lógica aparte.
- No se implementa nada de esto todavía — es diseño para discutir.

### 11.7 Pruebas a escribir cuando se implemente (no escritas todavía)

Siguiendo el patrón ya usado en el repo (`tests/`):
1. Consulta (automática o manual) sin CDR real, con `isSuccess:true` de PSE → `status` NO cambia,
   se persiste la respuesta cruda, no se reintenta el envío.
2. Nueva acción "Marcar como aceptado sin CDR" ejecutada explícitamente por un humano → sí aplica
   `STATUS_ACCEPTED` (comportamiento equivalente al `applyValidWithoutCdr()` actual, pero como paso
   separado y deliberado).
3. Código PSE `3105`/`1033`/`1032` embebido en el envío → clasificado como rechazo de negocio, no
   reintenta el envío.
4. `estado:501` / código PSE no identificable → nunca se mapea a un código SUNAT, no reintenta
   automáticamente, queda disponible para reenvío manual.
5. Documento transitorio que agota 5 intentos de envío → termina en `STATUS_ERROR` retryable
   manual, sin reintento automático adicional.
6. Consulta con CDR real disponible → sigue aplicándose vía `SunatCdrClassifier` exactamente como
   hoy (este camino no cambia).

**Nota (2026-09-19, ver sección 12): el punto 4 de arriba queda parcialmente desactualizado** — se
confirmó que `estado:501` casi siempre SÍ trae un código identificable (`0111`, `0109`, `1033`,
etc.) en el campo `code`/`errores` de la respuesta PSE, solo que hoy no se está leyendo por un bug
de captura. Ver sección 12 para el detalle completo y la propuesta de reclasificación.

## 12. Manejo de los "rechazados por PSE" mal capturados (2026-09-19, evidencia real de producción)

**Contexto**: a pedido del usuario, antes de continuar el diseño de la sección 11, se revisaron los
291 documentos PSE marcados `status='rejected'` en producción hoy, código por código, usando el
JSON crudo (`pse_response_json`) en vez del `sunat_message` que se guarda (que resultó ser
genérico e inútil para el diagnóstico — ver 12.2). Ningún código modificado todavía.

### 12.1 Resultado de la revisión — de 291 "rechazados", solo ~13 lo son de verdad

| Código PSE embebido | Casos | Qué significa realmente | ¿Es un rechazo real de SUNAT sobre el contenido del comprobante? |
|---|---|---|---|
| `1033` | 99 (34%) | "Comprobante ya registrado con otros datos" — **ya fue aceptado por SUNAT antes** | **No** — debía ir al flujo de "ya informado, consultar CDR" (`SunatDuplicateClassifier`), no a rechazado |
| `0111` | 84 (29%) | "No tiene el perfil para enviar comprobantes electrónicos — Rejected by policy" | **No** — bloqueo de cuenta/contrato PSE, no del comprobante |
| `0109` | 31 (11%) | "Servicio de autenticación no disponible" | **No** — caída transitoria del propio PSE |
| `0154` | 21 (7%) | "El RUC del archivo no corresponde al RUC del usuario / proveedor no autorizado" | **No** — credencial PSE mal asignada a un RUC que no le corresponde |
| `0151` | 21 (7%) | "El nombre del archivo ZIP es incorrecto" | **No** — bug de generación de nombre de archivo en `facturador_lycet` |
| `HTTP` / Bad Request | 15 (5%) | Respuesta HTTP genérica sin detalle SUNAT | Ambiguo — sin investigar a fondo todavía |
| sin `code` (`{"message":"Server Error"}`) | 5 (2%) | Error de infraestructura del propio PSE, ni siquiera llegó a evaluar el comprobante | **No** |
| `1032` | 13 (4%) | "Ya informado y anulado/rechazado" | **Sí** — este es el único caso realmente terminal |
| `0100` | 4 (1%) | "El sistema no puede responder su solicitud. Intente nuevamente..." | **No** — transitorio explícito en el propio mensaje de PSE |
| `1079` | 4 (1%) | "Solo puede enviarse en resumen diario — presentación fuera de fecha (5 días)" | **No** — problema de tiempo de envío, no de contenido del comprobante |
| `isSuccess:true` + XML firmado real, sin `cdr` embebido | 2 | Emisión **realmente exitosa** (una es una guía de remisión completa y válida) | **No** — ni siquiera debía llegar a esta bandeja |

29 tenants distintos afectados por `0111` a lo largo de 10 días distintos (04 al 19 de sept.); el
mismo RUC de proveedor PSE (`20600337832`) aparece repetido en todos los casos de `0154` contra
varios RUCs de tenant distintos — ambos patrones apuntan a un problema de configuración de cuenta,
no a comprobantes individuales con contenido inválido.

### 12.2 Causa raíz técnica — un typo de una palabra con el mayor impacto

`PseResponseFormatter::message()` (`src/Service/Fiscal/Provider/PseResponseFormatter.php:17`) busca
el mensaje de error en las claves `'mensaje', 'message', 'errors', 'error'`. El campo real que
devuelve ValidaPSE es **`errores`** (plural, en español) — que no está en esa lista. Por eso, para
cada uno de estos casos:

1. El mensaje que ve el resto del código queda **vacío** (`$out->pseMessage = ''`).
2. `SunatDuplicateClassifier::isAlreadySubmitted($estado, $mensaje)` recibe ese mensaje vacío y
   nunca detecta frases como "registrado previamente" que sí están en el JSON crudo — así los 99
   casos de `1033` nunca se clasifican como "ya informado" y caen directo a rechazado.
3. Lo que se guarda en `sunat_message` (lo que se ve en el dashboard) termina siendo el genérico
   `"Rechazado por PSE"` en vez del detalle real — por eso ha sido tan difícil, durante toda esta
   sesión, entender qué pasó en cada caso mirando solo el dashboard.

**Ojo con el formato real**: en la respuesta exitosa de ejemplo (12.1, última fila) el campo viene
como `"errores":[]` (arreglo vacío), y en las respuestas con error viene como `"errores":"texto..."`
(string) — el campo cambia de tipo según el caso. Cualquier fix debe manejar ambas formas (string
directo, o arreglo — posiblemente de strings) sin asumir un único tipo.

### 12.3 Propuesta de reclasificación por código PSE (diseño, no implementado)

Extiende el punto 11.3 (que ya proponía clasificar códigos SUNAT embebidos en PSE) con la lista
real de códigos vistos en producción:

| Código | Tratamiento propuesto |
|---|---|
| `1033` | Corregir el bug de 12.2 primero — una vez que el mensaje real llegue a `SunatDuplicateClassifier`, este código ya lo detecta solo (`DUPLICATE_CODES` ya incluye `'1033'`, no hace falta agregarlo). Pasa a "ya informado, consultar CDR" automáticamente. |
| `1032` | Dejar como rechazo terminal (correcto hoy) — comprobante ya anulado en SUNAT, no hay nada que reenviar. |
| `0109`, `0100`, respuesta sin `code` (`Server Error`) | Tratar como **transitorio**: mismo camino de reintento que una falla de conexión (`applyFailure()` con `errorType='transient'`, cola `QUEUE_PSE_RETRY`), en vez de terminal inmediato. |
| `0111` | Ver 12.3.1 — **corregido 2026-09-19**: aunque suele ser un error transitorio del lado de SUNAT, el reenvío **debe ser manual**, no automático — el usuario decide cuándo reintentar, el sistema no reintenta solo. |
| `0154` | Ver 12.3.1 — **corregido 2026-09-19**: se acepta el mensaje tal cual (RUC/proveedor no autorizado) — la causa se corrige editando el usuario SOL o las credenciales del PSE para ese tenant (acción manual, fuera de `facturador_lycet`, en panel central/Clave SOL). Pero a diferencia de `0111`, el **reenvío del documento sí puede volver a intentarse de forma automática** (mismo camino transitorio que `0109`/`0100`, con el tope de 5 de la sección 11.4) — así, una vez corregida la credencial, el reintento ya programado lo resuelve solo sin que alguien tenga que hacer clic documento por documento. |
| `0151` | Bug propio de `facturador_lycet` en la generación del nombre del ZIP. **Se deprioriza investigar la causa exacta por ahora** (decisión del usuario, 2026-09-19) — mientras tanto, tratar estos 21 casos igual que cualquier error no identificado: sin reintento automático, disponible para reenvío manual. |
| `1079` | Señala que el documento se intentó enviar más de 5 días después de su fecha de emisión — casi siempre síntoma de que quedó atascado en la cola de reintentos por otro motivo (ver el caso de `maracay`/3105 en la sección 9.2, 12+ reintentos durante más de un mes). Con el límite de 5 reintentos de la sección 11.4 ya propuesto, este síntoma debería dejar de producirse hacia adelante. Los casos ya existentes son casos perdidos — no se puede reenviar un comprobante fuera de fecha, requiere resumen diario o un documento nuevo (fuera del alcance de `facturador_lycet`, similar al caso de 9.3). |
| `HTTP` / Bad Request | Falta investigar el detalle antes de decidir — no se ha revisado si es un problema del lado de `facturador_lycet` (payload mal formado) o del lado de PSE. Por ahora, tratamiento conservador: transitorio con límite de reintentos, igual que 0109/0100, hasta investigarlo mejor. |
| `isSuccess:true` sin `cdr` pero con `xml`/`signedXml` presente | Bug de condición aparte del typo: `buildEmitResult()` solo acepta como exitoso `isSuccess && cdrZip !== null` — un `isSuccess:true` con XML firmado pero sin CDR embebido hoy cae a rechazado. Con la regla ya acordada en 11.1 ("solo un CDR real mueve el status a `accepted`"), la corrección correcta NO es marcarlo aceptado sin CDR — es tratarlo como **`STATUS_SENT`** (se envió, PSE lo tomó, falta el CDR) en vez de `STATUS_REJECTED`, disponible para consulta manual de CDR, igual que cualquier otro documento enviado sin CDR inmediato. |

### 12.3.1 `0111` vs `0154` — quién responde estos errores realmente (investigado, con fuentes)

Ninguno de los dos es un bug de `facturador_lycet` ni tampoco, en rigor, un error de ValidaPSE — son
respuestas que **SUNAT** le da a ValidaPSE, y ValidaPSE solo las reenvía tal cual. La diferencia
importante es a quién/qué hay que corregir en cada caso:

**`0111` — "No tiene el perfil para enviar comprobantes electrónicos — Rejected by policy"**: es un
error conocido y documentado de **SUNAT** (no específico de PSE ni de este proyecto — aparece igual
usando Greenter directo, ver los issues oficiales del propio Greenter). Ocurre cuando el "usuario
secundario SOL" que se usa para emitir no tiene habilitado el perfil de comprobantes electrónicos en
SUNAT. Causas típicas documentadas:
- Usuario secundario recién creado — SUNAT tarda ~24h en activar el perfil.
- Al usuario secundario le faltan casillas de permiso habilitadas en Clave SOL.
- **Inestabilidad general de la plataforma de SUNAT** — hay reportes históricos de otros
  proveedores de facturación mostrando este mismo error durante caídas masivas de SUNAT, afectando
  a muchos contribuyentes a la vez, sin relación con la configuración de ninguno en particular.

En los datos reales (12.1) este código golpeó **29 tenants distintos en 10 días distintos** — un
patrón que encaja mucho más con inestabilidad intermitente de SUNAT que con 29 configuraciones
individuales mal hechas al mismo tiempo.

**Decisión del usuario (2026-09-19), corrige la propuesta inicial de este documento**: aunque suele
ser un error transitorio de SUNAT que se resuelve reenviando, **ese reenvío debe hacerse de forma
manual, no automática** — el sistema no reintenta `0111` solo, queda disponible para que una persona
decida cuándo reenviarlo (el mismo criterio general de este plan: preferir la acción humana
explícita antes que un reintento silencioso, igual que ya se decidió para las consultas de CDR en
la sección 11).

Fuentes: [Error 0111 — Greenter Community](https://community.greenter.dev/d/157-codigo-error-0111-no-tiene-perfil-para-enviar-comprobantes-electronicos), [thegreenter/greenter#92](https://github.com/giansalex/greenter/issues/92), [Mifact — Caída Servidor SUNAT Error 0111](https://mifact.net/se-reporta-caida-en-la-plataforma-sunat-error-0111-02-08-2022/)

**`0154` — "El RUC del archivo no corresponde al RUC del usuario / proveedor no autorizado"**: esto
**sí es específico por tenant** y no se arregla solo con reintentar el envío del mismo documento. En
el sistema PSE de SUNAT, cada contribuyente (RUC) debe **afiliar explícitamente**, desde su propia
Clave SOL, a qué Proveedor de Servicios Electrónicos autoriza a emitir en su nombre (SUNAT lo llama
"Padrón de PSE"). El mensaje real visto en los datos (`"El proveedor no esta autorizado a emitir
comprobantes: 20600337832 diff <RUC del tenant>"`) confirma exactamente esto: el RUC `20600337832`
(el PSE que usa Tukifac) **no está afiliado/autorizado en SUNAT** para emitir en nombre de esos
tenants específicos.

**Decisión del usuario (2026-09-19)**: se acepta el mensaje tal cual lo dice SUNAT — la corrección
real es editar el usuario SOL o las credenciales del PSE para ese tenant (acción manual, de
configuración, fuera del código de `facturador_lycet`). Pero, a diferencia de `0111`, una vez
corregida esa configuración, **el reenvío del documento sí debe poder volver a intentarse de forma
automática** — por eso `0154` se clasifica como transitorio (mismo camino que `0109`/`0100`, tope de
5 intentos de la sección 11.4) en vez de terminal: si la credencial ya fue corregida por un admin,
el próximo reintento programado lo resuelve solo, sin que nadie tenga que hacer clic documento por
documento; si la credencial sigue sin corregirse, los 5 intentos se agotan igual y termina
disponible para reenvío manual como cualquier transitorio agotado.

Fuentes: [Significado del código 0154 — Factura24](https://www.factura24.pe/codigo-de-error-sunat/0154), [Padrón de Proveedores de Servicios Electrónicos — SUNAT](https://orientacion.sunat.gob.pe/3550-padron-de-proveedores-de-servicios-electronicos-pse), [Proveedor de Servicios Electrónicos — PSE, SUNAT](https://cpe.sunat.gob.pe/aliados/pse)

### 12.4 Decisiones confirmadas por el usuario (2026-09-19)

1. **Reclasificar el histórico: sí, Opción A** (re-evaluación asistida sobre los datos ya
   guardados, sin volver a llamar a PSE) — ver detalle abajo.
2. **`0151` (bug del ZIP): no investigar la causa exacta por ahora** — tratar esos 21 casos como
   cualquier error no identificado (sin reintento automático, reenvío manual disponible) hasta que
   se decida revisarlo más adelante.
3. **Confirmado el enfoque general**: clasificar correctamente tanto los errores de SUNAT (canal
   directo, ya correcto vía `SunatCdrClassifier`) como los de PSE (11.3 + 12.3 de este documento), y
   en base a esa clasificación decidir cuáles se reenvían automáticamente y cuáles quedan para acción
   manual:
   - **Automáticos (transitorios, tope de 5 intentos)**: `0109`, `0100`, `Server Error`, **`0154`**
     (la corrección de la credencial es manual, pero el reenvío del documento una vez corregida es
     automático — 12.3.1).
   - **Manuales (nunca reintenta solo)**: **`0111`** (aunque suele ser transitorio del lado de
     SUNAT, el reenvío se decide a mano — 12.3.1), `1032` (rechazo real), `0151`/`1079` (no
     identificado / caso perdido), `HTTP` (sin diagnosticar).
   Esto ya estaba definido desde la sección 11.3/11.4 — aquí solo se completa con los códigos reales
   encontrados en producción.

### 12.5 Qué hacer con los documentos que YA están mal clasificados en producción (histórico)

Esto es aparte de corregir el código hacia adelante — hay ~278 documentos ya guardados hoy con un
`status='rejected'` que, con la reclasificación de 12.3, no deberían estarlo.

**Decidido: Opción A** — re-evaluación asistida. Un comando de una sola vez (ej.
`app:fiscal:reclassify-pse-rejected`, no creado todavía) que recorre esos ~278 documentos, vuelve a
aplicar la lógica corregida de 12.2/12.3 sobre el `pse_response_json` ya guardado (sin llamar de
nuevo a PSE), y mueve cada uno a un estado más honesto:

- Los `1033` (99 docs) → estado "ya informado, pendiente de consultar CDR" (mismo estado que usa hoy
  `handleAlreadySubmitted()` para el canal directo) — **nunca** se auto-marcan `accepted`, solo
  quedan listos para que una persona use el botón "Consultar CDR" ya existente, siguiendo la regla
  de 11.1.
- Los 2 casos `isSuccess:true` sin CDR → mismo tratamiento, a `STATUS_SENT` pendiente de consulta.
- Los `0109`/`0100`/`Server Error` (40 docs) → quedan disponibles para reenvío manual inmediato
  (botón "Reenviar"), con nota de que fue una falla transitoria de PSE, no un rechazo de contenido.
- Los `0111` (84 docs) → **reenvío manual únicamente** (nunca automático, decisión del usuario en
  12.3.1), con nota explicando la causa probable (caída temporal de SUNAT, o perfil del usuario
  secundario SOL a revisar si persiste tenant por tenant).
- Los `0154` (21 docs) → se re-encolan para **reintento automático** (transitorio, tope de 5
  intentos, 12.3.1), con una nota visible de que no va a funcionar hasta que un admin corrija la
  afiliación del PSE (`20600337832`) o las credenciales para ese RUC específico en el Padrón de PSE
  de SUNAT — así, si ya se corrigió, el reintento programado lo resuelve solo; si no, se agota a los
  5 intentos igual que cualquier transitorio y queda disponible para reenvío manual.
- Los `0151` (21 docs) → reenvío manual disponible, sin nota adicional (no se investigó la causa,
  decisión del usuario en 12.4).
- Los `1032` (13 docs) no se tocan (rechazo real, terminal).
- Los `1079` (4 docs) quedan marcados como perdidos — no se pueden reenviar tal cual (fuera de
  fecha), requieren resumen diario o un documento nuevo, fuera del alcance de este repo.
- Los `HTTP`/Bad Request (15 docs) quedan disponibles para reenvío manual sin nota adicional, a la
  espera de investigarlos más adelante si vuelven a aparecer.

### 12.6 Qué NO se resuelve con esto

- `0111` no se reintenta solo (decisión del usuario, 12.3.1) — si es una caída pasajera de SUNAT, el
  reenvío manual lo resuelve; si persiste para un tenant específico, alguien tiene que revisar el
  usuario secundario SOL de ese tenant en el portal de SUNAT. Ninguno de los dos casos lo resuelve
  el código solo.
- `0154` no se arregla con ningún cambio de código ni reintentando — requiere que el tenant (o
  Tukifac en su representación) complete la afiliación del PSE `20600337832` en el Padrón de PSE de
  SUNAT, desde la Clave SOL del tenant afectado (12.3.1). Este plan solo evita que estos casos se
  vean como "rechazos de SUNAT por el contenido del comprobante" cuando en realidad es un paso de
  alta pendiente.
- El bug del nombre de ZIP (`0151`) queda sin investigar por ahora (decisión del usuario, 12.4).
- El caso `HTTP`/Bad Request (15 casos) sigue sin diagnóstico — no se sabe todavía si el problema
  es del payload que arma `facturador_lycet` o de PSE.

## 13. Diseño técnico de implementación (NO implementado — a la espera de aprobación)

Reúne en un solo lugar todos los cambios de código que hacen falta para lo decidido en las
secciones 11 y 12. Ningún archivo modificado todavía — esto es el mapa de qué tocar y cómo, para
aprobar antes de escribir código.

### 13.1 Resumen de los cambios (14 piezas, actualizado tras 13.10/13.11/14 — ambos canales, backend y frontend)

1. `PseResponseFormatter::message()` — leer el campo real `errores` (string o arreglo).
2. Extraer el código embebido (`pseResp['code']`) y pasarlo correctamente a
   `SunatDuplicateClassifier::isAlreadySubmitted()` (hoy se le pasa `estado`, no `code`).
3. Nuevo clasificador **unificado** `FiscalErrorBucketClassifier` (13.4) — decide el "bucket"
   (transitorio / solo-manual / negocio) para PSE **y** para el canal directo, reemplaza también a
   `SunatTerminalFaultClassifier` (obsoleta, 13.11.3).
4. `ValidaPseProvider::buildEmitResult()` — reordenar las ramas para usar los puntos 1-3, y tratar
   `isSuccess:true` sin CDR como "ya informado" en vez de "rechazado".
5. `FiscalEmitProcessor` (rama `if ($signedXml === '')`, PSE) — enrutar según el bucket: transitorio
   → `applyFailure()` (reintento automático real, cola `QUEUE_PSE_RETRY`); solo-manual →
   `handleManualOnlyResult()` (método compartido, 13.11.4); negocio → `handlePseBusinessResult()`
   actual, sin cambios.
6. `FiscalEmitProcessor` (dispatch general, línea 221-253) — nueva rama
   `elseif ($result->errorType === 'manual_only')` antes del `else` transitorio, necesaria porque el
   canal directo casi nunca pasa por el punto 5 (`signedXml` casi siempre se llena — 13.11.4).
7. `FiscalEmitProcessor::applyFailure()` — al agotar los 5 intentos rápidos de un transitorio real,
   poner `retryable=false` (no solo quitar `nextRetryAt`) — necesario para que
   `FiscalOrphanRepairService`/`findRetryableTransientErrors()` deje de re-encolarlo solo
   (Hallazgo 2, 13.10). Aplica a ambos canales.
8. `FiscalDocumentRepository::createFilteredQuery()` línea 250 — el grupo `action` del dashboard
   debe incluir `errorType = 'manual_only'`, si no esos documentos quedan invisibles en los filtros
   del dashboard (Hallazgo 1, 13.10).
9. `SunatDirectProvider::emit()` (rama sin CDR) — usar `FiscalErrorBucketClassifier` en vez de
   `SunatTerminalFaultClassifier::isTerminal()` (13.11.3).
10. `FiscalEmitProcessor::isNonRetryableEmitError()` — agregar patrones para excepciones PHP que ni
    llegan a SUNAT: empresa deshabilitada, credenciales GRE faltantes, fecha inválida, HTTP 401/403
    de la API de guías (13.11.5).
11. `FiscalEmitResult::$errorType` — actualizar el docblock (Hallazgo 4, ya resuelto, texto listo en
    13.10).
12. `views/fiscal_dashboard.html` línea 430 (`etHint`) — agregar la rama `manual_only`, si no el
    texto de la vista de detalle sigue diciendo "· rechazo SUNAT/PSE" para estos casos (segunda
    verificación, 13.11.7).
13. `FiscalBulkActionService::shouldSkip()` — omitir `business`/`manual_only`/`permanent` agotados
    para `action IN ('send','retry')`; `force` sigue sin filtro, como override explícito (14.1.1).
14. `FiscalCdrRecoveryService::pseMessage()` (línea 651-660) — mismo fix que la pieza 1, duplicado en
    este archivo, usado en la consulta de CDR en vez de en el envío (14.2.1).

Más el comando de reclasificación histórica (13.8), ahora cubriendo ambos canales (13.11.6),
separado de estos 14 puntos porque no toca el flujo de emisión en vivo, solo relee documentos ya
guardados.

### 13.2 Fix del formatter de mensaje

`PseResponseFormatter::message()` (`src/Service/Fiscal/Provider/PseResponseFormatter.php:15-24`)
hoy busca `'mensaje', 'message', 'errors', 'error'`. Cambio propuesto:

- Agregar `'errores'` a la lista de claves a revisar.
- Manejar que el valor pueda ser un **string** (`"El comprobante fue..."`) o un **arreglo** (visto
  vacío `[]` en respuestas exitosas, y no se ha visto todavía un arreglo con contenido en los datos
  reales, pero el código no debe asumir que nunca pasa) — si es arreglo, unir los elementos no
  vacíos con un separador, igual que ya hace `SunatCdrClassifier::buildMessage()` con `notes`.
- Mantener el orden de prioridad actual (revisar `mensaje`/`message` primero, que sí calzan con el
  formato de éxito documentado en la sección 6) y agregar `errores` al final, junto a `errors`.

### 13.3 Extracción correcta del código embebido para detectar duplicados

`ValidaPseProvider::buildEmitResult()` (línea 197-207) hoy llama:

```php
SunatDuplicateClassifier::isAlreadySubmitted(
    isset($pseResp['estado']) ? (string) $pseResp['estado'] : null,   // <- esto es 501, nunca 1033
    $out->pseMessage
)
```

Cambio: extraer `$embeddedCode = isset($pseResp['code']) ? (string) $pseResp['code'] : null;` una
sola vez al inicio del método, y pasar `$embeddedCode` (no `estado`) como primer argumento. Esto es
un bug **separado** del typo de 13.2 — cualquiera de los dos arregla el caso `1033` por su cuenta
(el código directo, o el texto del mensaje una vez que ya no llega vacío), pero corregir ambos es
más robusto y evita depender solo de coincidencia de texto.

### 13.4 Nuevo clasificador — unificado para PSE y canal directo (ampliado tras 13.11)

**Cambio de diseño 2026-09-19**: en vez de un clasificador exclusivo de PSE, se unifica en uno solo
— `FiscalErrorBucketClassifier` — usado por **ambos canales** (PSE en 13.5, SUNAT directo en 13.11).
La razón: al investigar el Hallazgo 3 (13.10/13.11) se confirmó que el canal directo sufre
exactamente el mismo patrón `0111` que PSE (mismo texto de SUNAT, 67 documentos, 22 tenants) — tenerlo
duplicado en dos clasificadores distintos habría sido inconsistente y con las reglas desincronizadas
tarde o temprano. Nueva clase en `src/Service/Fiscal/Provider/`, misma carpeta que `SunatCdrClassifier`
y `SunatDuplicateClassifier`, mismo estilo (estático, sin estado):

```php
final class FiscalErrorBucketClassifier
{
    public const BUCKET_TRANSIENT = 'transient';       // reintento automático (tope 5, sección 11.4)
    public const BUCKET_MANUAL_ONLY = 'manual_only';    // nunca reintenta solo
    public const BUCKET_BUSINESS = 'business';          // rechazo real, terminal

    // Códigos vistos en PSE (campo `code` del JSON). 0154: ver 12.3.1, la corrección es
    // manual pero el reintento del documento sí es automático.
    private const TRANSIENT_CODES = ['0109', '0100', '0154'];

    // Códigos de negocio puntuales por DEBAJO de 2000 (no los cubre el rango oficial de
    // SunatCdrClassifier) — heredado de SunatTerminalFaultClassifier::TERMINAL_CODES.
    // 1032: "comprobante ya informado, estado anulado o rechazado" — el correlativo quedó
    // quemado, reintentar el MISMO documento nunca va a funcionar (distinto de 1033, que sí
    // ya fue aceptado — ver SunatDuplicateClassifier).
    private const BUSINESS_CODES_BELOW_2000 = ['1032'];

    // Fragmentos de mensaje (normalizados sin acentos, en minúsculas) que valen para AMBOS
    // canales — el texto de SUNAT es idéntico salga por SOAP fault (directo) o reenviado
    // dentro del JSON de PSE.
    private const MANUAL_ONLY_NEEDLES = [
        'no tiene el perfil para enviar comprobantes',  // 0111, ambos canales
    ];
    private const BUSINESS_NEEDLES = [
        'estado anulado o rechazado', 'con estado anulado', 'con estado rechazado', // 1032, heredado
    ];

    public static function classify(?string $code, ?string $message = null): string
    {
        $code = $code !== null ? trim($code) : '';
        $normalizedMessage = self::normalize((string) $message);

        if ($code !== '' && in_array($code, self::BUSINESS_CODES_BELOW_2000, true)) {
            return self::BUCKET_BUSINESS;
        }
        foreach (self::BUSINESS_NEEDLES as $needle) {
            if ($normalizedMessage !== '' && str_contains($normalizedMessage, $needle)) {
                return self::BUCKET_BUSINESS; // 1032 por texto, cuando el código no venga limpio
            }
        }
        foreach (self::MANUAL_ONLY_NEEDLES as $needle) {
            if ($normalizedMessage !== '' && str_contains($normalizedMessage, $needle)) {
                return self::BUCKET_MANUAL_ONLY; // 0111, por texto — no siempre trae code='0111' limpio
            }
        }
        if ($code === '0111') {
            return self::BUCKET_MANUAL_ONLY;
        }
        if ($code !== '' && in_array($code, self::TRANSIENT_CODES, true)) {
            return self::BUCKET_TRANSIENT;
        }
        // Reutiliza el rango oficial SUNAT ya implementado (>= 2000 = rechazo de negocio),
        // en vez de mantener una segunda lista de códigos de negocio a mano.
        if (SunatCdrClassifier::isBusinessRejectionCode($code !== '' ? $code : null)) {
            return self::BUCKET_BUSINESS;
        }
        if ($code === '') {
            return self::BUCKET_TRANSIENT; // "Server Error" / sin código — infraestructura, transitorio
        }
        return self::BUCKET_MANUAL_ONLY; // 0151, 1079, HTTP, o código nuevo no visto — 11.3: no inventar clasificación
    }

    private static function normalize(string $value): string
    {
        $value = mb_strtolower(trim($value), 'UTF-8');
        $map = ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n'];

        return strtr($value, $map);
    }
}
```

Nota: el código `HTTP` (Bad Request, 15 casos en PSE, 3 en directo) cae en el `return` final
(`BUCKET_MANUAL_ONLY`) por ahora — no se separa en su propia categoría porque todavía no se
investigó (12.6), pero al no estar en ninguna lista específica, automáticamente recibe el
tratamiento conservador (manual, sin reintento) que ya se decidió para él (11.3: no inventar
clasificación para lo que no se entiende).

### 13.5 Cambios en `ValidaPseProvider::buildEmitResult()`

Reescritura de la parte final del método (hoy línea 194-213), agregando la rama de "éxito sin CDR" y
delegando la clasificación de fallas al nuevo clasificador:

```php
if ($isSuccess && $out->cdrZip !== null) {
    $out->sunatCode = '0';
    $out->sunatMessage = $out->pseMessage ?: 'Aceptado vía PSE';
} elseif ($isSuccess) {
    // PSE confirma éxito pero no trae el CDR embebido en esta respuesta — NO reenviar
    // (ya se envió), consultar el CDR por separado. Mismo tratamiento que "ya informado".
    $out->sunatCode = $estado !== null ? (string) $estado : null;
    $out->sunatMessage = $out->pseMessage ?: 'Enviado a PSE, CDR pendiente de consulta';
    $out->alreadySubmitted = true;
} elseif (SunatDuplicateClassifier::isAlreadySubmitted($embeddedCode, $out->pseMessage)) {
    $out->sunatCode = $embeddedCode;
    $out->sunatMessage = $out->pseMessage ?: 'El comprobante fue informado anteriormente';
    $out->rejected = false;
    $out->alreadySubmitted = true;
    $out->errorType = 'transient';
} else {
    $bucket = FiscalErrorBucketClassifier::classify($embeddedCode, $out->pseMessage);
    $out->sunatCode = $embeddedCode ?? ($estado !== null ? (string) $estado : 'error');
    $out->sunatMessage = $out->pseMessage ?: 'Rechazado por PSE';
    if ($bucket === FiscalErrorBucketClassifier::BUCKET_BUSINESS) {
        $out->rejected = true;
        $out->errorType = 'business';
    } elseif ($bucket === FiscalErrorBucketClassifier::BUCKET_TRANSIENT) {
        $out->rejected = false;
        $out->errorType = 'transient';
    } else { // BUCKET_MANUAL_ONLY
        $out->rejected = false;
        $out->errorType = 'manual_only'; // nuevo valor, ver 13.7
    }
}
```

`$estado` es `$pseResp['estado'] ?? null`, ya disponible en el método. El caso `isSuccess:true` sin
CDR reutiliza el flag `alreadySubmitted` que ya existe y ya está bien manejado en el procesador — no
hace falta un estado nuevo para eso.

### 13.6 Cambios en `FiscalEmitProcessor`

Hoy (línea 163-172), si no hay `signedXml`, todo lo que trae `pseResponse` va sin distinción a
`handlePseBusinessResult()`. Cambio propuesto — enrutar antes según `$result->errorType`:

```php
if ($signedXml === '') {
    if (!empty($result->pseResponse)) {
        if ($result->errorType === 'transient') {
            $this->recordAttempt($doc, $attemptNum, $providerName, FiscalDocument::STATUS_RETRYING, $result, null, $started);
            $this->applyFailure($doc, $empresa, 'transient', $result->pseMessage, $attemptNum, $started);
        } elseif ($result->errorType === 'manual_only') {
            $this->handleManualOnlyResult($doc, $result, $providerName, $attemptNum, $started); // nuevo método, compartido con 13.11.4
        } else {
            $this->handlePseBusinessResult($doc, $result, $providerName, $attemptNum, $started); // sin cambios, para 'business'
        }
        if ($doc->getFiscalFingerprint()) {
            $this->queue->releaseClaim($doc->getFiscalFingerprint());
        }
        return;
    }
    throw new \RuntimeException('Emisión sin XML firmado');
}
```

`handleManualOnlyResult()` (nuevo, muy parecido a `handlePseBusinessResult()` pero sin
`STATUS_REJECTED`/`rejectedAt` — usa `STATUS_ERROR` con `retryable=true` y **sin** llamar
`applyFailure()` ni `scheduleRetry()`, para que quede disponible para el botón "Reenviar" pero
nunca se reintente solo. Nombre genérico a propósito — no asume nada de PSE, se reutiliza también
desde el dispatch general del canal directo, ver 13.11.4):

```php
private function handleManualOnlyResult(
    FiscalDocument $doc,
    FiscalEmitResult $result,
    string $providerName,
    int $attemptNum,
    float $started
): void {
    $doc->setSentAt($doc->getSentAt() ?? new \DateTimeImmutable());
    $doc->setSunatCode($result->sunatCode);
    $doc->setSunatMessage($result->pseMessage ?: 'PSE no pudo procesar el comprobante — requiere revisión manual');
    $doc->setStatus(FiscalDocument::STATUS_ERROR);
    $doc->setErrorType('manual_only');
    $doc->setRetryable(true);
    $doc->setNextRetryAt(null); // nunca se auto-programa
    if (!empty($result->pseResponse)) {
        $doc->setPseResponseJson(json_encode($result->pseResponse, JSON_UNESCAPED_UNICODE) ?: null);
    }
    $this->recordAttempt($doc, $attemptNum, $providerName, FiscalDocument::STATUS_ERROR, $result, null, $started);
    $this->em->flush();
    $this->notifyOrEnqueueSync($doc);
}
```

### 13.7 Nuevo valor de `error_type`: `'manual_only'`

`FiscalDocument::$errorType` (`src/Entity/FiscalDocument.php:136`) hoy solo distingue
`transient`/`permanent`/`business` por convención (no es un enum de base de datos, es un
`varchar(20)`, así que agregar un valor nuevo no requiere migración). Se usa para:

- Filtrar en el dashboard/reportes sin mezclar "rechazo real de SUNAT" (`business`) con "PSE no
  pudo procesar por un motivo ajeno al contenido del comprobante" (`manual_only`) — hoy ambos casos
  se ven idénticos en la lista (`STATUS_REJECTED`/`STATUS_ERROR` sin distinción visual).
- `FiscalDocumentRepository` ya filtra por `errorType` en un par de queries (línea 237, 250, 384) —
  revisar si alguna de esas queries necesita incluir `manual_only` explícitamente o si al ya no ser
  `permanent` ni `transient` cae naturalmente donde corresponde (pendiente de revisar al
  implementar, no se tocó ese archivo todavía en esta sesión).

### 13.8 Comando de reclasificación histórica

Nuevo comando, ej. `app:fiscal:reclassify-pse-rejected` (`src/Command/`, mismo patrón que
`FiscalRequeueOrphanedCommand`), diseño:

1. Selecciona todos los `fiscal_documents` con `send_mode='pse' AND status='rejected'`.
2. Para cada uno, vuelve a decodificar su `pse_response_json` ya guardado (**no** llama a PSE de
   nuevo) y le aplica la misma lógica corregida de 13.2-13.5 "en frío" (parseo puro, sin llamar al
   proveedor) para obtener el bucket correcto.
3. Según el bucket resultante, mueve el documento:
   - "ya informado" (`1033`, o `isSuccess:true` sin CDR) → mismo tratamiento que
     `handleAlreadySubmitted()`: `STATUS_ERROR`, `retryable=false`, mensaje indicando consultar CDR
     manualmente. **Nunca** pasa a `accepted` automáticamente (regla 11.1).
   - `transient` (`0109`, `0100`, `Server Error`, `0154`) → `STATUS_ERROR`, `retryable=true`, y
     **si** se decide reintentarlos automáticamente de una vez, programar el primer reintento
     (`scheduleRetry`) — o dejarlos solo marcados y que el próximo ciclo normal los tome, a decidir
     al implementar.
   - `manual_only` (`0111`, y lo no clasificado: `0151`, `1079`, `HTTP`) → `STATUS_ERROR`,
     `retryable=true`, `nextRetryAt=null`, disponible para el botón "Reenviar".
   - `business` (`1032`) → no se toca.
4. Modo `--dry-run` obligatorio primero (imprime cuántos documentos cambiarían a cada bucket, sin
   escribir nada) — dado el volumen (278 documentos reales de producción), correr el comando a
   ciegas directo sobre datos reales no es aceptable; hay que ver el resumen antes de confirmar.
5. Registra en `FiscalAuditLog` cada cambio (evento nuevo, ej. `fiscal_pse_reclassified`), con el
   estado anterior y el nuevo, para trazabilidad — esto no es un cambio silencioso de datos de
   producción.

### 13.9 Pruebas a escribir (además de las ya listadas en 11.7)

1. `PseResponseFormatter::message()` con `errores` como string, como arreglo vacío, como arreglo con
   contenido, y con las claves viejas (`mensaje`/`message`) — todas deben seguir funcionando.
2. `1033` con el mensaje real completo (`"El comprobante fue registrado previamente..."`) →
   `isAlreadySubmitted()` lo detecta, ya sea por código o por texto.
3. `FiscalErrorBucketClassifier::classify()` con cada código/mensaje real de 12.1 y 13.11 (ambos
   canales) → bucket esperado según la tabla de 12.3/13.4/13.11.
4. `isSuccess:true` sin `cdr` → `alreadySubmitted=true`, nunca `rejected=true`.
5. Código `0111` → `errorType='manual_only'`, `STATUS_ERROR`, `retryable=true`, sin `nextRetryAt`.
6. Código `0109` → `errorType='transient'`, pasa por `applyFailure()`, programa reintento real.
7. Comando de reclasificación histórica, en `--dry-run` y en modo real, sobre un set de documentos
   de prueba con cada bucket — verificar que ninguno termine en `accepted` sin CDR real.

### 13.10 Verificación de consistencia contra el código real (2026-09-19)

A pedido del usuario, antes de implementar se releyó el código exacto donde va cada cambio de las
secciones 11-13 (no solo los archivos ya citados, también sus consumidores: repositorios,
controladores, y el worker). Se encontraron **4 inconsistencias reales** entre el diseño y el
código — 2 críticas que hay que corregir en el diseño antes de programar, 1 relacionada pero de
otro alcance, y 1 menor de documentación.

**Hallazgo 1 (crítico) — los filtros del dashboard no conocen `manual_only`.**
`FiscalDocumentRepository::createFilteredQuery()` (línea 233-256) arma los grupos que ve el usuario
en el dashboard (`processing`, `accepted`, `observed`, `rejected`, `action`, `cancelled`). El grupo
`action` ("Requiere acción", línea 249-250) filtra explícitamente
`d.errorType = 'permanent' OR d.errorType IS NULL` — **no** incluye `'manual_only'`. Sin corregir
esto, los ~141 documentos que la sección 12 planea mover a `manual_only` (`0111`, `0151`, `1079`,
`HTTP`) desaparecerían de los 6 filtros del dashboard — no estarían en "rechazado" (correcto, ya no
lo están) pero tampoco en "acción" (incorrecto, ahí es donde deben aparecer). **Corrección al
diseño**: la línea 250 debe quedar
`d.errorType = 'permanent' OR d.errorType = 'manual_only' OR d.errorType IS NULL`. Se agrega a la
lista de 13.1 como pieza 6.

**Hallazgo 2 (crítico) — existe un CUARTO mecanismo de reintento automático, no contemplado en las
secciones 11 ni 13 hasta ahora.** Además de (a) los reintentos rápidos con backoff de
`applyFailure()`, (b) el ZSET de Redis (`scheduleRetry`/`processDueRetries`) y (c) la cola
`fiscal:cdr_consult` (confirmada código muerto en 9.1), hay un **cuarto camino**:
`FiscalOrphanRepairService::repairBatch()` (llamado desde `FiscalWorkerCommand::repairOrphansIfIdle()`
— corre dentro del mismo worker permanente, cuando `fiscal:emit` está vacía — y también desde el
comando aparte `FiscalRequeueOrphanedCommand`). Este servicio usa
`FiscalDocumentRepository::findRetryableTransientErrors()`, que selecciona **cualquier** documento
con `status=error AND errorType=transient AND retryable=true AND (nextRetryAt IS NULL O ya pasó)`
dentro de una ventana de edad (por defecto 48h, `FISCAL_RETRY_MAX_AGE_SEC`, no configurada en este
repo) — **sin mirar `retry_count` ni `maxRetries()` en absoluto** — y lo re-encola a `fiscal:emit`.

Esto significa que el diseño de 11.4 ("agotados los 5 intentos → `STATUS_ERROR` con `retryable=true`
pero sin `nextRetryAt`, solo manual") **no se cumpliría tal como estaba escrito**: `nextRetryAt IS
NULL` sigue calzando con la condición de este barrido, así que el documento seguiría
reencolándose solo indefinidamente (hasta los 48h de edad) por esta vía, aunque `applyFailure()` ya
no lo programe por la suya. **Ya corregido en 11.4 y en esta sección**: al agotar los 5 intentos,
hay que poner `retryable=false`, no solo `nextRetryAt=null`. Se verificó que esto es seguro: el
botón manual "Reintentar" (`FiscalController::retry()`, línea 308-311) no revisa `retryable` en
absoluto — encola directo a `fiscal:emit` sin condición — así que apagar `retryable` no rompe el
reintento manual. La misma corrección aplica igual al nuevo bucket `transient` de PSE (`0109`,
`0100`, `Server Error`, `0154`) cuando agote sus 5 intentos — no es exclusivo del canal directo.

**Hallazgo 3 (incluido en esta fase, decisión del usuario 2026-09-19 — profundizado con evidencia
real, ver 13.11)**: lo que empezó como "falta agregar 3105 a una lista" resultó ser un problema
mucho más grande en el canal SUNAT directo. Se hizo el mismo tipo de auditoría que en la sección 12
pero para documentos del canal directo: **91 documentos, 27 tenants**, con `errorType=transient` y
`retry_count >= 5` (algunos con **160-203 reintentos acumulados**, algunos activos desde hace 7
semanas). El patrón dominante (67 de 91, 22 tenants) es el **mismo error `0111`** ya visto en PSE
("No tiene el perfil para enviar comprobantes electrónicos") — confirma que es un error genuino de
SUNAT, no algo de PSE. El resto son mezclas de rechazos de negocio reales sin CDR parseable (`3105`
y otros ≥2000), y errores que ni siquiera llegan a SUNAT (bugs de datos/configuración propios).
Detalle completo, evidencia y diseño del fix en la nueva sección 13.11.

**Hallazgo 4 (documentación, ya resuelto)**: el docblock de `FiscalEmitResult::$errorType`
(línea 40-44) documentaba solo `'business'`/`'transient'`/`null`. Texto de reemplazo listo para
implementar:

```php
/**
 * Tipo de fallo cuando no hay veredicto de aceptación:
 *  - 'business'     → rechazo definitivo de SUNAT/PSE (código 2000+, o casos puntuales <2000
 *                      como 1032). Terminal, no se reintenta.
 *  - 'transient'     → falla técnica/temporal (SUNAT sin CDR, excepción de sistema 0100-1999,
 *                      red, PSE 0109/0100/0154/Server Error). Reintentable hasta 5 intentos
 *                      (FiscalErrorBucketClassifier), luego pasa a manual.
 *  - 'manual_only'   → SUNAT/PSE respondió pero el motivo no se resuelve reintentando solo
 *                      (perfil SOL sin habilitar código 0111, credenciales, nombre de archivo,
 *                      fuera de fecha, o no identificado). Reenviable solo por acción humana.
 *  - null            → aceptado / observado (sin fallo).
 */
public ?string $errorType = null;
```

**Todo lo demás verificado sí es consistente**: los nombres de propiedades de `FiscalEmitResult`
(`success`, `rejected`, `alreadySubmitted`, `errorType`, etc.) coinciden exactamente con lo asumido
en 13.5-13.6; el orden real de `FiscalEmitProcessor::process()` revisa `$result->alreadySubmitted`
**antes** (línea 155) que la rama `if ($signedXml === '')` (línea 163) — así que el fix de 13.5 para
`isSuccess:true` sin CDR (que marca `alreadySubmitted=true`) queda correctamente interceptado por
`handleAlreadySubmitted()` sin necesidad de tocar nada más en `process()`, incluso en el caso real
visto en 12.1 donde sí venía un `xml` firmado (esa rama nunca llega a compararse contra
`isAccepted()`/`rejected` porque el chequeo de `alreadySubmitted` corta antes).

### 13.11 Hallazgo 3 profundizado — el mismo problema en el canal SUNAT directo (2026-09-19)

A pedido del usuario, se investigó con la misma profundidad que la sección 12 pero para el canal
directo, y se incluye esta corrección en la misma fase. Ningún código modificado todavía.

### 13.11.1 Evidencia real (91 documentos, 27 tenants)

Documentos con `send_mode` distinto de `pse`, `status IN (error, retrying)`, `errorType=transient`
y `retry_count >= 5`:

| Patrón de mensaje | Docs | Tenants | Máx. reintentos | Naturaleza real |
|---|---|---|---|---|
| "No tiene el perfil para enviar comprobantes electrónicos" | 67 | 22 | 166 | El mismo `0111` de PSE — confirma que es un error de SUNAT, no de PSE |
| `[401] Client error: POST https://api-cpe.sunat.gob.pe/...` | 5 | 1 | 166 | HTTP 401 de la API REST de guías (GRE) — credenciales/token inválidos |
| "El XML no contiene el tag o no existe información del u..." | 4 | 2 | 154 | Rechazo de negocio (código ≥2000, estructura del XML) |
| "Bad Request" | 3 | 3 | 7 | Ambiguo, igual que el `HTTP` de PSE |
| "El XML no contiene tag de la cantidad del concepto..." | 2 | 1 | 194 | Rechazo de negocio (código ≥2000) |
| "Fecha del pago único o de las cuotas no puede ser anter..." | 2 | 2 | 167 | Rechazo de negocio (validación de fecha de pago) |
| "Si el tipo de transacción es al Crédito debe consignars..." | 2 | 2 | 163 | Rechazo de negocio (campo obligatorio faltante) |
| "Solo puede enviar el comprobante en un resumen diario..." | 2 | 1 | 161 | Mismo `1079` de PSE — fuera de fecha |
| "El valor de venta por ítem difiere de los importes..." | 1 | 1 | 166 | Rechazo de negocio (cálculo) |
| "Empresa fiscal deshabilitada o no registrada" | 1 | 1 | 203 | **No es un error de SUNAT** — excepción PHP local (`EmpresaNoRegistradaException`), configuración del tenant |
| "Invalid datetime ..., expected format..." | 2 | 2 | 181 / 174 | **No es un error de SUNAT** — excepción PHP local (bug de formato de fecha) |

**Nota de transparencia**: no se pudo determinar con certeza completa, revisando solo el código,
por qué documentos tan viejos (`consorciobarra`, creado 2026-08-01) siguen con 203 reintentos hoy —
con la configuración por defecto (`FISCAL_MAX_RETRIES=20`, `FISCAL_RETRY_MAX_AGE_SEC=48h`, **ninguna
de las dos configurada en producción**, confirmado por SSH) el reintento lento debería haber dejado
de tocarlos después de 48h de antigüedad. No se investigó más a fondo el mecanismo exacto (requeriría
leer logs históricos del worker) porque no cambia la corrección: con el fix de abajo, ninguno de
estos casos vuelve a clasificarse como `transient`, así que los 3 mecanismos de reintento automático
(rápido, ZSET, barrido de huérfanos) dejan de tocarlos de raíz, sin importar cuál los sostenía.

### 13.11.2 Dos code paths distintos, dos fixes distintos

**(a) `SunatDirectProvider::emit()`, rama sin CDR (línea 116-141)** — cubre los patrones que SUNAT sí
alcanza a responder (con o sin CDR parseable): el `0111`, los rechazos de negocio (`≥2000`), `1079`,
etc. Se resuelve con el mismo `FiscalErrorBucketClassifier` de 13.4.

**(b) `FiscalEmitProcessor::isNonRetryableEmitError()` (línea 659-679)** — cubre excepciones PHP que
ni siquiera llegan a contactar a SUNAT: `EmpresaNoRegistradaException` ("deshabilitada o no
registrada", también "no tiene configuradas las credenciales SUNAT API GRE" desde
`SeeApiFactory::createConfiguredApi()`), errores de formato de fecha ("Invalid datetime"), y errores
HTTP de la API REST de guías (`SeeApiFactory` usa `api-cpe.sunat.gob.pe` vía Guzzle — un
`[401]`/`[403] Client error` de ahí es casi siempre credenciales inválidas, no una caída de red).
Estos NUNCA pasan por `SunatDirectProvider`, así que el fix de (a) no los cubre — hay que ampliar
esta función aparte.

### 13.11.3 Cambio en `SunatDirectProvider::emit()`

Reemplaza el uso de `SunatTerminalFaultClassifier::isTerminal()` (boolean) por
`FiscalErrorBucketClassifier::classify()` (3 buckets), en la rama sin CDR (línea 133-141):

```php
if (SunatDuplicateClassifier::isAlreadySubmitted($faultCode, $out->sunatMessage)) {
    $out->alreadySubmitted = true;
} else {
    $bucket = FiscalErrorBucketClassifier::classify($faultCode, $out->sunatMessage);
    if ($bucket === FiscalErrorBucketClassifier::BUCKET_BUSINESS) {
        $out->sunatCode = $faultCode ?? $out->sunatCode;
        $out->rejected = true;
        $out->errorType = 'business';
    } elseif ($bucket === FiscalErrorBucketClassifier::BUCKET_MANUAL_ONLY) {
        $out->sunatCode = $faultCode ?? $out->sunatCode;
        $out->errorType = 'manual_only';
    }
    // BUCKET_TRANSIENT: no toca nada, $out->errorType ya quedó 'transient' por defecto (línea 123)
}
```

`SunatTerminalFaultClassifier` queda **obsoleta** — su lógica (código `1032` + needles de mensaje)
ya vive dentro de `FiscalErrorBucketClassifier::BUSINESS_CODES_BELOW_2000`/`BUSINESS_NEEDLES` (13.4).
Se elimina el archivo al implementar, o se deja como alias deprecado si se prefiere no romper
referencias — a decidir al implementar.

### 13.11.4 `manual_only` necesita una rama nueva en el dispatch general de `process()` — hallazgo adicional

Al diseñar 13.11.3 se encontró que el fix **no alcanza por sí solo**: en el canal directo,
`$out->signedXml` se llena **siempre** que Greenter llega a firmar el XML localmente (línea 94,
`$see->getFactory()->getLastXml()`), **incluso si el envío después falla** — a diferencia de PSE,
donde a veces no hay `signedXml` en absoluto. Esto significa que el canal directo casi nunca entra
por la rama `if ($signedXml === '')` de `FiscalEmitProcessor::process()` (donde sí implementé el
enrutamiento por bucket en 13.6) — entra por el dispatch general más abajo (línea 221-253), que hoy
solo distingue `isObserved()` / `isAccepted()` / `rejected` / y-si-no-nada-de-eso-entonces-transitorio.
Ese dispatch **no sabe nada de `manual_only`** — un documento clasificado `manual_only` caería en el
`else` final y se reintentaría automáticamente igual, contradiciendo todo el diseño.

**Corrección**: agregar una rama explícita en el dispatch general, antes del `else` transitorio
(línea 237 actual):

```php
if ($result->isObserved()) {
    ...
} elseif ($result->isAccepted()) {
    ...
} elseif ($result->rejected) {
    ...
} elseif ($result->errorType === 'manual_only') {   // NUEVO
    $this->handleManualOnlyResult($doc, $result, $providerName, $attemptNum, $started);
    if ($doc->getFiscalFingerprint()) {
        $this->queue->releaseClaim($doc->getFiscalFingerprint());
    }
    return;
} else {
    // transitorio real, sin cambios
}
```

Y el método `handlePseManualOnlyResult()` de 13.6 se **generaliza** (se quita "Pse" del nombre,
`handleManualOnlyResult()`) para poder llamarse desde los dos puntos: la rama temprana
`if ($signedXml === '')` (PSE sin XML) y este nuevo punto del dispatch general (directo, y PSE en el
caso raro de que sí traiga `signedXml` pero termine clasificado `manual_only`). El cuerpo del método
no cambia — ya no asumía nada específico de PSE.

### 13.11.5 Cambio en `FiscalEmitProcessor::isNonRetryableEmitError()`

Añade patrones nuevos a la lista existente (línea 662-672), todos mapeando a `ERROR_PERMANENT` (ya
existente, no hace falta un bucket nuevo aquí — estos ni siquiera llegan a SUNAT, son configuración
o datos, y `ERROR_PERMANENT` ya implica "requiere acción manual", exactamente el tratamiento
correcto):

```php
foreach ([
    'openssl_sign',
    'openssl_pkey_get_private',
    'private key',
    'cannot be coerced',
    'certificado inválido: ',
    'certificado inválido',
    'clave privada',
    'cliente no autorizado',
    'token gre rechazado',
    'deshabilitada o no registrada',              // NUEVO — EmpresaNoRegistradaException
    'no tiene configuradas las credenciales',      // NUEVO — SeeApiFactory, credenciales GRE faltantes
    'invalid datetime',                            // NUEVO — bug de formato de fecha
] as $needle) {
    if (str_contains($m, $needle)) {
        return true;
    }
}
// NUEVO — HTTP 401/403 de la API REST de guías: credenciales/token, no red caída.
if (preg_match('/\[40[13]\]\s*client error/i', $m) === 1) {
    return true;
}
```

### 13.11.6 Tabla de reclasificación — los 91 documentos ya afectados

Mismo criterio que 12.5 (histórico), pero para el canal directo. Se agrega al comando de
reclasificación de 13.8 (ahora cubre ambos canales, no solo PSE — o se hace un comando hermano, a
decidir al implementar):

- `0111` (67 docs) → `manual_only`, `STATUS_ERROR`, `retryable=false` (agotado, más de 5 intentos ya
  acumulados — no tiene sentido dejarlo `retryable=true` como si fuera nuevo), disponible para
  reenvío manual.
- Rechazos de negocio sin CDR (XML sin tag, fecha de pago, crédito sin campo, valor de venta —
  10 docs) → `business`, `STATUS_REJECTED`, terminal.
- `1079` (2 docs) → mismo criterio que en PSE, perdidos, requieren resumen diario o documento nuevo.
- `[401] api-cpe.sunat.gob.pe` (5 docs, 1 tenant) → `permanent`, nota indicando revisar
  CLIENT_ID/CLIENT_SECRET de la API GRE para ese tenant en panel central.
- "Empresa deshabilitada" (1 doc) → `permanent`, nota indicando revisar configuración de la empresa.
- "Invalid datetime" (2 docs) → `permanent`, nota indicando revisar el dato de fecha en el snapshot
  original (probable bug de origen en `backend_go`/`frontend_tenant`, similar en espíritu al
  hallazgo de la sección 9.3 — no investigado a fondo todavía, fuera del alcance de decidir la causa
  raíz en esta sesión).
- `Bad Request` (3 docs) → `manual_only`, sin nota adicional, igual que el `HTTP` de PSE.

### 13.11.7 Segunda verificación (2026-09-19) — un hallazgo más, en el frontend

A pedido del usuario, se volvió a revisar la coherencia completa del diseño (11-13) contra el
código real una segunda vez, esta vez incluyendo el frontend del dashboard
(`views/fiscal_dashboard.html`), no revisado a fondo en la primera pasada (13.10).

**Verificado, sin problema**: la función JS `fiscalGroup(status, errorType)` (línea 321-330), que
arma la insignia de estado en la vista de detalle, para `status==='error'` ya hace
`errorType==='transient' ? 'En proceso' : 'Requiere acción'` — como `'manual_only' !== 'transient'`,
cae solo en "Requiere acción", que es exactamente lo que se quiere, sin tocar nada. Tampoco hace
falta tocar `loadStats()`/`FiscalDocumentDetailService::globalStats()` (línea 88-129): esos
contadores agrupan por `status`, no por `error_type`, así que `manual_only` (que se queda en
`STATUS_ERROR`, nunca `STATUS_REJECTED`) ya cae donde corresponde sin cambios. Tampoco hace falta
tocar el botón "Reenviar"/"Reintentar" (`EMIT_ACTIONS`, línea 495-499): el JS no lee `doc.retryable`
en ningún lado (confirmado por búsqueda en todo el archivo) — los botones no se ocultan ni se
deshabilitan según ese campo, coherente con que el endpoint backend tampoco lo revisa (13.10,
Hallazgo 2) — o sea, poner `retryable=false` al agotar los 5 intentos (pieza 7 de 13.1) es seguro
también desde este lado, el botón manual sigue funcionando igual.

**Hallazgo nuevo (menor, frontend)**: la línea 430 de `fiscal_dashboard.html` tiene un texto
**aparte** de `fiscalGroup()` — el sufijo que se agrega junto a la insignia de estado:

```js
const etHint=doc.error_type?(doc.error_type==='transient'?' · se reintenta solo':(doc.error_type==='permanent'?' · requiere acción':' · rechazo SUNAT/PSE')):'';
```

Esta es una segunda pieza de lógica, separada de `fiscalGroup()`, con el mismo tipo de gap: solo
distingue `transient`/`permanent`, y cualquier otro valor (incluyendo el futuro `manual_only`) cae
en el `else` final, que dice **"· rechazo SUNAT/PSE"** — justo el mensaje que se quiere evitar para
estos casos (no son un rechazo de contenido). Corrección:

```js
const etHint=doc.error_type?(doc.error_type==='transient'?' · se reintenta solo':(doc.error_type==='permanent'?' · requiere acción':(doc.error_type==='manual_only'?' · requiere reenvío manual':' · rechazo SUNAT/PSE'))):'';
```

Se agrega como pieza 12 a la lista de 13.1.

**Conclusión de esta segunda verificación**: con este ajuste (pieza 12), el diseño de las secciones
11-13 queda **coherente de punta a punta** — backend (emisión, clasificación, reintentos, filtros de
repositorio) y frontend (badges, textos, botones) — contra el código real tal como existe hoy en
`facturador_lycet`. No se encontraron más inconsistencias en esta segunda pasada. Queda listo para
implementar, en el orden de 13.1, empezando por las piezas 1-3 (que no cambian comportamiento en
producción hasta que 4-11 las conecten).

### 13.11.8 Pruebas adicionales

1. `FiscalErrorBucketClassifier::classify()` con cada patrón real de 13.11.1 → bucket esperado.
2. `SunatDirectProvider::emit()` con un fault `0111` simulado (sin CDR) → `errorType='manual_only'`,
   `rejected=false`.
3. `FiscalEmitProcessor::process()` con un resultado `errorType='manual_only'` y `signedXml` no vacío
   (caso directo) → `handleManualOnlyResult()` se ejecuta, `STATUS_ERROR`, sin `applyFailure()`, sin
   reintento programado.
4. `isNonRetryableEmitError()` con cada mensaje nuevo de 13.11.5 → `true`.
5. Confirmar que un documento con `errorType='business'` (código ≥2000 real, ej. `3105` simulado sin
   CDR) nunca vuelve a pasar por `applyFailure()`.

## 14. Reglas cerradas para implementación (2026-09-19) — a pedido del usuario, antes de tocar código

El usuario pidió detenerse antes de implementar y cerrar sin ambigüedad dos reglas: (1) los 5
intentos de envío, y (2) que un CDR real no es lo mismo que "aceptado". Se releyó el código
involucrado una vez más — incluyendo el vendor de Greenter, que no se había leído directamente hasta
ahora — y se encontraron **2 hallazgos nuevos** además de confirmar/cerrar ambas reglas. Ningún
código modificado todavía.

### 14.1 Regla exacta de los 5 intentos

**Qué cuenta como "intento"**: cada vez que `FiscalEmitProcessor::process()` intenta emitir el
comprobante (SOAP a SUNAT, o HTTP a PSE) — **no** cada vez que se consulta el CDR/estado. Son cosas
completamente distintas en el código: `applyFailure()`/`maxRetries()` viven en el flujo de EMISIÓN
(`FiscalEmitProcessor`); la consulta de CDR (`FiscalCdrConsultProcessor`/`FiscalCdrRecoveryService`)
nunca llama a `applyFailure()` ni a `maxRetries()` — son dos contadores y dos límites totalmente
separados, y así queda confirmado que deben seguir siéndolo.

**Numeración exacta (verificada línea por línea en `FiscalEmitProcessor::process()` y
`applyFailure()`)**:

| Intento | `attemptNum` al inicio de `process()` | Si falla, `retry_count` después de `applyFailure()` | ¿`withinFastRetries` (`retry_count < 5`)? | Resultado |
|---|---|---|---|---|
| 1 (envío inicial) | `getRetryCount()+1` = `0+1` = **1** | 1 | `1 < 5` → sí | Se programa el intento 2 |
| 2 | 2 | 2 | `2 < 5` → sí | Se programa el intento 3 |
| 3 | 3 | 3 | `3 < 5` → sí | Se programa el intento 4 |
| 4 | 4 | 4 | `4 < 5` → sí | Se programa el intento 5 |
| 5 | 5 | 5 | `5 < 5` → **no** | Termina: `STATUS_ERROR`, sin más reintento automático |

**Confirmado: "5 intentos" significa 5 intentos TOTALES de envío, contando el envío inicial como el
intento 1** — no "5 reintentos después del primero" (que serían 6 en total). Esto es exactly la
interpretación que pidió el usuario, y **ya es así en el código actual** con solo cambiar
`maxRetries()` de `20` a `5` (13.1, pieza pendiente) — no hace falta ningún otro ajuste al contador.

**Aplica igual a ambos canales**: `applyFailure()` es una única función compartida por
`SunatDirectProvider` y `ValidaPseProvider` — la única diferencia es la cola de backoff
(`QUEUE_RETRY` vs `QUEUE_PSE_RETRY`, línea 638-640), el límite de 5 es el mismo para los dos.

**Todos los caminos que podrían volver a encolar un envío, verificados uno por uno**:

| # | Mecanismo | ¿Respeta el límite de 5 hoy? | ¿Lo respeta con el fix de 13.10 (Hallazgo 2)? |
|---|---|---|---|
| 1 | `applyFailure()` → `scheduleRetry()` → ZSET Redis → `processDueRetries()` (worker) | Sí, ya mira `retry_count < maxRetries()` antes de programar | Sí, sin cambios adicionales |
| 2 | `FiscalOrphanRepairService::repairBatch()` / `findRetryableTransientErrors()` (corre dentro del worker permanente, más el comando `FiscalRequeueOrphanedCommand`) | **No** — no mira `retry_count` en absoluto, solo `errorType=transient AND retryable=true` | Sí — al agotar los 5, `retryable` pasa a `false`, deja de matchear la consulta |
| 3 | Botón manual "Reintentar"/"Reenviar"/"Forzar" (`FiscalController::retry()`/`sendManual()`/`forceSend()`) | No aplica — es una acción humana explícita, no automática; el usuario la pidió expresamente como el canal correcto para reenviar casos agotados o `manual_only` | Sigue funcionando (no depende de `retryable`, verificado en 13.11.7) |
| 4 | **Nuevo, encontrado en esta pasada** — `POST /documents/bulk/{action}` con `action=retry\|send\|force` (`FiscalBulkActionService::byFilters()`/`byUuids()`) | **No** — `shouldSkip()` (línea 104-122) solo omite si `status===accepted`; no mira `retryable`, `errorType` ni `retry_count` para nada | Ver 14.1.1, fix nuevo propuesto |

### 14.1.1 Hallazgo nuevo — la acción masiva del dashboard no respeta la clasificación

`FiscalBulkActionService::shouldSkip()` deja pasar CUALQUIER documento a `action=retry`/`send`
mientras no esté `accepted` — no importa si ya agotó los 5 intentos, si es `business` (rechazo real),
`manual_only` o `permanent`. Esto se usa desde el panel central/`frontend_tenant` (proxy vía
`backend_go`, confirmado revisando ese repo: `TenantFiscalHandler`/`superadmin fiscal_handler.go`
solo reenvían la petición cuando alguien hace clic en un botón masivo de esas vistas — **no** se
encontró ningún cron/scheduler en `backend_go` que llame a este endpoint solo; es una acción humana,
igual que el botón individual). Por eso no viola estrictamente "ningún proceso automático" — pero sí
dejaría que un reintento masivo hecho por una persona vuelva a gastar los 5 intentos de documentos
que el diseño ya marcó como perdidos, sin que la persona lo sepa.

**Esto probablemente explica** los reintentos anómalamente altos vistos en 13.11.1 (160-203, durante
semanas, muy por encima de los límites de edad/cantidad por defecto): more consistente con alguien
usando repetidamente "Reintentar todos"/"Forzar todos" sobre un filtro amplio mientras intentaba
destrabar comprobantes manualmente, sin saber que estaban condenados a fallar (empresa deshabilitada,
perfil SOL sin habilitar, fecha inválida) — es más consistente con eso que con un proceso desatendido
corriendo solo. No se pudo confirmar con
certeza total (requeriría logs históricos de acceso a esa ruta), pero es la explicación más
consistente con la evidencia y no cambia el fix necesario.

**Fix propuesto (nueva pieza, pendiente de aprobar)**: `shouldSkip()` para `action IN ('send',
'retry')` (no para `'force'`, que sigue siendo el override explícito) debe agregar:

```php
if (in_array($action, ['send', 'retry'], true)
    && $doc->getStatus() === FiscalDocument::STATUS_ERROR
    && in_array($doc->getErrorType(), ['business', 'manual_only', 'permanent'], true)
) {
    return true; // agotado o no-reenviable sin acción externa — solo 'force' lo pasa por alto
}
```

Así, un reintento masivo (`retry`/`send`) ya no desperdicia llamadas contra SUNAT/PSE en documentos
condenados a fallar — y si alguien de verdad quiere forzarlo (ej. después de corregir la
configuración), `force` sigue disponible sin cambios.

### 14.2 CDR real vs. "aceptado" — verificado contra el código de Greenter (vendor)

**Se confirmó, leyendo directamente `vendor/greenter/core/src/Core/Model/Response/CdrResponse.php`**
(no se modifica, solo se leyó para verificar — respeta la restricción de no tocar Greenter):

```php
public function isAccepted()
{
    $code = (int)$this->getCode();
    return $code === 0 || $code >= 4000;
}
```

Es decir: **el código SUNAT dentro del CDR (`0` = aceptado sin observación, `≥4000` = aceptado CON
observación) es lo único que determina si un CDR representa una aceptación** — cualquier otro código
(`1`-`3999`, que cubre tanto las excepciones de sistema 0100-1999 como los rechazos de negocio
2000-3999) hace que `isAccepted()` devuelva `false`. Esto confirma exactamente el diagrama del
usuario: la existencia del CDR NO es la señal de aceptación — el código QUE TRAE el CDR sí lo es.

**Dónde el código de `facturador_lycet` ya hace esto bien hoy**: `SunatCdrClassifier::fromCdrResponse()`
(`src/Service/Fiscal/Provider/SunatCdrClassifier.php:18-63`) **llama primero a `$cdr->isAccepted()`**
antes de decidir nada — si es `false`, clasifica el resultado como `rejected` (código ≥2000,
terminal) o `transient` (código <2000, reintentable), **nunca** como aceptado, sin importar que el
CDR exista y se haya podido parsear perfectamente. Esta pieza **ya está correcta** y no necesita
cambios — se usa tanto en emisión (`SunatDirectProvider::emit()`) como en recuperación de CDR
(`FiscalCdrRecoveryService::applyRecoveredCdr()`), para ambos canales, siempre que exista un
`CdrResponse` real parseado.

**Dónde SÍ está el problema (confirmado, ya identificado en las secciones 2 y 11, aquí verificado
con más detalle)**: el bug nunca estuvo en `SunatCdrClassifier` — está en los caminos que **deciden
"aceptado" SIN pasar por un `CdrResponse` real parseado**:

1. `FiscalCdrRecoveryService::applyValidWithoutCdr()` (línea 386-421) — pone
   `$doc->setStatus(STATUS_ACCEPTED)` y **fabrica** `$doc->setSunatCode('0')` aunque nunca se parseó
   ningún CDR — ese `'0'` no viene de SUNAT, lo escribe el propio código como si lo fuera. Esto es
   exactamente lo que el usuario describe como el riesgo a evitar: convertir un veredicto blando
   (texto/`isSuccess`) en un código SUNAT `'0'` inventado.
2. `FiscalCdrRecoveryService::consultPse()` (línea 355-366) — cuando el PSE no trae CDR, si
   `isSuccess:true` el código **fuerza** el veredicto a `ACCEPTED` salvo que el mensaje diga
   "rechaz" explícitamente:
   ```php
   if ($isSuccess) {
       $verdict = SunatValidityClassifier::classify($estado, $statusMessage);
       if ($verdict !== SunatValidityClassifier::REJECTED) {
           $verdict = SunatValidityClassifier::ACCEPTED;
       }
   }
   ```
   Esto confirma exactamente el patrón "`isSuccess:true` del PSE → `accepted`" que el usuario pidió
   no permitir — está en el código HOY, en la consulta (no en el envío, que ya se cubrió en 13.5).
3. Lo mismo aplica al canal **directo** en consulta: `consultSunatDirect()` también puede caer en
   `applyValidWithoutCdr()` cuando `getStatusCdr()` no trae CDR pero `getStatus()` sí trae un
   `statusMessage` que `SunatValidityClassifier` interpreta como "aceptado" por texto — **el mismo
   riesgo existe en ambos canales durante la consulta**, no es exclusivo de PSE.

Estos 3 puntos son exactamente lo que la sección 11.1 ya propone eliminar ("solo un CDR real...
puede mover el `status`") y la sección 11.2 ya propone separar en un paso manual explícito
("Marcar como aceptado sin CDR"). **Esta verificación no cambia el diseño de 11.1/11.2 — lo
confirma y lo cita con evidencia exacta de vendor**, además de precisar que `applyValidWithoutCdr()`
también fabrica un `sunat_code` falso, dato que antes no se había señalado explícitamente.

### 14.2.1 Hallazgo nuevo — el mismo typo de 13.2, duplicado en otro archivo

Al releer `FiscalCdrRecoveryService` completo se encontró que tiene **su propia copia** de la
función que arma el mensaje de PSE, con el **mismo bug** ya identificado en 13.2:

```php
// FiscalCdrRecoveryService.php:651-660 — misma lista incompleta que PseResponseFormatter
private function pseMessage(array $resp): string
{
    foreach (['mensaje', 'message', 'errors', 'error'] as $key) { // falta 'errores'
        ...
```

Esto significa que el texto que alimenta a `SunatValidityClassifier::classify()` durante una
**consulta** de CDR vía PSE sufre el mismo problema que el de **emisión** (12.2): si la respuesta de
PSE solo trae el detalle en `errores` (como se confirmó en los datos reales de la sección 12), el
clasificador de validez pierde su única señal de texto y depende solo del campo `estado` (que casi
nunca es uno de los códigos `0001`/`0011` que `SunatValidityClassifier` reconoce) — degradando aún
más la fiabilidad del veredicto blando que, de todas formas, el nuevo diseño (11.1) ya no va a usar
para mover `status`. Se agrega como fix menor, mismo criterio que 13.2 (agregar `'errores'`,
manejar string/arreglo) — aunque de aquí en adelante ya no decide `accepted`, sigue siendo el texto
que una persona lee en el botón "Consultar" para decidir manualmente, así que vale la pena
corregirlo igual.

### 14.3 SUNAT directo vs. PSE — los 4 sub-casos, verificados por separado

| Sub-caso | Canal directo | Canal PSE |
|---|---|---|
| **Respuesta propia del proveedor** | El objeto `$result` que devuelve `$see->send()` (Greenter/SOAP) — no tiene un "código propio" separado del CDR, es la respuesta SOAP en sí | El JSON de ValidaPSE (`isSuccess`, `estado`, `code`, `errores`, etc.) — **esto NO es un veredicto de SUNAT**, es la capa propia del PSE |
| **Código SUNAT embebido, sin ser el CDR completo** | El `$err->getCode()` de un fault SOAP sin CDR parseable (ej. `0111`, `3105` visto como fault) — es un código real de SUNAT, pero llegó por un fault, no por un CDR firmado | El campo `code` dentro del JSON de PSE (ej. `"code":"1033"`) — el PSE simplemente reenvía el código que SUNAT le dio a él, dentro de su propio sobre |
| **CDR real recuperado** | `ConsultCdrService::getStatusCdr()` (consulta) o el CDR devuelto directo en el `send()` (emisión) → objeto `CdrResponse` real, parseado por Greenter, clasificado por `SunatCdrClassifier` | El campo `cdr`/`cdr_base64`/`contenido_cdr` (o `xml` si contiene `ApplicationResponse`) del JSON de PSE → decodificado por `DomCdrReader`/`CdrNormalizer` a un `CdrResponse` real, **mismo clasificador** `SunatCdrClassifier` una vez extraído |
| **Ausencia de CDR** | `getStatusCdr()` no devuelve `CdrResponse`, o `send()` no trae CDR — cae al `statusMessage`/`verdict` (14.2, punto 3) | Ningún campo de CDR presente en el JSON — cae al `isSuccess`/`estado`/mensaje (14.2, punto 2) |

**Regla ya establecida (11.1) y confirmada aquí**: solo el tercer caso ("CDR real recuperado") — en
**cualquiera** de los dos canales — puede mover `status` a `accepted`/`observed`/`rejected`, siempre
pasando por `SunatCdrClassifier::fromCdrResponse()` (que a su vez usa `$cdr->isAccepted()` de
Greenter, 14.2). Los otros tres casos (respuesta propia del proveedor, código embebido sin CDR
completo, y ausencia de CDR) **informan pero nunca deciden `status` por sí solos** — quedan
disponibles como texto/detalle para que una persona decida manualmente (11.2), o para clasificar si
el **envío** debe reintentarse automáticamente o no (13.4/13.11, que sí sigue usando el código
embebido para decidir la cola de reintento — eso es una decisión distinta a "aceptar", es decisión
de "reintentar el envío o no", y no cambia el estado del documento a aceptado en ningún caso).

**`isSuccess:true` nunca implica `accepted` por sí solo** — confirmado ya cubierto en 13.5 (emisión)
y ahora también en 14.2 punto 2 (consulta) — en ningún punto del diseño nuevo el campo `isSuccess`
del PSE, aislado, mueve `status`.

### 14.4 Consecuencias para el plan de implementación

1. Se agrega la **pieza 13** a 13.1: `FiscalBulkActionService::shouldSkip()` — omitir `business`/
   `manual_only`/`permanent` agotados para `send`/`retry` (14.1.1).
2. Se agrega la **pieza 14** a 13.1: `FiscalCdrRecoveryService::pseMessage()` — mismo fix que 13.2,
   duplicado en este archivo (14.2.1).
3. 11.1/11.2 quedan **confirmados sin cambios de fondo** — esta revisión los valida con evidencia de
   vendor (Greenter) y precisa que `applyValidWithoutCdr()` también fabrica un `sunat_code` falso
   (dato nuevo, agregado a la justificación de por qué ese método deja de usarse para decidir
   `status`).
4. La tabla de 14.1 (caminos de re-encolado) reemplaza/amplía la lista de mecanismos de reintento ya
   mencionada en 13.10 Hallazgo 2 y 9.1 — ahora son **4 mecanismos verificados** (ZSET rápido,
   barrido de huérfanos, botón manual, acción masiva), no 3.
5. El recuento total de piezas de implementación pasa de 12 (13.1) a **14**.
