<?php

namespace App\Utils;

use App\Entity\IdPAudit;
use App\Entity\IdP;
use Doctrine\ORM\EntityNotFoundException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Translation\DataCollectorTranslator;

class SSPGetter
{
    protected $em;
    private $translator;
    private $projectDir;
    private $brokerConfig;
    private $database_host;
    private $database_name;
    private $database_user;
    private $database_port;
    private $database_password;
    private $database_type;
    private $samlidp_hostname;

    public function __construct(EntityManagerInterface $em, $database_host, $database_name, $database_user, $database_password, $database_driver, $database_port, $samlidp_hostname, string $projectDir)
    {
        $this->em = $em;
        $this->database_host = $database_host;
        $this->database_name = $database_name;
        $this->database_user = $database_user;
        $this->database_port = $database_port;
        $this->database_password = $database_password;
        $this->database_type = preg_replace('/pdo_/', '', $database_driver);
        $this->samlidp_hostname = $samlidp_hostname;
        $this->projectDir = $projectDir;
    }

    public function getSamlidpHostname()
    {
        return $this->samlidp_hostname;
    }

    public function getLoginPageData($host)
    {
        $idp = $this->em->getRepository(IdP::class)->findOneByHostname(str_replace('.' . $this->samlidp_hostname, '', $host));
        if ($idp) {
            $result = array();
            foreach ($idp->getOrganizationElements() as $orgElem) {
                if ($orgElem->getType() == 'Name') {
                    $result['OrganizationName'] = $orgElem->getValue();
                }
            }
            if (!empty($idp->getLogo())) {
                $result['Logo'] = array(
                        'url' => 'https://'.$this->samlidp_hostname.'/images/idp_logo/'.$idp->getLogo(),
                        'width' => 200,
                        'height' => 200,
                        );
            }
            foreach ($idp->getUsers() as $contact) {
                $result['contact'] = array(
                    'name' => $contact->getGivenName().' '.$contact->getSn(),
                    'email' => $contact->getEmail(),
                );
            }
            $result['status'] = $idp->getStatus();
            $result['hostname'] = $idp->getHostname();

            return $result;
        }
    }

    public function getSaml20spremoteForAnIdp($host)
    {
        // Itt állítjuk össze az adott IdP-hez tartozó saml20-sp-remote.php listát.
        $host = preg_replace('/:\\d+$/', '', (string) $host);
        $idp = $this->em->getRepository(IdP::class)->findOneByHostname(
            str_replace('.' . $this->samlidp_hostname, '', $host)
        );
        if (!$idp) {
            throw new EntityNotFoundException('No IdP found in the database.');
        }
        $metadata = array();

        // Itt szedjük ki a föderációs SP-ket, melyeket az IdP szeret
        $federations = $idp->getFederations();
        foreach ($federations as $federation) {
            $entities = $federation->getEntities();
            foreach ($entities as $entity) {
                $entityData = unserialize(stream_get_contents($entity->getEntitydata()));
                $metadata[$entity->getEntityid()] = $entityData;
            }
        }

        // Itt szedjük ki a föderáción kívüli SP-ket, melyeket az IdP szeret
        $entities = $idp->getEntities();
        foreach ($entities as $entity) {
            if (!isset($metadata[$entity->getEntityid()])) {
                $entityData = unserialize(stream_get_contents($entity->getEntitydata()));
                $metadata[$entity->getEntityid()] = $entityData;
            }
        }
        return $metadata;
    }

