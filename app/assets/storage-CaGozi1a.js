import{cU as t}from"./index-C6zMppVM.js";const e={async upload(a,r){const o=new FormData;return o.append("file",a,r||a.name||"file"),{url:(await t("/upload",o)).url}}};export{e as s};
