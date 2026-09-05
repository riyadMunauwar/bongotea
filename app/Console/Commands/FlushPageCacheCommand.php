<?php

namespace App\Console\Commands;

use App\Support\PageCache\StaticPageCache;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Empty the static page cache.
 *
 * `PageCacheInvalidator` covers everything the database knows about: a Page is
 * saved, a Product's price moves, and the URLs that died are named. A release
 * is the case it cannot see — nothing in the database changed, yet every
 * cached file was written by templates that no longer exist. `deploy/README.md`
 * has the operator-facing version, under "Releasing".
 */
class FlushPageCacheCommand extends Command
{
    protected $signature = 'page-cache:flush';

    protected $description = 'Empty the static page cache, removing every cached Page and its gzip sibling';

    public function handle(StaticPageCache $cache): int
    {
        $removed = $cache->flush();

        $this->components->info("Removed {$removed} cached ".Str::plural('Page', $removed).'.');

        return self::SUCCESS;
    }
}
