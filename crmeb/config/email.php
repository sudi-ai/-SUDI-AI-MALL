<?php
// SMTP credentials must be provided by deployment environment; never commit them here.
return [
    'host' => \think\facade\Env::get('email.host', ''),
    'port' => (int)\think\facade\Env::get('email.port', 587),
    'username' => \think\facade\Env::get('email.username', ''),
    'password' => \think\facade\Env::get('email.password', ''),
    'from' => \think\facade\Env::get('email.from', ''),
    'from_name' => \think\facade\Env::get('email.from_name', '苏迪商城'),
    'encryption' => strtolower((string)\think\facade\Env::get('email.encryption', 'tls')),
    'timeout' => (int)\think\facade\Env::get('email.timeout', 10),
];
