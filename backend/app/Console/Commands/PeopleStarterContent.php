<?php

namespace App\Console\Commands;

use App\Http\Controllers\Api\UpdatesController;
use App\Models\Article;
use App\Models\Course;
use App\Models\FormTemplate;
use App\Models\Outlet;
use App\Models\Post;
use App\Models\SigningRequest;
use App\Models\Todo;
use App\Models\User;
use App\Services\NotificationService;
use App\Notifications\InAppNotification;
use App\Support\StarterContent;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * people:starter-content — the first content of the staff pages
 * (owner, 2026-11-14). See App\Support\StarterContent for the words.
 *
 * Dry run unless --apply. Idempotent: everything is matched before it is
 * created (article slug, course title, post title, celebration key, one
 * signing request and one to-do per person and title), so a second run adds
 * nothing. Output is counts only — the repository is public and so are its
 * workflow logs: no staff names, phone numbers or emails are printed.
 */
class PeopleStarterContent extends Command
{
    protected $signature = 'people:starter-content
        {--apply : Write. Without it nothing is written.}
        {--actor= : Required with --apply: the author (user id or email).}
        {--recognise= : Optional: the colleague recognised in the first recognition (user id or email).}
        {--recognise-for= : With --recognise: why, in the owner\'s words.}
        {--except=* : Staff accounts to leave out (user id or email), e.g. a shared login.}';

    protected $description = 'Load the first handbook, training, updates, to-dos, signing requests and checklist items (dry run unless --apply)';

    private bool $apply = false;
    private array $counts = [];

    public function handle(): int
    {
        $this->apply = (bool) $this->option('apply');
        $actor = null;
        if ($this->apply) {
            $actor = $this->findUser((string) $this->option('actor'));
            if (!$actor) { $this->error('--apply needs --actor=<user id or email> of an existing user.'); return self::FAILURE; }
        }
        $staff = User::where('status', 'active')->where('user_type', 'staff')->orderBy('id')->get();
        // A shared login (owner, 2026-11-14: the "Super Admin" staff@ account) gets no
        // to-dos or signing requests: nobody in particular would ever do or sign them.
        $except = collect((array) $this->option('except'))->flatMap(fn ($v) => explode(',', (string) $v))->map(fn ($v) => trim($v))->filter();
        $unknown = 0;
        foreach ($except as $ref) {
            $u = $this->findUser($ref);
            if (!$u) { $unknown++; continue; }
            $staff = $staff->reject(fn (User $p) => $p->id === $u->id)->values();
        }
        if ($unknown) { $this->error("--except: {$unknown} account(s) not found; nothing was run."); return self::FAILURE; }
        $this->line(($this->apply ? 'APPLY' : 'DRY RUN') . " — active staff: {$staff->count()}" . ($except->isNotEmpty() ? " (after leaving out {$except->count()})" : ''));

        $run = function () use ($actor, $staff) {
            $articles = $this->articles($actor);
            $courses = $this->courses($actor);
            $this->posts($actor);
            $this->signing($actor, $staff, $articles);
            $this->todos($actor, $staff);
            $this->checklists($actor);
        };
        $this->apply ? DB::transaction($run) : $run();

        foreach ($this->counts as $k => $n) $this->line(sprintf('  %-34s %d', $k, $n));
        $this->info($this->apply ? 'Done.' : 'Dry run: nothing was written. Re-run with --apply --actor=<id|email>.');
        return self::SUCCESS;
    }

    // ── Handbook ──────────────────────────────────────────────────────────

    /** @return array<string, ?Article> title → article (null in a dry run when it would be new) */
    private function articles(?User $actor): array
    {
        $out = [];
        foreach (StarterContent::articles() as $a) {
            $slug = Str::slug($a['title']);
            $existing = Article::withTrashed()->where('slug', $slug)->first();
            if ($existing) { $this->count('handbook: already there'); $out[$a['title']] = $existing->trashed() ? null : $existing; continue; }
            $this->count('handbook: new article');
            if ($a['must_read']) $this->count('updates: new must-read policy post');
            if (!$this->apply) { $out[$a['title']] = null; continue; }
            $article = Article::create(['title' => $a['title'], 'slug' => $slug, 'category' => $a['category'], 'body' => $a['body'], 'outlet_id' => null,
                'must_read' => $a['must_read'], 'published_at' => now(), 'created_by' => $actor->id, 'updated_by' => $actor->id]);
            if ($a['must_read']) {
                // Same shape HandbookController::syncPost gives a must-read article; stamped as
                // already told — the welcome update is the one notice staff get for all of this.
                $post = Post::create(['kind' => 'update', 'author_id' => $actor->id, 'outlet_id' => null, 'title' => "Policy: {$article->title}",
                    'body' => Str::limit($article->body, 300) . "\n\nRead the full article in the handbook: /handbook/{$article->id}",
                    'must_read' => true, 'comments_enabled' => false, 'published_at' => now(), 'notified_at' => now()]);
                $article->forceFill(['post_id' => $post->id])->save();
            }
            $out[$a['title']] = $article;
        }
        return $out;
    }

    // ── Training ──────────────────────────────────────────────────────────

    private function courses(?User $actor): void
    {
        foreach (StarterContent::courses() as $c) {
            if (Course::withTrashed()->where('title', $c['title'])->exists()) { $this->count('training: already there'); continue; }
            $this->count('training: new course');
            if (!$this->apply) continue;
            $course = Course::create(['title' => $c['title'], 'description' => $c['description'], 'outlet_id' => null, 'required' => $c['required'],
                'pass_mark' => $c['pass_mark'], 'published_at' => now(), 'announced_at' => now(), 'created_by' => $actor->id, 'updated_by' => $actor->id]);
            foreach ($c['lessons'] as $i => $l) $course->lessons()->create(['position' => $i + 1, 'title' => $l['title'], 'body' => $l['body']]);
            foreach ($c['questions'] as $i => $q) $course->questions()->create(['position' => $i + 1, 'question' => $q['question'], 'options' => $q['options'], 'answer' => $q['answer']]);
        }
    }

    // ── Updates (one per tab) ─────────────────────────────────────────────

    private function posts(?User $actor): void
    {
        $p = StarterContent::posts();

        foreach (['update', 'event'] as $kind) {
            $d = $p[$kind];
            if (Post::withTrashed()->where('kind', $kind)->where('title', $d['title'])->exists()) { $this->count("updates: {$kind} already there"); continue; }
            $this->count("updates: new {$kind}");
            if (!$this->apply) continue;
            $post = Post::create(['kind' => $kind, 'author_id' => $actor->id, 'outlet_id' => null, 'title' => $d['title'], 'body' => $d['body'],
                'must_read' => (bool) ($d['must_read'] ?? false), 'comments_enabled' => true,
                'event_at' => $d['event_at'] ?? null, 'event_place' => $d['event_place'] ?? null, 'published_at' => now()]);
            // The welcome is the one notice for everything loaded here; the event shows in the feed.
            if ($kind === 'update') UpdatesController::notify($post); else $post->forceFill(['notified_at' => now()])->save();
        }

        $c = $p['celebration'];
        if (Post::withTrashed()->where('celebration_key', StarterContent::CELEBRATION_KEY)->exists()) { $this->count('updates: celebration already there'); }
        else {
            $this->count('updates: new celebration');
            if ($this->apply) Post::create(['kind' => 'celebration', 'author_id' => null, 'title' => $c['title'], 'body' => $c['body'], 'celebration_key' => StarterContent::CELEBRATION_KEY,
                'comments_enabled' => true, 'published_at' => now(), 'notified_at' => now()]);
        }

        // A recognition names a real person for a real reason — only the owner can give one.
        $who = $this->option('recognise') ? $this->findUser((string) $this->option('recognise')) : null;
        $why = trim((string) $this->option('recognise-for'));
        if (!$who || $why === '') { $this->count('updates: recognition skipped (needs --recognise and --recognise-for)'); return; }
        if ($actor && $who->id === $actor->id) { $this->count('updates: recognition skipped (cannot recognise yourself)'); return; }
        if (Post::where('kind', 'recognition')->where('recipient_id', $who->id)->where('body', $why)->exists()) { $this->count('updates: recognition already there'); return; }
        $this->count('updates: new recognition');
        if (!$this->apply) return;
        $post = Post::create(['kind' => 'recognition', 'author_id' => $actor->id, 'recipient_id' => $who->id, 'body' => $why, 'comments_enabled' => true, 'published_at' => now()]);
        UpdatesController::notify($post);
    }

    // ── To sign ───────────────────────────────────────────────────────────

    private function signing(?User $actor, $staff, array $articles): void
    {
        $toSign = array_values(array_filter(StarterContent::articles(), fn ($a) => $a['sign']));
        foreach ($staff as $person) {
            $asked = 0;
            foreach ($toSign as $a) {
                $article = $articles[$a['title']] ?? null;
                if ($article && SigningRequest::where('user_id', $person->id)->where('article_id', $article->id)->whereIn('status', ['pending', 'signed'])->exists()) { $this->count('to sign: already asked'); continue; }
                $this->count('to sign: new request');
                if (!$this->apply || !$article) continue;
                SigningRequest::create(['user_id' => $person->id, 'requested_by' => $actor->id, 'kind' => 'article', 'article_id' => $article->id,
                    'title' => $article->title, 'body' => $article->body, 'source_hash' => hash('sha256', $article->body), 'due_at' => now()->addDays(14)->endOfDay(), 'status' => 'pending']);
                $asked++;
            }
            if ($this->apply && $asked) {
                NotificationService::deliver($person, new InAppNotification(title: $asked === 1 ? 'A policy to sign' : "{$asked} policies to sign",
                    body: 'Read and sign on your phone within two weeks.', actionUrl: '/me/sign', icon: 'document', data: []));
            }
        }
    }

    // ── To-dos ────────────────────────────────────────────────────────────

    private function todos(?User $actor, $staff): void
    {
        foreach ($staff as $person) {
            $outletId = DB::table('outlet_user')->where('user_id', $person->id)->orderByDesc('is_primary')->value('outlet_id');
            foreach (StarterContent::todos() as $t) {
                if (Todo::where('user_id', $person->id)->where('title', $t['title'])->exists()) { $this->count('to-dos: already there'); continue; }
                $this->count('to-dos: new');
                if (!$this->apply) continue;
                Todo::create(['user_id' => $person->id, 'created_by' => $actor->id, 'outlet_id' => $outletId, 'title' => $t['title'], 'details' => $t['details'],
                    'due_at' => now()->addDays(7)->endOfDay(), 'status' => 'open']);
            }
        }
    }

    // ── Checklists ────────────────────────────────────────────────────────

    private function checklists(?User $actor): void
    {
        foreach (StarterContent::checklistAdditions() as $name => $add) {
            $t = FormTemplate::where('name', $name)->whereNull('outlet_id')->first();
            if (!$t) { $this->count('checklists: template not found'); continue; }
            $keys = array_column($t->items ?? [], 'key');
            $missing = array_values(array_filter($add, fn ($i) => !in_array($i['key'], $keys, true)));
            if (!$missing) { $this->count('checklists: items already there'); continue; }
            // Only a template still exactly as installed (never edited by a person) is extended;
            // one the team has edited is theirs, and is left alone.
            if ($t->updated_by !== null) { $this->count('checklists: edited by the team, left alone'); continue; }
            $this->count('checklists: items added');
            if ($this->apply) $t->forceFill(['items' => array_merge($t->items, $missing), 'updated_by' => $actor->id])->save();
        }

        $desk = StarterContent::chatDeskChecklist();
        foreach (Outlet::where('is_active', true)->whereIn('sales_channel', ['whatsapp', 'online'])->get(['id']) as $o) {
            if (FormTemplate::withTrashed()->where('outlet_id', $o->id)->where('name', $desk['name'])->exists()) { $this->count('checklists: chat desk already there'); continue; }
            $this->count('checklists: new chat-desk checklist');
            if ($this->apply) FormTemplate::create(['name' => $desk['name'], 'kind' => 'checklist', 'outlet_id' => $o->id, 'schedule' => 'daily', 'weekdays' => null,
                'due_time' => $desk['due_time'], 'items' => $desk['items'], 'requires_signature' => false, 'active' => true, 'created_by' => $actor->id, 'updated_by' => $actor->id]);
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function findUser(string $ref): ?User
    {
        $ref = trim($ref);
        if ($ref === '') return null;
        return ctype_digit($ref) ? User::find((int) $ref) : User::where('email', $ref)->first();
    }

    private function count(string $key): void
    {
        $this->counts[$key] = ($this->counts[$key] ?? 0) + 1;
    }
}
