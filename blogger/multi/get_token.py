#!/usr/bin/env python3
# Run once per Google account ON YOUR OWN COMPUTER: prints the JSON to paste as GitHub secret GMAIL_<n>_JSON.
#   pip install google-auth-oauthlib
#   python blogger/multi/get_token.py client_secret.json      (client_secret.json = OAuth client of type "Desktop app")
# A browser opens: sign in with the Google account that owns the blogs, allow Blogger access.
import json, sys
from google_auth_oauthlib.flow import InstalledAppFlow
flow = InstalledAppFlow.from_client_secrets_file(sys.argv[1] if len(sys.argv) > 1 else "client_secret.json", ["https://www.googleapis.com/auth/blogger"])
c = flow.run_local_server(port=0, prompt="consent", access_type="offline")
print(json.dumps({"type": "authorized_user", "client_id": c.client_id, "client_secret": c.client_secret, "refresh_token": c.refresh_token}))
