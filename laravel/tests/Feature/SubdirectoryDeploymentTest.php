<?php

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Routing\UrlGenerator;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SubdirectoryDeploymentTest extends TestCase
{
    public function test_apache_root_front_controller_preserves_the_application_mount_for_routes_and_assets(): void
    {
        $request = Request::create('https://krumost.com/sena_reflection/login', 'GET', server: $this->apacheServer());
        $urls = new UrlGenerator($this->app['router']->getRoutes(), $request, 'https://krumost.com/sena_reflection');

        $this->assertSame('/sena_reflection', $request->getBaseUrl());
        $this->assertSame('/login', $request->getPathInfo());
        $this->assertSame('https://krumost.com/sena_reflection/login', $urls->route('login'));
        $this->assertSame('https://krumost.com/sena_reflection', $urls->route('dashboard'));
        $this->assertSame('https://krumost.com/sena_reflection/build/assets/app.css', $urls->asset('build/assets/app.css'));
    }

    public function test_login_route_is_reached_when_apache_rewrites_a_mounted_request_to_root_index(): void
    {
        $this->withoutVite();

        $this->withServerVariables($this->apacheServer())->get('https://krumost.com/sena_reflection/login')
            ->assertOk()->assertInertia(fn (Assert $page) => $page->component('auth/login'));
    }

    public function test_guest_redirect_stays_inside_the_mounted_application(): void
    {
        $this->withServerVariables($this->apacheServer())->get('https://krumost.com/sena_reflection/records/create')
            ->assertRedirect('https://krumost.com/sena_reflection/login');
    }

    /** @return array<string, string> */
    private function apacheServer(): array
    {
        return [
            'SCRIPT_NAME' => '/sena_reflection/index.php',
            'PHP_SELF' => '/sena_reflection/index.php',
            'SCRIPT_FILENAME' => '/var/www/vhosts/krumost.com/httpdocs/sena_reflection/index.php',
            'DOCUMENT_ROOT' => '/var/www/vhosts/krumost.com/httpdocs',
            'HTTP_HOST' => 'krumost.com',
            'HTTPS' => 'on',
            'SERVER_PORT' => '443',
        ];
    }
}
