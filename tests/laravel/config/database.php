<?php
return [
    'default'=>'gaussdb',
    'migrations'=>['table'=>getenv('GAUSS_MIGRATION_TABLE') ?: 'elq_acceptance_migrations', 'update_date_on_publish'=>true],
    'connections'=>['gaussdb'=>[
        'driver'=>'gaussdb', 'host'=>getenv('GAUSS_HOST'), 'port'=>(int)getenv('GAUSS_PORT'),
        'database'=>getenv('GAUSS_DATABASE'), 'username'=>getenv('GAUSS_USER'), 'password'=>getenv('GAUSS_PASSWORD'),
        'schema'=>getenv('GAUSS_SCHEMA') ?: 'public', 'mode'=>getenv('GAUSS_MODE') ?: 'A',
        'distribution_key'=>getenv('GAUSS_DISTRIBUTED') === 'true' ? 'id' : '',
    ]],
];
