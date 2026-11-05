import { useEffect, useState } from 'react';
import api from '../../services/api';

function ForumThread({ thread, onReply }) {
  const [reply, setReply] = useState('');
  const [replyDrafts, setReplyDrafts] = useState({});
  const [error, setError] = useState('');
  const [submitting, setSubmitting] = useState(false);

  async function submitReply(event, parentId = null) {
    event.preventDefault();
    const draft = parentId ? replyDrafts[parentId] || '' : reply;
    if (!draft.trim()) return;

    setSubmitting(true);
    setError('');
    try {
      await api.post(`/forum/threads/${thread.id}/posts`, {
        body: draft.trim(),
        parent_id: parentId || undefined,
      });
      if (parentId) {
        setReplyDrafts((drafts) => ({ ...drafts, [parentId]: '' }));
      } else {
        setReply('');
      }
      onReply();
    } catch (requestError) {
      setError(requestError.response?.data?.message || 'Sign in to post a reply, then try again.');
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <article>
      <h2>{thread.title}</h2>
      <p>{thread.body}</p>
      <small>Started by {thread.author?.name || 'FarmLink member'} · {thread.posts_count} replies</small>
      <ul aria-label="Thread replies">
        {(thread.posts || []).map((post) => (
          <li key={post.id} style={{ marginInlineStart: post.parent_id ? '1.5rem' : 0 }}>
            <p>{post.body}</p>
            <small>{post.author?.name || 'FarmLink member'}</small>
            <button
              type="button"
              onClick={() => setReplyDrafts((drafts) => ({
                ...drafts,
                [post.id]: drafts[post.id] || `@${post.author?.name || 'member'} `,
              }))}
            >
              Reply
            </button>
            <form onSubmit={(event) => submitReply(event, post.id)}>
              <label>
                Reply to this comment
                <input
                  value={replyDrafts[post.id] || ''}
                  onChange={(event) => setReplyDrafts((drafts) => ({ ...drafts, [post.id]: event.target.value }))}
                  maxLength="5000"
                />
              </label>
              <button type="submit" disabled={submitting}>Post reply</button>
            </form>
          </li>
        ))}
      </ul>
      <form onSubmit={(event) => submitReply(event)}>
        <label>
          Add a reply
          <textarea value={reply} onChange={(event) => setReply(event.target.value)} minLength="2" maxLength="5000" required />
        </label>
        <button type="submit" disabled={submitting}>Post reply</button>
      </form>
      {error && <p role="alert">{error}</p>}
    </article>
  );
}

export default function CommunityHub() {
  const [section, setSection] = useState('knowledge');
  const [articles, setArticles] = useState([]);
  const [threads, setThreads] = useState([]);
  const [selectedArticle, setSelectedArticle] = useState(null);
  const [selectedThread, setSelectedThread] = useState(null);
  const [title, setTitle] = useState('');
  const [body, setBody] = useState('');
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(true);

  async function loadArticles() {
    setLoading(true);
    setError('');
    try {
      const { data } = await api.get('/knowledge/articles');
      setArticles(data.data || []);
    } catch {
      setError('Knowledge Hub content could not be loaded.');
    } finally {
      setLoading(false);
    }
  }

  async function loadThreads() {
    setLoading(true);
    setError('');
    try {
      const { data } = await api.get('/forum/threads');
      setThreads(data.data || []);
    } catch {
      setError('Community discussions could not be loaded.');
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => {
    if (section === 'knowledge') loadArticles();
    if (section === 'forum') loadThreads();
  }, [section]);

  async function openArticle(article) {
    try {
      const { data } = await api.get(`/knowledge/articles/${article.slug}`);
      setSelectedArticle(data.data);
    } catch {
      setError('This article is no longer available.');
    }
  }

  async function openThread(thread) {
    try {
      const { data } = await api.get(`/forum/threads/${thread.id}`);
      setSelectedThread(data.data);
    } catch {
      setError('This discussion is no longer available.');
    }
  }

  async function createThread(event) {
    event.preventDefault();
    setError('');
    try {
      await api.post('/forum/threads', { title, body });
      setTitle('');
      setBody('');
      await loadThreads();
    } catch (requestError) {
      setError(requestError.response?.data?.message || 'Sign in to start a discussion.');
    }
  }

  return (
    <main>
      <h1>Knowledge Hub & Community</h1>
      <nav aria-label="Community sections">
        <button type="button" onClick={() => { setSection('knowledge'); setSelectedArticle(null); }}>
          Knowledge Hub
        </button>
        <button type="button" onClick={() => { setSection('forum'); setSelectedThread(null); }}>
          Community forum
        </button>
      </nav>
      {error && <p role="alert">{error}</p>}
      {loading && <p role="status">Loading community content…</p>}

      {section === 'knowledge' && !loading && !selectedArticle && (
        <section aria-label="Articles and tutorials">
          {articles.length === 0 && <p>Articles and tutorials will appear here when published.</p>}
          {articles.map((article) => (
            <article key={article.id}>
              <h2>{article.title}</h2>
              <p>{article.summary}</p>
              <small>{article.content_type === 'tutorial' ? 'Tutorial' : 'Article'}</small>
              <button type="button" onClick={() => openArticle(article)}>Read more</button>
            </article>
          ))}
        </section>
      )}
      {section === 'knowledge' && selectedArticle && (
        <article>
          <button type="button" onClick={() => setSelectedArticle(null)}>Back to Knowledge Hub</button>
          <h2>{selectedArticle.title}</h2>
          <p>{selectedArticle.summary}</p>
          {selectedArticle.video_url && (
            <p>
              <a href={selectedArticle.video_url} target="_blank" rel="noreferrer">Watch tutorial video</a>
            </p>
          )}
          <div style={{ whiteSpace: 'pre-wrap' }}>{selectedArticle.body}</div>
        </article>
      )}

      {section === 'forum' && !loading && (
        <section aria-label="Community discussions">
          {!selectedThread && (
            <>
              <form onSubmit={createThread}>
                <h2>Share an experience or ask the community</h2>
                <label>
                  Topic
                  <input value={title} onChange={(event) => setTitle(event.target.value)} minLength="5" maxLength="180" required />
                </label>
                <label>
                  Your post
                  <textarea value={body} onChange={(event) => setBody(event.target.value)} minLength="10" maxLength="10000" required />
                </label>
                <button type="submit">Start discussion</button>
              </form>
              {threads.map((thread) => (
                <article key={thread.id}>
                  <h2>{thread.title}</h2>
                  <p>{thread.body}</p>
                  <small>{thread.author?.name || 'FarmLink member'} · {thread.posts_count} replies</small>
                  <button type="button" onClick={() => openThread(thread)}>Open discussion</button>
                </article>
              ))}
              {threads.length === 0 && <p>No discussions yet. Start the first one.</p>}
            </>
          )}
          {selectedThread && (
            <>
              <button type="button" onClick={() => { setSelectedThread(null); loadThreads(); }}>Back to discussions</button>
              <ForumThread thread={selectedThread} onReply={() => openThread(selectedThread)} />
            </>
          )}
        </section>
      )}
    </main>
  );
}
