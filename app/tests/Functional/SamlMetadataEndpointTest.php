<?php

namespace Tests\Functional;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class SamlMetadataEndpointTest extends WebTestCase
{
    public function testMetadataEndpointContainsEntityDescriptor(): void
    {
        $client = static::createClient();
        $client->request('GET', '/saml/metadata', [], [], ['HTTP_HOST' => 'demo.idpuni.localhost']);

        $response = $client->getResponse();
        $this->assertSame(200, $response->getStatusCode());
        $this->assertContains('EntityDescriptor', $response->getContent());
    }
}
