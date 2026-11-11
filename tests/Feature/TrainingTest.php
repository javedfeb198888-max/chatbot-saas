<?php

namespace Tests\Feature;

use App\Actions\CreateClientWithOwner;
use App\Actions\Training\AddSource;
use App\Actions\Training\SaveQaPair;
use App\Jobs\TrainSource;
use App\Models\Bot;
use App\Models\Chunk;
use App\Models\Client;
use App\Models\QaPair;
use App\Models\Source;
use App\Support\CurrentClient;
use App\Training\HostResolver;
use App\Training\HtmlTextExtractor;
use App\Training\TextChunker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Embeddings;
use Tests\TestCase;

class TrainingTest extends TestCase
{
    use RefreshDatabase;

    private Client $acme;

    private Client $globex;

    private Bot $bot;

    /** Host name => IP list, used instead of real DNS. */
    private array $dns = [
        'acme.test' => ['93.184.216.34'],
        'other.test' => ['93.184.216.35'],
        'intranet.test' => ['10.0.0.5'],
        'localhost' => ['127.0.0.1'],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        Embeddings::fake();
        Http::preventStrayRequests();

        $dns = &$this->dns;
        $this->app->instance(HostResolver::class, new class($dns) extends HostResolver
        {
            public function __construct(private array &$dns)
            {
            }

            public function resolve(string $host): array
            {
                return filter_var($host, FILTER_VALIDATE_IP) ? [$host] : ($this->dns[$host] ?? []);
            }
        });

        $create = app(CreateClientWithOwner::class);
        $this->acme = $create->handle(['name' => 'Acme Dental', 'slug' => 'acme'], 'Ali', 'ali@acme.test');
        $this->globex = $create->handle(['name' => 'Globex Law', 'slug' => 'globex'], 'Sara', 'sara@globex.test');

        app(CurrentClient::class)->set($this->acme);
        $this->bot = Bot::firstOrFail();
    }

    private function page(string $body, string $title = 'Opening hours'): string
    {
        return "<html><head><title>{$title}</title><style>p{color:red}</style></head><body>"
            .'<nav><a href="/">Home menu link</a></nav>'
            ."<main><h1>{$title}</h1>{$body}</main>"
            .'<footer>Copyright footer text</footer><script>var secret = 1;</script></body></html>';
    }

    private function addUrl(string $url): Source
    {
        return app(AddSource::class)->handle($this->bot, ['type' => 'url', 'url' => $url]);
    }

    public function test_a_web_page_becomes_searchable_chunks(): void
    {
        Http::fake(['acme.test/*' => Http::response(
            $this->page('<p>We are open Monday to Friday from nine to five.</p><ul><li>Cleaning</li><li>Whitening</li></ul>'),
            200, ['Content-Type' => 'text/html; charset=UTF-8'],
        )]);

        $source = $this->addUrl('https://acme.test/hours')->fresh();

        $this->assertSame('ready', $source->status);
        $this->assertSame('Opening hours', $source->title);
        $this->assertNotNull($source->last_synced_at);

        $chunk = Chunk::where('source_id', $source->id)->sole();

        $this->assertSame($this->acme->id, $chunk->client_id);
        $this->assertCount(1536, $chunk->embedding);
        $this->assertStringContainsString('open Monday to Friday', $chunk->content);
        $this->assertStringContainsString('Whitening', $chunk->content);
        $this->assertSame('https://acme.test/hours', $chunk->metadata['url']);
    }

    public function test_menus_footers_scripts_and_styles_are_left_out(): void
    {
        Http::fake(['*' => Http::response($this->page('<p>Real page content for the visitor to read.</p>'), 200, ['Content-Type' => 'text/html'])]);

        $content = Chunk::where('source_id', $this->addUrl('https://acme.test/a')->id)->sole()->content;

        foreach (['Home menu link', 'Copyright footer', 'secret', 'color:red'] as $noise) {
            $this->assertStringNotContainsString($noise, $content);
        }
    }

    public function test_a_page_in_urdu_script_is_accepted(): void
    {
        Http::fake(['*' => Http::response($this->page('<p>ہم پیر سے جمعہ تک صبح نو بجے سے شام پانچ بجے تک کھلے ہیں۔</p>', 'اوقات'), 200, ['Content-Type' => 'text/html; charset=UTF-8'])]);

        $source = $this->addUrl('https://acme.test/ur')->fresh();

        $this->assertSame('ready', $source->status);
        $this->assertStringContainsString('پیر سے جمعہ', Chunk::where('source_id', $source->id)->sole()->content);
    }

    public function test_an_unchanged_page_is_not_embedded_again(): void
    {
        Http::fake(['*' => Http::response($this->page('<p>The same text on both visits to this page.</p>'), 200, ['Content-Type' => 'text/html'])]);

        $source = $this->addUrl('https://acme.test/same');
        $firstIds = Chunk::where('source_id', $source->id)->pluck('id')->all();

        TrainSource::dispatch($source->id, $source->client_id);

        $this->assertSame($firstIds, Chunk::where('source_id', $source->id)->pluck('id')->all());
    }

