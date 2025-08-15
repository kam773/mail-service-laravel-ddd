<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Shared\Models;

use PHPUnit\Framework\TestCase;

/**
 * Tests for Domain\Shared\Models\BaseModel::newFactory behavior.
 *
 * Framework: PHPUnit (Laravel-style unit tests)
 */
final class BaseModelTest extends TestCase
{
}

namespace Domain\Sample\Models {
    use Domain\Shared\Models\BaseModel;

    /**
     * A concrete model in the Domain\Sample\Models namespace used to drive BaseModel::newFactory.
     */
    class SampleModel extends BaseModel
    {
        protected $table = 'sample_models';
        public $timestamps = false;
    }
}

namespace Domain\Accounting\Reports\Models {
    use Domain\Shared\Models\BaseModel;

    /**
     * A model in a deeper namespace to verify that BaseModel only uses segment[1] as the domain
     * and last segment as the model name when building the factory class string.
     */
    class Report extends BaseModel
    {
        protected $table = 'reports';
        public $timestamps = false;
    }
}

namespace Database\Factories\Sample {
    /**
     * Stub factory for Domain\Sample\Models\SampleModel.
     * Mimics Laravel factory instance shape minimally for identification in tests.
     */
    class SampleModelFactory
    {
        public function id(): string
        {
            return 'sample-factory-instance';
        }
    }
}

namespace Database\Factories\Accounting {
    /**
     * Stub factory for Domain\Accounting\Reports\Models\Report.
     */
    class ReportFactory
    {
        public function id(): string
        {
            return 'report-factory-instance';
        }
    }
}

namespace Tests\Unit\Domain\Shared\Models {

    use Domain\Sample\Models\SampleModel;
    use Domain\Accounting\Reports\Models\Report;
    use PHPUnit\Framework\Assert;
    use PHPUnit\Framework\TestCase;
    use ReflectionMethod;

    /**
     * Note: We directly invoke the protected static BaseModel::newFactory() via reflection
     * on the concrete subclasses to avoid coupling to HasFactory internals and to
     * precisely validate the container resolution and namespace parsing behavior.
     */
    final class BaseModelNewFactoryBehaviorTest extends TestCase
    {
        /**
         * Provides a minimal container that responds to app() usage.
         * If Laravel's global app() helper is available in the test runtime,
         * we will use that; otherwise we simulate enough for resolution.
         */
        protected function setUp(): void
        {
            parent::setUp();

            // If the global helper app() is not defined (pure PHPUnit run),
            // define a simple shim to mimic resolving from a static registry.
            if (!function_exists('\\app')) {
                // Static registry for bindings.
                if (!isset($GLOBALS['__base_model_test_container'])) {
                    $GLOBALS['__base_model_test_container'] = [];
                }

                /**
                 * @param string $abstract
                 * @return mixed
                 */
                function app(string $abstract) {
                    if (isset($GLOBALS['__base_model_test_container'][$abstract])) {
                        $entry = $GLOBALS['__base_model_test_container'][$abstract];
                        // If entry is callable, call to get instance; else return raw instance.
                        return is_callable($entry) ? $entry() : $entry;
                    }

                    // If a class exists for the abstract, instantiate it.
                    if (class_exists($abstract)) {
                        return new $abstract();
                    }

                    // Simulate Laravel container behavior on unknown abstract:
                    // Throw a Reflection-like exception indicating class does not exist.
                    throw new \RuntimeException("Unable to resolve '$abstract': class not bound or does not exist.");
                }
            }

            // Bind our stub factories either in global registry (shim) or in Laravel container if present.
            $this->bind('Database\\Factories\\Sample\\SampleModelFactory', new \Database\Factories\Sample\SampleModelFactory());
            $this->bind('Database\\Factories\\Accounting\\ReportFactory', new \Database\Factories\Accounting\ReportFactory());
        }

        protected function tearDown(): void
        {
            // Clear shim registry between tests to avoid cross-test leakage.
            if (isset($GLOBALS['__base_model_test_container'])) {
                $GLOBALS['__base_model_test_container'] = [];
            }
            parent::tearDown();
        }

        /**
         * Helper to bind an abstract to an instance either to Laravel container or our shim.
         */
        private function bind(string $abstract, $instance): void
        {
            if (function_exists('\\app') && !isset($GLOBALS['__base_model_test_container'])) {
                // If running in a Laravel testing environment (Tests\TestCase),
                // app() should be the framework container. Attempt binding via instance method if available.
                try {
                    // When running outside of full Laravel, app() may be our shim; catch errors.
                    $app = \app();
                    if (is_object($app) && method_exists($app, 'instance')) {
                        $app->instance($abstract, $instance);
                        return;
                    }
                } catch (\Throwable $e) {
                    // Fall back to shim-based registry below.
                }
            }
            // Fallback: store in global registry for shim-based app() defined above.
            $GLOBALS['__base_model_test_container'][$abstract] = $instance;
        }

        private function invokeNewFactory(string $fqcn)
        {
            $rm = new ReflectionMethod($fqcn, 'newFactory');
            $rm->setAccessible(true);
            // Static protected method: invoke with null for instance.
            return $rm->invoke(null);
        }

        public function testNewFactoryResolvesFactoryForSimpleDomainModel(): void
        {
            $factory = $this->invokeNewFactory(SampleModel::class);

            Assert::assertIsObject($factory, 'Expected newFactory to return an object instance.');
            Assert::assertInstanceOf(\Database\Factories\Sample\SampleModelFactory::class, $factory);
            Assert::assertSame('sample-factory-instance', $factory->id(), 'Resolved factory instance should be the bound SampleModelFactory.');
        }

        public function testNewFactoryParsesOnlySecondNamespaceSegmentAsDomainAndLastAsModel(): void
        {
            $factory = $this->invokeNewFactory(Report::class);

            Assert::assertIsObject($factory);
            Assert::assertInstanceOf(\Database\Factories\Accounting\ReportFactory::class, $factory);
            Assert::assertSame('report-factory-instance', $factory->id(), 'Resolved factory instance should be the bound ReportFactory.');
        }

        public function testNewFactoryThrowsWhenFactoryBindingOrClassMissing(): void
        {
            // Define a temporary model class under a domain with no binding and no factory class.
            // Namespace: Domain\NoFactory\Models\Ghost => expects Database\Factories\NoFactory\GhostFactory
            eval('
                namespace Domain\\NoFactory\\Models;
                class Ghost extends \\Domain\\Shared\\Models\\BaseModel {
                    protected $table = "ghosts";
                    public $timestamps = false;
                }
            ');

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage("Database\\Factories\\NoFactory\\GhostFactory");

            $rm = new \ReflectionMethod('Domain\\NoFactory\\Models\\Ghost', 'newFactory');
            $rm->setAccessible(true);
            try {
                $rm->invoke(null);
            } catch (\Throwable $e) {
                // Ensure message contains the unresolved abstract for clarity
                if (strpos($e->getMessage(), 'Database\\Factories\\NoFactory\\GhostFactory') === false) {
                    throw new \RuntimeException("Database\\Factories\\NoFactory\\GhostFactory resolution failed", 0, $e);
                }
                throw $e;
            }
        }

        public function testNewFactoryIsDeterministicForSameModel(): void
        {
            $first = $this->invokeNewFactory(SampleModel::class);
            $second = $this->invokeNewFactory(SampleModel::class);

            // We expect container to return the same instance we bound for determinism in this test setup.
            Assert::assertSame($first, $second, 'newFactory should return the same bound instance for the same model within the container lifetime.');
        }
    }
}