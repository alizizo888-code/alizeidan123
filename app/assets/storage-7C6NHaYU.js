import{cr as t}from"./index-B_Z9_Ak0.js";const e={async upload(a,o){const r=new FormData;return r.append("file",a,o||a.name||"file"),{url:(await t("/upload",r)).url}}};export{e as s};