    public function getIdps($host)
    {
        $idps = $this->em->getRepository(IdP::class)->findByHostname(str_replace('.' . $this->samlidp_hostname, '', $host));
        if (count($idps) == 0) {
            // szándékos fallback az összes IdP listázására, ha valahol nem direktben hívják meg
            $idps = $this->em->getRepository(IdP::class)->findAll();
        }
        $result = array();
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $incomingHost = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '';
        $portSuffix = '';
        $port = null;

        if (strpos($incomingHost, ':') !== false) {
            $parts = explode(':', $incomingHost);
            $portCandidate = array_pop($parts);
            if (ctype_digit($portCandidate)) {
                $port = $portCandidate;
            }
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_PORT']) && ctype_digit((string) $_SERVER['HTTP_X_FORWARDED_PORT'])) {
            $port = (string) $_SERVER['HTTP_X_FORWARDED_PORT'];
        } elseif (!empty($_SERVER['SERVER_PORT']) && ctype_digit((string) $_SERVER['SERVER_PORT'])) {
            $port = (string) $_SERVER['SERVER_PORT'];
        }

        if ($port !== null) {
            $isHttps = ($scheme === 'https');
            $isDefaultPort = ($isHttps && $port === '443') || (!$isHttps && $port === '80');
            if (!$isDefaultPort) {
                $portSuffix = ':' . $port;
            }
        }

        $hostWithoutPort = strtolower(preg_replace('/:\\d+$/', '', (string) $incomingHost));

        foreach ($idps as $idp) {
            if (strlen($idp->getInstituteName())>1) {
                $certDescriptor = $this->resolveCertificateDescriptor($idp, $hostWithoutPort);
                if ($certDescriptor === null) {
                    // Skip IdP entries without a valid certificate/key pair
                    continue;
                }

                $brokerSettings = $this->getBrokerSettings($idp->getHostname());
                $defaultScope = $this->resolveBrokerScope($idp, $brokerSettings);
                $brokerAuthSource = $this->resolvePrimaryBrokerAuthSourceId($idp);

                $result[$idp->getEntityId($this->samlidp_hostname)] = array(
                    'entityid' => $idp->getEntityId($this->samlidp_hostname),
                    'host' => $idp->getHostname().'.'.$this->samlidp_hostname,
                    'privatekey' => $certDescriptor['relativeKey'],
                    'certificate' => $certDescriptor['relativeCert'],
                    'scope' => $idp->getScopes(),
                    'certData' => $certDescriptor['certData'],
                    'auth' => $brokerAuthSource,
                    'attributes.NameFormat' => 'urn:oasis:names:tc:SAML:2.0:attrname-format:uri',
                    'userid.attribute' => 'username',
                    'attributeencodings' => array(
                      'urn:oid:1.3.6.1.4.1.5923.1.1.1.10' => 'raw',
                    ),
                    'sign.logout' => true,
                    'redirect.sign' => true,
                    'assertion.encryption' => true,
                    'EntityAttributes' => array(
                        'http://macedir.org/entity-category-support' => array('http://refeds.org/category/research-and-scholarship', 'http://www.geant.net/uri/dataprotection-code-of-conduct/v1'),
                        'urn:oasis:names:tc:SAML:attribute:assurance-certification' => array('https://refeds.org/sirtfi')
                    ),

                    'name' => array(
                        'en' => $idp->getInstituteName(),
                    ),
                    'NameIDFormat' => array(
                        'urn:oasis:names:tc:SAML:2.0:nameid-format:transient',
                    ),
                    'SingleSignOnService' => $scheme.'://'.$idp->getHostname().'.'.$this->samlidp_hostname.$portSuffix.'/saml2/idp/SSOService.php',
                    'SingleLogoutService' => $scheme.'://'.$idp->getHostname().'.'.$this->samlidp_hostname.$portSuffix.'/saml2/idp/SingleLogoutService.php',
                    'SingleSignOnServiceBinding' => array(
                        'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect',
                        'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST'
                        ),
                    'SingleLogoutServiceBinding' => array(
                        'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect',
                        'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST'
                        )

                );
                foreach ($idp->getUsers() as $contact) {
                    $result[$idp->getEntityId($this->samlidp_hostname)]['contacts'][] = array(
                        'contactType' => 'technical',
                        'surName' => $contact->getSn(),
                        'givenName' => $contact->getGivenName(),
                        'emailAddress' => 'mailto:' . $contact->getEmail(),
                    );
                    $result[$idp->getEntityId($this->samlidp_hostname)]['contacts'][] = array(
                        'contactType' => 'other',
                        'surName' => $contact->getSn(),
                        'givenName' => $contact->getGivenName(),
                        'emailAddress' => 'mailto:' . $contact->getEmail(),
                        'attributes'        => [
                            'xmlns:remd'        => 'http://refeds.org/metadata',
                            'remd:contactType'  => 'http://refeds.org/metadata/contactType/security',
                        ],
                    );
                }

                if (!empty($idp->getLogo())) {
                    $result[$idp->getEntityId($this->samlidp_hostname)]['UIInfo']['Logo'] = array(
                        array(
                            'url' => 'https://'. $this->getSamlidpHostname().'/images/idp_logo/'.$idp->getLogo(),
                            'width' => 200,
                            'height' => 200,
                            ),
                        );
                }
                $o_elements = array();
                foreach ($idp->getOrganizationElements() as $orgElem) {
                    if ($orgElem->getType() == 'Name') {
                        $result[$idp->getEntityId($this->samlidp_hostname)]['OrganizationName'][$orgElem->getLang()] = $orgElem->getValue();
                        $result[$idp->getEntityId($this->samlidp_hostname)]['UIInfo']['DisplayName'][$orgElem->getLang()] = $orgElem->getValue();
                        $o_elements[] = $orgElem->getValue();
                    }
                    if ($orgElem->getType() == 'InformationUrl') {
                        $result[$idp->getEntityId($this->samlidp_hostname)]['OrganizationURL'][$orgElem->getLang()] = $orgElem->getValue();
                    }
                }

                # authproc dynamic parts
                $result[$idp->getEntityId($this->samlidp_hostname)]['authproc'][9] = array(
                    'class' => 'ubuntunetbroker:AttributeAugment',
                    'idp' => $idp->getHostname(),
                    'scope' => $defaultScope,
                    'identifier_salt' => $brokerSettings['identifier_salt'],
                    'affiliation' => $this->arrayizeConfigValue($brokerSettings['affiliation']),
                    'groups' => $this->arrayizeConfigValue($brokerSettings['groups']),
                    'entitlements' => $this->arrayizeConfigValue($brokerSettings['entitlements']),
                    'languages' => $this->arrayizeConfigValue($brokerSettings['languages']),
                    'access_groups' => $this->arrayizeConfigValue($brokerSettings['access_groups']),
                    'functional_groups' => $this->arrayizeConfigValue($brokerSettings['functional_groups']),
                    'institutional_groups' => $this->arrayizeConfigValue($brokerSettings['institutional_groups']),
                );
                $result[$idp->getEntityId($this->samlidp_hostname)]['authproc'][16] = array(
                    'class' => 'core:AttributeAdd',
                    'o' => $o_elements
                );
            }
        }

        return $result;
    }

