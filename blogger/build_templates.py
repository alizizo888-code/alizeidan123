#!/usr/bin/env python3
"""Build one Blogger theme per network brand from blogger/network.json.

  python3 blogger/build_templates.py   ->  blogger/templates/<id>.xml

Every theme: brand name/colour, the blog's own pages menu (PageList widget), posts, a "شبكة مواقعنا" block linking the
other 11 blogs plus oxygen11.com, and an order bar that opens the hub app (/app/?via=<id>#/new) so every order lands in
the one operations room tagged with the blog it came from. Paste the XML in Blogger: Theme > Edit HTML.
"""
import json
from html import escape
from pathlib import Path

HERE = Path(__file__).resolve().parent
HUB = "https://oxygen11.com"
net = json.loads((HERE / "network.json").read_text(encoding="utf-8"))
out = HERE / "templates"
out.mkdir(exist_ok=True)

TPL = r"""<?xml version="1.0" encoding="UTF-8" ?>
<!DOCTYPE html>
<html b:css='false' b:defaultwidgetversion='2' b:layoutsVersion='3' b:responsive='true' b:templateVersion='1.0.0' dir='rtl' lang='ar' xmlns='http://www.w3.org/1999/xhtml' xmlns:b='http://www.google.com/2005/gml/b' xmlns:data='http://www.google.com/2005/gml/data' xmlns:expr='http://www.google.com/2005/gml/expr'>
<head>
<meta charset='UTF-8'/>
<meta content='width=device-width, initial-scale=1' name='viewport'/>
<meta content='__COLOR__' name='theme-color'/>
<b:include data='blog' name='all-head-content'/>
<title><b:if cond='data:view.isHomepage'><data:blog.title/><b:else/><data:view.title.escaped/> | __NAME__</b:if></title>
<link href='https://fonts.googleapis.com/css2?family=Tajawal:wght@400;700;800&amp;display=swap' rel='stylesheet'/>
<b:skin><![CDATA[
:root{--c:__COLOR__;--ink:#0f172a;--mut:#64748b;--bg:#f8fafc;--card:#fff;--line:#e2e8f0}
@media (prefers-color-scheme:dark){:root{--ink:#e2e8f0;--mut:#94a3b8;--bg:#0b1220;--card:#111a2e;--line:#1e293b}}
*{box-sizing:border-box}body{margin:0;font-family:Tajawal,system-ui,sans-serif;background:var(--bg);color:var(--ink);line-height:1.85;padding-bottom:84px}
a{color:var(--c);text-decoration:none}a:hover{text-decoration:underline}
.top{background:var(--c);color:#fff;padding:18px 16px 14px}
.top .w,.wrap{max-width:1080px;margin:0 auto}
.brand{display:flex;align-items:center;gap:12px}
.mark{width:44px;height:44px;border-radius:12px;background:rgba(255,255,255,.18);display:grid;place-items:center;font-weight:800;font-size:22px}
.brand h1,.brand .h1{margin:0;font-size:20px;font-weight:800;color:#fff}
.brand p{margin:2px 0 0;font-size:13px;opacity:.9}
.top .PageList ul{list-style:none;margin:12px 0 0;padding:0;display:flex;flex-wrap:wrap;gap:6px}
.top .PageList a{color:#fff;background:rgba(255,255,255,.14);padding:5px 12px;border-radius:999px;font-size:13px;display:inline-block}
.top .PageList h3{display:none}
.wrap{display:grid;grid-template-columns:1fr 300px;gap:22px;padding:22px 16px}
@media (max-width:860px){.wrap{grid-template-columns:1fr}}
.post-outer,.side .widget{background:var(--card);border:1px solid var(--line);border-radius:16px;padding:18px;margin-bottom:16px}
.post-title{margin:0 0 6px;font-size:22px;line-height:1.5}
.post-meta{color:var(--mut);font-size:13px;margin-bottom:10px}
.post-body img{max-width:100%;height:auto;border-radius:12px}
.post-body h2,.post-body h3{line-height:1.5}
.side h2,.side h3.title{font-size:16px;margin:0 0 10px;color:var(--c)}
.net{list-style:none;margin:0;padding:0;display:grid;gap:6px}
.net a{display:flex;gap:8px;align-items:center;font-size:14px;color:var(--ink)}
.net i{width:9px;height:9px;border-radius:50%;flex:none}
.cta-box{background:var(--c);color:#fff;border-radius:16px;padding:18px;text-align:center}
.cta-box a{display:inline-block;margin-top:8px;background:#fff;color:var(--c);padding:9px 18px;border-radius:999px;font-weight:800}
.bar{position:fixed;inset:auto 0 0 0;background:var(--card);border-top:1px solid var(--line);padding:10px 16px;display:flex;gap:10px;justify-content:center;z-index:9}
.bar a{flex:1;max-width:260px;text-align:center;padding:11px;border-radius:12px;font-weight:800}
.bar .go{background:var(--c);color:#fff}.bar .home{border:1px solid var(--line);color:var(--ink)}
.foot{text-align:center;color:var(--mut);font-size:13px;padding:10px 16px 24px}
.blog-pager{display:flex;justify-content:space-between;margin:10px 0}
.read-more{font-weight:700}
.side .widget.cta-box{background:var(--c);color:#fff;border:0}
]]></b:skin>
<script type='application/ld+json'>{"@context":"https://schema.org","@type":"HomeAndConstructionBusiness","name":"__FULL__","url":"https://__HOST__/","areaServed":["مكة المكرمة","جدة"],"parentOrganization":{"@type":"Organization","name":"مجموعة أكسجين للصيانة والمقاولات العامة","url":"__HUB__/"},"sameAs":["__HUB__/"]}</script>
</head>
<body>
<header class='top'><div class='w'>
 <div class='brand'><div class='mark'>__INITIAL__</div><div>
  <b:if cond='data:view.isHomepage'><h1><a expr:href='data:blog.homepageUrl' style='color:#fff'><data:blog.title/></a></h1><b:else/><div class='h1'><a expr:href='data:blog.homepageUrl' style='color:#fff'><data:blog.title/></a></div></b:if>
  <p>__ANGLE__ — مكة المكرمة وجدة</p></div></div>
 <nav class='PageList'><ul><li><a href='/'>الرئيسية</a></li>__PAGES__</ul></nav>
</div></header>
<div class='wrap'>
 <main>
  <b:section class='main' id='main' showaddelement='false'>
   <b:widget id='Blog1' locked='true' title='المقالات' type='Blog' version='2'>
    <b:widget-settings><b:widget-setting name='showDateHeader'>false</b:widget-setting><b:widget-setting name='showShareButtons'>false</b:widget-setting></b:widget-settings>
    <b:includable id='main' var='this'>
     <b:loop values='data:posts' var='post'>
      <article class='post-outer'>
       <b:if cond='data:view.isSingleItem'>
        <h1 class='post-title'><data:post.title/></h1>
       <b:else/>
        <h2 class='post-title'><a expr:href='data:post.url'><data:post.title/></a></h2>
       </b:if>
       <div class='post-meta'><data:post.date/></div>
       <b:if cond='data:view.isSingleItem'>
        <div class='post-body'><data:post.body/></div>
        <div class='cta-box'>تحتاج فني __ANGLE__ الآن؟<br/><a href='__ORDER__'>اطلب الخدمة من __NAME__</a></div>
       <b:else/>
        <div class='post-body'><data:post.snippets.short/></div>
        <a class='read-more' expr:href='data:post.url'>اقرأ المقال كاملاً ←</a>
       </b:if>
      </article>
     </b:loop>
     <b:include name='postPagination'/>
    </b:includable>
   </b:widget>
  </b:section>
 </main>
 <aside class='side'>
  <div class='widget cta-box'>طلبك يصل مباشرة لغرفة العمليات<br/><a href='__ORDER__'>اطلب الآن</a></div>
  <div class='widget'><h2>شبكة مواقعنا</h2><ul class='net'>__NETWORK__</ul></div>
  <b:section class='sidebar' id='sidebar' showaddelement='true'>
   <b:widget id='Label1' locked='false' title='التصنيفات' type='Label' version='2'/>
   <b:widget id='PopularPosts1' locked='false' title='الأكثر قراءة' type='PopularPosts' version='2'/>
  </b:section>
 </aside>
</div>
<footer class='foot'>© __FULL__ — جزء من <a href='__HUB__/'>مجموعة أكسجين للصيانة والمقاولات العامة</a></footer>
<nav class='bar'><a class='home' href='__HUB__/'>الموقع الرئيسي</a><a class='go' href='__ORDER__'>اطلب الخدمة</a></nav>
</body>
</html>
"""

