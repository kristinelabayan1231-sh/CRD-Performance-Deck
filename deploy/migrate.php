<?php

// One-time migration/seed runner for hosts with no SSH access (InfinityFree).
// The deploy workflow copies this to the web root, hits it once over HTTP
// with a secret token, then deletes it via FTP in the same run — see
// .github/workflows/deploy.yml. It should never sit on the server for long;
// if you ever find this file still deployed, delete it immediately.

header('Content-Type: text/plain');

require __DIR__.'/vendor/autoload.php';

/** @var \Illuminate\Foundation\Application $app */
$app = require __DIR__.'/bootstrap/app.php';

$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);

// Bootstrapping (not just building) the app is what actually loads .env —
// the token check below depends on that having happened first.
$kernel->bootstrap();

$token = $_GET['token'] ?? '';
$expected = (string) env('DEPLOY_MIGRATE_TOKEN', '');

if ($expected === '' || ! hash_equals($expected, $token)) {
    http_response_code(403);
    echo "Forbidden\n";
    exit;
}

$kernel->call('migrate', ['--force' => true]);
echo $kernel->output();

$kernel->call('db:seed', ['--force' => true]);
echo $kernel->output();

echo "\nDone.\n";
