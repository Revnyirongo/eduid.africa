<?php

namespace App\Controller;

use RuntimeException;
use SAML2\Constants;
use SimpleSAML\Configuration;
use SimpleSAML\Error\Error as SimpleSamlError;
use SimpleSAML\IdP;
use SimpleSAML\Metadata\MetaDataStorageHandler;
use SimpleSAML\Metadata\SAMLBuilder;
use SimpleSAML\Metadata\Signer;
use SimpleSAML\Module\saml\IdP\SAML2 as SAML2IdP;
use SimpleSAML\Utils\Auth as SimpleAuth;
use SimpleSAML\Utils\Config\Metadata as MetadataUtils;
use SimpleSAML\Utils\Crypto as SimpleCrypto;
use SimpleSAML\Utils\HTTP as SimpleHttp;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Throwable;

class SamlController extends AbstractController
{
    #[Route('/metadata', name: 'app_saml_metadata', methods: ['GET'])]
    #[Route('/saml/metadata', name: 'app_saml_metadata_prefixed', methods: ['GET'])]
    public function metadataAction(Request $request)
    {
        $this->bootstrapSimpleSaml($request);

        try {
            $globalConfig = Configuration::getInstance();
            if (!$globalConfig->getOptionalBoolean('enable.saml20-idp', false)) {
                return new Response(
                    'SAML2 IdP is disabled.',
                    Response::HTTP_NOT_FOUND,
                    ['Content-Type' => 'text/plain']
                );
            }

            if ($globalConfig->getOptionalBoolean('admin.protectmetadata', false)) {
                SimpleAuth::requireAdmin();
            }

            $metadataHandler = MetaDataStorageHandler::getMetadataHandler();
            try {
                $idpEntityId = $metadataHandler->getMetaDataCurrentEntityID('saml20-idp-hosted');
                $idpMetadata = $metadataHandler->getMetaDataConfig($idpEntityId, 'saml20-idp-hosted');
            } catch (\Exception $exception) {
                if (!$this->getParameter('kernel.debug')) {
                    throw $exception;
                }

                $metadataDir = $globalConfig->getPathValue('metadatadir', null);
                $metadataFile = $metadataDir ? rtrim($metadataDir, '/') . '/saml20-idp-hosted.php' : '';
                if ($metadataFile === '' || !is_file($metadataFile)) {
                    throw $exception;
                }

                $metadataSet = require $metadataFile;
                if (empty($metadataSet)) {
                    throw $exception;
                }

                $firstKey = array_key_first($metadataSet);
                $entry = $metadataSet[$firstKey];
                if (!isset($entry['entityid']) && is_string($firstKey)) {
                    $entry['entityid'] = $firstKey;
                }

                if (empty($entry['entityid'])) {
                    throw $exception;
                }

                $idpEntityId = $entry['entityid'];
                $idpMetadata = Configuration::loadFromArray($entry, 'metadata.saml20-idp-hosted');
            }

            $metadataArray = $this->buildIdpMetadataArray(
                $metadataHandler,
                $idpMetadata,
                $globalConfig,
                $idpEntityId
            );

            $builder = new SAMLBuilder($idpEntityId);
            $builder->addMetadataIdP20($metadataArray);
            $builder->addOrganizationInfo($metadataArray);

            $xml = $builder->getEntityDescriptorText();
            $xml = Signer::sign($xml, $idpMetadata->toArray(), 'SAML 2 IdP');

            return new Response($xml, Response::HTTP_OK, ['Content-Type' => 'application/xml']);
        } catch (SimpleSamlError $error) {
            return new Response(
                $error->getMessage(),
                Response::HTTP_BAD_REQUEST,
                ['Content-Type' => 'text/plain']
            );
        } catch (Throwable $exception) {
            error_log('Metadata generation failed: ' . $exception->getMessage());
            $message = 'Failed to generate SAML metadata.';
            if ($this->getParameter('kernel.debug')) {
                $message .= ' ' . $exception->getMessage();
            }
            return new Response(
                $message,
                Response::HTTP_INTERNAL_SERVER_ERROR,
                ['Content-Type' => 'text/plain']
            );
        }
    }