    public function getUserSalt($username)
    {
        $idp = $this->em->getRepository(IdP::class)->findOneByHostname(str_replace('.' . $this->samlidp_hostname, '', $_SERVER['HTTP_HOST']));
        if (!$idp) {
            throw new EntityNotFoundException('No IdP found in the database.');
        }

        if (preg_match('/@/', $username)) {
            $idpUser = $this->em->getRepository(\App\Entity\IdPUser::class)->findOneByEmail($username);
        } else {
            $idpUser = $this->em->getRepository(\App\Entity\IdPUser::class)->findOneBy(
                array('username' => $username, 'IdP' => $idp)
            );
        }
        # for production use here could come some error handling
        if (is_null($idpUser)){
                return null;
        } else {
                return $idpUser->getSalt();
        }
    }

    public function getAuthsources()
    {
        $config = array();
        $config['admin'] = array('core:AdminPassword');
        $defaultIdpHost = getenv('DEFAULT_IDP_HOSTNAME');
        if (empty($defaultIdpHost)) {
            $defaultIdpHost = getenv('SEED_IDP_HOSTNAME');
        }
        $defaultIdpEntityId = null;
        if (!empty($defaultIdpHost) && !empty($this->samlidp_hostname)) {
            $defaultIdpEntityId = 'https://' . $defaultIdpHost . '.' . $this->samlidp_hostname . '/saml2/idp/metadata.php';
        }

        $spEntityId = null;
        if (!empty($this->samlidp_hostname)) {
            $spEntityId = 'https://attributes.' . $this->samlidp_hostname . '/simplesaml/module.php/saml/sp/metadata.php/default-sp';
        }

        $config['default-sp'] = array(
                'saml:SP',
                'entityID' => $spEntityId,
                'idp' => $defaultIdpEntityId,
                'discoURL' => null,
                'privatekey' => 'attributes.' . $this->samlidp_hostname . '.key',
                'certificate' => 'attributes.' . $this->samlidp_hostname . '.crt',
                // 'privatekey' => 'attributes_samlidp_io.key',
                // 'certificate' => 'attributes_samlidp_io.crt',
                'attributes' => array(
                    'urn:oid:1.3.6.1.4.1.5923.1.1.1.6',
                    'urn:oid:2.16.840.1.113730.3.1.241',
                    'urn:oid:0.9.2342.19200300.100.1.3',
                    'urn:oid:1.3.6.1.4.1.5923.1.1.1.9',
                    'urn:oid:1.3.6.1.4.1.5923.1.1.1.10',
                    'urn:oid:2.5.4.10',
                    'urn:oid:1.3.6.1.4.1.25178.1.2.9',
                    'urn:oasis:names:tc:SAML:attribute:pairwise-id',
                    'urn:oasis:names:tc:SAML:attribute:subject-id'
                ),
                'name' => array(
                    'en' => 'attribute releasing tester',
                ),
        );

        $host = $_SERVER['HTTP_HOST'];
        $hostWithoutPort = strtolower(preg_replace('/:\\d+$/', '', (string) $host));

        if ($hostWithoutPort != 'attributes.' . $this->samlidp_hostname) {
            $idp = $this->em->getRepository(IdP::class)->findOneByHostname(
                str_replace('.' . $this->samlidp_hostname, '', $hostWithoutPort)
            );
            if ($idp === null) {
                return $config;
            }
            foreach ($this->buildBrokerAuthsources($idp) as $authSourceId => $authSourceConfig) {
                $config[$authSourceId] = $authSourceConfig;
            }
        }

        return $config;
    }