    public function test_a_changed_page_replaces_its_chunks(): void
    {
        Http::fakeSequence('acme.test/*')
            ->push($this->page('<p>Old price list for all of our dental services.</p>'), 200, ['Content-Type' => 'text/html'])
            ->push($this->page('<p>New price list for all of our dental services.</p>'), 200, ['Content-Type' => 'text/html']);

        $source = $this->addUrl('https://acme.test/prices');
        TrainSource::dispatch($source->id, $source->client_id);

        $chunk = Chunk::where('source_id', $source->id)->sole();

        $this->assertStringContainsString('New price list', $chunk->content);
    }

    public function test_a_server_error_marks_the_source_as_failed(): void
    {
        Http::fake(['*' => Http::response('down', 500)]);

        $source = $this->addUrl('https://acme.test/broken')->fresh();

        $this->assertSame('failed', $source->status);
        $this->assertStringContainsString('500', $source->error);
        $this->assertSame(0, Chunk::count());
    }

    public function test_a_page_without_readable_text_fails_with_a_clear_message(): void
    {
        Http::fake(['*' => Http::response('<html><body><div id="app"></div><script>render()</script></body></html>', 200, ['Content-Type' => 'text/html'])]);

        $source = $this->addUrl('https://acme.test/spa')->fresh();

        $this->assertSame('failed', $source->status);
        $this->assertStringContainsString('JavaScript', $source->error);
    }

    public function test_files_that_are_not_web_pages_are_rejected(): void
    {
        Http::fake(['*' => Http::response('%PDF-1.7', 200, ['Content-Type' => 'application/pdf'])]);

        $this->assertSame('failed', $this->addUrl('https://acme.test/file.pdf')->fresh()->status);
    }

    public function test_private_and_local_addresses_are_never_requested(): void
    {
        Http::fake();

        foreach ([
            'http://intranet.test/admin',
            'http://localhost/secret',
            'http://127.0.0.1/secret',
            'http://169.254.169.254/latest/meta-data',
            'http://[::1]/secret',
            'ftp://acme.test/file',
            'https://user:pass@acme.test/',
            'https://acme.test:8080/',
            'https://unknown-host.test/',
        ] as $url) {
            $source = $this->addUrl($url)->fresh();

            $this->assertSame('failed', $source->status, $url);
        }

        Http::assertNothingSent();
    }

