<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Smoke tests for the template's dependency-free helpers. These exercise the
 * reusable architecture without touching the database or the network.
 */
final class CoreTest extends TestCase
{
    public function testEnvReturnsDefaultWhenKeyMissing(): void
    {
        self::assertSame('fallback', env('DEFINITELY_NOT_SET_KEY', 'fallback'));
    }

    public function testRouteBuildsPathWithoutParams(): void
    {
        self::assertSame('/dashboard/', route('dashboard'));
    }

    public function testRouteAppendsQueryString(): void
    {
        self::assertSame('/admin/users.php?id=5', route('admin.users', ['id' => 5]));
    }

    public function testUnknownRouteReturnsHash(): void
    {
        self::assertSame('#', @route('no.such.route'));
    }

    public function testHasRoleIsCaseInsensitive(): void
    {
        self::assertTrue(hasRole('Admin', 'admin', 'super_admin'));
        self::assertFalse(hasRole('user', 'admin'));
    }

    public function testIsAdminRole(): void
    {
        self::assertTrue(isAdminRole('super_admin'));
        self::assertFalse(isAdminRole('user'));
    }
}