    private function buildBrokerAuthsources(IdP $idp)
    {
        $settings = $this->getBrokerSettings($idp->getHostname());
        $sources = array();
        $sourceChoices = array();
        $enabledMethods = $this->getEnabledBrokerMethods($idp->getHostname(), $settings);

        foreach ($enabledMethods as $method) {
            $type = isset($method['type']) ? strtolower((string) $method['type']) : 'local';
            $key = $this->sanitizeBrokerKey(isset($method['key']) ? (string) $method['key'] : $type);
            $authSourceId = $this->buildBrokerAuthSourceId($idp->getHostname(), $key);
            $label = isset($method['label']) && is_array($method['label']) ? $method['label'] : array('en' => ucfirst($key));

            if ($type === 'local') {
                $sources[$authSourceId] = $this->buildLocalSqlAuthsource($idp);
            } elseif ($type === 'saml') {
                $samlAuthsource = $this->buildUpstreamSamlAuthsource($method);
                if ($samlAuthsource === null) {
                    continue;
                }
                $sources[$authSourceId] = $samlAuthsource;
            } elseif ($type === 'oidc') {
                $oidcAuthsource = $this->buildOidcAuthsource($idp, $method);
                if ($oidcAuthsource === null) {
                    continue;
                }
                $sources[$authSourceId] = $oidcAuthsource;
            } else {
                continue;
            }

            $sourceChoices[$authSourceId] = array(
                'text' => $label,
            );
        }

        if (count($sources) === 0) {
            $fallbackId = $this->buildBrokerAuthSourceId($idp->getHostname(), 'local');
            $sources[$fallbackId] = $this->buildLocalSqlAuthsource($idp);
            $sourceChoices[$fallbackId] = array(
                'text' => array('en' => 'UbuntuNet account'),
            );
        }

        if (count($sources) > 1) {
            $brokerSourceId = $this->buildBrokerAuthSourceId($idp->getHostname(), 'broker');
            $sources[$brokerSourceId] = array(
                'multiauth:MultiAuth',
                'sources' => $sourceChoices,
                'preselect' => array_key_first($sourceChoices),
            );
        }

        return $sources;
    }