    public function test_a_redirect_to_a_private_address_is_blocked(): void
    {
        Http::fake([
            'acme.test/*' => Http::response('', 302, ['Location' => 'http://intranet.test/admin']),
            'intranet.test/*' => Http::response($this->page('<p>Internal admin data that must stay private.</p>'), 200, ['Content-Type' => 'text/html']),
        ]);

        $source = $this->addUrl('https://acme.test/go')->fresh();

        $this->assertSame('failed', $source->status);
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'intranet.test'));
        $this->assertSame(0, Chunk::count());
    }

    public function test_a_normal_redirect_is_followed(): void
    {
        Http::fake([
            'acme.test/old' => Http::response('', 301, ['Location' => '/new']),
            'acme.test/new' => Http::response($this->page('<p>This page has moved to a new address.</p>'), 200, ['Content-Type' => 'text/html']),
        ]);

        $this->assertSame('ready', $this->addUrl('https://acme.test/old')->fresh()->status);
    }

    public function test_a_sitemap_adds_pages_from_the_same_site_only(): void
    {
        Http::fake([
            'acme.test/sitemap.xml' => Http::response(
                '<?xml version="1.0"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
                .'<url><loc>https://acme.test/a</loc></url><url><loc>https://acme.test/b</loc></url>'
                .'<url><loc>https://acme.test/a</loc></url><url><loc>https://other.test/x</loc></url></urlset>',
                200, ['Content-Type' => 'application/xml'],
            ),
            'acme.test/*' => Http::response($this->page('<p>Some useful text about our dental clinic.</p>'), 200, ['Content-Type' => 'text/html']),
        ]);

        $sitemap = app(AddSource::class)->handle($this->bot, ['type' => 'sitemap', 'url' => 'https://acme.test/sitemap.xml'])->fresh();

        $this->assertSame('ready', $sitemap->status);
        $this->assertSame('2 pages added', $sitemap->title);

        $pages = Source::where('parent_id', $sitemap->id)->get();

        $this->assertEqualsCanonicalizing(['https://acme.test/a', 'https://acme.test/b'], $pages->pluck('url')->all());
        $this->assertSame(['ready'], $pages->pluck('status')->unique()->all());
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'other.test'));
    }

    public function test_a_sitemap_stops_at_the_page_limit(): void
    {
        config(['chatbot.training.max_pages_per_bot' => 2]);

        $urls = collect(range(1, 5))->map(fn ($i) => "<url><loc>https://acme.test/p{$i}</loc></url>")->implode('');

        Http::fake([
            'acme.test/sitemap.xml' => Http::response("<urlset>{$urls}</urlset>", 200, ['Content-Type' => 'text/xml']),
            'acme.test/*' => Http::response($this->page('<p>Some useful text about our dental clinic.</p>'), 200, ['Content-Type' => 'text/html']),
        ]);

        $sitemap = app(AddSource::class)->handle($this->bot, ['type' => 'sitemap', 'url' => 'https://acme.test/sitemap.xml'])->fresh();

        $this->assertSame(2, Source::where('type', 'url')->count());
        $this->assertStringContainsString('3 skipped', $sitemap->title);
    }

    public function test_single_pages_also_respect_the_limit_and_reject_duplicates(): void
    {
        config(['chatbot.training.max_pages_per_bot' => 1]);
        Http::fake(['*' => Http::response($this->page('<p>Some useful text about our dental clinic.</p>'), 200, ['Content-Type' => 'text/html'])]);

        $this->addUrl('https://acme.test/one');

        try {
            $this->addUrl('https://acme.test/one');
            $this->fail('A duplicate page was accepted.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('already', $e->getMessage());
        }

        $this->expectException(ValidationException::class);
        $this->addUrl('https://acme.test/two');
    }

    public function test_pasted_text_is_trained(): void
    {
        $source = app(AddSource::class)->handle($this->bot, [
            'type' => 'text', 'title' => 'Parking', 'content' => 'Free parking is available behind the building for all patients.',
        ])->fresh();

        $this->assertSame('ready', $source->status);
        $this->assertStringContainsString('Free parking', Chunk::where('source_id', $source->id)->sole()->content);
    }

    public function test_a_question_and_answer_pair_is_one_chunk_and_follows_edits_and_deletes(): void
    {
        $qa = app(SaveQaPair::class);

        $pair = $qa->create($this->bot, 'Do you accept insurance?', 'Yes, we accept most major insurance plans.');

        $chunk = Chunk::where('source_id', $pair->source_id)->sole();
        $this->assertSame("Question: Do you accept insurance?\nAnswer: Yes, we accept most major insurance plans.", $chunk->content);
        $this->assertSame('qa', $chunk->metadata['type']);

        $qa->update($pair, 'Do you accept insurance?', 'No, payment is by cash or card only.');
        $this->assertStringContainsString('cash or card', Chunk::where('source_id', $pair->source_id)->sole()->content);

        $qa->delete($pair);
        $this->assertSame(0, Chunk::count());
        $this->assertSame(0, QaPair::count());
    }

    public function test_training_data_never_crosses_clients(): void
    {
        Http::fake(['*' => Http::response($this->page('<p>Acme private pricing information for patients.</p>'), 200, ['Content-Type' => 'text/html'])]);

        $source = $this->addUrl('https://acme.test/private');

        // A job carrying the wrong client id cannot find, train or read the source.
        Source::whereKey($source->id)->update(['status' => 'pending']);
        (new TrainSource($source->id, $this->globex->id))->handle(app(CurrentClient::class), app(\App\Training\SourceTrainer::class));
        $this->assertSame('pending', $source->fresh()->status);

        app(CurrentClient::class)->set($this->globex);

        $this->assertSame(0, Source::count());
        $this->assertSame(0, Chunk::count());
    }

    public function test_long_text_is_cut_into_overlapping_chunks(): void
    {
        config(['chatbot.training.chunk_words' => 50, 'chatbot.training.chunk_overlap_words' => 5]);

        $paragraphs = collect(range(1, 12))
            ->map(fn ($p) => collect(range(1, 20))->map(fn ($w) => "p{$p}w{$w}")->implode(' '))
            ->implode("\n\n");

        $chunks = app(TextChunker::class)->chunk($paragraphs);

        $this->assertGreaterThan(3, count($chunks));

        foreach ($chunks as $chunk) {
            $this->assertLessThanOrEqual(60, TextChunker::countWords($chunk));
        }

        // The start of each chunk repeats the end of the one before it.
        $endOfFirst = implode(' ', array_slice(preg_split('/\s+/', $chunks[0]), -5));
        $this->assertStringStartsWith($endOfFirst, preg_replace('/\s+/', ' ', $chunks[1]));

        // Nothing is lost.
        $this->assertStringContainsString('p12w20', end($chunks));
        $this->assertStringContainsString('p1w1 ', $chunks[0]);
    }

    public function test_one_very_long_paragraph_is_still_split(): void
    {
        config(['chatbot.training.chunk_words' => 50, 'chatbot.training.chunk_overlap_words' => 5]);

        $chunks = app(TextChunker::class)->chunk(implode(' ', array_fill(0, 400, 'word')));

        $this->assertGreaterThanOrEqual(8, count($chunks));
    }

    public function test_the_extractor_keeps_structure_and_reads_tables_and_lists(): void
    {
        $page = app(HtmlTextExtractor::class)->extract(
            '<html><head><title> Fees &amp; Plans </title></head><body><article>'
            .'<h2>Fees</h2><p>Check-up <strong>costs</strong> 50 dollars.</p>'
            .'<table><tr><td>Cleaning</td><td>80</td></tr></table>'
            .'<form><input value="x">Subscribe form text</form></article></body></html>'
        );

        $this->assertSame('Fees & Plans', $page->title);
        $this->assertSame("Fees\n\nCheck-up costs 50 dollars.\n\nCleaning 80", $page->text);
    }
}