    #[Route('/saml2/idp/metadata.php', name: 'app_saml_metadata_legacy', methods: ['GET'])]
    #[Route('/saml/saml2/idp/metadata.php', name: 'app_saml_metadata_legacy_prefixed', methods: ['GET'])]
    public function metadataLegacyAction(Request $request)
    {
        return $this->metadataAction($request);
    }

    #[Route('/sso', name: 'app_saml_sso', methods: ['GET', 'POST'])]
    #[Route('/saml/sso', name: 'app_saml_sso_prefixed', methods: ['GET', 'POST'])]
    public function ssoAction(Request $request)
    {
        $this->bootstrapSimpleSaml($request);

        try {
            $metadata = MetaDataStorageHandler::getMetadataHandler();
            $idpEntityId = $metadata->getMetaDataCurrentEntityID('saml20-idp-hosted');
            $idp = IdP::getById('saml2:' . $idpEntityId);

            SAML2IdP::receiveAuthnRequest($idp);
        } catch (SimpleSamlError $error) {
            return new Response(
                $error->getMessage(),
                Response::HTTP_BAD_REQUEST,
                ['Content-Type' => 'text/plain']
            );
        } catch (Throwable $exception) {
            if ($this->has('logger')) {
                $this->get('logger')->error('Failed to process SAML SSO request.', [
                    'exception' => $exception,
                    'host' => $request->getHost(),
                    'request_uri' => $request->getRequestUri(),
                ]);
            }
            return new Response(
                'Failed to process SAML SSO request.',
                Response::HTTP_INTERNAL_SERVER_ERROR,
                ['Content-Type' => 'text/plain']
            );
        }

        return new Response('', Response::HTTP_NO_CONTENT);
    }

    #[Route('/logout', name: 'app_saml_logout', methods: ['GET', 'POST'])]
    #[Route('/saml/logout', name: 'app_saml_logout_prefixed', methods: ['GET', 'POST'])]
    public function singleLogoutAction(Request $request)
    {
        $this->bootstrapSimpleSaml($request);

        try {
            $metadata = MetaDataStorageHandler::getMetadataHandler();
            $idpEntityId = $metadata->getMetaDataCurrentEntityID('saml20-idp-hosted');
            $idp = IdP::getById('saml2:' . $idpEntityId);

            if ($request->query->has('ReturnTo')) {
                $idp->doLogoutRedirect(SimpleHttp::checkURLAllowed($request->query->get('ReturnTo')));
            } else {
                SAML2IdP::receiveLogoutMessage($idp);
            }
        } catch (SimpleSamlError $error) {
            return new Response(
                $error->getMessage(),
                Response::HTTP_BAD_REQUEST,
                ['Content-Type' => 'text/plain']
            );
        } catch (Throwable $exception) {
            return new Response(
                'Failed to process SAML logout request.',
                Response::HTTP_INTERNAL_SERVER_ERROR,
                ['Content-Type' => 'text/plain']
            );
        }

        return new Response('', Response::HTTP_NO_CONTENT);
    }

    #[Route('/saml2/idp/SSOService.php', name: 'app_saml_sso_legacy', methods: ['GET', 'POST'])]
    #[Route('/saml/saml2/idp/SSOService.php', name: 'app_saml_sso_legacy_prefixed', methods: ['GET', 'POST'])]
    public function ssoLegacyAction(Request $request)
    {
        return $this->ssoAction($request);
    }

    #[Route('/saml2/idp/SingleLogoutService.php', name: 'app_saml_logout_legacy', methods: ['GET', 'POST'])]
    #[Route('/saml/saml2/idp/SingleLogoutService.php', name: 'app_saml_logout_legacy_prefixed', methods: ['GET', 'POST'])]
    public function singleLogoutLegacyAction(Request $request)
    {
        return $this->singleLogoutAction($request);
    }

