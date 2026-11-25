<?php

declare(strict_types=1);

namespace App\Tests\EventSubscriber;

use App\EventSubscriber\TokenSubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Security\Core\Security;

/**
 * Las consultas del panel sueltan el bloqueo de sesión para no poner en fila al resto de peticiones.
 */
class TokenSubscriberSessionLockTest extends TestCase
{
    private function handle(string $method, bool $isAdmin, int $expectedSaves, string $uri = '/api/v1/fiscal/documents'): void
    {
        $security = $this->createMock(Security::class);
        $security->method('isGranted')->willReturn($isAdmin);

        $session = $this->createMock(SessionInterface::class);
        $session->expects($this->exactly($expectedSaves))->method('save');
        $request = Request::create($uri, $method);
        $request->setSession($session);

        (new TokenSubscriber('secreto', $security))->onKernelRequest(
            new RequestEvent($this->createMock(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST)
        );
    }

    public function testPanelQueryReleasesTheSession(): void
    {
        $this->handle('GET', true, 1);
    }

    public function testPanelActionKeepsTheSession(): void
    {
        $this->handle('POST', true, 0, '/api/v1/fiscal/documents/abc/force');
    }

    public function testTokenRequestsDoNotTouchTheSession(): void
    {
        $this->handle('GET', false, 0, '/api/v1/fiscal/documents?token=secreto');
    }

    public function testRequestWithoutAdminOrTokenIsStillRejected(): void
    {
        $this->expectException(AccessDeniedHttpException::class);
        $this->handle('GET', false, 0);
    }
}
