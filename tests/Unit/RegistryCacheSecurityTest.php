<?php

namespace Tests\Unit;

use Liberu\Foundation\ModuleManager\Cache\RegistryCache;
use Liberu\Foundation\ModuleManager\ModuleRegistry;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class RegistryCacheSecurityTest extends TestCase
{
    public function test_supported_module_registry_caches_round_trip(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'registry-security-');
        try {
            $cache = new RegistryCache;
            $cache->write(new ModuleRegistry([]), $path);

            self::assertSame([], $cache->load([], true, $path)->all());
        } finally {
            unlink($path);
        }
    }

    public function test_unexpected_cached_objects_are_rejected_without_invoking_their_wakeup_methods(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'registry-security-');
        UnexpectedRegistryPayload::$awakened = false;
        file_put_contents($path, serialize(new ModuleRegistry(['payload' => new UnexpectedRegistryPayload])));

        try {
            (new RegistryCache)->load([], true, $path);
            self::fail('An unexpected cached object was accepted.');
        } catch (RuntimeException) {
            self::assertFalse(UnexpectedRegistryPayload::$awakened);
        } finally {
            unlink($path);
        }
    }
}

final class UnexpectedRegistryPayload
{
    public static bool $awakened = false;

    public function __wakeup(): void
    {
        self::$awakened = true;
    }
}
