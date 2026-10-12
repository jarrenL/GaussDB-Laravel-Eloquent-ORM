<?php

declare(strict_types=1);

namespace GaussDb\Eloquent;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Processors\PostgresProcessor;

/**
 * Processor for GaussDB. The Postgres insertGetId path (RETURNING) already
 * works over ODBC on both deployment shapes; booleans normalize to '1'/'0'
 * strings with the BoolsAsChar=0 DSN flag, so models needing native bools
 * should cast attributes.
 */
class GaussDBProcessor extends PostgresProcessor
{
}
