<?php

namespace Tests\Feature\Admin;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The OpenAPI operations live in App\OpenApi\Paths\*, not on the controller methods, so
 * nothing in PHP links a route to its documentation. This test is that link: it fails the
 * build when a route is added without docs, or docs describe a route that no longer exists.
 *
 * Without this, the "keep them in sync by hand" note in those files is just a wish.
 */
class OpenApiDocumentTest extends TestCase
{
    /**
     * Dev-only routes are registered behind `app()->environment('local')`, so they are
     * absent under APP_ENV=testing. They are documented on purpose; assert that separately.
     */
    protected const LOCAL_ONLY = ['GET /admin/dev/last-otp'];

    protected function document(): array
    {
        Artisan::call('l5-swagger:generate');

        $path = storage_path('api-docs/api-docs.json');

        $this->assertFileExists($path, 'The OpenAPI document was not generated.');

        return json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    }

    /** @return array<string, true> */
    protected function realRoutes(): array
    {
        $real = [];

        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();

            if (! str_starts_with($uri, 'api/v1/admin')) {
                continue;
            }

            foreach ($route->methods() as $method) {
                if (in_array($method, ['HEAD', 'OPTIONS'], true)) {
                    continue;
                }

                $real[strtoupper($method).' /'.preg_replace('#^api/v1/#', '', $uri)] = true;
            }
        }

        return $real;
    }

    /** @return array<string, true> */
    protected function documentedOperations(array $doc): array
    {
        $documented = [];

        foreach ($doc['paths'] as $path => $verbs) {
            foreach (array_keys($verbs) as $verb) {
                $documented[strtoupper($verb).' '.$path] = true;
            }
        }

        return $documented;
    }

    #[Test]
    public function every_admin_route_is_documented(): void
    {
        $undocumented = array_diff_key($this->realRoutes(), $this->documentedOperations($this->document()));

        $this->assertSame([], array_keys($undocumented), sprintf(
            "These admin routes have no OpenAPI operation. Add them to app/OpenApi/Paths/:\n  %s",
            implode("\n  ", array_keys($undocumented))
        ));
    }

    #[Test]
    public function no_operation_describes_a_route_that_does_not_exist(): void
    {
        $phantom = array_diff_key(
            $this->documentedOperations($this->document()),
            $this->realRoutes(),
        );

        foreach (self::LOCAL_ONLY as $localOnly) {
            unset($phantom[$localOnly]);
        }

        $this->assertSame([], array_keys($phantom), sprintf(
            "These OpenAPI operations describe routes that do not exist:\n  %s",
            implode("\n  ", array_keys($phantom))
        ));
    }

    #[Test]
    public function the_dev_helper_is_absent_outside_local(): void
    {
        // APP_ENV is `testing` here, so the guard in routes/api.php must have excluded it.
        $this->assertArrayNotHasKey(
            'GET /admin/dev/last-otp',
            $this->realRoutes(),
            'The local-only dev helper is reachable outside APP_ENV=local.'
        );

        $this->getJson('/api/v1/admin/dev/last-otp')->assertStatus(404);
    }

    #[Test]
    public function the_document_is_structurally_sound(): void
    {
        $doc = $this->document();

        $this->assertSame('3.0.0', $doc['openapi']);
        $this->assertSame('Guftagu Admin API', $doc['info']['title']);
        $this->assertArrayHasKey('bearerAuth', $doc['components']['securitySchemes']);
        $this->assertNotEmpty($doc['servers'][0]['url']);

        foreach (['Envelope', 'ErrorEnvelope', 'Meta', 'AdminProfile', 'PermissionItem', 'GrantScope'] as $schema) {
            $this->assertArrayHasKey($schema, $doc['components']['schemas'], "Missing schema {$schema}.");
        }
    }

    #[Test]
    public function every_ref_resolves_to_a_defined_schema(): void
    {
        $doc     = $this->document();
        $defined = array_keys($doc['components']['schemas']);
        $refs    = [];

        // A typo in a $ref renders as an empty box in Swagger UI with no error anywhere,
        // so walk the whole document and check each one.
        array_walk_recursive($doc, function ($value, $key) use (&$refs) {
            if ($key === '$ref' && is_string($value)) {
                $refs[] = $value;
            }
        });

        $this->assertNotEmpty($refs, 'No $refs found — the document is probably not using the shared schemas.');

        $broken = [];

        foreach (array_unique($refs) as $ref) {
            $name = str_replace('#/components/schemas/', '', $ref);

            if (! in_array($name, $defined, true)) {
                $broken[] = $ref;
            }
        }

        $this->assertSame([], $broken, 'These $refs point at undefined schemas: '.implode(', ', $broken));
    }

    #[Test]
    public function protected_operations_declare_the_bearer_scheme(): void
    {
        $doc = $this->document();

        // Only these may be reached without a token.
        $public = [
            'POST /admin/auth/login',
            'POST /admin/auth/mfa/verify',
            'GET /admin/dev/last-otp',
        ];

        $missingSecurity = [];

        foreach ($doc['paths'] as $path => $verbs) {
            foreach ($verbs as $verb => $operation) {
                $id = strtoupper($verb).' '.$path;

                if (in_array($id, $public, true)) {
                    continue;
                }

                if (empty($operation['security'])) {
                    $missingSecurity[] = $id;
                }
            }
        }

        $this->assertSame([], $missingSecurity, sprintf(
            "These operations need `security: [['bearerAuth' => []]]` or Swagger will not send a token:\n  %s",
            implode("\n  ", $missingSecurity)
        ));
    }
}
