<?php

namespace Database\Seeders;

use App\Models\Post;
use App\Services\Seo\SeoSettings;
use Database\Seeders\Concerns\SeedsContent;
use Illuminate\Database\Seeder;

/**
 * Six demo blog posts with covers and SEO. Safe to re-run: existing
 * records only get fields that are still empty.
 */
class PostSeeder extends Seeder
{
    use SeedsContent;

    private const AUTHOR = 'Mahfuz Ahmed';

    public function run(): void
    {
        $this->typeTemplate();

        foreach ($this->posts() as $index => $data) {
            $post = Post::query()->firstOrNew(['slug' => $data['slug']]);
            $body = trim($data['body'])."\n\n".$this->closing($data);

            $this->fillBlank($post, [
                'title' => $data['title'],
                'excerpt' => $data['excerpt'],
                'body' => $body,
                'cover' => $this->cover('content/posts/'.$data['slug'].'.jpg', $data['hue']),
                'author' => self::AUTHOR,
                'category' => $data['category'],
                'tags' => $data['tags'],
                'reading_minutes' => max(1, (int) ceil(str_word_count(strip_tags($body)) / 220)),
                'published_at' => now()->subDays(7 + $index * 18)->setTime(9, 0),
            ]);

            if ($post->isDirty()) {
                $post->save();
            }

            $this->seedSeo($post, [
                'meta_title' => $data['seo_title'],
                'meta_description' => $data['seo_description'],
                'keywords' => $data['keywords'],
                'og_image_alt' => 'Cover image for "'.$data['title'].'"',
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function closing(array $data): string
    {
        $topic = strtolower($data['category']);

        return <<<MD
## Putting it into practice

If you want to try these ideas, start small. Pick one screen, one job or one page in a project you already know well, apply a single change from this post and measure the result for a couple of weeks. Small, measured changes are easier to explain to a team and much easier to undo if they do not work.

I write these notes from real client work, so every recommendation here has been tested on a production system. If you have a question about {$topic}, a different experience to share, or a project where these ideas could help, the contact page is the quickest way to reach me, and I read every message.
MD;
    }

    private function typeTemplate(): void
    {
        $settings = app(SeoSettings::class);

        if ($settings->typeTemplate('post') !== []) {
            return;
        }

        $settings->putTypeTemplate('post', [
            'description' => '{{page_title}}: practical notes from {{site_name}} on building, shipping and maintaining fast, accessible and search-friendly web apps.',
            'og_type' => 'article',
            'schema_type' => 'Article',
            'sitemap_priority' => 0.6,
            'sitemap_changefreq' => 'monthly',
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function posts(): array
    {
        return [
            [
                'slug' => 'nextjs-laravel-bff',
                'title' => 'Building a backend-for-frontend with Next.js and Laravel',
                'category' => 'Engineering',
                'hue' => 200,
                'tags' => ['Next.js', 'Laravel', 'Architecture', 'Security'],
                'excerpt' => 'Why I put a thin backend-for-frontend between the browser and a Laravel API, what it handles, and the mistakes that taught me where its boundaries should be.',
                'seo_title' => 'Building a Backend-for-Frontend with Next.js and Laravel',
                'seo_description' => 'Why I put a thin backend-for-frontend between the browser and a Laravel API, what it should handle, and the mistakes that showed me where its limits should be.',
                'keywords' => ['backend for frontend', 'Next.js Laravel', 'API security', 'BFF pattern'],
                'body' => <<<'MD'
Most of my recent projects pair a Next.js front end with a Laravel API. For a long time the browser talked to the API directly, with a token stored somewhere in JavaScript. It worked, but every project ended with the same uncomfortable questions about token storage, CORS rules and leaking internal URLs. A backend-for-frontend, or BFF, answers most of those questions by putting a small server layer that belongs to the front end between the browser and the API.

## What the BFF is responsible for

The BFF is not a second backend. It owns a short list of jobs, and I keep that list written down in every project so it does not grow quietly.

### Sessions and tokens

The browser holds an encrypted, HTTP-only session cookie. The API token lives inside that session and never reaches client-side JavaScript. When a Server Component or Route Handler needs data, it reads the session on the server and attaches the token to the outgoing request. A stolen script cannot read a token that the browser never sees.

### A trusted channel to the API

Every request from the BFF carries a shared secret and the origin the visitor actually used. The API rejects anything without both. This turns the API into a private service that only our front end can call, while still letting the API know which public domain a request came from.

### Shaping responses

The BFF can combine two API calls into one response, rewrite storage URLs so media is served from the same origin, and strip fields the page does not need. It should not contain business rules. If I catch myself writing a permission check in the BFF, that check belongs in Laravel.

## What stays in Laravel

Validation, authorization, queues, notifications, search indexing and anything that touches the database stay in the API. Laravel is very good at these jobs, and keeping them in one place means a mobile app or an integration can reuse them later without copying logic from the front end.

## Mistakes that shaped the boundaries

- I once cached a user-specific response in a shared cache. The fix was simple, but it taught me to mark every BFF fetch as either public or private from the start.
- I let the BFF format dates and currencies for a while. It felt convenient until the API needed the same formatting for emails. Formatting now lives in the API or in shared front-end helpers, never in both.
- I forgot that public pages calling the session become dynamic. Moving the account button into a small client component let the rest of the page be cached again.

## Performance and caching

Public pages fetch SEO data and content through a separate helper that never touches cookies or headers. Those responses are cached under tags, and the API calls a protected revalidation route whenever content changes. Visitors get static pages, editors see their changes within seconds, and the API is not called on every page view.

## When a BFF is not worth it

For a small internal tool with a handful of users, a direct API with a well-configured session cookie can be enough. The BFF earns its keep when there is a public site that must be fast, a private area that must be secure, and more than one client that will eventually use the API.

## Summary

A BFF is a thin, boring layer, and that is the point. It keeps tokens away from the browser, gives the API a trusted caller, and lets public pages stay static. Write down its responsibilities, keep business rules in the API, and review the list whenever a new feature tempts you to add one more thing to the middle layer.
MD,
            ],
            [
                'slug' => 'nextjs-seo-checklist',
                'title' => 'A practical SEO checklist for Next.js apps',
                'category' => 'SEO',
                'hue' => 140,
                'tags' => ['SEO', 'Next.js', 'Metadata', 'Performance'],
                'excerpt' => 'The checklist I run before launching a Next.js site: metadata, social images, favicons, sitemaps, robots rules, structured data and the details third-party checkers flag.',
                'seo_title' => 'A Practical SEO Checklist for Launching Next.js Apps',
                'seo_description' => 'The SEO checklist I run before launching any Next.js site: metadata, social images, favicons, sitemaps, robots rules, structured data and what checkers flag.',
                'keywords' => ['Next.js SEO', 'SEO checklist', 'structured data', 'sitemap robots.txt'],
                'body' => <<<'MD'
Search engines and social networks are strict readers. They do not care how elegant your components are; they read the HTML your server returns, follow your redirects and fetch your images. This is the checklist I run before every launch. None of it is glamorous, and all of it is easy to forget.

## Metadata on every page

Each indexable page needs a unique title between fifty and sixty characters and a description between about one hundred forty and one hundred sixty characters. Shorter is not wrong, but most checkers flag it, and a title that matches the main heading performs better in practice.

### Canonical URLs

Every page should declare its canonical URL, and that URL should match the Open Graph URL. Pagination pages after the first are their own canonical, while filtered or sorted views point back to the base listing.

### Metadata in the head

Recent Next.js versions can stream metadata into the body for faster first paint. Real browsers handle that, but many crawlers and checkers only read the head. Make sure known bots and your own scanner receive metadata in the head.

## Social images and favicons

- Social images should be at least 1200 by 628 pixels, with a ratio of 1.91 to 1, and declared with width, height, type and alt tags.
- The favicon at the root should be a real ICO file that answers with a 200 status, not a redirect to a PNG.
- Provide an Apple touch icon of 180 pixels and a web manifest with 192 and 512 pixel icons.

## Sitemap and robots rules

The sitemap should list every indexable page and nothing else. Each URL should return 200, be its own canonical and not be blocked by robots rules. Keep the sitemap within fifty thousand URLs per file, use absolute URLs on a single host and include a last modified date when you know it.

The robots file should answer with plain text, include a group for all user agents, point to the sitemap with an absolute URL, and never block the assets that pages need to render, such as scripts, styles, images or the social image route.

## Redirects and status codes

Pick one host and redirect the other, including the www version and plain HTTP. Redirect common index paths such as index.html and index.php to the home page, and make sure random URLs return a real 404 instead of a friendly page with a 200 status.

## Content and structure

Each page should have exactly one main heading, at least a couple of section headings and some subheadings, without skipping levels. Thin pages rarely rank, so give important pages enough words to answer the visitor's question properly, and link generously to related pages on your own site.

## Structured data

Add WebSite and Person or Organization data site-wide, breadcrumbs on inner pages, Article data on posts with a headline, image, date and author, and the matching page type for collections and contact pages. Validate the output rather than trusting the code that generates it.

## Performance

Fast pages are easier to crawl and nicer to use. Prefer static or incrementally regenerated pages for public content, keep session checks out of shared layouts, size images properly and remove third-party scripts that do not earn their weight.

## Closing thoughts

Run a scanner that checks these rules the same way external tools do, and keep a regression test for every issue you fix. SEO problems are rarely hard to solve, but they come back quietly when nobody is watching.
MD,
            ],
            [
                'slug' => 'offline-first-forms',
                'title' => 'Designing offline-first forms that never lose data',
                'category' => 'Engineering',
                'hue' => 25,
                'tags' => ['Offline-first', 'PWA', 'IndexedDB', 'UX'],
                'excerpt' => 'Lessons from building an inspection app for places without signal: save locally first, sync in order, show honest status and make every server write idempotent.',
                'seo_title' => 'Designing Offline-First Web Forms That Never Lose Data',
                'seo_description' => 'Lessons from building an inspection app for places with no signal: save locally first, sync in order, show honest status and keep every server write idempotent.',
                'keywords' => ['offline-first forms', 'IndexedDB', 'background sync', 'progressive web app'],
                'body' => <<<'MD'
Losing a half-finished form is one of the most frustrating things software can do to a person. It happens most often to people working in the field, in basements, on trains or in rural areas, where the connection comes and goes. While building an inspection app for surveyors, I learned that offline-first forms are less about clever technology and more about a few firm rules.

## Rule one: save locally before anything else

Every change is written to local storage the moment it happens. In the browser that usually means IndexedDB, which can hold structured data and binary files such as photos. The network is treated as an optional extra, not as the place where data lives.

### Use an outbox

Alongside the saved data, each change is added to an outbox: a simple ordered list of operations waiting to reach the server. The outbox makes sync predictable, because it records intent rather than trying to compare two copies of the same record later.

### Keep the format boring

Store plain objects with explicit version numbers. When the form changes in a future release, a small migration step can upgrade old records instead of throwing them away.

## Rule two: sync in order, and retry patiently

A sync worker sends outbox items one by one, in the order they were created. When a request fails because the connection dropped, the worker waits and tries again with growing delays. When a request fails because the server rejected the data, the item is marked for attention instead of being retried forever.

- Photos upload directly to storage through short-lived signed URLs, so large files never pass through the application server.
- The worker pauses when the device is low on battery and resumes when charging or back on a stable connection.
- A manual sync button exists for the moments when people simply want reassurance.

## Rule three: show honest status

People trust what they can see. Each record shows whether it is saved on the device, waiting to sync or safely on the server. A small log lists recent sync activity with times. When something needs attention, the message says what happened and what to do, in plain language.

## Rule four: make every server write idempotent

Retries mean the server will sometimes receive the same operation twice. Each operation carries a unique identifier created on the device. The API stores those identifiers and ignores repeats, so a flaky connection never creates duplicate inspections or duplicate photos.

## Conflicts are rarer than you think

I expected conflicts to be the hardest part. In practice, one person usually owns a record while they work on it. A simple rule, where the latest change to each field wins and every change is kept in a history, covered almost every real case.

## Testing on real devices

Emulators lie about storage limits and battery savers. Test on the cheapest device your users actually carry, fill its storage, switch it to airplane mode in the middle of an upload and see what happens. Every bug I found this way would have reached production otherwise.

## Wrapping up

Offline-first forms come down to saving locally, syncing in order, being honest about status and making the server tolerant of repeats. Follow those rules and people stop worrying about the connection and get on with their work.
MD,
            ],
            [
                'slug' => 'laravel-queues-production',
                'title' => 'Running Laravel queue workers in production',
                'category' => 'Engineering',
                'hue' => 280,
                'tags' => ['Laravel', 'Queues', 'Redis', 'DevOps'],
                'excerpt' => 'What I learned from running Laravel queues for real traffic: separate connections, sensible timeouts, idempotent jobs, honest monitoring and calm deploys.',
                'seo_title' => 'Running Laravel Queue Workers in Production: Lessons',
                'seo_description' => 'What I learned running Laravel queues under real traffic: separate connections, sensible timeouts, idempotent jobs, honest monitoring and calm, boring deploys.',
                'keywords' => ['Laravel queues', 'queue workers', 'Redis queue', 'production Laravel'],
                'body' => <<<'MD'
Queues are where Laravel applications do their quiet, important work: sending emails, importing files, generating reports and keeping search indexes fresh. They are also where problems hide, because a failed job does not show an error page to anyone. These are the lessons I keep relearning, written down so I stop relearning them.

## Separate connections for separate jobs

Not all jobs are equal. A password reset email must go out within seconds, while a nightly report can wait. I give long-running work its own queue connection and its own workers, so a slow import never delays a login email.

### Match retry settings to timeouts

The connection's retry setting must be longer than the longest job timeout. If it is shorter, the queue assumes a slow job has died and hands it to another worker, and the job runs twice. This single setting has caused more duplicate emails than any bug I have written.

### Name queues by purpose

Queues named after what they do, such as mail, search or reports, make dashboards readable and scaling decisions obvious.

## Make jobs idempotent

Any job can run more than once: after a timeout, a deploy or a worker crash. Design every job so that running it twice has the same effect as running it once.

- Check whether the work is already done before doing it again.
- Use unique identifiers when calling external services that support them.
- Prefer updating a record to a known state over adding to a running total.

## Keep payloads small

Pass identifiers, not whole models or large arrays. The job loads fresh data when it runs, which avoids acting on stale information and keeps the queue storage lean.

## Monitor what matters

A queue can look healthy while silently failing. I watch three numbers: how many jobs are waiting, how long the oldest one has waited and how many failed in the last hour. Alerts fire on the age of the oldest job, because a growing wait is the earliest sign that workers are stuck or overloaded.

### Failed jobs deserve a process

Failed jobs are stored with their error. Someone reviews them, fixes the cause and retries them deliberately. Retrying everything blindly hides bugs and can repeat side effects such as charging a card twice.

## Deploy calmly

Workers keep old code in memory, so every deploy must restart them gracefully. Laravel's restart command lets current jobs finish before workers exit. Database migrations that remove columns should happen in two steps, so jobs queued before the deploy still find the data they expect.

## Scale with intent

Add workers when the oldest job keeps waiting too long, not because the server has spare memory. More workers can make things worse if they compete for the same database rows or hit rate limits on external services.

## Final thoughts

Good queue setups are boring: separate connections, timeouts that agree with each other, idempotent jobs, honest monitoring and graceful deploys. Get those right and the queue becomes the most reliable part of the application instead of the most mysterious.
MD,
            ],
            [
                'slug' => 'scoping-projects',
                'title' => 'How I scope a project before writing any code',
                'category' => 'Process',
                'hue' => 50,
                'tags' => ['Process', 'Planning', 'Clients', 'Estimates'],
                'excerpt' => 'The scoping process I use with clients: observe the real workflow, write the smallest useful release, list what is out of scope and agree on how success is measured.',
                'seo_title' => 'How I Scope a Client Web Project Before Writing Any Code',
                'seo_description' => 'The scoping process I use with clients: watch the real workflow, define the smallest useful release, list what is out of scope and agree how to measure success.',
                'keywords' => ['project scoping', 'software estimates', 'client discovery', 'MVP planning'],
                'body' => <<<'MD'
The most expensive mistakes in a software project happen before anyone writes code. A vague goal turns into a long feature list, the list turns into an estimate, and the estimate turns into a promise that nobody can keep. Over the years I have settled on a scoping process that keeps projects small, honest and useful.

## Start by watching, not by asking

Clients describe the process they think they have. Watching people work shows the process they actually have. I ask to sit with the people who will use the software for a few hours, and I write down every step, workaround and sticky note.

### Look for private spreadsheets

Almost every team has a private spreadsheet or notebook that fills a gap in their official tools. That gap is usually the most valuable thing to build, and it is rarely on the original feature list.

### Count the interruptions

Phone calls, repeated questions and copy-paste between systems are measurable. Counting them gives a baseline that later proves whether the project worked.

## Define the smallest useful release

The first release should solve one painful problem completely rather than many problems partially. I describe it in a single paragraph that a non-technical person can read and agree with. If the paragraph needs the word "and" more than twice, the release is too big.

- Who uses it, and in what situation.
- The one job it does for them.
- How they did that job before.

## Write down what is out of scope

An explicit out-of-scope list prevents more arguments than any contract clause. It also shows the client that their other ideas were heard and parked deliberately, not forgotten. Many of those ideas never come back once the first release is in use, because the real needs turn out to be different.

## Agree on how success is measured

Before estimating, we agree on one or two numbers that should change: fewer missed appointments, faster reports or fewer support calls. Those numbers guide every trade-off during the build and make the final review a conversation about outcomes instead of features.

## Estimate in ranges, with assumptions

I estimate the first release as a range and list the assumptions behind it, such as access to an existing system or a decision from a specific person by a certain date. When an assumption breaks, we both know why the estimate moves.

## Plan the first weeks in detail and the rest loosely

The first two weeks are planned task by task. Everything after that is a list of themes in priority order. Detailed long-term plans feel reassuring, but they are usually wrong by the third week, and keeping them up to date costs time that is better spent building.

## Keep a decision log

Every significant decision gets one line in a shared document: what we decided, why and who agreed. Months later, when someone asks why something works the way it does, the answer is already written down.

## In short

Watch real work, build the smallest useful thing, write down what you are not doing, agree on a measure of success and estimate honestly. It is not complicated, but it consistently turns uncertain ideas into projects that ship and get used.
MD,
            ],
            [
                'slug' => 'accessible-dashboards',
                'title' => 'Accessible dashboards: color, focus and motion',
                'category' => 'Design',
                'hue' => 310,
                'tags' => ['Accessibility', 'Design', 'Dashboards', 'Motion'],
                'excerpt' => 'Practical accessibility for data-heavy screens: color that does not carry meaning alone, visible focus, keyboard-friendly tables and motion that respects user settings.',
                'seo_title' => 'Accessible Dashboards: Color, Focus and Motion Done Well',
                'seo_description' => 'Accessibility for data-heavy screens: color that never carries meaning alone, visible focus, keyboard-friendly tables and motion that respects settings.',
                'keywords' => ['accessible dashboards', 'web accessibility', 'focus states', 'reduced motion'],
                'body' => <<<'MD'
Dashboards are dense by nature. They pack numbers, charts and controls into a single screen, and that density makes accessibility both harder and more important. People rely on dashboards to make decisions quickly, so anything that slows them down or hides information has a real cost. These are the practices I apply to every data-heavy interface I build.

## Color should never carry meaning alone

Red and green status colors are the classic problem. Around one in twelve men has some form of color vision deficiency, and many of them cannot tell those two apart. Every status needs a second signal: an icon, a label or a pattern.

### Check contrast in both themes

Text and important graphics need enough contrast against their background in light and dark themes. Muted grey text looks elegant in a design file and becomes unreadable on a laptop in sunlight. I check contrast for every text style and every chart color pair.

### Use a limited, tested palette

A small palette of chart colors that remain distinguishable for common color vision deficiencies is better than a large palette that looks good only to some people.

## Focus must always be visible

Keyboard users need to see where they are. Removing the focus outline because it looks untidy is one of the most common accessibility bugs. A clear, high-contrast focus ring that appears only for keyboard navigation keeps both designers and users happy.

- Every interactive element should be reachable with the Tab key in a logical order.
- Dialogs should move focus inside when opened and return it when closed.
- Skip links let people jump past navigation straight to the main content.

## Tables that work with a keyboard and a screen reader

Many dashboards are tables at heart. Use real table markup with header cells, so screen readers can announce which column a value belongs to. Sortable columns should be buttons that announce the current sort direction. Row actions should be reachable without a mouse, and long tables benefit from a sticky header.

## Charts need text alternatives

A chart is a picture of data, and pictures need descriptions. Each chart gets a short summary of the key takeaway, such as revenue grew twelve percent this quarter, and a way to view the underlying numbers as a table. That table helps everyone who wants exact values, not only screen reader users.

## Motion should respect user settings

Animated transitions help people follow changes, but motion can cause discomfort or distraction. Operating systems let users ask for reduced motion, and the interface should honor that setting by replacing movement with simple fades or instant changes.

### Real-time updates need restraint

Numbers that update constantly can be overwhelming. Group small changes, avoid flashing, and announce only important updates to assistive technology through polite live regions.

## Test with real tools and real people

Automated checkers catch missing labels and low contrast, but they cannot tell whether a workflow makes sense. I navigate every new screen with only a keyboard, try it with a screen reader, zoom to two hundred percent and switch on reduced motion. Whenever possible, I watch someone who relies on assistive technology use it.

## Conclusion

Accessible dashboards are clearer for everyone. Use more than color for meaning, keep focus visible, build real tables, describe charts and respect motion preferences. These habits cost little when applied from the start and save a great deal compared with fixing them after launch.
MD,
            ],
        ];
    }
}