    private function buildLocalSqlAuthsource(IdP $idp)
    {
        $id_p_id = $idp->getId();

        return array(
            'sqlauth:SQL',
            'dsn' => $this->database_type . ':host='.$this->database_host.';port='. $this->database_port. ';dbname='.$this->database_name,
            'username' => $this->database_user,
            'password' => $this->database_password,
            'query' => "SELECT username, email, givenName, surName, display_name, affiliation, (CASE scope.value WHEN '@' THEN domain.domain ELSE CONCAT_WS('.',scope.value, domain.domain) END) AS scope FROM idp_internal_mysql_user, scope, domain WHERE (username = :username OR email = :username) AND password = :password AND idp_internal_mysql_user.scope_id=scope.id AND scope.domain_id=domain.id AND (domain.idp_id=$id_p_id OR domain.idp_id IS NULL);",
        );
    }

    private function buildUpstreamSamlAuthsource(array $method)
    {
        $idpEntityId = trim((string) ($method['idp_entity_id'] ?? ''));
        $metadataUrl = trim((string) ($method['metadata_url'] ?? ''));

        if ($idpEntityId === '' && $metadataUrl === '') {
            return null;
        }

        return array(
            'saml:SP',
            'entityID' => null,
            'idp' => $idpEntityId !== '' ? $idpEntityId : null,
            'discoURL' => trim((string) ($method['discovery_url'] ?? '')) ?: null,
            'metadataURL' => $metadataUrl !== '' ? $metadataUrl : null,
            'privatekey' => 'attributes.' . $this->samlidp_hostname . '.key',
            'certificate' => 'attributes.' . $this->samlidp_hostname . '.crt',
            'sign.authnrequest' => true,
            'sign.logout' => true,
        );
    }

    private function buildOidcAuthsource(IdP $idp, array $method)
    {
        $clientId = trim((string) ($method['client_id'] ?? ''));
        $clientSecret = trim((string) ($method['client_secret'] ?? ''));
        $issuer = trim((string) ($method['issuer'] ?? ''));
        $authorizationEndpoint = trim((string) ($method['authorization_endpoint'] ?? ''));
        $tokenEndpoint = trim((string) ($method['token_endpoint'] ?? ''));
        $userinfoEndpoint = trim((string) ($method['userinfo_endpoint'] ?? ''));

        if ($clientId === '' || $clientSecret === '') {
            return null;
        }

        if ($issuer === '' && ($authorizationEndpoint === '' || $tokenEndpoint === '')) {
            return null;
        }

        $provider = trim((string) ($method['provider_name'] ?? $method['key'] ?? 'OIDC'));
        $attributeMap = isset($method['attribute_map']) && is_array($method['attribute_map'])
            ? $method['attribute_map']
            : $this->getDefaultOidcAttributeMap();

        return array(
            'ubuntunetbroker:OidcGeneric',
            'provider_name' => $provider,
            'issuer' => $issuer,
            'authorization_endpoint' => $authorizationEndpoint,
            'token_endpoint' => $tokenEndpoint,
            'userinfo_endpoint' => $userinfoEndpoint,
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'scopes' => isset($method['scopes']) && is_array($method['scopes']) ? $method['scopes'] : array('openid', 'email', 'profile'),
            'client_auth_method' => trim((string) ($method['client_auth_method'] ?? 'client_secret_post')),
            'attribute_map' => $attributeMap,
            'prompt' => trim((string) ($method['prompt'] ?? 'select_account')),
            'login_hint' => trim((string) ($method['login_hint'] ?? '')),
        );
    }

