<?php

namespace Tests\Unit;

use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Exception\ConnectException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class InstallerSecurityTest extends TestCase
{
    public function test_installer_requires_a_key_and_protects_session_actions_without_url_credentials(): void
    {
        $directory = sys_get_temp_dir().'/installer-security-'.bin2hex(random_bytes(8));
        mkdir($directory.'/public', 0700, true);
        copy(public_path('installer.php'), $directory.'/public/installer.php');
        file_put_contents($directory.'/.env', "INSTALLER_ENABLED=true\n");
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        $server = new Process([PHP_BINARY, '-S', $address, '-t', $directory.'/public'], $directory, ['INSTALLER_ENABLED' => false, 'INSTALLER_KEY' => false]);
        $server->start();
        $cookies = new CookieJar;
        $client = new Client(['base_uri' => 'http://'.$address, 'cookies' => $cookies, 'http_errors' => false, 'allow_redirects' => false, 'timeout' => 5, 'proxy' => '']);

        try {
            $ready = false;
            for ($attempt = 0; $attempt < 100; $attempt++) {
                try {
                    $client->get('/installer.php');
                    $ready = true;
                    break;
                } catch (ConnectException) {
                    usleep(20000);
                }
            }
            $this->assertTrue($ready, $server->getErrorOutput());
            $this->assertSame(403, $client->get('/installer.php?action=status')->getStatusCode());

            file_put_contents($directory.'/.env', "INSTALLER_ENABLED=true\nINSTALLER_KEY=test-installer-secret\n");
            $this->assertSame(403, $client->get('/installer.php?key=test-installer-secret&action=status')->getStatusCode());
            $this->assertSame(403, $client->post('/installer.php', ['form_params' => ['key' => 'wrong']])->getStatusCode());
            $login = $client->post('/installer.php', ['form_params' => ['key' => 'test-installer-secret']]);
            $this->assertSame(303, $login->getStatusCode());
            $this->assertSame('installer.php', $login->getHeaderLine('Location'));

            $page = $client->get('/installer.php');
            $html = (string) $page->getBody();
            $this->assertSame(200, $page->getStatusCode());
            $this->assertStringContainsString('no-store', $page->getHeaderLine('Cache-Control'));
            $this->assertStringNotContainsString('test-installer-secret', $html);
            $this->assertStringNotContainsString('&key=', $html);
            $this->assertSame(1, preg_match('/const installerCsrf = "([a-f0-9]{64})";/', $html, $matches));
            $this->assertSame(200, $client->get('/installer.php?action=status')->getStatusCode());
            $this->assertSame(403, $client->get('/installer.php?action=invalid')->getStatusCode());
            $this->assertSame(403, $client->post('/installer.php?action=invalid')->getStatusCode());
            $this->assertSame(403, $client->post('/installer.php?action=invalid', ['headers' => ['X-Installer-CSRF' => 'wrong']])->getStatusCode());
            $response = $client->post('/installer.php?action=invalid', ['headers' => ['X-Installer-CSRF' => $matches[1]]]);
            $this->assertSame('Invalid action', json_decode((string) $response->getBody(), true)['message']);

            file_put_contents($directory.'/.env', "INSTALLER_ENABLED=true\nINSTALLER_KEY=rotated-secret\n");
            $this->assertSame(403, $client->get('/installer.php?action=status')->getStatusCode());
        } finally {
            $server->stop();
            unlink($directory.'/public/installer.php');
            unlink($directory.'/.env');
            rmdir($directory.'/public');
            rmdir($directory);
        }
    }
}
