import{cs as s}from"./index-CCAgEQ88.js";const e={async upload(a,r){const o=new FormData;return o.append("file",a,r||a.name||"file"),{url:(await s("/upload",o)).url}}};export{e as s};