for b in net:
    # only blogs that exist; the blogspot address keeps working and Blogger redirects it to the custom domain once one is set
    others = [o for o in net if o["id"] != b["id"] and o.get("blogspot")]
    items = [f"<li><a href='{HUB}/'><i style='background:#38bdf8'></i>الموقع الرئيسي — أكسجين</a></li>"]
    items += [f"<li><a href='{o['blogspot']}'><i style='background:{o['color']}'></i>{escape(o['title'])}</a></li>" for o in others]
    full = f"{b['name']} للصيانة والمقاولات العامة" if b["id"] != "oxygen" else "مجموعة أكسجين للصيانة والمقاولات العامة"
    rep = {
        "__COLOR__": b["color"], "__NAME__": escape(b["name"]), "__FULL__": escape(full), "__HOST__": b["host"],
        "__HUB__": HUB, "__ANGLE__": escape(b["angle_label"]), "__INITIAL__": escape(b["name"].replace("ال", "", 1)[:1]),
        "__ORDER__": f"{HUB}/app/?via={b['id']}#/new", "__NETWORK__": "".join(items),
        "__PAGES__": "".join(f"<li><a href='{u}'>{escape(t)}</a></li>" for t, u in b.get("page_paths", [])),
    }
    xml = TPL
    for k, v in rep.items():
        xml = xml.replace(k, v)
    (out / f"{b['id']}.xml").write_text(xml, encoding="utf-8")
print(f"{len(net)} templates -> {out}")