    private function resolvePrimaryBrokerAuthSourceId(IdP $idp)
    {
        $settings = $this->getBrokerSettings($idp->getHostname());
        $methods = $this->getEnabledBrokerMethods($idp->getHostname(), $settings);
        $realMethodCount = 0;
        $lastKey = 'local';

        foreach ($methods as $method) {
            $type = isset($method['type']) ? strtolower((string) $method['type']) : 'local';
            if ($type === 'local') {
                $realMethodCount++;
                $lastKey = $this->sanitizeBrokerKey(isset($method['key']) ? (string) $method['key'] : 'local');
                continue;
            }

            if ($type === 'saml') {
                if (trim((string) ($method['idp_entity_id'] ?? '')) === '' && trim((string) ($method['metadata_url'] ?? '')) === '') {
                    continue;
                }
            }

            if ($type === 'oidc') {
                if (
                    trim((string) ($method['client_id'] ?? '')) === ''
                    || trim((string) ($method['client_secret'] ?? '')) === ''
                    || (
                        trim((string) ($method['issuer'] ?? '')) === ''
                        && trim((string) ($method['authorization_endpoint'] ?? '')) === ''
                    )
                ) {
                    continue;
                }
            }

            $realMethodCount++;
            $lastKey = $this->sanitizeBrokerKey(isset($method['key']) ? (string) $method['key'] : $type);
        }

        if ($realMethodCount > 1) {
            return $this->buildBrokerAuthSourceId($idp->getHostname(), 'broker');
        }

        return $this->buildBrokerAuthSourceId($idp->getHostname(), $lastKey);
    }

    private function getEnabledBrokerMethods($hostname, array $settings)
    {
        $methods = isset($settings['methods']) && is_array($settings['methods']) ? $settings['methods'] : array();
        if (count($methods) === 0) {
            $methods = array(
                array(
                    'key' => 'local',
                    'type' => 'local',
                    'enabled' => true,
                    'label' => array('en' => 'UbuntuNet account'),
                ),
            );
        }

        $enabled = array();
        foreach ($methods as $method) {
            if (!is_array($method)) {
                continue;
            }
            if (array_key_exists('enabled', $method) && !$method['enabled']) {
                continue;
            }
            $enabled[] = $method;
        }

        if (count($enabled) === 0) {
            $enabled[] = array(
                'key' => 'local',
                'type' => 'local',
                'enabled' => true,
                'label' => array('en' => 'UbuntuNet account'),
            );
        }

        return $enabled;
    }

    private function buildBrokerAuthSourceId($hostname, $key)
    {
        return 'as-' . $hostname . '-' . $this->sanitizeBrokerKey($key);
    }

    private function sanitizeBrokerKey($key)
    {
        $sanitized = preg_replace('/[^a-z0-9]+/i', '-', strtolower((string) $key));
        $sanitized = trim((string) $sanitized, '-');

        return $sanitized !== '' ? $sanitized : 'source';
    }

    private function getBrokerSettings($hostname)
    {
        $config = $this->loadBrokerConfig();
        $defaults = isset($config['_defaults']) && is_array($config['_defaults']) ? $config['_defaults'] : array();
        $settings = isset($config[$hostname]) && is_array($config[$hostname]) ? $config[$hostname] : array();

        $merged = $defaults;
        foreach ($settings as $key => $value) {
            if (is_array($value) && isset($merged[$key]) && is_array($merged[$key]) && array_keys($merged[$key]) !== range(0, count($merged[$key]) - 1)) {
                $merged[$key] = array_merge($merged[$key], $value);
            } else {
                $merged[$key] = $value;
            }
        }

        if (empty($merged['identifier_salt'])) {
            $merged['identifier_salt'] = getenv('BROKER_IDENTIFIER_SALT') ?: 'change-me-before-production';
        }

        return $merged;
    }

