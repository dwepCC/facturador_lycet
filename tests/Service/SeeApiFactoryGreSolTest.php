<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\ConfigProviderInterface;
use App\Service\FileDataReader;
use App\Service\SeeApiFactory;
use PHPUnit\Framework\TestCase;

/**
 * SUNAT no tiene ambiente de pruebas para guías de remisión (09/31): solo NubeFact lo permite.
 * En "pruebas" el SOL de autenticación GRE debe salir del .env del facturador (la cuenta de
 * sandbox de NubeFact), nunca del SOL que el tenant configuró para SUNAT — y al pasar a
 * "producción" debe volver a usarse el SOL real de la empresa.
 */
final class SeeApiFactoryGreSolTest extends TestCase
{
    private const TENANT_SOL_USER = '20600055419SECUNDARIO';
    private const TENANT_SOL_PASS = 'claveRealDelTenant';
    private const SANDBOX_SOL_USER = '20161515648MODDATOS';
    private const SANDBOX_SOL_PASS = 'MODDATOS';

    public function testPruebasUsaElSolDelEnvNoElDelTenant(): void
    {
        $credentials = $this->buildAndInspect('pruebas');

        self::assertSame('20161515648', substr($credentials['username'], 0, 11));
        self::assertSame(self::SANDBOX_SOL_USER, $credentials['username']);
        self::assertSame(self::SANDBOX_SOL_PASS, $credentials['password']);
    }

    public function testProduccionUsaElSolRealDelTenant(): void
    {
        $credentials = $this->buildAndInspect('produccion');

        self::assertSame(self::TENANT_SOL_USER, $credentials['username']);
        self::assertSame(self::TENANT_SOL_PASS, $credentials['password']);
    }

    /**
     * @return array{username: string, password: string, client_id: string}
     */
    private function buildAndInspect(string $ambiente): array
    {
        $ruc = '20600055419';
        $company = [
            'ambiente' => $ambiente,
            'SOL_USER' => self::TENANT_SOL_USER,
            'SOL_PASS' => self::TENANT_SOL_PASS,
            'certificate' => '',
            'CLIENT_ID' => 'prod-client-id',
            'CLIENT_SECRET' => 'prod-client-secret',
        ];

        $config = $this->fakeConfig([
            'CLIENT_ID' => 'sandbox-client-id',
            'CLIENT_SECRET' => 'sandbox-client-secret',
            'SOL_USER' => self::SANDBOX_SOL_USER,
            'SOL_PASS' => self::SANDBOX_SOL_PASS,
            'AUTH_URL' => 'https://gre-test.nubefact.com/v1',
            'API_URL' => 'https://gre-test.nubefact.com/v1',
        ]);
        $fileProvider = $this->fakeConfig([
            'companies' => json_encode([$ruc => $company], JSON_THROW_ON_ERROR),
        ]);
        $fileReader = new FileDataReader(sys_get_temp_dir());

        $factory = new SeeApiFactory($config, $fileProvider, $fileReader, sys_get_temp_dir());
        $api = $factory->build($ruc);

        $prop = new \ReflectionProperty($api, 'credentials');
        $prop->setAccessible(true);

        return $prop->getValue($api);
    }

    /**
     * @param array<string, string> $values
     */
    private function fakeConfig(array $values): ConfigProviderInterface
    {
        return new class ($values) implements ConfigProviderInterface {
            /** @param array<string, string> $values */
            public function __construct(private array $values)
            {
            }

            public function get($key)
            {
                return $this->values[$key] ?? '';
            }

            public function store($key, $value)
            {
                $this->values[$key] = $value;

                return true;
            }
        };
    }
}
