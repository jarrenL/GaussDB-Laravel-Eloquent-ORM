<?php
require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$schema = $app->make('db')->connection()->getSchemaBuilder();
foreach ([getenv('GAUSS_SCHEMA').'.'.getenv('GAUSS_ARTISAN_TABLE'), getenv('GAUSS_MIGRATION_TABLE')] as $table) {
    $schema->dropIfExists($table);
    if ($schema->hasTable($table)) { throw new RuntimeException('Cleanup left table'); }
}
echo "zero leftovers\n";
