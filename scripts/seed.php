<?php

require_once __DIR__ . '/db.php';

try {
    $pdo = createPdoFromEnv();
} catch (RuntimeException $runtimeException) {
    fwrite(STDERR, $runtimeException->getMessage() . "\n");
    exit(1);
} catch (PDOException $exception) {
    fwrite(STDERR, 'Database connection failed: ' . $exception->getMessage() . "\n");
    exit(1);
}

$tableExists = function (PDO $pdo, $table) {
    $stmt = $pdo->prepare("SELECT 1 FROM information_schema.tables WHERE table_schema = 'public' AND table_name = :table LIMIT 1");
    $stmt->execute(['table' => $table]);
    return (bool) $stmt->fetchColumn();
};

if (!$tableExists($pdo, 'users')) {
    fwrite(STDOUT, "Users table not found; skipping seed.\n");
    exit(0);
}

$username = getenv('SEED_USER_USERNAME') ?: 'demo';
$email = getenv('SEED_USER_EMAIL') ?: 'demo@example.org';
$displayName = getenv('SEED_USER_DISPLAY_NAME') ?: 'Demo User';

$sql = 'INSERT INTO users (username, email, display_name) VALUES (:username, :email, :display_name)
        ON CONFLICT (username) DO UPDATE SET email = EXCLUDED.email, display_name = EXCLUDED.display_name';

$stmt = $pdo->prepare($sql);
$stmt->execute([
    'username' => $username,
    'email' => $email,
    'display_name' => $displayName,
]);

echo "Seeded user '{$username}'.\n";

$requiredIdpTables = ['idp', 'organization_element', 'domain', 'scope'];
foreach ($requiredIdpTables as $table) {
    if (!$tableExists($pdo, $table)) {
        fwrite(STDOUT, "Table '{$table}' not found; skipping IdP seed.\n");
        exit(0);
    }
}

$idpSlug = getenv('SEED_IDP_HOSTNAME') ?: '';
$baseHost = getenv('SAMLIDP_HOSTNAME') ?: '';

// Derive a sensible default IdP slug from the configured base host (take the leftmost label),
// or fall back to "demo" to preserve existing behaviour.
if ($idpSlug === '') {
    if (strpos($baseHost, '.') !== false) {
        $idpSlug = substr($baseHost, 0, strpos($baseHost, '.'));
    } elseif ($baseHost !== '') {
        $idpSlug = $baseHost;
    } else {
        $idpSlug = 'demo';
    }
}

$idpName = getenv('SEED_IDP_NAME') ?: ($idpSlug === 'demo' ? 'Demo Identity Provider' : ucfirst($idpSlug) . ' Identity Provider');
$idpUrl = getenv('SEED_IDP_URL') ?: 'https://' . $idpSlug . '.' . ($baseHost ?: 'example.edu');

