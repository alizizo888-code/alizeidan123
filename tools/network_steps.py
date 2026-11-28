#!/usr/bin/env python3
"""Print the Hostinger multi-execute `steps` that publish the current release to ALL 12 sites (one command = every site updated).
usage: tools/network_steps.py [deploy|create]      deploy (default): git-deploy plugin + cache clear for every site
                                                    create: the 10 new subdomain websites (run once, then deploy)
Registry = oxygen-ac-automation/includes/class-oxygen-network.php. Nothing here talks to Hostinger by itself: the printed JSON is
handed to the Hostinger connector (mcp multi-execute) after the owner has authorised the deploy."""
import json, re, sys, os
ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), '..'))
src = open(os.path.join(ROOT, 'oxygen-ac-automation/includes/class-oxygen-network.php'), encoding='utf-8').read()
rows = re.findall(r"'(\w+)'\s*=>\s*\['name' => '([^']+)',\s*'label' => '([^']*)'", src)
USER, REPO, HUB = 'u283616892', 'https://github.com/alizizo888-code/alizeidan123.git', 'oxygen11.com'
mode = sys.argv[1] if len(sys.argv) > 1 else 'deploy'
steps = []
for id_, name, label in rows:
    domain = HUB if id_ == 'oxygen' else f'{label}.{HUB}'
    branch = 'oxygen-dist' if id_ == 'oxygen' else 'plugin-dist-mutqan'      # hub = Oxygen build; the 11 others = the stock build + runtime brand
    if mode == 'create':
        if id_ in ('oxygen', 'mutqan'): continue
        steps.append({'operation': 'hosting_websites_create', 'params': {'username': USER, 'domain': domain}})
        continue
    steps.append({'operation': 'hosting_git_deploy-website-repository', 'params': {'username': USER, 'domain': domain, 'repository_url': REPO, 'branch': branch, 'directory': 'wp-content/plugins/oxygen-ac-automation'}})
    steps.append({'operation': 'hosting_cache_clear', 'params': {'username': USER, 'domain': domain}})
print(json.dumps({'steps': steps}, ensure_ascii=False, indent=1))
