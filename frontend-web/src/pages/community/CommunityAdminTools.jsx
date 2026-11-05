import { useEffect, useState } from 'react';
import api from '../../services/api';

const emptyArticle = {
  title: '',
  summary: '',
  body: '',
  content_type: 'article',
  video_url: '',
  status: 'draft',
};

export default function CommunityAdminTools() {
  const [authorized, setAuthorized] = useState(null);
  const [articles, setArticles] = useState([]);
  const [threads, setThreads] = useState([]);
  const [posts, setPosts] = useState([]);
  const [article, setArticle] = useState(emptyArticle);
  const [editingArticleId, setEditingArticleId] = useState(null);
  const [error, setError] = useState('');
  const [message, setMessage] = useState('');

  async function loadTools() {
    try {
      const [access, articleResponse, forumResponse, postResponse] = await Promise.all([
        api.get('/community/admin-access'),
        api.get('/admin/community/articles'),
        api.get('/admin/community/forum'),
        api.get('/admin/community/forum/posts'),
      ]);
      setAuthorized(access.data.data.authorized);
      setArticles(articleResponse.data.data || []);
      setThreads(forumResponse.data.data || []);
      setPosts(postResponse.data.data || []);
    } catch (requestError) {
      setAuthorized(false);
      setError(requestError.response?.status === 403
        ? 'Community administrator access is required.'
        : 'Administration tools could not be loaded.');
    }
  }

  useEffect(() => { loadTools(); }, []);

  async function publishArticle(event) {
    event.preventDefault();
    setError('');
    setMessage('');
    try {
      if (editingArticleId) {
        await api.patch(`/admin/community/articles/${editingArticleId}`, article);
      } else {
        await api.post('/admin/community/articles', article);
      }
      setArticle(emptyArticle);
      setEditingArticleId(null);
      setMessage('Article saved.');
      await loadTools();
    } catch (requestError) {
      setError(requestError.response?.data?.message || 'The article could not be saved.');
    }
  }

  async function updateArticleStatus(item, status) {
    try {
      await api.patch(`/admin/community/articles/${item.id}`, { ...item, status });
      await loadTools();
    } catch (requestError) {
      setError(requestError.response?.data?.message || 'The article status could not be updated.');
    }
  }

  async function moderateThread(thread, status) {
    try {
      await api.patch(`/admin/community/forum/threads/${thread.id}`, { status });
      await loadTools();
    } catch (requestError) {
      setError(requestError.response?.data?.message || 'The discussion could not be moderated.');
    }
  }

  async function moderatePost(post, status) {
    try {
      await api.patch(`/admin/community/forum/threads/${post.thread_id}/posts/${post.id}`, { status });
      await loadTools();
    } catch (requestError) {
      setError(requestError.response?.data?.message || 'The reply could not be moderated.');
    }
  }

  if (authorized === null) return <p role="status">Checking administrator access…</p>;
  if (!authorized) return <p role="alert">{error || 'Community administrator access is required.'}</p>;

  return (
    <main>
      <h1>Community administration</h1>
      {error && <p role="alert">{error}</p>}
      {message && <p role="status">{message}</p>}

      <section>
        <h2>{editingArticleId ? 'Edit Knowledge Hub content' : 'Create a Knowledge Hub article or tutorial'}</h2>
        <form onSubmit={publishArticle}>
          <label>Title<input value={article.title} onChange={(event) => setArticle({ ...article, title: event.target.value })} minLength="5" maxLength="180" required /></label>
          <label>Summary<input value={article.summary} onChange={(event) => setArticle({ ...article, summary: event.target.value })} minLength="10" maxLength="500" required /></label>
          <label>Content<textarea value={article.body} onChange={(event) => setArticle({ ...article, body: event.target.value })} minLength="20" maxLength="50000" required /></label>
          <label>
            Type
            <select value={article.content_type} onChange={(event) => setArticle({ ...article, content_type: event.target.value })}>
              <option value="article">Article</option>
              <option value="tutorial">Tutorial</option>
            </select>
          </label>
          <label>HTTPS video URL (optional)<input type="url" value={article.video_url} onChange={(event) => setArticle({ ...article, video_url: event.target.value })} /></label>
          <label>
            Status
            <select value={article.status} onChange={(event) => setArticle({ ...article, status: event.target.value })}>
              <option value="draft">Draft</option>
              <option value="published">Publish</option>
              <option value="archived">Archive</option>
            </select>
          </label>
          <button type="submit">Save article</button>
          {editingArticleId && (
            <button type="button" onClick={() => { setEditingArticleId(null); setArticle(emptyArticle); }}>
              Cancel edit
            </button>
          )}
        </form>
      </section>

      <section>
        <h2>Articles</h2>
        {articles.map((item) => (
          <article key={item.id}>
            <h3>{item.title}</h3>
            <p>Status: {item.status}</p>
            <button
              type="button"
              onClick={() => {
                setEditingArticleId(item.id);
                setArticle({
                  title: item.title,
                  slug: item.slug,
                  summary: item.summary,
                  body: item.body,
                  content_type: item.content_type,
                  video_url: item.video_url || '',
                  status: item.status,
                });
              }}
            >
              Edit
            </button>
            {item.status !== 'published' && <button type="button" onClick={() => updateArticleStatus(item, 'published')}>Publish</button>}
            {item.status === 'published' && <button type="button" onClick={() => updateArticleStatus(item, 'archived')}>Archive</button>}
          </article>
        ))}
      </section>

      <section>
        <h2>Forum moderation</h2>
        {threads.map((thread) => (
          <article key={thread.id}>
            <h3>{thread.title}</h3>
            <p>{thread.body}</p>
            <p>Status: {thread.status}</p>
            {thread.status !== 'published' && <button type="button" onClick={() => moderateThread(thread, 'published')}>Restore</button>}
            {thread.status === 'published' && <button type="button" onClick={() => moderateThread(thread, 'hidden')}>Hide</button>}
            {thread.status !== 'removed' && <button type="button" onClick={() => moderateThread(thread, 'removed')}>Remove</button>}
          </article>
        ))}
        <h3>Replies</h3>
        {posts.map((post) => (
          <article key={post.id}>
            <p>In “{post.thread?.title || 'discussion'}”, {post.author?.name || 'member'} wrote: {post.body}</p>
            <p>Status: {post.status}</p>
            {post.status !== 'published' && <button type="button" onClick={() => moderatePost(post, 'published')}>Restore reply</button>}
            {post.status === 'published' && <button type="button" onClick={() => moderatePost(post, 'hidden')}>Hide reply</button>}
            {post.status !== 'removed' && <button type="button" onClick={() => moderatePost(post, 'removed')}>Remove reply</button>}
          </article>
        ))}
      </section>
    </main>
  );
}