try {
    $pdo->beginTransaction();

    $findIdp = $pdo->prepare('SELECT id FROM idp WHERE hostname = :hostname LIMIT 1');
    $findIdp->execute(['hostname' => $idpSlug]);
    $idpId = $findIdp->fetchColumn();

    if ($idpId === false) {
        $insertIdp = $pdo->prepare(
            "INSERT INTO idp (id, status, hostname, registrationInstant) VALUES (nextval('idp_id_seq'), :status, :hostname, NOW()) RETURNING id"
        );
        $insertIdp->execute([
            'status' => 'active',
            'hostname' => $idpSlug,
        ]);
        $idpId = $insertIdp->fetchColumn();
    }

    if ($idpId === false) {
        throw new RuntimeException('Unable to ensure default IdP exists.');
    }

    $ensureOrgElement = function (PDO $pdo, $idpId, $type, $lang, $value) {
        $select = $pdo->prepare('SELECT 1 FROM organization_element WHERE idp_id = :idp_id AND type = :type AND lang = :lang');
        $select->execute([
            'idp_id' => $idpId,
            'type' => $type,
            'lang' => $lang,
        ]);

        if ($select->fetchColumn() === false) {
            $insert = $pdo->prepare(
                "INSERT INTO organization_element (id, idp_id, type, lang, value) VALUES (nextval('organization_element_id_seq'), :idp_id, :type, :lang, :value)"
            );
            $insert->execute([
                'idp_id' => $idpId,
                'type' => $type,
                'lang' => $lang,
                'value' => $value,
            ]);
        }
    };

    $ensureOrgElement($pdo, $idpId, 'Name', 'en', $idpName);
    $ensureOrgElement($pdo, $idpId, 'InformationUrl', 'en', $idpUrl);

    // Ensure a domain and scope exist for the IdP, so metadata generation does not fail.
    $domain = $pdo->prepare('SELECT id FROM domain WHERE idp_id = :idp_id LIMIT 1');
    $domain->execute(['idp_id' => $idpId]);
    $domainId = $domain->fetchColumn();

    if ($domainId === false) {
        $insertDomain = $pdo->prepare(
            "INSERT INTO domain (id, idp_id, domain) VALUES (nextval('domain_id_seq'), :idp_id, :domain) RETURNING id"
        );
        $insertDomain->execute([
            'idp_id' => $idpId,
            'domain' => $idpSlug,
        ]);
        $domainId = $insertDomain->fetchColumn();
    }

    $scopeId = null;
    $scopeExists = $pdo->prepare('SELECT id FROM scope WHERE domain_id = :domain_id LIMIT 1');
    $scopeExists->execute(['domain_id' => $domainId]);
    $scopeId = $scopeExists->fetchColumn();
    if ($scopeId === false) {
        $insertScope = $pdo->prepare(
            "INSERT INTO scope (id, domain_id, value) VALUES (nextval('scope_id_seq'), :domain_id, :value) RETURNING id"
        );
        $insertScope->execute([
            'domain_id' => $domainId,
            'value' => $idpSlug,
        ]);
        $scopeId = $insertScope->fetchColumn();
    }

    // If the insert above did not return, fall back to re-reading.
    if ($scopeId === false || $scopeId === null) {
        $scopeExists->execute(['domain_id' => $domainId]);
        $scopeId = $scopeExists->fetchColumn();
    }

    if ($scopeId !== false && $scopeId !== null) {
        $setDefaultScope = $pdo->prepare('UPDATE idp SET default_scope_id = :scope_id WHERE id = :id');
        $setDefaultScope->execute([
            'scope_id' => $scopeId,
            'id' => $idpId,
        ]);
    }

    $idpUserUsername = getenv('SEED_IDP_USER_USERNAME') ?: 'demo';
    $idpUserEmail = getenv('SEED_IDP_USER_EMAIL') ?: 'demo@example.org';
    $idpUserPassword = getenv('SEED_IDP_USER_PASSWORD') ?: 'demo123';
    $idpUserGivenName = getenv('SEED_IDP_USER_GIVEN_NAME') ?: 'Demo';
    $idpUserSurname = getenv('SEED_IDP_USER_SURNAME') ?: 'User';
    $idpUserDisplayName = getenv('SEED_IDP_USER_DISPLAY_NAME') ?: 'Demo User';
    $idpUserAffiliation = getenv('SEED_IDP_USER_AFFILIATION') ?: 'member';

    if ($scopeId !== false && $scopeId !== null) {
        $userExists = $pdo->prepare('SELECT 1 FROM idp_internal_mysql_user WHERE username = :username AND idp_id = :idp_id');
        $userExists->execute([
            'username' => $idpUserUsername,
            'idp_id' => $idpId,
        ]);

        if ($userExists->fetchColumn() === false) {
            $insertUser = $pdo->prepare(
                "INSERT INTO idp_internal_mysql_user (id, scope_id, idp_id, username, password, email, givenName, surName, display_name, affiliation, enabled, deleted)
                 VALUES (nextval('idp_internal_mysql_user_id_seq'), :scope_id, :idp_id, :username, :password, :email, :givenname, :surname, :display_name, :affiliation, true, false)"
            );
            $insertUser->execute([
                'scope_id' => $scopeId,
                'idp_id' => $idpId,
                'username' => $idpUserUsername,
                'password' => $idpUserPassword,
                'email' => $idpUserEmail,
                'givenname' => $idpUserGivenName,
                'surname' => $idpUserSurname,
                'display_name' => $idpUserDisplayName,
                'affiliation' => $idpUserAffiliation,
            ]);
        }
    }

    $pdo->commit();

    echo "Seeded IdP '{$idpSlug}'.\n";
} catch (Throwable $exception) {
    $pdo->rollBack();
    fwrite(STDERR, 'Failed to seed default IdP: ' . $exception->getMessage() . "\n");
}
