<?php

declare(strict_types=1);

namespace App\Tests\Controller\v1;

use App\Controller\v1\FiscalController;
use App\Repository\EmpresaRepository;
use App\Repository\FiscalDocumentRepository;
use App\Service\Fiscal\FiscalBulkActionService;
use App\Service\Fiscal\FiscalCdrConsultProcessor;
use App\Service\Fiscal\FiscalCdrRecoveryService;
use App\Service\Fiscal\FiscalCompanySyncService;
use App\Service\Fiscal\FiscalConnectionTestService;
use App\Service\Fiscal\FiscalDocumentDetailService;
use App\Service\Fiscal\FiscalDocumentPdfResolver;
use App\Service\Fiscal\FiscalDocumentService;
use App\Service\Fiscal\FiscalFileFetcher;
use App\Service\Fiscal\FiscalQueueService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * Pruebas de FiscalController::parseDateFilter() — bug real: el servidor tiene
 * date.timezone=Europe/Berlin, así que interpretar un filtro "solo fecha" (del selector del
 * dashboard, que representa un día en hora de Perú) con el timezone por defecto del servidor
 * desplazaba el límite hasta 7 horas antes de medianoche real en Perú, colando en el filtro
 * comprobantes de la noche del día anterior (created_at se guarda en UTC).
 */
class FiscalControllerParseDateFilterTest extends TestCase
{
    private function buildController(): FiscalController
    {
        return new FiscalController(
            $this->createMock(FiscalDocumentService::class),
            $this->createMock(FiscalDocumentRepository::class),
            $this->createMock(FiscalDocumentDetailService::class),
            $this->createMock(FiscalQueueService::class),
            $this->createMock(FiscalFileFetcher::class),
            $this->createMock(FiscalBulkActionService::class),
            $this->createMock(EmpresaRepository::class),
            $this->createMock(FiscalCompanySyncService::class),
            $this->createMock(FiscalConnectionTestService::class),
            $this->createMock(FiscalDocumentPdfResolver::class),
            $this->createMock(FiscalCdrConsultProcessor::class),
            $this->createMock(FiscalCdrRecoveryService::class),
            $this->createMock(EntityManagerInterface::class)
        );
    }

    private function invoke(FiscalController $controller, mixed $value, bool $endOfDay): ?\DateTimeImmutable
    {
        $method = new \ReflectionMethod(FiscalController::class, 'parseDateFilter');
        $method->setAccessible(true);

        return $method->invoke($controller, $value, $endOfDay);
    }

    public function testDateOnlyFromIsMidnightPeruConvertedToUtc(): void
    {
        $controller = $this->buildController();

        $dt = $this->invoke($controller, '2026-08-29', false);

        $this->assertNotNull($dt);
        $this->assertSame('UTC', $dt->getTimezone()->getName());
        // Medianoche en Perú (UTC-5) es las 05:00 UTC del mismo día.
        $this->assertSame('2026-08-29T05:00:00+00:00', $dt->format('c'));
    }

    public function testDateOnlyToIsEndOfDayPeruConvertedToUtc(): void
    {
        $controller = $this->buildController();

        $dt = $this->invoke($controller, '2026-08-30', true);

        $this->assertNotNull($dt);
        $this->assertSame('UTC', $dt->getTimezone()->getName());
        // 23:59:59.999999 en Perú del 30/08 cae en 31/08 04:59:59 UTC.
        $this->assertSame('2026-08-31', $dt->format('Y-m-d'));
        $this->assertSame('04:59:59', $dt->format('H:i:s'));
    }

    /**
     * Reproduce el bug reportado en producción: un comprobante emitido el 28/08 a las 22:54 hora
     * de Perú (guardado como 2026-08-29T03:54:00 UTC) NO debe pasar un filtro from=2026-08-29
     * ahora que el límite se calcula en hora de Perú (05:00 UTC), aunque el timezone por defecto
     * del servidor (Europe/Berlin) lo hubiera colado antes del fix.
     */
    public function testDocumentFromPreviousPeruEveningIsExcludedByFromFilter(): void
    {
        $controller = $this->buildController();

        $from = $this->invoke($controller, '2026-08-29', false);
        $createdAt = new \DateTimeImmutable('2026-08-29T03:54:00+00:00'); // 28/08 22:54 hora Perú

        $this->assertLessThan($from, $createdAt, 'el documento de la noche anterior debía quedar antes del límite from, es decir excluido');
    }

    public function testValueWithExplicitTimeIsUsedAsIs(): void
    {
        $controller = $this->buildController();

        $dt = $this->invoke($controller, '2026-08-29T10:00:00+00:00', false);

        $this->assertNotNull($dt);
        $this->assertSame('2026-08-29T10:00:00+00:00', $dt->format('c'));
    }

    public function testEmptyValueReturnsNull(): void
    {
        $controller = $this->buildController();

        $this->assertNull($this->invoke($controller, '', false));
        $this->assertNull($this->invoke($controller, null, false));
    }
}