    private function bootstrapSimpleSaml(Request $request): void
    {
        $projectDir = $this->getParameter('kernel.project_dir');
        $configDir = $projectDir . '/conf/simplesamlphp';
        $repoRootConfigDir = dirname($projectDir) . '/conf/simplesamlphp';

        if (!is_dir($configDir) && is_dir($repoRootConfigDir)) {
            // Symfony's project dir points to /app/app, but the config lives one level up in /app/conf.
            $configDir = $repoRootConfigDir;
        }

        if (!is_dir($configDir)) {
            throw new RuntimeException(sprintf(
                'SimpleSAMLphp configuration directory not found at "%s".',
                $configDir
            ));
        }

        putenv('SIMPLESAMLPHP_CONFIG_DIR=' . $configDir);
        $_ENV['SIMPLESAMLPHP_CONFIG_DIR'] = $configDir;
        $_SERVER['SIMPLESAMLPHP_CONFIG_DIR'] = $configDir;

        $requestHost = $request->getHttpHost();
        if (!empty($requestHost)) {
            $_SERVER['HTTP_HOST'] = $requestHost;
            $_SERVER['SERVER_NAME'] = $requestHost;
        }

        if (empty($_SERVER['SERVER_PORT'])) {
            $_SERVER['SERVER_PORT'] = $request->getPort();
        }

        $_SERVER['HTTPS'] = $request->isSecure() ? 'on' : 'off';

        $include = $projectDir . '/vendor/simplesamlphp/simplesamlphp/www/_include.php';
        if (!file_exists($include)) {
            $alternate = $projectDir . '/vendor/simplesamlphp/simplesamlphp/public/_include.php';
            if (file_exists($alternate)) {
                $include = $alternate;
            } else {
                throw new RuntimeException(sprintf(
                    'SimpleSAMLphp bootstrap file missing at "%s".',
                    $include
                ));
            }
        }

        require_once $include;
    }