    private function loadBrokerConfig()
    {
        if (is_array($this->brokerConfig)) {
            return $this->brokerConfig;
        }

        $this->brokerConfig = array();
        $configDir = getenv('SIMPLESAMLPHP_CONFIG_DIR');
        $candidatePaths = array();

        if (!empty($configDir)) {
            $candidatePaths[] = rtrim($configDir, '/') . '/broker.php';
        }
        $candidatePaths[] = $this->projectDir . '/../conf/simplesamlphp/broker.php';

        foreach ($candidatePaths as $path) {
            if (is_readable($path)) {
                $loaded = require $path;
                if (is_array($loaded)) {
                    $this->brokerConfig = $loaded;
                    break;
                }
            }
        }

        return $this->brokerConfig;
    }

    private function resolveBrokerScope(IdP $idp, array $brokerSettings)
    {
        if (!empty($brokerSettings['default_scope'])) {
            return trim((string) $brokerSettings['default_scope']);
        }

        $defaultScope = $idp->getDefaultScope($this->samlidp_hostname);
        if (is_object($defaultScope) && method_exists($defaultScope, 'getFullScope')) {
            return $defaultScope->getFullScope();
        }
        if (is_string($defaultScope) && $defaultScope !== '') {
            return $defaultScope;
        }

        return $idp->getHostname() . '.' . $this->samlidp_hostname;
    }

    private function arrayizeConfigValue($value)
    {
        if (is_array($value)) {
            return array_values(array_filter(array_map('strval', $value), 'strlen'));
        }

        if ($value === null) {
            return array();
        }

        $value = trim((string) $value);
        if ($value === '') {
            return array();
        }

        return array($value);
    }

    private function getDefaultOidcAttributeMap()
    {
        return array(
            'sub' => array('uid', 'oidc_sub'),
            'email' => array('mail'),
            'name' => array('displayName', 'display_name', 'cn'),
            'given_name' => array('givenName'),
            'family_name' => array('surName'),
            'preferred_username' => array('username'),
            'hd' => array('schacHomeOrganization'),
            'locale' => array('preferredLanguage'),
        );
    }

    public function addIdpAuditRecord($host, $username, $sp)
    {
        $idp = $this->em->getRepository(IdP::class)->findOneByHostname(str_replace('.' . $this->samlidp_hostname, '', $host));

        if (preg_match('/@/', $username)) {
            $idpUser = $this->em->getRepository(\App\Entity\IdPUser::class)->findOneByEmail($username);
        } else {
            $idpUser = $this->em->getRepository(\App\Entity\IdPUser::class)->findOneBy(
                array('username' => $username, 'IdP' => $idp)
            );
        }

        $now = new \DateTime();

        $newidpaudit = new IdPAudit($idpUser, $now, $idp, 'none');

        $this->em->persist($newidpaudit);
        $this->em->flush();
    }

