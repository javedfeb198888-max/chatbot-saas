<?php

namespace App\Training;

use App\Jobs\TrainSource;
use App\Models\Chunk;
use App\Models\QaPair;
use App\Models\Source;
use App\Training\Exceptions\TrainingException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Turns one source (a page, a sitemap, pasted text or a Q&A pair) into
 * searchable chunks. Always called inside the source's client context.
 */
class SourceTrainer
{
    public function __construct(
        private PageFetcher $fetcher,
        private HtmlTextExtractor $extractor,
        private SitemapParser $sitemaps,
        private TextChunker $chunker,
        private Embedder $embedder,
    ) {
    }

    public function train(Source $source): void
    {
        $source->update(['status' => 'processing', 'error' => null]);

        try {
            match ($source->type) {
                'url' => $this->trainUrl($source),
                'sitemap' => $this->trainSitemap($source),
                'text' => $this->trainText($source),
                'qa' => $this->trainQa($source),
                default => throw new TrainingException('Unknown source type.'),
            };
        } catch (TrainingException $e) {
            $source->update(['status' => 'failed', 'error' => $e->getMessage()]);
        } catch (Throwable $e) {
            Log::error('Training failed', ['source_id' => $source->id, 'exception' => $e]);

            $source->update(['status' => 'failed', 'error' => 'Something went wrong while training. Please try again.']);
        }
    }

    private function trainUrl(Source $source): void
    {
        $page = $this->fetcher->fetch($source->url);

        if (! $page->isHtml()) {
            throw new TrainingException('This address is not a web page.');
        }

        $extracted = $this->extractor->extract($page->body);

        if (TextChunker::countWords($extracted->text) < 5) {
            throw new TrainingException('No readable text was found on this page. Pages built entirely with JavaScript are not supported yet.');
        }

        $source->title = $extracted->title ?: $source->url;

        $this->store($source, $extracted->text, ['url' => $source->url, 'title' => $source->title]);
    }

    private function trainText(Source $source): void
    {
        $text = trim((string) $source->content);

        if ($text === '') {
            throw new TrainingException('There is no text to train on.');
        }

        $this->store($source, $text, ['title' => $source->title]);
    }

    private function trainQa(Source $source): void
    {
        $pair = QaPair::where('source_id', $source->id)->first();

        if ($pair === null) {
            throw new TrainingException('The question for this entry no longer exists.');
        }

        $source->title = $pair->question;

        // One Q&A pair is always one chunk, so the answer is never cut in half.
        $this->store(
            $source,
            "Question: {$pair->question}\nAnswer: {$pair->answer}",
            ['type' => 'qa'],
            chunkIt: false,
        );
    }

    private function trainSitemap(Source $source): void
    {
        $limit = (int) config('chatbot.training.max_pages_per_bot');
        $host = parse_url($source->url, PHP_URL_HOST);

        $parsed = $this->parseSitemap($source->url);
        $pages = $parsed['pages'];

        // A sitemap index lists other sitemaps; read up to five of them.
        foreach (array_slice($parsed['sitemaps'], 0, 5) as $child) {
            if (parse_url($child, PHP_URL_HOST) === $host) {
                array_push($pages, ...$this->parseSitemap($child)['pages']);
            }
        }

        $existing = Source::where('bot_id', $source->bot_id)->where('type', 'url')->pluck('url')->all();
        $room = max(0, $limit - count($existing));

        $new = collect($pages)
            ->filter(fn (string $url) => parse_url($url, PHP_URL_HOST) === $host)
            ->unique()
            ->reject(fn (string $url) => in_array($url, $existing, true))
            ->values();

        $accepted = $new->take($room);

        foreach ($accepted as $url) {
            $child = Source::create([
                'bot_id' => $source->bot_id,
                'parent_id' => $source->id,
                'type' => 'url',
                'url' => $url,
                'status' => 'pending',
            ]);

            TrainSource::dispatch($child->id, $child->client_id);
        }

        $skipped = $new->count() - $accepted->count();

        $source->update([
            'status' => 'ready',
            'title' => $accepted->count().' pages added'.($skipped > 0 ? ", {$skipped} skipped (limit of {$limit} pages)" : ''),
            'last_synced_at' => now(),
        ]);
    }

    /** @return array{pages: array<int, string>, sitemaps: array<int, string>} */
    private function parseSitemap(string $url): array
    {
        return $this->sitemaps->parse($this->fetcher->fetch($url)->body);
    }

    /**
     * Replaces the source's chunks, unless the text is the same as last time.
     *
     * @param  array<string, mixed>  $metadata
     */
    private function store(Source $source, string $text, array $metadata, bool $chunkIt = true): void
    {
        $hash = hash('sha256', $text);

        if ($source->content_hash === $hash && $source->chunks()->exists()) {
            $source->fill(['status' => 'ready', 'last_synced_at' => now()])->save();

            return;
        }

        $pieces = $chunkIt ? $this->chunker->chunk($text) : [$text];
        $vectors = $this->embedder->embed($pieces);

        DB::transaction(function () use ($source, $pieces, $vectors, $metadata, $hash) {
            $source->chunks()->delete();

            foreach ($pieces as $index => $piece) {
                Chunk::create([
                    'bot_id' => $source->bot_id,
                    'source_id' => $source->id,
                    'content' => $piece,
                    'embedding' => $vectors[$index],
                    'token_count' => (int) ceil(TextChunker::countWords($piece) * 1.3),
                    'metadata' => $metadata + ['position' => $index],
                ]);
            }

            $source->fill([
                'status' => 'ready',
                'content_hash' => $hash,
                'last_synced_at' => now(),
            ])->save();
        });
    }
}
