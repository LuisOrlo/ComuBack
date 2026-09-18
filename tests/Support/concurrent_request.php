<?php

require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$kernel = $app->make(\Illuminate\Contracts\Http\Kernel::class);
$arguments = json_decode(base64_decode($argv[1]), true, 512, JSON_THROW_ON_ERROR);

touch($arguments['ready']);
while (!is_file($arguments['go'])) {
    usleep(10000);
}

$request = \Illuminate\Http\Request::create(
    $arguments['uri'],
    'POST',
    $arguments['payload']
);
$request->headers->set('Accept', 'application/json');
$request->headers->set('Authorization', 'Bearer '.$arguments['token']);
$response = $kernel->handle($request);

echo json_encode([
    'status' => $response->getStatusCode(),
    'body' => json_decode($response->getContent(), true),
], JSON_UNESCAPED_UNICODE).PHP_EOL;
