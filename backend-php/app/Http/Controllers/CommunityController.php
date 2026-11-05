<?php

namespace App\Http\Controllers;

use App\Models\ForumPost;
use App\Models\ForumThread;
use App\Models\KnowledgeArticle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CommunityController extends Controller
{
    public function articles(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['nullable', Rule::in(['article', 'tutorial'])],
            'search' => ['nullable', 'string', 'max:120'],
        ]);
        $articles = KnowledgeArticle::query()
            ->where('status', 'published')
            ->when($validated['type'] ?? null, fn ($query, $type) => $query->where('content_type', $type))
            ->when($validated['search'] ?? null, fn ($query, $search) => $query->where(function ($builder) use ($search): void {
                $builder->where('title', 'ilike', '%' . addcslashes($search, '%_\\') . '%')
                    ->orWhere('summary', 'ilike', '%' . addcslashes($search, '%_\\') . '%');
            }))
            ->orderByDesc('published_at')
            ->paginate(min(max((int) $request->query('per_page', 20), 1), 50));

        return response()->json($articles);
    }

    public function article(string $slug): JsonResponse
    {
        $article = KnowledgeArticle::query()
            ->where('slug', $slug)
            ->where('status', 'published')
            ->firstOrFail();

        return response()->json(['data' => $article]);
    }

    public function threads(): JsonResponse
    {
        $threads = ForumThread::query()
            ->where('status', 'published')
            ->with('author:id,name,role')
            ->withCount(['posts' => fn ($query) => $query->where('status', 'published')])
            ->latest()
            ->paginate(20);

        return response()->json($threads);
    }

    public function thread(ForumThread $thread): JsonResponse
    {
        abort_unless($thread->status === 'published', 404);
        $thread->load([
            'author:id,name,role',
            'posts' => fn ($query) => $query->where('status', 'published')
                ->with('author:id,name,role')
                ->orderBy('created_at'),
        ]);
        $thread->loadCount(['posts' => fn ($query) => $query->where('status', 'published')]);

        return response()->json(['data' => $thread]);
    }

    public function createThread(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'min:5', 'max:180'],
            'body' => ['required', 'string', 'min:10', 'max:10000'],
        ]);
        $thread = ForumThread::create([
            ...$validated,
            'author_id' => $request->user()->id,
            'status' => 'published',
        ]);
        $thread->load('author:id,name,role');

        return response()->json(['data' => $thread], 201);
    }

    public function createPost(Request $request, ForumThread $thread): JsonResponse
    {
        abort_unless($thread->status === 'published', 404);
        $validated = $request->validate([
            'body' => ['required', 'string', 'min:2', 'max:5000'],
            'parent_id' => [
                'nullable',
                'uuid',
                Rule::exists('forum_posts', 'id')->where(fn ($query) => $query
                    ->where('thread_id', $thread->id)
                    ->where('status', 'published')),
            ],
        ]);
        $post = ForumPost::create([
            'thread_id' => $thread->id,
            'parent_id' => $validated['parent_id'] ?? null,
            'author_id' => $request->user()->id,
            'body' => $validated['body'],
            'status' => 'published',
        ]);
        $post->load('author:id,name,role');

        return response()->json(['data' => $post], 201);
    }

    public function adminAccess(Request $request): JsonResponse
    {
        return response()->json(['data' => [
            'authorized' => $this->isAdministrator((string) $request->user()->id),
        ]]);
    }

    public function adminArticles(): JsonResponse
    {
        return response()->json(KnowledgeArticle::query()->latest()->paginate(50));
    }

    public function storeArticle(Request $request): JsonResponse
    {
        return $this->saveArticle($request, null);
    }

    public function updateArticle(Request $request, KnowledgeArticle $article): JsonResponse
    {
        return $this->saveArticle($request, $article);
    }

    private function saveArticle(Request $request, ?KnowledgeArticle $article): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'min:5', 'max:180'],
            'slug' => ['nullable', 'string', 'max:200'],
            'summary' => ['required', 'string', 'min:10', 'max:500'],
            'body' => ['required', 'string', 'min:20', 'max:50000'],
            'content_type' => ['required', Rule::in(['article', 'tutorial'])],
            'video_url' => ['nullable', 'url', 'max:2048', 'regex:/^https:\/\/.+/i'],
            'status' => ['required', Rule::in(['draft', 'published', 'archived'])],
        ]);
        $validated['slug'] = Str::slug($validated['slug'] ?? $validated['title']);
        abort_if($validated['slug'] === '', 422, 'The article title or slug must contain letters or numbers.');
        Validator::make(
            ['slug' => $validated['slug']],
            ['slug' => ['required', 'string', 'max:200', Rule::unique('knowledge_articles', 'slug')->ignore($article?->id)]],
        )->validate();
        $validated['author_id'] = $request->user()->id;
        $validated['published_at'] = $validated['status'] === 'published'
            ? ($article?->published_at ?? now())
            : null;

        if ($article) {
            $article->update($validated);
            return response()->json(['data' => $article->refresh()]);
        }

        return response()->json(['data' => KnowledgeArticle::create($validated)], 201);
    }

    public function adminThreads(): JsonResponse
    {
        $threads = ForumThread::query()
            ->with('author:id,name,role')
            ->withCount('posts')
            ->latest()
            ->paginate(50);

        return response()->json($threads);
    }

    public function adminPosts(): JsonResponse
    {
        $posts = ForumPost::query()
            ->with(['author:id,name,role', 'thread:id,title'])
            ->latest()
            ->paginate(100);

        return response()->json($posts);
    }

    public function moderateThread(Request $request, ForumThread $thread): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in(['published', 'hidden', 'removed'])],
        ]);
        $thread->update([
            'status' => $validated['status'],
            'moderated_at' => now(),
        ]);

        return response()->json(['data' => $thread->refresh()]);
    }

    public function moderatePost(Request $request, ForumThread $thread, ForumPost $post): JsonResponse
    {
        abort_unless((string) $post->thread_id === (string) $thread->id, 404);
        $validated = $request->validate([
            'status' => ['required', Rule::in(['published', 'hidden', 'removed'])],
        ]);
        DB::transaction(function () use ($post, $validated): void {
            if ($validated['status'] === 'published') {
                $post->update(['status' => 'published', 'moderated_at' => now()]);
                return;
            }

            $frontier = [$post->id];
            while ($frontier !== []) {
                ForumPost::query()
                    ->whereIn('id', $frontier)
                    ->update(['status' => $validated['status'], 'moderated_at' => now()]);
                $frontier = ForumPost::query()
                    ->whereIn('parent_id', $frontier)
                    ->pluck('id')
                    ->all();
            }
        });

        return response()->json(['data' => $post->refresh()]);
    }

    private function isAdministrator(string $userId): bool
    {
        $allowedIds = array_filter(array_map('trim', explode(',', (string) config('services.community.admin_ids'))));

        return in_array($userId, $allowedIds, true);
    }
}
