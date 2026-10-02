<?php
// TEMPORARY diagnostic: shows how the client address reaches PHP. Remove after use.
include __DIR__ . '/../core/db.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');
echo json_encode([
    'REMOTE_ADDR'         => $_SERVER['REMOTE_ADDR'] ?? null,
    'X-Forwarded-For'     => $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null,
    'CF-Connecting-IP'    => $_SERVER['HTTP_CF_CONNECTING_IP'] ?? null,
    'True-Client-IP'      => $_SERVER['HTTP_TRUE_CLIENT_IP'] ?? null,
    'X-Real-IP'           => $_SERVER['HTTP_X_REAL_IP'] ?? null,
    'trust_forwarded_env' => getenv('HASTRA_TRUST_FORWARDED') ?: null,
    'astra_get_client_ip' => astra_get_client_ip(),
    'is_local_request'    => astra_is_local_request(),
], JSON_PRETTY_PRINT);
