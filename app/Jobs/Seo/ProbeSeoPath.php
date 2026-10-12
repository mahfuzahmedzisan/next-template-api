<?php

namespace App\Jobs\Seo;

use App\Models\SeoPage;
use App\Services\Seo\SeoSettings;
use App\Support\Seo\SeoQueue;
use App\Support\Seo\SeoUrl;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Http;

/**
 * Checks that a registered path really answers on the public site. Runs on
 * the queue, never inside a request: under `php artisan serve` a synchronous
 * probe would wait on a Next page that is itself waiting on this API.
 */
class ProbeSeoPath implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** Stored when the site didn't answer (timeout, DNS, refused). */
    public const UNREACHABLE = 0;

    public int $tries = 1;

    public int $timeout = 30;

    public function __construct(public int $pageId)
    {
        $this->onConnection(SeoQueue::connection());
        $this->onQueue(SeoQueue::name());
        $this->afterCommit();
    }

    public static function dispatchForAll(): void
    {
        SeoPage::query()->orderBy('id')->pluck('id')->each(fn (int $id) => self::dispatch($id));
    }

    public function handle(SeoSettings $settings): void
    {
        $page = SeoPage::query()->find($this->pageId);

        if ($page === null) {
            return;
        }

        $settings->refresh();
        $url = SeoUrl::join($settings->siteUrl(), $page->path);
        $wasAllowed = $page->statusAllowsSitemap();

        [$status, $location] = $this->request($url);

        $page->forceFill([
            'last_status' => $status,
            'redirect_to' => $status >= 300 && $status < 400 ? $location : null,
            'last_checked_at' => now(),
        ])->saveQuietly();

        if ($wasAllowed !== $page->statusAllowsSitemap()) {
            RegenerateSeoFiles::dispatch()->delay(now()->addSeconds(RegenerateSeoFiles::DEBOUNCE_SECONDS));
        }
    }

    /**
     * @return array{0: int, 1: ?string}
     */
    private function request(string $url): array
    {
        $client = Http::timeout((int) config('seo.probe.timeout', 5))
            ->withUserAgent((string) config('seo.audit.user_agent'))
            ->withOptions(['allow_redirects' => false]);

        try {
            $response = $client->head($url);

            if (in_array($response->status(), [405, 501], true)) {
                $response = $client->get($url);
            }
        } catch (ConnectionException) {
            return [self::UNREACHABLE, null];
        }

        return [$response->status(), $this->location($response, $url)];
    }

    private function location(Response $response, string $url): ?string
    {
        $location = trim($response->header('Location'));

        if ($location === '') {
            return null;
        }

        if (preg_match('#^https?://#i', $location) === 1) {
            return mb_substr($location, 0, 2048);
        }

        $origin = preg_replace('#^(https?://[^/]+).*$#i', '$1', $url);

        return mb_substr(SeoUrl::join((string) $origin, $location), 0, 2048);
    }
}
