# Blogger API automation — one-time setup (owner)
1. console.cloud.google.com → new project → APIs & Services → Library → enable **Blogger API v3**.
2. OAuth consent screen: External, add your Google account as a test user (Publishing status can stay "Testing"; refresh tokens of test apps expire after 7 days → click **Publish app** to make them last).
3. Credentials → Create credentials → OAuth client ID → **Web application**, redirect URI `https://developers.google.com/oauthplayground`.
4. https://developers.google.com/oauthplayground → gear ⚙ → "Use your own OAuth credentials" (client id/secret) → Step 1 scope `https://www.googleapis.com/auth/blogger` → Authorize with the account that owns the blogs → Step 2 "Exchange authorization code for tokens" → copy the **refresh token**.
5. GitHub repo alizizo888-code/mutaqin-oxygen-app → Settings → Secrets and variables → Actions → add `BLOGGER_CLIENT_ID`, `BLOGGER_CLIENT_SECRET`, `BLOGGER_REFRESH_TOKEN`.
6. Actions → "Blogger API automation" → Run workflow (mode `relayout`, then `publish`). From then on it runs 4×/day by itself.
Blogs that finish setup (theme + pages) are added to `state.api_blogs` (default: the 11 live ones). Creating new blogs and domain mapping still need the Blogger web UI.

## Optional: free AI to make every article more unique (owner order 2026-11-08)
Add any of these repo secrets (Settings → Secrets and variables → Actions). They are tried in this order; with none, articles publish as generated.
- `GEMINI_API_KEY`: aistudio.google.com → Get API key (free tier). Optional `GEMINI_MODEL` (default `gemini-2.5-flash`).
- `GROQ_API_KEY`: console.groq.com → API Keys (free tier). Optional `GROQ_MODEL`.
- `OPENROUTER_API_KEY`: openrouter.ai → Keys (free `:free` models). Optional `OPENROUTER_MODEL`.
The AI writes three extra sections only. Text that mentions prices, phone numbers, percentages, ratings, licences or years of experience is rejected.

## Multi-account (blogger/multi, workflow "Blogger multi-account publish")
One secret per Google account that owns blogs: `GMAIL_1_JSON`, `GMAIL_2_JSON`, … each = `{"type":"authorized_user","client_id":"…","client_secret":"…","refresh_token":"…"}`.
Get it either way:
- **Phone/browser only:** OAuth Playground (steps 3–4 above) signed in with that account → take the refresh token → build the JSON with the same client id/secret.
- **Computer:** OAuth client of type *Desktop app* → `python blogger/multi/get_token.py client_secret.json` → paste the printed line.
The main account's three `BLOGGER_*` secrets can be reused as `GMAIL_1_JSON`. Each blog is published by the account that owns it (found with `blogs.listByUser`).
Keywords: `blogger/multi/keywords.html` (needs a fine-grained GitHub token with Actions read/write) or edit `blogger/multi/keywords.json`.
