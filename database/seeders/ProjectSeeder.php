<?php

namespace Database\Seeders;

use App\Models\Project;
use App\Services\Seo\SeoSettings;
use Database\Seeders\Concerns\SeedsContent;
use Illuminate\Database\Seeder;

/**
 * Six demo case studies with covers and SEO. Safe to re-run: existing
 * records only get fields that are still empty.
 */
class ProjectSeeder extends Seeder
{
    use SeedsContent;

    public function run(): void
    {
        $this->typeTemplate();

        foreach ($this->projects() as $index => $data) {
            $project = Project::query()->firstOrNew(['slug' => $data['slug']]);

            $this->fillBlank($project, [
                'title' => $data['title'],
                'summary' => $data['summary'],
                'body' => trim($data['body'])."\n\n".$this->workingTogether($data),
                'cover' => $this->cover('content/projects/'.$data['slug'].'.jpg', $data['hue']),
                'tags' => $data['tags'],
                'stack' => $data['stack'],
                'client' => $data['client'],
                'year' => $data['year'],
                'live_url' => $data['live_url'],
                'source_url' => $data['source_url'] ?? null,
                'published_at' => now()->subDays(20 + $index * 35)->startOfDay(),
            ]);

            if (! $project->exists) {
                $project->featured = $data['featured'];
                $project->sort = $index;
            }

            if ($project->isDirty()) {
                $project->save();
            }

            $this->seedSeo($project, [
                'meta_title' => $data['seo_title'],
                'meta_description' => $data['seo_description'],
                'keywords' => $data['keywords'],
                'og_image_alt' => 'Cover of the '.$data['client'].' case study',
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function workingTogether(array $data): string
    {
        $stack = implode(', ', array_slice($data['stack'], 0, -1)).' and '.last($data['stack']);

        return <<<MD
## Working together

The {$data['client']} project ran in short weekly cycles. Every Friday we shared a working build, a short written update and a list of open questions, so decisions never had to wait for the next meeting. Stakeholders could try each change on a preview link the same day, which kept feedback concrete and kept surprises out of the launch week.

The stack, {$stack}, was chosen for the team's existing skills and for long-term maintenance rather than novelty. After the {$data['year']} launch we stayed on for a support period to fix issues, write documentation and hand the system over to the in-house team with a runbook that covers deploys, backups, monitoring and the most common support requests.
MD;
    }

    private function typeTemplate(): void
    {
        $settings = app(SeoSettings::class);

        if ($settings->typeTemplate('project') !== []) {
            return;
        }

        $settings->putTypeTemplate('project', [
            'description' => '{{page_title}}: a case study by {{site_name}} covering the problem, the approach, the stack and the measurable results for the client.',
            'og_type' => 'article',
            'schema_type' => 'CreativeWork',
            'sitemap_priority' => 0.7,
            'sitemap_changefreq' => 'monthly',
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function projects(): array
    {
        return [
            [
                'slug' => 'ledgerly-finance-dashboard',
                'title' => 'Ledgerly finance dashboard',
                'client' => 'Ledgerly',
                'year' => 2026,
                'hue' => 210,
                'featured' => true,
                'tags' => ['SaaS', 'Dashboard', 'Fintech'],
                'stack' => ['Next.js', 'Laravel', 'PostgreSQL', 'Redis'],
                'live_url' => 'https://ledgerly.example.com',
                'summary' => 'A cash-flow dashboard that gives small agencies one honest view of invoices, payroll and runway, replacing three spreadsheets and a weekly reconciliation meeting.',
                'seo_title' => 'Ledgerly Finance Dashboard Case Study for Small Agencies',
                'seo_description' => 'How we designed Ledgerly, a cash-flow dashboard that replaced three spreadsheets and cut weekly reconciliation for small agencies from hours to minutes.',
                'keywords' => ['finance dashboard', 'Next.js case study', 'Laravel SaaS', 'cash flow'],
                'body' => <<<'MD'
## The challenge

Ledgerly started as a spreadsheet that the founders shared with a handful of agency owners. Every Monday someone exported invoices from the accounting tool, pasted payroll figures from another sheet and then argued about which number was right. The data existed, but nobody trusted it, and the meeting that was meant to plan the week turned into a reconciliation session.

The brief was simple to say and hard to deliver: one screen that shows cash in, cash out and runway, updated without anyone copying numbers by hand. It had to work for agencies with five people and for those with fifty, and it could not ask owners to change the accounting software they already paid for.

## The approach

### Discovery

We spent the first two weeks with four agency owners, watching them prepare the Monday numbers. Every one of them kept a private column of adjustments. Those adjustments became the most important feature: a place to record expected payments and known costs that the accounting tool does not know about yet.

### Design

The dashboard opens on a single runway figure with the assumptions written underneath in plain language. Every chart can be expanded into the rows behind it, so a number is never a dead end. We tested three layouts on paper before writing any code, and the simplest one won every round.

### Build

The Next.js front end talks to a Laravel API through a small backend-for-frontend layer, which keeps tokens off the browser. Imports run on queued jobs, so a slow accounting provider never blocks the interface, and Redis caches the expensive aggregates for each workspace.

## Results

- Monday reconciliation dropped from about two hours to fifteen minutes.
- Owners now check the runway figure several times a week instead of once a month.
- The adjustments feature is used by every paying workspace.

## What we would do differently

We would ship the adjustments feature first. It was the reason people switched, and building it earlier would have shortened the feedback loop by several weeks. We would also invest sooner in import monitoring, because the first support tickets were all about providers that changed their export format without notice.
MD,
            ],
            [
                'slug' => 'harbor-clinic-booking',
                'title' => 'Harbor Clinic booking system',
                'client' => 'Harbor Clinic',
                'year' => 2025,
                'hue' => 165,
                'featured' => true,
                'tags' => ['Healthcare', 'Booking', 'Payments'],
                'stack' => ['Next.js', 'Laravel', 'MySQL', 'Stripe'],
                'live_url' => 'https://harbor-clinic.example.com',
                'summary' => 'An online booking and reminder system for a physiotherapy clinic that cut missed appointments by a third and freed the front desk from most phone calls.',
                'seo_title' => 'Harbor Clinic Online Booking System and Reminders Case Study',
                'seo_description' => 'A booking and reminder system for a physiotherapy clinic, built with Next.js, Laravel and Stripe, that cut missed appointments by a third in its first quarter.',
                'keywords' => ['clinic booking system', 'appointment reminders', 'Laravel Stripe', 'healthcare web app'],
                'body' => <<<'MD'
## The challenge

Harbor Clinic is a busy physiotherapy practice with six therapists and one front desk. Almost every booking happened by phone, which meant the receptionist spent most of the day on calls while patients waited at the counter. Missed appointments were the bigger problem: roughly one in eight slots went empty because people forgot, and the clinic had no simple way to fill them.

The clinic wanted patients to book, move and cancel appointments themselves, pay a small deposit for first visits, and receive reminders that actually reduce no-shows. Staff needed to keep full control of each therapist's calendar, including breaks and home visits.

## The approach

### Discovery

We sat at the front desk for two mornings and logged every call. Most were simple: book, move or confirm. That list became the scope for the first release, and anything outside it stayed a phone call on purpose.

### Design

The booking flow asks for one decision per screen: the treatment, the therapist, the time and then the contact details. Times are shown as large buttons that work well on a phone, and the deposit step explains why the clinic asks for it. Staff see a calendar that mirrors the paper diary they were used to.

### Build

Laravel handles availability rules, holds a slot for ten minutes while a patient pays, and releases it automatically if the payment fails. Stripe takes the deposits, and queued jobs send reminders by email and text two days and two hours before each visit.

## Results

- Missed appointments fell by about a third in the first quarter.
- Around seventy percent of bookings now happen online, mostly in the evening.
- The front desk handles far fewer calls and more patients in person.

## What we learned

Reminders matter more than any other feature, but only when they are easy to act on. Adding a one-tap link to move an appointment did more for no-shows than sending more messages. Small details, such as showing the therapist's photo, also made patients noticeably more confident about booking online.
MD,
            ],
            [
                'slug' => 'fieldnote-inspection-app',
                'title' => 'Fieldnote offline inspection app',
                'client' => 'Fieldnote',
                'year' => 2025,
                'hue' => 30,
                'featured' => true,
                'tags' => ['PWA', 'Offline-first', 'Field service'],
                'stack' => ['Next.js', 'IndexedDB', 'Laravel', 'S3'],
                'live_url' => 'https://fieldnote.example.com',
                'summary' => 'An offline-first inspection app for building surveyors that keeps working in basements and plant rooms, then syncs photos and reports as soon as a signal returns.',
                'seo_title' => 'Fieldnote Offline-First Inspection App for Surveyors',
                'seo_description' => 'Fieldnote is an offline-first progressive web app that lets building surveyors finish inspections without signal, then syncs photos and reports later.',
                'keywords' => ['offline-first app', 'progressive web app', 'inspection software', 'IndexedDB sync'],
                'body' => <<<'MD'
## The challenge

Fieldnote's surveyors inspect fire doors, plant rooms and car parks, which are exactly the places where mobile signal disappears. Their previous tool was a web form that lost everything when the connection dropped, so most surveyors wrote notes on paper and typed them up in the evening. Reports reached clients days late and photos were often attached to the wrong item.

The goal was an app that works fully offline, captures photos against the right checklist item, and syncs reliably without the surveyor thinking about it. It had to run on the company's existing Android tablets and on personal phones.

## The approach

### Discovery

We joined two surveyors on site visits. The pattern was clear: they work through a building floor by floor, take many photos quickly, and only look at the report at the end of the day. The app needed to follow that rhythm instead of forcing a form-first workflow.

### Design

Each inspection is a list of rooms, each room a short checklist, and each item can hold photos and a note. A small status badge shows what is saved locally and what has reached the server, so nobody has to wonder whether their work is safe.

### Build

The app is a progressive web app built with Next.js. Every change is written to IndexedDB first and added to an outbox. A sync worker sends the outbox in order when a connection appears, uploads photos directly to storage with signed URLs, and retries with backoff. The Laravel API treats every change as idempotent, so a repeated upload never creates duplicates.

## Results

- Reports now reach clients on the same day as the visit.
- Paper notes have disappeared from site visits entirely.
- Misfiled photos dropped to almost zero thanks to item-level capture.

## What we learned

Offline-first is mostly about trust. The status badge, a clear log of what synced and when, and a manual retry button did more for adoption than any performance work. We also learned to test on cheap tablets early, because storage limits and battery savers behave very differently from a developer phone.
MD,
            ],
            [
                'slug' => 'atlas-commerce-storefront',
                'title' => 'Atlas Commerce storefront rebuild',
                'client' => 'Atlas Commerce',
                'year' => 2024,
                'hue' => 330,
                'featured' => true,
                'tags' => ['E-commerce', 'Performance', 'Search'],
                'stack' => ['Next.js', 'Laravel', 'Meilisearch', 'Redis'],
                'live_url' => 'https://atlas-commerce.example.com',
                'summary' => 'A storefront rebuild for an outdoor gear retailer that made product pages load in under a second and turned site search into the highest-converting entry point.',
                'seo_title' => 'Atlas Commerce Storefront Rebuild: Faster Pages and Search',
                'seo_description' => 'How a Next.js storefront rebuild for an outdoor gear retailer got product pages under one second and turned site search into the best converting entry point.',
                'keywords' => ['ecommerce storefront', 'Next.js performance', 'Meilisearch', 'conversion rate'],
                'body' => <<<'MD'
## The challenge

Atlas Commerce sells outdoor gear to people who often shop from a phone at the trailhead. Their old storefront was a heavy theme with dozens of plugins. Product pages took four to six seconds to become usable on mobile, and the built-in search returned nothing for common misspellings such as "tent pegs" written as "tent pags".

The team wanted a faster storefront without migrating their order management, warehouse integration or customer accounts. The rebuild also had to keep every existing URL so years of search rankings would not be lost.

## The approach

### Discovery

Analytics showed that most revenue came from shoppers who searched first. We audited the top two hundred search terms and found that about a fifth returned no results at all. That made search the clear priority after raw speed.

### Design

Product pages lead with the photo, the price and stock for the nearest store, followed by the details people asked about most in support emails. Filters stay visible on desktop and collapse into a single sheet on mobile.

### Build

Product and category pages are statically generated with Next.js and revalidated when stock or prices change, so the server rarely renders anything on demand. Meilisearch powers typo-tolerant search with synonyms maintained by the merchandising team. A Laravel service syncs the catalog from the existing back office and keeps all legacy URLs as permanent redirects.

## Results

- Product pages became usable in under a second on a mid-range phone.
- Searches with no results dropped from about twenty percent to under three.
- Search became the highest-converting entry point on the site.

## What we learned

Speed work paid off most where it touched the first visit. Image sizing and removing third-party scripts did more than any clever caching. On search, giving the merchandising team a simple synonyms screen mattered more than tuning ranking rules, because they understand how customers describe products.
MD,
            ],
            [
                'slug' => 'pulse-ops-monitoring',
                'title' => 'Pulse real-time operations monitor',
                'client' => 'Pulse Logistics',
                'year' => 2024,
                'hue' => 270,
                'featured' => false,
                'tags' => ['Realtime', 'Logistics', 'Dashboard'],
                'stack' => ['Laravel Reverb', 'Redis', 'Next.js', 'PostgreSQL'],
                'live_url' => 'https://pulse.example.com',
                'summary' => 'A real-time operations screen for a regional logistics company that shows late deliveries as they happen, so dispatchers can act before customers call.',
                'seo_title' => 'Pulse Real-Time Operations Monitoring for Logistics Teams',
                'seo_description' => 'Pulse is a real-time operations screen for a logistics company, built on Laravel Reverb and Redis, that flags late deliveries before customers notice and call.',
                'keywords' => ['real-time dashboard', 'Laravel Reverb', 'logistics software', 'operations monitoring'],
                'body' => <<<'MD'
## The challenge

Pulse Logistics runs around three hundred deliveries a day across the region. Dispatchers learned about late drivers when an annoyed customer phoned, by which point the only option was an apology. The data to spot delays early already existed in the driver app, but it sat in a database that someone queried by hand at the end of the day.

The company wanted a live screen for the dispatch room that shows which deliveries are at risk, why, and what the dispatcher can do about it, without flooding them with alerts.

## The approach

### Discovery

We spent a shift in the dispatch room. Dispatchers did not want more data; they wanted fewer surprises. Anything that needed action within thirty minutes mattered, and everything else was noise.

### Design

The screen shows three columns: on time, at risk and late. A delivery moves between columns as its estimate changes, and each card explains the reason in a few words, such as traffic, a long previous stop or a vehicle issue. Sound alerts are reserved for deliveries that become late with no plan attached.

### Build

Driver locations and stop events stream into Laravel, which recalculates estimates and broadcasts changes over Laravel Reverb. Redis holds the live state for every active route, so the screen updates within a second or two. A nightly job stores the history in PostgreSQL for reporting.

## Results

- Dispatchers now contact customers before a delivery is late in most cases.
- Complaint calls about late deliveries fell noticeably within two months.
- Managers use the history to plan routes around known slow periods.

## What we learned

Real-time interfaces need restraint. The first version updated too often and felt restless, so we added gentle transitions and grouped small changes. Explaining the reason for each risk turned the screen from a scoreboard into a tool that dispatchers trust and act on.
MD,
            ],
            [
                'slug' => 'brightpath-learning-portal',
                'title' => 'Brightpath learning portal',
                'client' => 'Brightpath Academy',
                'year' => 2023,
                'hue' => 95,
                'featured' => false,
                'tags' => ['Education', 'Portal', 'Search'],
                'stack' => ['Next.js', 'Laravel', 'Typesense', 'MySQL'],
                'live_url' => 'https://brightpath.example.com',
                'source_url' => 'https://github.com/example/brightpath-portal',
                'summary' => 'A learning portal for an adult education provider that brought courses, progress and certificates into one place and made the course catalog easy to search.',
                'seo_title' => 'Brightpath Learning Portal for Adult Education Courses',
                'seo_description' => 'Brightpath is a learning portal that brings courses, progress tracking and certificates into one place for adult learners, with a typo-tolerant catalog.',
                'keywords' => ['learning portal', 'education platform', 'Typesense search', 'Laravel Next.js'],
                'body' => <<<'MD'
## The challenge

Brightpath Academy offers evening and weekend courses for adults returning to study. Learners used one site to find courses, another to watch lessons and an email inbox to receive certificates. Many dropped out in the first weeks simply because they could not find their next lesson, and staff spent hours answering the same navigation questions.

The academy wanted a single portal where learners can discover courses, follow their progress and download certificates, with an admin area simple enough for course coordinators.

## The approach

### Discovery

Interviews with learners showed that most studied on a phone during short breaks. They needed to pick up exactly where they stopped, with as few taps as possible. Coordinators, on the other hand, worked on desktops and wanted bulk actions.

### Design

The learner home page shows one large "continue" card with the next lesson, followed by a short list of upcoming deadlines. The catalog uses plain-language filters such as evenings, weekends and online only. Coordinators get a table-first admin area with bulk enrolment and messaging.

### Build

Laravel powers enrolment, progress tracking and certificate generation, while Next.js serves the learner portal. Typesense provides instant, typo-tolerant catalog search. Certificates are generated as PDFs on a queue and stored privately, with signed links that expire.

## Results

- Learners who reached the second week of a course increased significantly.
- Navigation questions to staff dropped by more than half.
- Coordinators enrol whole cohorts in minutes instead of an afternoon.

## What we learned

The continue card was the most valuable thing we built, and also the simplest. Designing for short mobile sessions forced us to remove steps everywhere else too. We also learned to involve coordinators early, because their workflows shaped the data model more than the learner features did.
MD,
            ],
        ];
    }
}
