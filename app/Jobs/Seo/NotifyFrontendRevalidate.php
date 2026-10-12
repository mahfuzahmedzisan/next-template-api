<?php

namespace App\Jobs\Seo;

use App\Support\Seo\SeoQueue;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Tells the Next.js app to drop its cached SEO data. A no-op until
 * FRONTEND_REVALIDATE_URL is set; a 404 means the route isn't deployed yet.
 */
class NotifyFrontendRevalidate implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public int $timeout = 30;

    /**
     * @param  list<string>  $tags
     */
    public function __construct(public array $tags = ['seo'])
    {
        $this->onConnection(SeoQueue::connection());
        $this->onQueue(SeoQueue::name());
        $this->afterCommit();
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [5, 30];
    }

    public function handle(): void
    {
        $url = trim((string) config('services.frontend.revalidate_url'));
        $secret = (string) config('services.frontend.bff_secret');

        if ($url === '' || $secret === '') {
            return;
        }

        try {
            $response = Http::timeout(3)
                ->acceptJson()
                ->withHeaders(['X-BFF-Secret' => $secret])
                ->post($url, ['tags' => array_values(array_unique($this->tags))]);
        } catch (ConnectionException $exception) {
            throw new RuntimeException('Frontend revalidate request failed: '.$exception->getMessage(), previous: $exception);
        }

        if ($response->status() === 404) {
            Log::debug('Frontend revalidate route is not deployed yet.', ['url' => $url]);

            return;
        }

        if ($response->serverError()) {
            throw new RuntimeException('Frontend revalidate returned '.$response->status().'.');
        }

        if (! $response->successful()) {
            Log::warning('Frontend revalidate was rejected.', ['url' => $url, 'status' => $response->status()]);
        }
    }
}
