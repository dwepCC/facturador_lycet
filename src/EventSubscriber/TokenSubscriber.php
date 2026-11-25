<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Security;

/**
 * Protege /api/* con CLIENT_TOKEN (ERP) o sesión admin (dashboard autenticado).
 */
class TokenSubscriber implements EventSubscriberInterface
{
    private string $token;
    private Security $security;

    public function __construct(string $token, Security $security)
    {
        $this->token = $token;
        $this->security = $security;
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        $path = $event->getRequest()->getPathInfo();

        if (substr($path, 0, 4) !== '/api') {
            return;
        }

        if ($event->getRequest()->getMethod() === 'OPTIONS') {
            return;
        }

        // Dashboard autenticado: API fiscal vía cookie de sesión (sin token en URL).
        if (strpos($path, '/api/v1/fiscal') === 0 && $this->security->isGranted('ROLE_ADMIN')) {
            $this->releaseSessionLock($event->getRequest());

            return;
        }

        // Integraciones máquina (backend_go, scripts): CLIENT_TOKEN en query string.
        $token = $event->getRequest()->query->get('token');
        if ($token !== $this->token) {
            throw new AccessDeniedHttpException('This action needs a valid token!');
        }
    }

    /**
     * PHP bloquea el archivo de sesión mientras la petición lo tiene abierto, y las demás peticiones
     * del mismo usuario esperan en fila: una consulta lenta frenaba todo el panel. Ya autenticado, una
     * consulta no vuelve a necesitar la sesión, así que se cierra antes de ejecutarla.
     *
     * Solo en GET/HEAD: las acciones (reenviar, consultar CDR...) siguen yendo de una en una por usuario.
     */
    private function releaseSessionLock(Request $request): void
    {
        if ($request->isMethodSafe() && $request->hasSession()) {
            $request->getSession()->save();
        }
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => 'onKernelRequest',
        ];
    }
}
