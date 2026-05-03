<?php

$container = require __DIR__ . '/../bootstrap_symfony.php';
$sspgetter = $container->get(\App\Utils\SSPGetter::class);

$metadata = $sspgetter->getSaml20spremoteForAnIdp($_SERVER['HTTP_HOST'] ?? '');

$metadata['https://attributes.' . $sspgetter->getSamlidpHostname() . '/simplesaml/module.php/saml/sp/metadata.php/default-sp'] = array(
  'SingleLogoutService' => array(
    0 => array(
      'Binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect',
      'Location' => 'https://attributes.' . $sspgetter->getSamlidpHostname() . '/simplesaml/module.php/saml/sp/saml2-logout.php/default-sp',
    ),
  ),
  'AssertionConsumerService' => array(
    0 => array(
      'index' => 0,
      'Binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST',
      'Location' => 'https://attributes.' . $sspgetter->getSamlidpHostname() . '/simplesaml/module.php/saml/sp/saml2-acs.php/default-sp',
    ),
  ),
  'attributes' => array(
        'urn:oid:1.3.6.1.4.1.5923.1.1.1.6',
        'urn:oid:2.16.840.1.113730.3.1.241',
        'urn:oid:0.9.2342.19200300.100.1.3',
        'urn:oid:1.3.6.1.4.1.5923.1.1.1.9',
        'urn:oid:1.3.6.1.4.1.5923.1.1.1.7',
        'urn:oid:1.3.6.1.4.1.5923.1.1.1.10',
        'urn:oid:1.3.6.1.4.1.25178.1.2.9',
        'urn:oid:2.5.4.10',
        'preferredLanguage',
        'isMemberOf',
        'copAccessGroup',
        'copFunctionalGroup',
        'copInstitutionalGroup',
        'urn:oasis:names:tc:SAML:attribute:pairwise-id',
        'urn:oasis:names:tc:SAML:attribute:subject-id'
  ),
  'name' => array(
      'en' => $sspgetter->getSamlidpHostname() . ' - attribute releasing tester',
  ),
  'certificate' => 'attributes.' . $sspgetter->getSamlidpHostname() . '.crt'
);

$metadata['https://cert-manager.com/shibboleth'] = array(
  'AssertionConsumerService' => array(
    0 => array(
      'index' => 1,
      'Binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST',
      'Location' => 'https://cert-manager.com/Shibboleth.sso/SAML2/POST',
      'isDefault' => true,
    ),
    1 => array(
      'index' => 3,
      'Binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST',
      'Location' => 'https://cert-manager.com/saml2int/Shibboleth.sso/SAML2/POST',
    ),
  ),
  'SingleLogoutService' => array(
    0 => array(
      'Binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect',
      'Location' => 'https://cert-manager.com/Shibboleth.sso/SLO/Redirect',
    ),
    1 => array(
      'Binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST',
      'Location' => 'https://cert-manager.com/Shibboleth.sso/SLO/POST',
    ),
  ),
  'attributes' => array(
    'urn:oid:1.3.6.1.4.1.5923.1.1.1.6', // eduPersonPrincipalName
    'urn:oid:0.9.2342.19200300.100.1.3', // mail
    'urn:oid:2.16.840.1.113730.3.1.241', // displayName
    'urn:oid:2.5.4.42', // givenName
    'urn:oid:2.5.4.4', // sn
    'urn:oid:1.3.6.1.4.1.25178.1.2.9', // schacHomeOrganization
    'urn:oid:1.3.6.1.4.1.5923.1.1.1.7', // eduPersonEntitlement
  ),
  'name' => array(
    'en' => 'Sectigo SCM',
  ),
  'authproc' => array(
    99 => array(
      'class' => 'core:AttributeMap',
      'oid2name',
    ),
  ),
);

return $metadata;
