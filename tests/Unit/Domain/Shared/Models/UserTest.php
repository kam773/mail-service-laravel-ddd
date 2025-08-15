<?php

/**
 * Testing library and framework: PHPUnit (Laravel TestCase if available).
 *
 * Scope: Unit tests for Domain\Shared\Models\User focusing on model attributes and casts.
 */

namespace Tests\Unit\Domain\Shared\Models;

use Domain\Shared\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PHPUnit\Framework\TestCase as BaseTestCase;

// Prefer Laravel's Tests\TestCase if available to boot the application/facades.
if (class_exists(\Tests\TestCase::class)) {
    abstract class LaravelBaseTestCase extends \Tests\TestCase {}
} else {
    abstract class LaravelBaseTestCase extends BaseTestCase {}
}

class UserTest extends LaravelBaseTestCase
{
    public function test_fillable_attributes_are_defined_as_expected(): void
    {
        $user = new User();
        $expected = ['name', 'email', 'password'];
        $actual = $user->getFillable();

        $this->assertIsArray($actual);
        sort($expected);
        $actualSorted = array_values(array_unique($actual));
        sort($actualSorted);
        $this->assertSame($expected, $actualSorted);
    }

    public function test_hidden_attributes_are_excluded_from_serialization(): void
    {
        $user = new User();
        $user->name = 'Jane Doe';
        $user->email = 'jane@example.test';
        $user->password = 'irrelevant';

        $asArray = $user->toArray();
        $this->assertArrayHasKey('name', $asArray);
        $this->assertArrayHasKey('email', $asArray);
        $this->assertArrayNotHasKey('password', $asArray);
        $this->assertArrayNotHasKey('remember_token', $asArray);

        $json = $user->toJson();
        $this->assertIsString($json);
        $this->assertStringContainsString('"name":"Jane Doe"', $json);
        $this->assertStringContainsString('"email":"jane@example.test"', $json);
        $this->assertStringNotContainsString('password', $json);
        $this->assertStringNotContainsString('remember_token', $json);
    }

    public function test_email_verified_at_is_cast_to_carbon(): void
    {
        $user = new User();
        $timestamp = '2023-03-02 10:11:12';
        $user->email_verified_at = $timestamp;

        $value = $user->email_verified_at;
        $this->assertInstanceOf(Carbon::class, $value);
        $this->assertSame($timestamp, $value->format('Y-m-d H:i:s'));
    }

    public function test_email_verified_at_is_null_when_unset_or_null(): void
    {
        $user = new User();
        $this->assertNull($user->email_verified_at);

        $user->email_verified_at = null;
        $this->assertNull($user->email_verified_at);
    }

    public function test_password_is_hashed_via_cast_on_assignment(): void
    {
        $plain = 'P@ssw0rd-' . Str::random(6);
        $user = new User();
        $user->password = $plain;

        $raw = $user->getAttributes()['password'] ?? null;

        $this->assertNotNull($raw);
        $this->assertIsString($raw);
        $this->assertNotSame($plain, $raw);
        $this->assertTrue(Hash::check($plain, $raw));
    }

    public function test_already_hashed_password_is_not_rehashed(): void
    {
        $plain = 'Secret-' . Str::random(6);
        $hashed = Hash::make($plain);

        $user = new User();
        $user->fill(['password' => $hashed]);

        $raw = $user->getAttributes()['password'] ?? null;

        $this->assertIsString($raw);
        $this->assertSame($hashed, $raw);
    }

    public function test_casts_definition_exposes_expected_keys(): void
    {
        $user = new User();
        $casts = $user->getCasts();

        $this->assertIsArray($casts);
        $this->assertArrayHasKey('email_verified_at', $casts);
        $this->assertSame('datetime', $casts['email_verified_at'] ?? null);
        $this->assertArrayHasKey('password', $casts);
        $this->assertSame('hashed', $casts['password'] ?? null);
    }
}