    private function buildIdpMetadataArray(
        MetaDataStorageHandler $metadataHandler,
        Configuration $idpMetadata,
        Configuration $globalConfig,
        string $idpEntityId
    ): array {
        $metaArray = [
            'metadata-set' => 'saml20-idp-hosted',
            'entityid' => $idpEntityId,
        ];

        $ssoBinding = $metadataHandler->getGenerated('SingleSignOnServiceBinding', 'saml20-idp-hosted');
        $sloBinding = $metadataHandler->getGenerated('SingleLogoutServiceBinding', 'saml20-idp-hosted');
        $ssoLocation = $metadataHandler->getGenerated('SingleSignOnService', 'saml20-idp-hosted');
        $sloLocation = $metadataHandler->getGenerated('SingleLogoutService', 'saml20-idp-hosted');

        $metaArray['SingleSignOnService'] = [];
        foreach ((array) $ssoBinding as $binding) {
            $metaArray['SingleSignOnService'][] = [
                'Binding' => $binding,
                'Location' => $ssoLocation,
            ];
        }

        $metaArray['SingleLogoutService'] = [];
        foreach ((array) $sloBinding as $binding) {
            $metaArray['SingleLogoutService'][] = [
                'Binding' => $binding,
                'Location' => $sloLocation,
            ];
        }

        $keys = [];
        $crypto = new SimpleCrypto();
        $newCert = $crypto->loadPublicKey($idpMetadata, false, 'new_');
        if ($newCert !== null) {
            $keys[] = [
                'type' => 'X509Certificate',
                'signing' => true,
                'encryption' => true,
                'X509Certificate' => $newCert['certData'],
            ];
        }

        $currentCert = $crypto->loadPublicKey($idpMetadata, true);
        if ($currentCert !== null) {
            $keys[] = [
                'type' => 'X509Certificate',
                'signing' => true,
                'encryption' => $newCert === null,
                'X509Certificate' => $currentCert['certData'],
            ];
        }

        if ($idpMetadata->hasValue('https.certificate')) {
            $httpsCert = $crypto->loadPublicKey($idpMetadata, true, 'https.');
            if ($httpsCert !== null) {
                $keys[] = [
                    'type' => 'X509Certificate',
                    'signing' => true,
                    'encryption' => false,
                    'X509Certificate' => $httpsCert['certData'],
                ];
            }
        }

        if (count($keys) === 1) {
            $metaArray['certData'] = $keys[0]['X509Certificate'];
        } elseif (!empty($keys)) {
            $metaArray['keys'] = $keys;
        }

        if ($idpMetadata->getOptionalBoolean('saml20.sendartifact', false)) {
            $metaArray['ArtifactResolutionService'][] = [
                'index' => 0,
                'Location' => SimpleHttp::getBaseURL() . 'saml2/idp/ArtifactResolutionService.php',
                'Binding' => Constants::BINDING_SOAP,
            ];
        }

        if ($idpMetadata->getOptionalBoolean('saml20.hok.assertion', false)) {
            array_unshift($metaArray['SingleSignOnService'], [
                'hoksso:ProtocolBinding' => Constants::BINDING_HTTP_REDIRECT,
                'Binding' => Constants::BINDING_HOK_SSO,
                'Location' => $ssoLocation,
            ]);
        }

        if ($idpMetadata->getOptionalBoolean('saml20.ecp', false)) {
            $metaArray['SingleSignOnService'][] = [
                'index' => 0,
                'Binding' => Constants::BINDING_SOAP,
                'Location' => $ssoLocation,
            ];
        }

        $metaArray['NameIDFormat'] = $idpMetadata->getArrayizeString(
            'NameIDFormat',
            'urn:oasis:names:tc:SAML:2.0:nameid-format:transient'
        );

        if ($idpMetadata->hasValue('OrganizationName')) {
            $metaArray['OrganizationName'] = $idpMetadata->getLocalizedString('OrganizationName');
            if ($idpMetadata->hasValue('OrganizationDisplayName')) {
                $metaArray['OrganizationDisplayName'] = $idpMetadata->getLocalizedString('OrganizationDisplayName');
            } else {
                $metaArray['OrganizationDisplayName'] = $metaArray['OrganizationName'];
            }
            if ($idpMetadata->hasValue('OrganizationURL')) {
                $metaArray['OrganizationURL'] = $idpMetadata->getLocalizedString('OrganizationURL');
            }
        }

        if ($idpMetadata->hasValue('scope')) {
            $metaArray['scope'] = $idpMetadata->getArray('scope');
        }

        if ($idpMetadata->hasValue('EntityAttributes')) {
            $metaArray['EntityAttributes'] = $idpMetadata->getArray('EntityAttributes');
            if (MetadataUtils::isHiddenFromDiscovery($metaArray)) {
                $metaArray['hide.from.discovery'] = true;
            }
        }

        if ($idpMetadata->hasValue('UIInfo')) {
            $metaArray['UIInfo'] = $idpMetadata->getArray('UIInfo');
        }

        if ($idpMetadata->hasValue('DiscoHints')) {
            $metaArray['DiscoHints'] = $idpMetadata->getArray('DiscoHints');
        }

        if ($idpMetadata->hasValue('RegistrationInfo')) {
            $metaArray['RegistrationInfo'] = $idpMetadata->getArray('RegistrationInfo');
        }

        if ($idpMetadata->hasValue('validate.authnrequest')) {
            $metaArray['sign.authnrequest'] = $idpMetadata->getOptionalBoolean('validate.authnrequest', false);
        }

        if ($idpMetadata->hasValue('redirect.validate')) {
            $metaArray['redirect.sign'] = $idpMetadata->getOptionalBoolean('redirect.validate', false);
        }

        if ($idpMetadata->hasValue('contacts')) {
            foreach ($idpMetadata->getArray('contacts') as $contact) {
                $metaArray['contacts'][] = MetadataUtils::getContact($contact);
            }
        }

        $technicalContactEmail = $globalConfig->getString('technicalcontact_email', false);
        if ($technicalContactEmail && $technicalContactEmail !== 'na@example.org') {
            $technicalContact = [
                'emailAddress' => $technicalContactEmail,
                'name' => $globalConfig->getString('technicalcontact_name', null),
                'contactType' => 'technical',
            ];
            $metaArray['contacts'][] = MetadataUtils::getContact($technicalContact);
        }

        return $metaArray;
    }
}
