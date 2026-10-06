<?php

declare(strict_types=1);

namespace App\Tests\Service\Fiscal\Observability;

use App\Entity\FiscalAlert;
use App\Repository\EmpresaRepository;
use App\Repository\FiscalAlertRepository;
use App\Repository\FiscalAuditLogRepository;
use App\Service\Fiscal\FiscalQueueService;
use App\Service\Fiscal\Observability\FiscalAlertService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * Alertas: reconocer / resolver desde el panel, y orden de la lista (sin reconocer primero,
 * críticas antes que advertencias). Antes no había forma de cerrar una alerta.
 */
class FiscalAlertServiceAcknowledgeTest extends TestCase
{
    private function alert(string $type, string $severity, string $createdAt, ?string $slug = null): FiscalAlert
    {
        $a = new FiscalAlert();
        $a->setAlertType($type)->setSeverity($severity)->setMessage('msg ' . $type)->setTenantSlug($slug);
        $ref = new \ReflectionProperty(FiscalAlert::class, 'createdAt');
        $ref->setAccessible(true);
        $ref->setValue($a, new \DateTimeImmutable($createdAt));

        return $a;
    }

    private function service(FiscalAlertRepository $alerts, ?EntityManagerInterface $em = null): FiscalAlertService
    {
        return new FiscalAlertService(
            $em ?? $this->createMock(EntityManagerInterface::class),
            $alerts,
            $this->createMock(FiscalAuditLogRepository::class),
            $this->createMock(EmpresaRepository::class),
            $this->createMock(FiscalQueueService::class)
        );
    }

    public function testAcknowledgeSetsTimestampAndWhoDidIt(): void
    {
        $alert = $this->alert(FiscalAlert::TYPE_TENANT_DISCONNECTED, 'warning', '-1 hour', 'demo');
        $alerts = $this->createMock(FiscalAlertRepository::class);
        $alerts->method('find')->with(7)->willReturn($alert);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('flush');

        $out = $this->service($alerts, $em)->acknowledge(7, 'admin@tukifac.com');

        self::assertNotNull($alert->getAcknowledgedAt());
        self::assertNull($alert->getResolvedAt());
        self::assertSame('admin@tukifac.com', json_decode((string) $alert->getMetadataJson(), true)['ack_by']);
        self::assertNotNull($out['acknowledged_at']);
    }

    public function testAcknowledgeTwiceDoesNotChangeTheOriginalTimestamp(): void
    {
        $alert = $this->alert(FiscalAlert::TYPE_QUEUE_SATURATED, 'critical', '-1 hour');
        $first = new \DateTimeImmutable('-30 minutes');
        $alert->setAcknowledgedAt($first);
        $alerts = $this->createMock(FiscalAlertRepository::class);
        $alerts->method('find')->willReturn($alert);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('flush');

        $this->service($alerts, $em)->acknowledge(1, 'otro');

        self::assertSame($first, $alert->getAcknowledgedAt());
    }

    public function testResolveClosesTheAlertAndAlsoAcknowledgesIt(): void
    {
        $alert = $this->alert(FiscalAlert::TYPE_CONSECUTIVE_ERRORS, 'critical', '-2 hours', 'demo');
        $alerts = $this->createMock(FiscalAlertRepository::class);
        $alerts->method('find')->willReturn($alert);

        $out = $this->service($alerts)->resolve(3, 'admin@tukifac.com');

        self::assertNotNull($alert->getResolvedAt());
        self::assertNotNull($alert->getAcknowledgedAt());
        self::assertSame('admin@tukifac.com', json_decode((string) $alert->getMetadataJson(), true)['resolved_by']);
        self::assertNotNull($out['resolved_at']);
    }

    public function testUnknownAlertReturnsNull(): void
    {
        $alerts = $this->createMock(FiscalAlertRepository::class);
        $alerts->method('find')->willReturn(null);

        self::assertNull($this->service($alerts)->acknowledge(99, null));
        self::assertNull($this->service($alerts)->resolve(99, null));
    }

    public function testListOpenPutsUnacknowledgedFirstThenCriticalThenNewest(): void
    {
        $ackedCritical = $this->alert(FiscalAlert::TYPE_QUEUE_SATURATED, 'critical', '-1 hour');
        $ackedCritical->setAcknowledgedAt(new \DateTimeImmutable());
        $warningOld = $this->alert(FiscalAlert::TYPE_TENANT_DISCONNECTED, 'warning', '-5 hours', 'a');
        $warningNew = $this->alert(FiscalAlert::TYPE_RETRY_ANOMALY, 'warning', '-1 hour', 'b');
        $critical = $this->alert(FiscalAlert::TYPE_CONSECUTIVE_ERRORS, 'critical', '-3 hours', 'c');

        $alerts = $this->createMock(FiscalAlertRepository::class);
        $alerts->method('findOpen')->willReturn([$ackedCritical, $warningOld, $warningNew, $critical]);

        $types = array_map(static fn (array $r): string => $r['alert_type'], $this->service($alerts)->listOpen());

        self::assertSame([
            FiscalAlert::TYPE_CONSECUTIVE_ERRORS,   // sin reconocer, crítica
            FiscalAlert::TYPE_RETRY_ANOMALY,        // sin reconocer, advertencia, más reciente
            FiscalAlert::TYPE_TENANT_DISCONNECTED,  // sin reconocer, advertencia, más antigua
            FiscalAlert::TYPE_QUEUE_SATURATED,      // reconocida al final
        ], $types);
    }

    public function testSerializeIncludesAgeAndAcknowledgement(): void
    {
        $alert = $this->alert(FiscalAlert::TYPE_TENANT_DISCONNECTED, 'warning', '-2 hours', 'demo');
        $alerts = $this->createMock(FiscalAlertRepository::class);
        $alerts->method('findOpen')->willReturn([$alert]);

        $row = $this->service($alerts)->listOpen()[0];

        self::assertGreaterThanOrEqual(7190, $row['age_seconds']);
        self::assertNull($row['acknowledged_at']);
        self::assertNull($row['resolved_at']);
    }
}
