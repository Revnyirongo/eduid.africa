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

$idpSlug = getenv('SEED_IDP_HOSTNAME') ?: 'demo';
$idpName = getenv('SEED_IDP_NAME') ?: 'Demo Identity Provider';
$idpUrl = getenv('SEED_IDP_URL') ?: 'https://demo.example.edu';

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

    $pdo->commit();

    echo "Seeded IdP '{$idpSlug}'.\n";
} catch (Throwable $exception) {
    $pdo->rollBack();
    fwrite(STDERR, 'Failed to seed default IdP: ' . $exception->getMessage() . "\n");
}
