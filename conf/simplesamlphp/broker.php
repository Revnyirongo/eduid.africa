<?php

return [
    '_defaults' => [
        // Change this in production. It is used when generating deterministic
        // broker-side identifiers and entitlements for users coming from
        // multiple upstream authentication methods.
        'identifier_salt' => getenv('BROKER_IDENTIFIER_SALT') ?: 'change-me-before-production',
        'default_scope' => getenv('BROKER_DEFAULT_SCOPE') ?: (getenv('SAMLIDP_HOSTNAME') ?: 'eduid.africa'),
        'affiliation' => ['member'],
        'groups' => [],
        'entitlements' => [],
        'languages' => [],
        'access_groups' => [],
        'functional_groups' => [],
        'institutional_groups' => [],
    ],

    // Example tenant configuration.
    //
    // Each tenant can expose one or more upstream authentication methods in the
    // same brokered IdP session:
    // - local:          current SQL-backed login
    // - saml:           institutional SAML upstream
    // - oidc:           Google or Microsoft as an upstream OIDC provider
    //
    // The MultiAuth selector is enabled automatically whenever more than one
    // method is active for a tenant.
    'idpuni' => [
        'methods' => [
            [
                'key' => 'local',
                'type' => 'local',
                'enabled' => true,
                'label' => ['en' => 'UbuntuNet account'],
            ],
            [
                'key' => 'institutional',
                'type' => 'saml',
                'enabled' => false,
                'label' => ['en' => 'Institutional login'],
                'idp_entity_id' => getenv('BROKER_INSTITUTIONAL_IDP_ENTITY_ID') ?: '',
                'metadata_url' => getenv('BROKER_INSTITUTIONAL_METADATA_URL') ?: '',
                'discovery_url' => getenv('BROKER_INSTITUTIONAL_DISCOVERY_URL') ?: '',
            ],
            [
                'key' => 'google',
                'type' => 'oidc',
                'enabled' => false,
                'label' => ['en' => 'Google'],
                'provider_name' => 'Google',
                'issuer' => 'https://accounts.google.com',
                'client_id' => getenv('GOOGLE_CLIENT_ID') ?: '',
                'client_secret' => getenv('GOOGLE_CLIENT_SECRET') ?: '',
                'scopes' => ['openid', 'email', 'profile'],
                'prompt' => 'select_account',
                'attribute_map' => [
                    'sub' => ['uid', 'oidc_sub'],
                    'email' => ['mail'],
                    'name' => ['displayName', 'display_name', 'cn'],
                    'given_name' => ['givenName'],
                    'family_name' => ['surName'],
                    'preferred_username' => ['username'],
                    'hd' => ['schacHomeOrganization'],
                    'locale' => ['preferredLanguage'],
                ],
            ],
            [
                'key' => 'microsoft',
                'type' => 'oidc',
                'enabled' => false,
                'label' => ['en' => 'Microsoft'],
                'provider_name' => 'Microsoft',
                'issuer' => getenv('MICROSOFT_OIDC_ISSUER') ?: 'https://login.microsoftonline.com/common/v2.0',
                'client_id' => getenv('MICROSOFT_CLIENT_ID') ?: '',
                'client_secret' => getenv('MICROSOFT_CLIENT_SECRET') ?: '',
                'scopes' => ['openid', 'email', 'profile'],
                'attribute_map' => [
                    'sub' => ['uid', 'oidc_sub'],
                    'email' => ['mail'],
                    'name' => ['displayName', 'display_name', 'cn'],
                    'given_name' => ['givenName'],
                    'family_name' => ['surName'],
                    'preferred_username' => ['username'],
                    'tid' => ['institutionTenantId'],
                    'locale' => ['preferredLanguage'],
                ],
            ],
        ],

        // The broker filter adds these attributes after any upstream login so
        // downstream SPs can consume a consistent CoP profile.
        'groups' => [
            'cop:example',
        ],
        'entitlements' => [
            'urn:ubuntunet:cop:example:member',
        ],
        'languages' => [
            'English',
        ],
        'access_groups' => [
            'Member',
        ],
        'functional_groups' => [
            'Facilitator',
        ],
        'institutional_groups' => [
            'Partner',
        ],
    ],
];
