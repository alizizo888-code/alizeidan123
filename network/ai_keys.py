"""Hand the free AI providers' keys (kept as GitHub secrets) to the WhatsApp hub, which passes them to oxygen11.com.

The workflow proves who it is with GitHub's own signed token (OIDC, audience «mutqan-hub»): no password is kept
anywhere. The site's AI tries the free providers in order and falls back to the paid one only when they are all
exhausted; keys already typed on the site are left alone. Prints provider names only — never a key.
"""
import json, os, sys
import requests

HUB = os.getenv("HUB_URL", "https://vps.oxygen11.com")
u, t = os.getenv("ACTIONS_ID_TOKEN_REQUEST_URL"), os.getenv("ACTIONS_ID_TOKEN_REQUEST_TOKEN")
if not u or not t:
    print("no OIDC token available (the workflow needs permissions: id-token: write)"); sys.exit(1)
jwt = requests.get(u + "&audience=mutqan-hub", headers={"authorization": "Bearer " + t}, timeout=20).json()["value"]
prov = {}
for pid, env in [("gemini", "GEMINI_API_KEY"), ("groq", "GROQ_API_KEY"), ("openrouter", "OPENROUTER_API_KEY"), ("openai", "OPENAI_API_KEY"), ("anthropic", "ANTHROPIC_API_KEY")]:
    k = (os.getenv(env) or "").strip()
    if k:
        prov[pid] = {"key": k}
print("keys found in secrets:", ", ".join(sorted(prov)) or "none")
r = requests.post(HUB + "/ai-keys", headers={"authorization": "Bearer " + jwt, "content-type": "application/json"}, data=json.dumps({"providers": prov}), timeout=60)
print("hub answered", r.status_code)
try:
    d = r.json()
    print("newly set:", ", ".join(d.get("set") or []) or "none (already set on the site)")
    print("live now:", ", ".join(d.get("live") or []) or "none", "| Claude fallback:", "yes" if d.get("claude") else "no")
except Exception:
    print(r.text[:300])
sys.exit(0 if r.ok else 1)
