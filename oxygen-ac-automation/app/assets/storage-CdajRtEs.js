import{bT as t}from"./index-BdQj9ObN.js";const e={async upload(a,r){const o=new FormData;return o.append("file",a,r||a.name||"file"),{url:(await t("/upload",o)).url}}};export{e as s};
