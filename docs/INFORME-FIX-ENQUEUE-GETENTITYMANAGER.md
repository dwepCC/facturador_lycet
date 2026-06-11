# Informe: corrección `getEntityManager` en acciones fiscales manuales

**Fecha:** 2026-05-26  
**Error:** `Undefined method "getEntityManager". The method name must start with either findBy, findOneBy or countBy!`  
**Endpoints afectados:** `POST /api/v1/fiscal/documents/{uuid}/send|retry|force|email|poll`

---

## 1. Causa raíz

`FiscalController::enqueueAction()` llamaba:

```php
$this->repo->getEntityManager()->flush();
```

`FiscalDocumentRepository` extiende `Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository`, donde `getEntityManager()` es **protected**. Desde el controlador, PHP no puede invocarlo directamente; la llamada cae en `ServiceEntityRepository::__call()`, que solo permite métodos `findBy*`, `findOneBy*` y `countBy*`. Por eso aparece el error `Undefined method "getEntityManager"`.

**Efecto colateral:** si `queue->push()` ya había encolado el job en Redis, el `catch` devolvía HTTP 500 aunque el trabajo **sí estaba en la cola** → inconsistencia job en Redis / documento sin `status=queued` en BD.

---

## 2. Archivo y línea afectada

| Archivo | Línea (antes) | Código problemático |
|---------|---------------|---------------------|
| `facturador_lycet/src/Controller/v1/FiscalController.php` | ~441 | `$this->repo->getEntityManager()->flush();` |

---

## 3. Corrección aplicada

### 3.1 Inyección de `EntityManagerInterface`

El controlador ahora recibe `EntityManagerInterface $em` en el constructor (mismo patrón que `FiscalDocumentService`).

### 3.2 Persistencia correcta

```php
$this->em->flush();
```

La entidad `$doc` ya está managed (cargada con `findOneBy`); no requiere `persist()` adicional.

### 3.3 Manejo de excepciones separado

1. **`push` falla** → HTTP 500 (job no encolado).
2. **`push` OK, `flush` falla** → HTTP 202 ACCEPTED (job ya en Redis; no penalizar al cliente).

---

## 4. Lógica de negocio (sin cambios)

La condición para actualizar `status=queued` y `queued_at` se mantiene igual:

- Cola `fiscal:emit`
- Estado actual: `pending`, `queued` o `retrying`
- `provider === null`

Documentos `error` (failed), `accepted`, etc. se encolan pero **no** pasan por el bloque de actualización de estado (comportamiento previo, alineado con `FiscalBulkActionService`).

---

## 5. Resultado de pruebas

```bash
cd facturador_lycet
php vendor/bin/phpunit tests/Controller/v1/FiscalControllerEnqueueActionTest.php -v
```

| Caso | Documento | Acción | HTTP | `flush()` | Estado BD tras acción |
|------|-----------|--------|------|-----------|------------------------|
| 1 | `pending` | send | 202 | Sí | `queued` + `queued_at` |
| 2 | `error` (failed) | retry | 202 | No | Permanece `error` |
| 3 | `queued` | force | 202 | Sí | `queued` + `queued_at` |
| 4 | `retrying` | retry | 202 | Sí | `queued` + `queued_at` |
| 5 | `accepted` | send | 202 | No | Permanece `accepted` |
| — | no encontrado | send | 404 | No | — |
| — | `pending` + flush falla | send | 202 | Error ignorado | Job en Redis OK |

Test adicional: `FiscalDocumentRepository` no expone `getEntityManager()` como método público.

---

## 6. Confirmaciones

| Verificación | Estado |
|--------------|--------|
| `retry` encola en Redis | OK |
| `force` encola en Redis | OK |
| `send` encola en Redis | OK |
| BD sincronizada cuando aplica condición de negocio | OK |
| Sin error 500 por `getEntityManager` | OK |
| Sin error 500 si flush falla tras push exitoso | OK |

---

## 7. Referencia de implementación correcta

`FiscalDocumentService::enqueueToEmit()` ya usaba el patrón correcto:

```php
$this->em->flush();
```

La corrección alinea `FiscalController::enqueueAction()` con ese servicio.
