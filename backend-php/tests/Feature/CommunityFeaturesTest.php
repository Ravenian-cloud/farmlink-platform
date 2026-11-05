<?php

namespace Tests\Feature;

use App\Models\ForumPost;
use App\Models\ForumThread;
use App\Models\KnowledgeArticle;
use App\Models\User;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CommunityFeaturesTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_knowledge_hub_only_serves_published_content(): void
    {
        KnowledgeArticle::create([
            'title' => 'Soil health guide',
            'slug' => 'soil-health-guide',
            'summary' => 'Practical steps for improving soil health.',
            'body' => 'Use crop rotation and compost to support healthy soil.',
            'content_type' => 'article',
            'status' => 'published',
            'published_at' => now(),
        ]);
        KnowledgeArticle::create([
            'title' => 'Unpublished draft',
            'slug' => 'unpublished-draft',
            'summary' => 'A draft that is not public yet.',
            'body' => 'Draft material that should not be shown.',
            'content_type' => 'article',
            'status' => 'draft',
        ]);

        $this->getJson('/api/v1/knowledge/articles')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.slug', 'soil-health-guide');
        $this->getJson('/api/v1/knowledge/articles/unpublished-draft')->assertNotFound();
    }

    public function test_authenticated_members_can_create_threaded_replies_only_within_the_same_thread(): void
    {
        $user = $this->user();
        $token = $this->token($user);
        $headers = ['Idempotency-Key' => (string) Str::uuid()];

        $threadResponse = $this->withToken($token)->postJson('/api/v1/forum/threads', [
            'title' => 'How do you store beans?',
            'body' => 'What storage methods work best during the rainy season?',
        ], $headers)->assertCreated();
        $threadId = $threadResponse->json('data.id');

        $postResponse = $this->withToken($token)->postJson("/api/v1/forum/threads/{$threadId}/posts", [
            'body' => 'Drying the beans fully before storage helps.',
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();
        $postId = $postResponse->json('data.id');

        $this->withToken($token)->postJson("/api/v1/forum/threads/{$threadId}/posts", [
            'body' => 'Thank you for sharing that.',
            'parent_id' => $postId,
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();

        $otherThread = ForumThread::create([
            'author_id' => $user->id,
            'title' => 'Another discussion',
            'body' => 'This is a separate discussion.',
            'status' => 'published',
        ]);
        ForumPost::create([
            'thread_id' => $otherThread->id,
            'author_id' => $user->id,
            'body' => 'A post in a different thread.',
            'status' => 'published',
        ]);
        $foreignPost = ForumPost::query()->where('thread_id', $otherThread->id)->firstOrFail();

        $this->withToken($token)->postJson("/api/v1/forum/threads/{$threadId}/posts", [
            'body' => 'This must not attach across threads.',
            'parent_id' => $foreignPost->id,
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertUnprocessable();
    }

    public function test_community_administration_is_allowlisted_and_can_publish_content(): void
    {
        $admin = $this->user();
        $member = $this->user();
        config(['services.community.admin_ids' => $admin->id]);
        $article = [
            'title' => 'Water conservation basics',
            'summary' => 'A guide to practical water conservation.',
            'body' => 'Use mulching and careful scheduling to conserve farm water.',
            'content_type' => 'tutorial',
            'video_url' => 'https://example.com/tutorial',
            'status' => 'published',
        ];

        $this->withToken($this->token($member))
            ->postJson('/api/v1/admin/community/articles', $article)
            ->assertForbidden();

        $this->withToken($this->token($admin))
            ->postJson('/api/v1/admin/community/articles', $article)
            ->assertCreated()
            ->assertJsonPath('data.status', 'published');
    }

    public function test_moderating_a_parent_reply_hides_descendants_without_restoring_removed_children(): void
    {
        $admin = $this->user();
        config(['services.community.admin_ids' => $admin->id]);
        $thread = ForumThread::create([
            'author_id' => $admin->id,
            'title' => 'Storage discussion',
            'body' => 'Share safe storage practices.',
            'status' => 'published',
        ]);
        $parent = ForumPost::create([
            'thread_id' => $thread->id,
            'author_id' => $admin->id,
            'body' => 'Keep produce dry.',
            'status' => 'published',
        ]);
        $child = ForumPost::create([
            'thread_id' => $thread->id,
            'parent_id' => $parent->id,
            'author_id' => $admin->id,
            'body' => 'Thanks for the advice.',
            'status' => 'published',
        ]);
        $token = $this->token($admin);

        $this->withToken($token)->patchJson("/api/v1/admin/community/forum/threads/{$thread->id}/posts/{$parent->id}", [
            'status' => 'removed',
        ])->assertOk();
        $this->assertDatabaseHas('forum_posts', ['id' => $child->id, 'status' => 'removed']);

        $this->withToken($token)->patchJson("/api/v1/admin/community/forum/threads/{$thread->id}/posts/{$parent->id}", [
            'status' => 'published',
        ])->assertOk();
        $this->assertDatabaseHas('forum_posts', ['id' => $parent->id, 'status' => 'published']);
        $this->assertDatabaseHas('forum_posts', ['id' => $child->id, 'status' => 'removed']);
    }

    private function user(): User
    {
        return User::create([
            'name' => 'Community member',
            'phone' => '2547' . random_int(10000000, 99999999),
            'password' => bcrypt('community-test-password'),
            'role' => 'farmer',
        ]);
    }

    private function token(User $user): string
    {
        return JWT::encode([
            'iss' => (string) config('app.url'),
            'sub' => (string) $user->id,
            'iat' => time(),
            'nbf' => time(),
            'exp' => time() + 3600,
            'jti' => (string) Str::uuid(),
        ], (string) config('jwt.secret'), 'HS256');
    }
}
