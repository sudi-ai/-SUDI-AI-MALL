<?php
$root = __DIR__ . '/../..';
$controller = file_get_contents($root . '/crmeb/app/api/controller/v1/EmailAuthController.php');
$login = file_get_contents($root . '/crmeb/app/services/user/LoginServices.php');
$routes = file_get_contents($root . '/crmeb/app/api/route/v1.php');
$migration = file_get_contents($root . '/crmeb/public/install/sudi_email_auth_migration.sql');
$required = [
    'email/register/verify' => $routes,
    'email/register' => $routes,
    'verifyRegistrationCode' => $controller,
    'password_hash($password' => $login,
    'account|phone|email' => $login,
    'UNIQUE INDEX `uniq_user_email`' => $migration,
    '`pwd` varchar(255)' => $migration,
];
foreach ($required as $needle => $subject) {
    if (strpos($subject, $needle) === false) throw new RuntimeException('Email auth contract missing: ' . $needle);
}
echo "Email registration contract OK\n";