    private function resolveCertificateDescriptor(IdP $idp, $requestHost)
    {
        $certBaseDir = $this->getCertBaseDir();
        $slug = trim((string) $idp->getHostname());
        $baseDomain = trim((string) $this->samlidp_hostname);
        $folderCandidates = array();
        $requestHost = trim((string) $requestHost);

        if ($requestHost !== '') {
            $folderCandidates[] = $requestHost;
            if ($baseDomain !== '' && substr($requestHost, -strlen($baseDomain)) === $baseDomain) {
                $prefix = rtrim(substr($requestHost, 0, -strlen($baseDomain)), '.');
                if ($prefix !== '') {
                    $folderCandidates[] = $prefix;
                }
            }
        }

        if ($slug !== '') {
            if ($baseDomain !== '') {
                $folderCandidates[] = $slug . '.' . $baseDomain;
            }
            $folderCandidates[] = $slug;
        }

        $folderCandidates[] = 'default';
        $folderCandidates[] = '';
        $folderCandidates = array_values(array_unique($folderCandidates));

        foreach ($folderCandidates as $folder) {
            foreach ($this->candidateCertificatePairs() as $pair) {
                $paths = $this->buildCertificatePaths($certBaseDir, $folder, $pair['cert'], $pair['key']);
                if (is_readable($paths['certPath']) && is_readable($paths['keyPath'])) {
                    $certContent = file_get_contents($paths['certPath']);
                    if ($certContent === false) {
                        continue;
                    }

                    return array(
                        'certPath' => $paths['certPath'],
                        'keyPath' => $paths['keyPath'],
                        'relativeCert' => $paths['relativeCert'],
                        'relativeKey' => $paths['relativeKey'],
                        'certData' => $this->normaliseCertificateBody($certContent),
                    );
                }
            }
        }

        $targetFolder = $slug !== '' ? $slug : 'default';
        $paths = $this->buildCertificatePaths($certBaseDir, $targetFolder, 'idp.crt.pem', 'idp.key.pem');
        $certBody = $idp->getCertPem();
        $keyBody = $idp->getCertKey();

        if ($certBody === null || $certBody === '' || $keyBody === null || $keyBody === '') {
            return null;
        }

        $this->ensureDirectory(dirname($paths['certPath']));

        file_put_contents($paths['certPath'], $certBody);
        file_put_contents($paths['keyPath'], $keyBody);
        @chmod($paths['keyPath'], 0600);

        return array(
            'certPath' => $paths['certPath'],
            'keyPath' => $paths['keyPath'],
            'relativeCert' => $paths['relativeCert'],
            'relativeKey' => $paths['relativeKey'],
            'certData' => $this->normaliseCertificateBody($certBody),
        );
    }

    private function candidateCertificatePairs()
    {
        return array(
            array('cert' => 'idp.crt.pem', 'key' => 'idp.key.pem'),
            array('cert' => 'idp.crt', 'key' => 'idp.key'),
        );
    }

    private function buildCertificatePaths($baseDir, $folder, $certFile, $keyFile)
    {
        $baseDir = rtrim($baseDir, '/');
        $folder = trim((string) $folder, '/');

        if ($folder === '') {
            $certPath = $baseDir . '/' . $certFile;
            $keyPath = $baseDir . '/' . $keyFile;
            $relativeCert = $certFile;
            $relativeKey = $keyFile;
        } else {
            $certPath = $baseDir . '/' . $folder . '/' . $certFile;
            $keyPath = $baseDir . '/' . $folder . '/' . $keyFile;
            $relativeCert = $folder . '/' . $certFile;
            $relativeKey = $folder . '/' . $keyFile;
        }

        return array(
            'certPath' => $certPath,
            'keyPath' => $keyPath,
            'relativeCert' => $relativeCert,
            'relativeKey' => $relativeKey,
        );
    }

    private function normaliseCertificateBody($certificate)
    {
        $certificate = trim((string) $certificate);
        if ($certificate === '') {
            return '';
        }

        $lines = preg_split('/\r?\n/', $certificate);
        $filtered = array();
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || strpos($line, '-----BEGIN') === 0 || strpos($line, '-----END') === 0) {
                continue;
            }
            $filtered[] = $line;
        }

        return implode("\n", $filtered);
    }

    private function getCertBaseDir()
    {
        $projectRoot = $this->projectDir ?: realpath(__DIR__ . '/../../..');
        if ($projectRoot === false) {
            $projectRoot = dirname(dirname(dirname(__DIR__)));
        }

        $candidate = $projectRoot . '/certs';
        $certDir = is_dir($candidate) ? $candidate : dirname($projectRoot) . '/certs';
        $this->ensureDirectory($certDir);

        return $certDir;
    }

    private function ensureDirectory($directory)
    {
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new \RuntimeException(sprintf('Unable to create directory %s', $directory));
        }
    }
}
