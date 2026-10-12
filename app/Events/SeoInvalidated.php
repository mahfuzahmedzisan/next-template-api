<?php

namespace App\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Something that feeds resolved SEO changed: SEO settings, a page, a detail
 * record, or the site name/logo. Dispatched after the transaction commits.
 */
class SeoInvalidated implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    /**
     * @param  list<string>  $tags  Next.js cache tags to revalidate
     */
    public function __construct(public array $tags = ['seo']) {}
}
