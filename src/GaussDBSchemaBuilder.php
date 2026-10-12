<?php

declare(strict_types=1);

namespace GaussDb\Eloquent;

use Illuminate\Database\Schema\Builder;

/**
 * Schema builder wired to the GaussDB grammar. The base implementation's
 * introspection (hasTable / hasColumn / getColumns) runs against
 * information_schema, which GaussDB provides.
 */
class GaussDBSchemaBuilder extends Builder
{
}
